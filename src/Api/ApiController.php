<?php

declare(strict_types=1);

namespace Machma\Forms\Api;

use Machma\Forms\Config\Config;
use Machma\Forms\Http\Request;
use Machma\Forms\Http\Response;
use Machma\Forms\Security\AltchaService;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Storage\Database;
use Machma\Forms\Submission\SubmissionHandler;

final class ApiController
{
    public function __construct(
        private readonly Config $config,
        private readonly SubmissionHandler $submissions,
        private readonly AltchaService $altcha,
        private readonly RateLimiter $rateLimiter,
        private readonly Hasher $hasher,
        private readonly Database $db,
        private readonly \Closure $clock,
    ) {
    }

    /** GET /v1/{site}/{form}/spec – public form JSON without recipient, mail and autoresponder. */
    public function spec(Request $request, array $vars): Response
    {
        $site = $this->config->site($vars['site']);
        $form = $this->config->form($vars['site'], $vars['form']);
        if ($site === null || $form === null) {
            return SubmissionHandler::error('not_found', 404, 'de');
        }
        if ($deny = $this->denyForeignOrigin($request, $site)) {
            return $deny;
        }
        unset($form['recipient'], $form['mail'], $form['autoresponder']);
        $public = ['site' => $site['id']] + $form + [
            'locales' => $site['locales'],
            'honeypot' => SubmissionHandler::HONEYPOT,
            'dryRun' => $site['dryRun'],
        ];
        return $this->cors(Response::json($public), $request, $site)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /** GET /v1/challenge?site={site} – HMAC-signed ALTCHA task, valid for 10 minutes. */
    public function challenge(Request $request): Response
    {
        $siteId = $request->queryString('site');
        $site = $this->config->site($siteId);
        if ($site === null) {
            return SubmissionHandler::error('not_found', 404, 'de');
        }
        if ($deny = $this->denyForeignOrigin($request, $site)) {
            return $deny;
        }
        $now = ($this->clock)();
        $ipHash = $this->hasher->ip($request->ip, $now);
        $perIp = max(30, (int) $site['rateLimit']['perIp'] * 10);
        $retry = $this->rateLimiter->hit("challenge:$siteId:$ipHash", $perIp, (int) $site['rateLimit']['windowMinutes'] * 60, $now);
        if ($retry !== null) {
            return $this->cors(SubmissionHandler::error('rate_limited', 429, $site['locales'][0], ['minutes' => (int) ceil($retry / 60)]), $request, $site)
                ->withHeader('Retry-After', (string) $retry);
        }
        return $this->cors(Response::json($this->altcha->create($siteId, $now)), $request, $site);
    }

    /** POST /v1/{site}/{form} */
    public function submit(Request $request, array $vars): Response
    {
        $response = $this->submissions->handle($vars['site'], $vars['form'], $request);
        $site = $this->config->site($vars['site']);
        return $site !== null ? $this->cors($response, $request, $site) : $response;
    }

    /** OPTIONS – CORS preflight, only for the site's origins. */
    public function preflight(Request $request, array $vars): Response
    {
        $siteId = $vars['site'] ?? $request->queryString('site');
        $site = $this->config->site($siteId);
        if ($site === null || !SubmissionHandler::originAllowed($request->origin(), $site['origins'])) {
            return new Response(403, '', ['Vary' => 'Origin']);
        }
        return $this->cors(new Response(204), $request, $site)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    /** GET /health */
    public function health(Request $request): Response
    {
        try {
            $this->db->one('SELECT 1 AS ok');
            $db = true;
        } catch (\Throwable) {
            $db = false;
        }
        $ok = $db && $this->config->generatedAt() !== null;
        return Response::json([
            'ok' => $ok,
            'db' => $db,
            'config' => $this->config->generatedAt(),
            'sites' => count($this->config->sites()),
        ], $ok ? 200 : 503);
    }

    private function cors(Response $response, Request $request, array $site): Response
    {
        $response->withHeader('Vary', 'Origin');
        $origin = $request->origin();
        if ($origin !== null && SubmissionHandler::originAllowed($origin, $site['origins'])) {
            $response->withHeader('Access-Control-Allow-Origin', $origin);
        }
        return $response;
    }

    /** GET requests from a foreign origin are refused; same-origin GETs carry no Origin header. */
    private function denyForeignOrigin(Request $request, array $site): ?Response
    {
        $origin = $request->origin();
        if ($origin !== null && !SubmissionHandler::originAllowed($origin, $site['origins'])) {
            return SubmissionHandler::error('forbidden', 403, $site['locales'][0] ?? 'de')->withHeader('Vary', 'Origin');
        }
        return null;
    }
}
