<?php

declare(strict_types=1);

namespace Machma\Forms\Admin;

use Machma\Forms\Config\Config;
use Machma\Forms\Http\Request;
use Machma\Forms\Http\Response;
use Machma\Forms\Mail\MailException;
use Machma\Forms\Mail\Mailer;
use Machma\Forms\Mail\MailMessage;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Submission\SubmissionHandler;
use Machma\Forms\Submission\SubmissionLog;

final class AdminController
{
    private const PAGE_SIZE = 50;

    /** Fixed flash texts – never reflect user input. */
    private const FLASH = [
        'logged_out' => ['ok', 'Abgemeldet.'],
        'password_set' => ['ok', 'Passwort gespeichert. Bitte jetzt anmelden.'],
        'invite_sent' => ['ok', 'Einladung verschickt (Link 24 h gültig).'],
        'invite_failed' => ['error', 'Einladung konnte nicht verschickt werden (SMTP-Fehler).'],
        'smtp_ok' => ['ok', 'SMTP-Test verschickt – bitte Posteingang prüfen.'],
        'smtp_failed' => ['error', 'SMTP-Test fehlgeschlagen. Details im PHP-Error-Log.'],
        'test_sent' => ['ok', 'Testeinsendung verschickt.'],
        'test_dry' => ['ok', 'Testeinsendung geprüft (dryRun – nichts verschickt).'],
        'test_failed' => ['error', 'Testeinsendung fehlgeschlagen (SMTP-Fehler).'],
        'csrf' => ['error', 'Sitzung abgelaufen. Bitte erneut versuchen.'],
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly SubmissionLog $log,
        private readonly Mailer $mailer,
        private readonly SubmissionHandler $submissions,
        private readonly Hasher $hasher,
        private readonly RateLimiter $rateLimiter,
        private readonly View $view,
        private readonly string $baseUrl,
        private readonly array $secrets,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    // ---------------------------------------------------------------- login & passwords

    public function login(Request $request): Response
    {
        if ($this->auth->currentUser($request) !== null) {
            return Response::redirect('/admin');
        }
        $error = null;
        if ($request->method === 'POST') {
            if (!$this->preSessionCsrfOk($request)) {
                $error = self::FLASH['csrf'][1];
            } else {
                $result = $this->auth->login($request->postString('email'), $request->postString('password'), $request->ip);
                if ($result['ok']) {
                    return Response::redirect('/admin')
                        ->withCookie(Auth::cookieName($request, 'mf_session'), $result['sessionId'], Auth::SESSION_TTL, $request->https);
                }
                $error = $result['error'] === 'rate_limited'
                    ? 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.'
                    : 'E-Mail oder Passwort ist falsch.';
            }
        }
        return $this->page($request, 'admin/login', ['title' => 'Anmelden', 'error' => $error, 'email' => $request->postString('email')], $error ? 401 : 200);
    }

    public function logout(Request $request): Response
    {
        $user = $this->auth->currentUser($request);
        if ($user !== null && $this->csrfOk($request, $user)) {
            $this->auth->logout($request);
        }
        return Response::redirect('/admin/login?msg=logged_out')
            ->withCookie(Auth::cookieName($request, 'mf_session'), '', 0, $request->https);
    }

    public function invite(Request $request, array $vars): Response
    {
        $token = $vars['token'];
        $email = $this->auth->peekToken($token);
        if ($email === null) {
            return $this->page($request, 'admin/message', [
                'title' => 'Link ungültig',
                'text' => 'Dieser Link ist abgelaufen oder wurde schon benutzt. Unter „Passwort vergessen“ kannst du einen neuen anfordern.',
            ], 410);
        }
        $error = null;
        if ($request->method === 'POST') {
            if (!$this->preSessionCsrfOk($request)) {
                $error = self::FLASH['csrf'][1];
            } else {
                $error = Auth::passwordProblem($request->postString('password'), $request->postString('password_confirm'));
                if ($error === null) {
                    $this->auth->redeemToken($token, $request->postString('password'));
                    return Response::redirect('/admin/login?msg=password_set');
                }
            }
        }
        return $this->page($request, 'admin/invite', ['title' => 'Passwort setzen', 'email' => $email, 'error' => $error, 'min' => Auth::MIN_PASSWORD]);
    }

    public function reset(Request $request): Response
    {
        $done = false;
        if ($request->method === 'POST' && $this->preSessionCsrfOk($request)) {
            $now = ($this->clock)();
            $email = strtolower(trim($request->postString('email')));
            $ipOk = $this->rateLimiter->hit('reset:' . $this->hasher->ip($request->ip, $now), 10, 3600, $now) === null;
            $mailOk = $this->rateLimiter->hit('resetmail:' . $this->hasher->email($email), 3, 3600, $now) === null;
            // Only addresses from users.yaml get a mail; the answer is always the same.
            if ($ipOk && $mailOk && $this->config->user($email) !== null) {
                $this->sendLink($request, $email, 'reset');
            }
            $done = true;
        }
        return $this->page($request, 'admin/reset', ['title' => 'Passwort vergessen', 'done' => $done]);
    }

    // ---------------------------------------------------------------- dashboard

    public function overview(Request $request): Response
    {
        if (!$user = $this->auth->currentUser($request)) {
            return Response::redirect('/admin/login');
        }
        $now = ($this->clock)();
        $since = $now - 30 * 86400;
        $sites = [];
        foreach ($user['sites'] as $siteId) {
            $site = $this->config->site($siteId);
            if ($site === null) {
                continue;
            }
            $sites[] = ['site' => $site, 'totals' => $this->log->totalsByForm($siteId, $since)];
        }
        return $this->page($request, 'admin/overview', ['title' => 'Übersicht', 'user' => $user, 'sites' => $sites, 'generatedAt' => $this->config->generatedAt()]);
    }

    public function site(Request $request, array $vars): Response
    {
        if (!$user = $this->auth->currentUser($request)) {
            return Response::redirect('/admin/login');
        }
        $site = $this->config->site($vars['site']);
        if ($site === null || !in_array($site['id'], $user['sites'], true)) {
            return $this->page($request, 'admin/message', ['title' => 'Nicht gefunden', 'text' => 'Diese Seite gibt es nicht oder du darfst sie nicht sehen.', 'user' => $user], 404);
        }
        $now = ($this->clock)();
        $days = $request->queryString('days') === '90' ? 90 : 30;
        $form = $request->queryString('form');
        $form = isset($site['forms'][$form]) ? $form : null;
        $status = in_array($request->queryString('status'), SubmissionLog::STATUSES, true) ? $request->queryString('status') : null;
        $from = self::parseDate($request->queryString('from'));
        $to = self::parseDate($request->queryString('to'));
        $page = max(1, (int) $request->queryString('page'));

        $total = $this->log->count([$site['id']], $form, $status, $from, $to !== null ? $to + 86400 : null);
        $logs = $this->log->query([$site['id']], $form, $status, $from, $to !== null ? $to + 86400 : null, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return $this->page($request, 'admin/site', [
            'title' => $site['id'],
            'user' => $user,
            'site' => $site,
            'days' => $days,
            'chart' => Chart::svg($this->log->daily($site['id'], $form, $now - ($days - 1) * 86400 - $now % 86400), $days, $now),
            'totals' => $this->log->totalsByForm($site['id'], $now - $days * 86400),
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'filter' => ['form' => $form, 'status' => $status, 'from' => $request->queryString('from'), 'to' => $request->queryString('to'), 'days' => $days],
            'flash' => $this->flash($request),
        ]);
    }

    public function errors(Request $request): Response
    {
        if (!$user = $this->requireAdmin($request)) {
            return Response::redirect('/admin/login');
        }
        $logs = $this->log->query(array_keys($this->config->sites()), null, 'error', ($this->clock)() - 90 * 86400, null, 200, 0);
        return $this->page($request, 'admin/errors', ['title' => 'Fehler aller Seiten', 'user' => $user, 'logs' => $logs]);
    }

    // ---------------------------------------------------------------- admin actions

    public function users(Request $request): Response
    {
        if (!$user = $this->requireAdmin($request)) {
            return Response::redirect('/admin/login');
        }
        $users = [];
        foreach ($this->config->users() as $email => $u) {
            $users[] = ['email' => $email, 'role' => $u['role'], 'sites' => $u['sites'], 'active' => $this->auth->hasPassword($email)];
        }
        return $this->page($request, 'admin/users', ['title' => 'Benutzer', 'user' => $user, 'users' => $users, 'flash' => $this->flash($request), 'smtpFrom' => $this->secrets['smtp']['from'] ?? '']);
    }

    public function sendInvite(Request $request): Response
    {
        if (!($user = $this->requireAdmin($request)) || !$this->csrfOk($request, $user)) {
            return Response::redirect('/admin/users?msg=csrf');
        }
        $email = strtolower(trim($request->postString('email')));
        if ($this->config->user($email) === null) {
            return Response::redirect('/admin/users');
        }
        return Response::redirect('/admin/users?msg=' . ($this->sendLink($request, $email, 'invite') ? 'invite_sent' : 'invite_failed'));
    }

    public function smtpTest(Request $request): Response
    {
        if (!($user = $this->requireAdmin($request)) || !$this->csrfOk($request, $user)) {
            return Response::redirect('/admin/users?msg=csrf');
        }
        try {
            $this->mailer->send(new MailMessage(
                to: [$user['email']],
                subject: 'SMTP-Test machma forms',
                body: "Diese Testmail wurde über das zentrale Postfach verschickt.\n\nZeit: " . date('d.m.Y H:i:s', ($this->clock)()) . "\n",
                fromName: 'machma forms',
            ));
            $msg = 'smtp_ok';
        } catch (MailException $e) {
            error_log('[machma-forms] SMTP-Test: ' . $e->getMessage());
            $msg = 'smtp_failed';
        }
        return Response::redirect('/admin/users?msg=' . $msg);
    }

    /** Test submission: runs the delivery (recipient mail, autoresponder to the admin) without browser checks. */
    public function testSubmission(Request $request, array $vars): Response
    {
        $site = $this->config->site($vars['site']);
        if (!($user = $this->requireAdmin($request)) || !$this->csrfOk($request, $user) || $site === null) {
            return Response::redirect('/admin?msg=csrf');
        }
        $formId = $request->postString('form');
        $form = $site['forms'][$formId] ?? null;
        if ($form === null) {
            return Response::redirect('/admin/sites/' . $site['id']);
        }
        $now = ($this->clock)();
        $locale = $site['locales'][0] ?? 'de';
        $ipHash = $this->hasher->ip($request->ip, $now);
        $logFn = function (string $status, ?string $reason, bool $auto = false) use ($now, $site, $formId, $ipHash, $locale): void {
            $this->log->write($now, $site['id'], $formId, $status, $reason ?? 'admin_test', $ipHash, $locale, 0, $auto);
        };
        $response = $this->submissions->deliver($site, $form, self::sampleValues($form, $user['email'], $now), $locale, $now, null, $logFn);
        $msg = $response->status !== 200 ? 'test_failed' : (!empty($site['dryRun']) ? 'test_dry' : 'test_sent');
        return Response::redirect('/admin/sites/' . $site['id'] . '?msg=' . $msg);
    }

    /** @return array<string, mixed> */
    public static function sampleValues(array $form, string $email, int $now): array
    {
        $values = [];
        foreach ($form['fields'] as $f) {
            $values[$f['name']] = match ($f['type']) {
                'email' => $email,
                'tel' => '+43 1 234567',
                'textarea' => 'Testeinsendung aus dem Dashboard von machma forms.',
                'number' => $f['min'] ?? 1,
                'date' => date('Y-m-d', $now + 86400 * 14),
                'select', 'radio' => $f['options'][0]['value'],
                'checkbox' => isset($f['options']) ? [$f['options'][0]['value']] : true,
                'consent' => true,
                'hidden' => 'test',
                default => 'Test',
            };
        }
        return $values;
    }

    // ---------------------------------------------------------------- helpers

    private function sendLink(Request $request, string $email, string $purpose): bool
    {
        $token = $this->auth->createToken($email, $purpose);
        $base = rtrim($this->baseUrl !== '' ? $this->baseUrl : (($request->https ? 'https://' : 'http://') . ($request->header('host') ?? 'localhost')), '/');
        $link = $base . '/admin/invite/' . $token;
        $intro = $purpose === 'invite'
            ? "du wurdest zum Dashboard von machma forms eingeladen."
            : "für deinen Zugang zu machma forms wurde ein neues Passwort angefordert.";
        try {
            $this->mailer->send(new MailMessage(
                to: [$email],
                subject: $purpose === 'invite' ? 'Einladung zu machma forms' : 'Passwort zurücksetzen – machma forms',
                body: "Hallo,\n\n$intro\nÜber diesen Link setzt du dein Passwort (24 Stunden gültig, einmal nutzbar):\n\n$link\n\n"
                    . "Wenn du das nicht angefordert hast, kannst du diese Mail ignorieren.\n\n-- \nmachma forms\n",
                fromName: 'machma forms',
            ));
            return true;
        } catch (MailException $e) {
            error_log('[machma-forms] Link-Mail: ' . $e->getMessage());
            return false;
        }
    }

    private function requireAdmin(Request $request): ?array
    {
        $user = $this->auth->currentUser($request);
        return $user !== null && $user['role'] === 'admin' ? $user : null;
    }

    private function csrfOk(Request $request, array $user): bool
    {
        return $request->method === 'POST' && hash_equals($user['csrf'], $request->postString('_csrf'));
    }

    /** Double-submit cookie for forms shown before login (login, reset, invite). */
    private function preSessionCsrfOk(Request $request): bool
    {
        $cookie = $request->cookies[Auth::cookieName($request, 'mf_csrf')] ?? '';
        return $cookie !== '' && hash_equals($cookie, $request->postString('_csrf'));
    }

    private function page(Request $request, string $template, array $vars, int $status = 200): Response
    {
        $user = $vars['user'] ?? null;
        $setCookie = null;
        if ($user !== null) {
            $csrf = $user['csrf'];
        } else {
            $name = Auth::cookieName($request, 'mf_csrf');
            $csrf = $request->cookies[$name] ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/', $csrf)) {
                $csrf = bin2hex(random_bytes(32));
                $setCookie = $name;
            }
        }
        $vars += ['flash' => $this->flash($request), 'user' => null];
        $response = Response::html($this->view->render($template, $vars + ['csrf' => $csrf]), $status);
        if ($setCookie !== null) {
            $response->withCookie($setCookie, $csrf, 7200, $request->https);
        }
        return $response;
    }

    /** @return array{0:string, 1:string}|null */
    private function flash(Request $request): ?array
    {
        return self::FLASH[$request->queryString('msg')] ?? null;
    }

    private static function parseDate(string $value): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $ts = strtotime($value . ' 00:00:00 UTC');
        return $ts === false ? null : $ts;
    }
}
