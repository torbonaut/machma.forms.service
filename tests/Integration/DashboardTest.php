<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Integration;

use Machma\Forms\App;
use Machma\Forms\Config\Config;
use Machma\Forms\Http\Request;
use Machma\Forms\Http\Response;
use Machma\Forms\Mail\NullMailer;
use Machma\Forms\Storage\Database;
use Machma\Forms\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private const PASSWORD = 'sehr-geheimes-passwort';

    private static function cookies(Response $res): array
    {
        $out = [];
        foreach ($res->cookies as $c) {
            [$pair] = explode(';', $c);
            [$k, $v] = explode('=', $pair, 2);
            $out[rawurldecode($k)] = rawurldecode($v);
        }
        return $out;
    }

    private static function csrfFrom(Response $res): string
    {
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $res->body, $m);
        return $m[1];
    }

    /** Runs invite → set password → login and returns the session cookie. */
    private function loginAs(App $app, NullMailer $mailer, string $email): array
    {
        $app->admin; // ensure constructed
        $auth = (new \ReflectionProperty($app->admin, 'auth'))->getValue($app->admin);
        $token = $auth->createToken($email, 'invite');

        $form = $app->handle(new Request('GET', "/admin/invite/$token"));
        self::assertSame(200, $form->status);
        $csrfCookie = self::cookies($form)['mf_csrf'];
        $res = $app->handle(new Request('POST', "/admin/invite/$token", post: [
            '_csrf' => self::csrfFrom($form), 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
        ], cookies: ['mf_csrf' => $csrfCookie]));
        self::assertSame(303, $res->status);
        self::assertSame('/admin/login?msg=password_set', $res->headers['Location']);

        $res = $app->handle(new Request('POST', '/admin/login', post: [
            '_csrf' => $csrfCookie, 'email' => $email, 'password' => self::PASSWORD,
        ], cookies: ['mf_csrf' => $csrfCookie]));
        self::assertSame(303, $res->status, $res->body);
        return ['mf_session' => self::cookies($res)['mf_session']];
    }

    public function testInviteLoginAndRoles(): void
    {
        ['app' => $app, 'mailer' => $mailer] = Fixtures::app();
        $session = $this->loginAs($app, $mailer, 'office@beispielhotel.example');

        $overview = $app->handle(new Request('GET', '/admin', cookies: $session));
        self::assertSame(200, $overview->status);
        self::assertStringContainsString('beispielhotel', $overview->body);
        self::assertStringNotContainsString('/admin/sites/demo', $overview->body, 'client sees only own sites');
        self::assertStringContainsString("frame-ancestors 'none'", $overview->headers['Content-Security-Policy']);
        self::assertSame('DENY', $overview->headers['X-Frame-Options']);

        self::assertSame(404, $app->handle(new Request('GET', '/admin/sites/demo', cookies: $session))->status);
        self::assertSame(200, $app->handle(new Request('GET', '/admin/sites/beispielhotel', query: ['days' => '90', 'status' => 'sent'], cookies: $session))->status);
        self::assertSame(303, $app->handle(new Request('GET', '/admin/users', cookies: $session))->status, 'admin only');
    }

    public function testInviteLinkIsSingleUse(): void
    {
        ['app' => $app] = Fixtures::app();
        $auth = (new \ReflectionProperty($app->admin, 'auth'))->getValue($app->admin);
        $token = $auth->createToken('office@beispielhotel.example', 'invite');
        self::assertNotNull($auth->redeemToken($token, self::PASSWORD));
        self::assertNull($auth->redeemToken($token, self::PASSWORD));
        self::assertSame(410, $app->handle(new Request('GET', "/admin/invite/$token"))->status);
    }

    public function testLoginRateLimit(): void
    {
        ['app' => $app] = Fixtures::app();
        $auth = (new \ReflectionProperty($app->admin, 'auth'))->getValue($app->admin);
        $auth->redeemToken($auth->createToken('torben@machma.app', 'invite'), self::PASSWORD);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('invalid', $auth->login('torben@machma.app', 'falsch', '1.2.3.4')['error']);
        }
        self::assertSame('rate_limited', $auth->login('torben@machma.app', self::PASSWORD, '1.2.3.4')['error']);
    }

    public function testMissingCsrfIsRejected(): void
    {
        ['app' => $app, 'mailer' => $mailer] = Fixtures::app();
        $session = $this->loginAs($app, $mailer, 'torben@machma.app');
        $res = $app->handle(new Request('POST', '/admin/smtp-test', cookies: $session));
        self::assertSame('/admin/users?msg=csrf', $res->headers['Location']);
        self::assertCount(0, $mailer->sent);

        $page = $app->handle(new Request('GET', '/admin/users', cookies: $session));
        $res = $app->handle(new Request('POST', '/admin/smtp-test', post: ['_csrf' => self::csrfFrom($page)], cookies: $session));
        self::assertSame('/admin/users?msg=smtp_ok', $res->headers['Location']);
        self::assertSame(['torben@machma.app'], $mailer->sent[0]->to);
    }

    public function testRemovedUserLosesAccess(): void
    {
        ['app' => $app, 'mailer' => $mailer] = Fixtures::app();
        $session = $this->loginAs($app, $mailer, 'office@beispielhotel.example');

        // Same database, new config without the user (= next deploy)
        $config = new Config(['sites' => Fixtures::config()->sites(), 'users' => ['torben@machma.app' => ['role' => 'admin', 'sites' => []]]]);
        $app2 = new App($config, Fixtures::SECRETS, $app->db, new NullMailer());
        self::assertSame(303, $app2->handle(new Request('GET', '/admin', cookies: $session))->status);
    }

    public function testResetAlwaysSameAnswer(): void
    {
        ['app' => $app, 'mailer' => $mailer] = Fixtures::app();
        $bodies = [];
        foreach (['office@beispielhotel.example', 'unknown@example.com'] as $email) {
            $form = $app->handle(new Request('GET', '/admin/reset'));
            $csrf = self::cookies($form)['mf_csrf'];
            $res = $app->handle(new Request('POST', '/admin/reset', post: ['_csrf' => $csrf, 'email' => $email], cookies: ['mf_csrf' => $csrf]));
            $bodies[] = preg_replace('/[a-f0-9]{64}/', '', $res->body);
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertCount(1, $mailer->sent);
        self::assertSame(['office@beispielhotel.example'], $mailer->sent[0]->to);
        self::assertMatchesRegularExpression('~https://forms\.example\.test/admin/invite/[a-f0-9]{64}~', $mailer->sent[0]->body);
    }

    public function testTestSubmissionRespectsDryRun(): void
    {
        ['app' => $app, 'mailer' => $mailer] = Fixtures::app();
        $session = $this->loginAs($app, $mailer, 'torben@machma.app');
        $page = $app->handle(new Request('GET', '/admin/sites/demo', cookies: $session));
        $csrf = self::csrfFrom($page);
        $res = $app->handle(new Request('POST', '/admin/sites/demo/test', post: ['_csrf' => $csrf, 'form' => 'kontakt'], cookies: $session));
        self::assertSame('/admin/sites/demo?msg=test_dry', $res->headers['Location']);
        self::assertCount(0, $mailer->sent);

        $res = $app->handle(new Request('POST', '/admin/sites/beispielhotel/test', post: ['_csrf' => $csrf, 'form' => 'kontakt'], cookies: $session));
        self::assertSame('/admin/sites/beispielhotel?msg=test_sent', $res->headers['Location']);
        self::assertSame(['rezeption@beispielhotel.example'], $mailer->sent[0]->to);
        self::assertSame(['torben@machma.app'], $mailer->sent[1]->to, 'autoresponder to the admin');
    }
}
