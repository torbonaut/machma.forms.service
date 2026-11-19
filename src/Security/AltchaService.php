<?php

declare(strict_types=1);

namespace Machma\Forms\Security;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use Machma\Forms\Storage\Database;

/**
 * ALTCHA proof-of-work: HMAC-signed challenges (10 min), bound to a site,
 * with replay protection for used signatures in SQLite.
 */
final class AltchaService
{
    public const TTL = 600;
    public const MAX_NUMBER = 50000;

    private readonly Altcha $altcha;

    public function __construct(
        string $hmacKey,
        private readonly Database $db,
        private readonly int $maxNumber = self::MAX_NUMBER,
    ) {
        $this->altcha = new Altcha($hmacKey);
    }

    /** @return array{algorithm:string, challenge:string, maxnumber:int, salt:string, signature:string} */
    public function create(string $siteId, int $now): array
    {
        $challenge = $this->altcha->createChallenge(new ChallengeOptions(
            algorithm: Algorithm::SHA256,
            maxNumber: $this->maxNumber,
            expires: (new \DateTimeImmutable())->setTimestamp($now + self::TTL),
            params: ['site' => $siteId, 'iat' => $now],
        ));

        return [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'maxnumber' => $challenge->maxNumber,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
        ];
    }

    /**
     * Reads the (still unverified) issue time from the payload – used for the
     * minimum-time check, which runs before the signature check.
     */
    public static function issuedAt(mixed $payload): ?int
    {
        $params = self::params($payload);
        return isset($params['iat']) && ctype_digit((string) $params['iat']) ? (int) $params['iat'] : null;
    }

    /**
     * Verifies signature, expiry, site binding and that the solution was never used.
     * Marks it as used on success.
     */
    public function verify(mixed $payload, string $siteId, int $now): bool
    {
        if (!is_string($payload) || $payload === '' || strlen($payload) > 2000) {
            return false;
        }
        $params = self::params($payload);
        if (($params['site'] ?? null) !== $siteId
            || !isset($params['expires']) || !ctype_digit((string) $params['expires'])
            || (int) $params['expires'] < $now) {
            return false;
        }
        try {
            if (!$this->altcha->verifySolution($payload, true)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        $signature = (string) ($data['signature'] ?? '');
        $stmt = $this->db->run(
            'INSERT OR IGNORE INTO altcha_used (signature, expires) VALUES (?, ?)',
            [$signature, (int) $params['expires']],
        );
        return $stmt->rowCount() === 1;
    }

    /** @return array<string, mixed> */
    private static function params(mixed $payload): array
    {
        if (!is_string($payload) || strlen($payload) > 2000) {
            return [];
        }
        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data) || !is_string($data['salt'] ?? null)) {
            return [];
        }
        $parts = explode('?', $data['salt'], 2);
        if (count($parts) < 2) {
            return [];
        }
        parse_str($parts[1], $params);
        return $params;
    }
}
