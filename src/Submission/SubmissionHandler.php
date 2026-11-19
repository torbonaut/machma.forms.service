<?php

declare(strict_types=1);

namespace Machma\Forms\Submission;

use Machma\Forms\Config\Config;
use Machma\Forms\Form\Messages;
use Machma\Forms\Form\SubmissionValidator;
use Machma\Forms\Http\Request;
use Machma\Forms\Http\Response;
use Machma\Forms\Mail\MailBuilder;
use Machma\Forms\Mail\MailException;
use Machma\Forms\Mail\Mailer;
use Machma\Forms\Security\AltchaService;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Security\RedirectResolver;

/**
 * The POST /v1/{site}/{form} flow. Each step can abort with the documented status.
 * Principle: no value from the browser decides where a mail goes.
 */
final class SubmissionHandler
{
    public const MAX_BODY = 32768;
    public const MIN_SECONDS = 3;
    public const AUTORESPONDER_PER_DAY = 3;
    public const HONEYPOT = '_website';
    /** Keys in the body that are not form fields. */
    public const RESERVED = ['locale', 'altcha', self::HONEYPOT, '_redirect'];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly AltchaService $altcha,
        private readonly Hasher $hasher,
        private readonly SubmissionValidator $validator,
        private readonly Mailer $mailer,
        private readonly SubmissionLog $log,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function handle(string $siteId, string $formId, Request $request): Response
    {
        $now = ($this->clock)();
        $started = microtime(true);

        // 1. Site and form exist
        $site = $this->config->site($siteId);
        $form = $this->config->form($siteId, $formId);
        if ($site === null || $form === null) {
            return self::error('not_found', 404, 'de');
        }
        $fallbackLocale = $site['locales'][0] ?? 'de';

        // 2. Origin is allowed
        if (!self::originAllowed($request->origin(), $site['origins'])) {
            return self::error('forbidden', 403, $fallbackLocale);
        }

        // 3. JSON body < 32 KB
        if ($request->bodyTooLarge || strlen($request->body) > self::MAX_BODY) {
            return self::error('too_large', 413, $fallbackLocale);
        }
        if (!$request->isJson()) {
            return self::error('unsupported', 415, $fallbackLocale);
        }
        try {
            $input = json_decode($request->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $input = null;
        }
        if (!is_array($input) || array_is_list($input) && $input !== []) {
            return self::error('unsupported', 400, $fallbackLocale);
        }

        $locale = is_string($input['locale'] ?? null) && in_array($input['locale'], $site['locales'], true)
            ? $input['locale'] : $fallbackLocale;
        $ipHash = $this->hasher->ip($request->ip, $now);
        $logFn = function (string $status, ?string $reason, bool $auto = false) use ($now, $siteId, $formId, $ipHash, $locale, $started): void {
            $this->log->write($now, $siteId, $formId, $status, $reason, $ipHash, $locale,
                (int) round((microtime(true) - $started) * 1000), $auto);
        };

        // 4. Rate limit per IP hash and site
        $limit = $site['rateLimit'];
        $retry = $this->rateLimiter->hit("submit:$siteId:$ipHash", (int) $limit['perIp'], (int) $limit['windowMinutes'] * 60, $now);
        if ($retry !== null) {
            $logFn('rate_limited', null);
            return self::error('rate_limited', 429, $locale, ['minutes' => (int) ceil($retry / 60)])
                ->withHeader('Retry-After', (string) $retry);
        }

        $redirect = RedirectResolver::resolve($input['_redirect'] ?? null, $form['redirect'] ?? null, $locale, $site['origins']);

        // 5. Honeypot empty and minimum time reached → otherwise silently "ok"
        $honeypot = $input[self::HONEYPOT] ?? '';
        if ($honeypot !== '' && $honeypot !== null) {
            $logFn('spam', 'honeypot');
            return self::success($redirect);
        }
        $issuedAt = AltchaService::issuedAt($input['altcha'] ?? null);
        if ($issuedAt !== null && $now - $issuedAt < self::MIN_SECONDS) {
            $logFn('spam', 'too_fast');
            return self::success($redirect);
        }

        // 6. ALTCHA solution valid, not expired, never used. Without a solution nothing is ever sent.
        if (!$this->altcha->verify($input['altcha'] ?? null, $siteId, $now)) {
            $logFn('spam', 'challenge_invalid');
            return self::error('challenge_invalid', 400, $locale);
        }

        // 7. Validate fields; unknown fields are dropped
        $result = $this->validator->validate($form, array_diff_key($input, array_flip(self::RESERVED)), $locale);
        if ($result['errors'] !== []) {
            $logFn('invalid', 'validation');
            return Response::json([
                'ok' => false,
                'error' => 'validation',
                'message' => Messages::get('error_validation', $locale),
                'fields' => $result['errors'],
            ], 422);
        }

        return $this->deliver($site, $form, $result['values'], $locale, $now, $redirect, $logFn);
    }

    /**
     * Steps 8–10: recipient mail, optional autoresponder, log. Also used by the dashboard test submission.
     *
     * @param \Closure(string, ?string, bool=): void $logFn
     */
    public function deliver(array $site, array $form, array $values, string $locale, int $now, ?string $redirect, \Closure $logFn): Response
    {
        $message = MailBuilder::recipientMail($site, $form, $values, $locale, $now);
        $auto = MailBuilder::autoresponder($site, $form, $values, $locale);

        // Autoresponder has its own limit: max 3 per address and day (only a hash of the address is stored).
        $autoAllowed = false;
        if ($auto !== null) {
            $bucket = 'auto:' . $this->hasher->email($auto->to[0]);
            $autoAllowed = $this->rateLimiter->check($bucket, self::AUTORESPONDER_PER_DAY, 86400, $now) === null;
        }

        if (!empty($site['dryRun'])) {
            $logFn('sent', 'dry_run', $autoAllowed);
            return self::success($redirect, [
                'dryRun' => true,
                'preview' => [
                    'mail' => $message->toArray(),
                    'autoresponder' => $autoAllowed ? $auto->toArray() : null,
                ],
            ]);
        }

        // 8. Mail to the recipient alias with CC/BCC, Reply-To = replyTo field
        try {
            $this->mailer->send($message);
        } catch (MailException $e) {
            $logFn('error', $e->reason);
            return self::error('send_failed', 502, $locale);
        }

        // 9. Optional autoresponder – a failure here never fails the submission
        $autoSent = false;
        if ($auto !== null && $autoAllowed) {
            try {
                $this->mailer->send($auto);
                $this->rateLimiter->record('auto:' . $this->hasher->email($auto->to[0]), $now);
                $autoSent = true;
            } catch (MailException) {
                $autoSent = false;
            }
        }

        // 10. Log and answer
        $logFn('sent', null, $autoSent);
        return self::success($redirect);
    }

    /** @param list<string> $origins */
    public static function originAllowed(?string $origin, array $origins): bool
    {
        if ($origin === null) {
            return false;
        }
        // An Origin header is only scheme://host[:port] – anything with a path, query or credentials is rejected.
        $parts = parse_url($origin);
        if (!is_array($parts) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $normalized = RedirectResolver::normalizeOrigin($origin);
        if ($normalized === null) {
            return false;
        }
        foreach ($origins as $allowed) {
            if (RedirectResolver::normalizeOrigin($allowed) === $normalized) {
                return true;
            }
        }
        return false;
    }

    private static function success(?string $redirect, array $extra = []): Response
    {
        $body = ['ok' => true];
        if ($redirect !== null) {
            $body['redirect'] = $redirect;
        }
        return Response::json($body + $extra);
    }

    /** @param array<string, string|int> $vars */
    public static function error(string $code, int $status, string $locale, array $vars = []): Response
    {
        return Response::json([
            'ok' => false,
            'error' => $code,
            'message' => Messages::get('error_' . $code, $locale, $vars),
        ], $status);
    }
}
