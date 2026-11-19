<?php

declare(strict_types=1);

namespace Machma\Forms;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Machma\Forms\Admin\AdminController;
use Machma\Forms\Admin\Auth;
use Machma\Forms\Admin\View;
use Machma\Forms\Api\ApiController;
use Machma\Forms\Config\Config;
use Machma\Forms\Form\SubmissionValidator;
use Machma\Forms\Http\Request;
use Machma\Forms\Http\Response;
use Machma\Forms\Mail\Mailer;
use Machma\Forms\Mail\SmtpMailer;
use Machma\Forms\Security\AltchaService;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Storage\Database;
use Machma\Forms\Submission\SubmissionHandler;
use Machma\Forms\Submission\SubmissionLog;

use function FastRoute\simpleDispatcher;

/** Wires services together and dispatches requests (FastRoute + small middleware). */
final class App
{
    public readonly SubmissionHandler $submissions;
    public readonly ApiController $api;
    public readonly AdminController $admin;
    private readonly Dispatcher $dispatcher;

    /** @param array<string, mixed> $secrets */
    public function __construct(
        public readonly Config $config,
        public readonly array $secrets,
        public readonly Database $db,
        public readonly Mailer $mailer,
        ?\Closure $clock = null,
        int $altchaMaxNumber = AltchaService::MAX_NUMBER,
    ) {
        $clock ??= static fn (): int => time();
        $hasher = new Hasher((string) $secrets['ip_hash_salt']);
        $rateLimiter = new RateLimiter($db);
        $altcha = new AltchaService((string) $secrets['altcha_hmac_key'], $db, $altchaMaxNumber);
        $log = new SubmissionLog($db);

        $this->submissions = new SubmissionHandler($config, $rateLimiter, $altcha, $hasher, new SubmissionValidator(), $mailer, $log, $clock);
        $this->api = new ApiController($config, $this->submissions, $altcha, $rateLimiter, $hasher, $db, $clock);
        $auth = new Auth($db, $config, $rateLimiter, $hasher, (string) $secrets['session_key'], $clock);
        $this->admin = new AdminController(
            $config, $auth, $log, $mailer, $this->submissions, $hasher, $rateLimiter,
            new View(dirname(__DIR__) . '/templates'), (string) ($secrets['base_url'] ?? ''), $secrets, $clock,
        );

        $this->dispatcher = simpleDispatcher(function (RouteCollector $r): void {
            $r->get('/health', [$this->api, 'health']);
            $r->get('/v1/challenge', [$this->api, 'challenge']);
            $r->addRoute('OPTIONS', '/v1/challenge', [$this->api, 'preflight']);
            $r->get('/v1/{site:[a-z0-9_-]+}/{form:[a-z0-9_-]+}/spec', [$this->api, 'spec']);
            $r->addRoute('OPTIONS', '/v1/{site:[a-z0-9_-]+}/{form:[a-z0-9_-]+}/spec', [$this->api, 'preflight']);
            $r->post('/v1/{site:[a-z0-9_-]+}/{form:[a-z0-9_-]+}', [$this->api, 'submit']);
            $r->addRoute('OPTIONS', '/v1/{site:[a-z0-9_-]+}/{form:[a-z0-9_-]+}', [$this->api, 'preflight']);

            $r->addRoute(['GET', 'POST'], '/admin/login', [$this->admin, 'login']);
            $r->post('/admin/logout', [$this->admin, 'logout']);
            $r->addRoute(['GET', 'POST'], '/admin/invite/{token:[a-f0-9]{64}}', [$this->admin, 'invite']);
            $r->addRoute(['GET', 'POST'], '/admin/reset', [$this->admin, 'reset']);
            $r->get('/admin', [$this->admin, 'overview']);
            $r->get('/admin/sites/{site:[a-z0-9_-]+}', [$this->admin, 'site']);
            $r->post('/admin/sites/{site:[a-z0-9_-]+}/test', [$this->admin, 'testSubmission']);
            $r->get('/admin/users', [$this->admin, 'users']);
            $r->post('/admin/users/invite', [$this->admin, 'sendInvite']);
            $r->post('/admin/smtp-test', [$this->admin, 'smtpTest']);
            $r->get('/admin/errors', [$this->admin, 'errors']);
        });
    }

    public static function fromFiles(string $root): self
    {
        $secretsFile = $root . '/secrets.php';
        if (!is_file($secretsFile)) {
            throw new \RuntimeException('secrets.php fehlt (siehe secrets.example.php)');
        }
        $secrets = require $secretsFile;
        return new self(
            Config::load($root . '/config/compiled.php'),
            $secrets,
            new Database($root . '/var/forms.sqlite'),
            new SmtpMailer($secrets['smtp']),
        );
    }

    public function handle(Request $request): Response
    {
        $path = $request->path !== '/' ? rtrim($request->path, '/') : '/';
        $info = $this->dispatcher->dispatch($request->method, $path);

        try {
            $response = match ($info[0]) {
                Dispatcher::FOUND => ($info[1])($request, $info[2]),
                Dispatcher::METHOD_NOT_ALLOWED => Response::json(['ok' => false, 'error' => 'method_not_allowed'], 405)
                    ->withHeader('Allow', implode(', ', $info[1])),
                default => Response::json(['ok' => false, 'error' => 'not_found'], 404),
            };
        } catch (\Throwable $e) {
            error_log('[machma-forms] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::json(['ok' => false, 'error' => 'server_error'], 500);
        }

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        if (str_starts_with($path, '/admin')) {
            // Dashboard: strict CSP, no framing, no external scripts
            $response->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Referrer-Policy', 'same-origin')
                ->withHeader('Cache-Control', 'no-store');
        }
        return $response;
    }
}
