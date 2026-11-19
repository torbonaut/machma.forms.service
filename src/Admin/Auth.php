<?php

declare(strict_types=1);

namespace Machma\Forms\Admin;

use Machma\Forms\Config\Config;
use Machma\Forms\Http\Request;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Storage\Database;

/**
 * Dashboard login: Argon2id hashes in SQLite, sessions in SQLite (only HMAC of the id stored),
 * invite/reset links (24 h, single use). Who may see which sites comes only from users.yaml.
 */
final class Auth
{
    public const SESSION_TTL = 8 * 3600;
    public const TOKEN_TTL = 24 * 3600;
    public const LOGIN_LIMIT = 5;
    public const LOGIN_WINDOW = 15 * 60;
    public const MIN_PASSWORD = 10;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly Hasher $hasher,
        #[\SensitiveParameter] private readonly string $sessionKey,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function cookieName(Request $request, string $base): string
    {
        return $request->https ? '__Host-' . $base : $base;
    }

    /** @return array{email:string, role:string, sites:list<string>, csrf:string}|null */
    public function currentUser(Request $request): ?array
    {
        $id = $request->cookies[self::cookieName($request, 'mf_session')] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return null;
        }
        $now = ($this->clock)();
        $row = $this->db->one('SELECT email, csrf, expires FROM sessions WHERE id_hash = ? AND expires > ?', [$this->hash($id), $now]);
        if ($row === null) {
            return null;
        }
        // Removed from users.yaml → access ends with the next request.
        $user = $this->config->user($row['email']);
        if ($user === null) {
            $this->db->run('DELETE FROM sessions WHERE email = ?', [$row['email']]);
            return null;
        }
        if ((int) $row['expires'] - $now < self::SESSION_TTL - 600) {
            $this->db->run('UPDATE sessions SET expires = ? WHERE id_hash = ?', [$now + self::SESSION_TTL, $this->hash($id)]);
        }
        return ['email' => $row['email'], 'role' => $user['role'], 'sites' => $this->visibleSites($user), 'csrf' => $row['csrf']];
    }

    /**
     * @return array{ok:bool, sessionId?:string, error?:string}
     */
    public function login(string $email, string $password, string $ip): array
    {
        $now = ($this->clock)();
        $email = strtolower(trim($email));
        $emailBucket = 'login:' . $this->hasher->email($email);
        $ipBucket = 'loginip:' . $this->hasher->ip($ip, $now);

        if ($this->rateLimiter->check($emailBucket, self::LOGIN_LIMIT, self::LOGIN_WINDOW, $now) !== null
            || $this->rateLimiter->check($ipBucket, self::LOGIN_LIMIT * 4, self::LOGIN_WINDOW, $now) !== null) {
            return ['ok' => false, 'error' => 'rate_limited'];
        }

        $row = $this->config->user($email) !== null
            ? $this->db->one('SELECT password_hash FROM users WHERE email = ?', [$email])
            : null;
        // Always verify against something so timing does not reveal whether the user exists.
        $hash = $row['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), self::algo());
        $valid = password_verify($password, $hash) && $row !== null;

        if (!$valid) {
            $this->rateLimiter->record($emailBucket, $now);
            $this->rateLimiter->record($ipBucket, $now);
            return ['ok' => false, 'error' => 'invalid'];
        }
        $this->rateLimiter->clear($emailBucket);
        if (password_needs_rehash($hash, self::algo())) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE email = ?', [password_hash($password, self::algo()), $email]);
        }
        return ['ok' => true, 'sessionId' => $this->createSession($email)];
    }

    public function createSession(string $email): string
    {
        $id = bin2hex(random_bytes(32));
        $this->db->run('INSERT INTO sessions (id_hash, email, csrf, expires) VALUES (?, ?, ?, ?)', [
            $this->hash($id), strtolower($email), bin2hex(random_bytes(32)), ($this->clock)() + self::SESSION_TTL,
        ]);
        return $id;
    }

    public function logout(Request $request): void
    {
        $id = $request->cookies[self::cookieName($request, 'mf_session')] ?? '';
        if ($id !== '') {
            $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [$this->hash($id)]);
        }
    }

    /** Creates an invite/reset link token (24 h, single use). */
    public function createToken(string $email, string $purpose): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run('INSERT INTO tokens (token_hash, email, purpose, expires) VALUES (?, ?, ?, ?)', [
            $this->hash($token), strtolower($email), $purpose, ($this->clock)() + self::TOKEN_TTL,
        ]);
        return $token;
    }

    /** @return string|null email if the token is valid (does not consume it) */
    public function peekToken(string $token): ?string
    {
        $row = $this->db->one('SELECT email FROM tokens WHERE token_hash = ? AND used = 0 AND expires > ?', [$this->hash($token), ($this->clock)()]);
        if ($row === null || $this->config->user($row['email']) === null) {
            return null;
        }
        return $row['email'];
    }

    /** Sets the password via a token. Returns the email on success. */
    public function redeemToken(string $token, string $password): ?string
    {
        $email = $this->peekToken($token);
        if ($email === null) {
            return null;
        }
        $used = $this->db->run('UPDATE tokens SET used = 1 WHERE token_hash = ? AND used = 0', [$this->hash($token)]);
        if ($used->rowCount() !== 1) {
            return null;
        }
        $this->db->run(
            'INSERT INTO users (email, password_hash, updated) VALUES (?, ?, ?)
             ON CONFLICT(email) DO UPDATE SET password_hash = excluded.password_hash, updated = excluded.updated',
            [$email, password_hash($password, self::algo()), ($this->clock)()],
        );
        // A new password ends all existing sessions and other open links.
        $this->db->run('DELETE FROM sessions WHERE email = ?', [$email]);
        $this->db->run('UPDATE tokens SET used = 1 WHERE email = ?', [$email]);
        return $email;
    }

    public function hasPassword(string $email): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE email = ?', [strtolower($email)]) !== null;
    }

    /** @param array{role:string, sites:list<string>} $user @return list<string> */
    public function visibleSites(array $user): array
    {
        return $user['role'] === 'admin' ? array_keys($this->config->sites()) : $user['sites'];
    }

    public static function passwordProblem(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Das Passwort braucht mindestens ' . self::MIN_PASSWORD . ' Zeichen.';
        }
        if (strlen($password) > 4096) {
            return 'Das Passwort ist zu lang.';
        }
        if (!hash_equals($password, $confirm)) {
            return 'Die Passwörter stimmen nicht überein.';
        }
        return null;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->sessionKey);
    }

    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
