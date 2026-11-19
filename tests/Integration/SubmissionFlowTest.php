<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Integration;

use Machma\Forms\Http\Request;
use Machma\Forms\Mail\MailException;
use Machma\Forms\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class SubmissionFlowTest extends TestCase
{
    private function logs($app): array
    {
        return $app->db->all('SELECT status, reason, autoresponder FROM logs ORDER BY id');
    }

    public function testSuccessfulSubmission(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $altcha = Fixtures::solve($app);
        $clock->advance(5);
        $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha, 'unknown' => 'x']));

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['ok' => true, 'redirect' => '/danke'], json_decode($res->body, true));
        self::assertSame(Fixtures::ORIGIN, $res->headers['Access-Control-Allow-Origin']);
        self::assertCount(2, $mailer->sent);

        $mail = $mailer->sent[0];
        self::assertSame(['rezeption@beispielhotel.example'], $mail->to);
        self::assertSame(['chef@beispielhotel.example'], $mail->cc);
        self::assertSame(['archiv@beispielhotel.example'], $mail->bcc);
        self::assertSame('erika@example.com', $mail->replyTo);
        self::assertSame('Neue Anfrage: Zimmeranfrage von Erika Muster', $mail->subject);
        self::assertStringContainsString("Anreise: 01.07.2027\nPersonen: 2", $mail->body);
        self::assertSame('Hotel Seeblick via machma forms', $mail->fromName);

        self::assertSame(['erika@example.com'], $mailer->sent[1]->to);
        self::assertSame([['status' => 'sent', 'reason' => null, 'autoresponder' => 1]], $this->logs($app));
        // nothing but metadata is stored
        $row = $app->db->one('SELECT * FROM logs');
        self::assertStringNotContainsString('erika', strtolower(json_encode($row)));
        self::assertStringNotContainsString('203.0.113', json_encode($row));
    }

    public function testUnknownSiteOrForm(): void
    {
        ['app' => $app] = Fixtures::app();
        self::assertSame(404, Fixtures::post($app, [], '/v1/nope/kontakt')->status);
        self::assertSame(404, Fixtures::post($app, [], '/v1/beispielhotel/nope')->status);
    }

    public function testForeignOrMissingOrigin(): void
    {
        ['app' => $app] = Fixtures::app();
        $res = Fixtures::post($app, Fixtures::validInput(), origin: 'https://evil.example');
        self::assertSame(403, $res->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $res->headers);
        self::assertSame(403, Fixtures::post($app, Fixtures::validInput(), origin: null)->status);
        self::assertSame(403, Fixtures::post($app, Fixtures::validInput(), origin: Fixtures::ORIGIN . '/path')->status);
    }

    public function testBodyTypeAndSize(): void
    {
        ['app' => $app] = Fixtures::app();
        $res = $app->handle(new Request('POST', '/v1/beispielhotel/kontakt', [], ['origin' => Fixtures::ORIGIN, 'content-type' => 'application/x-www-form-urlencoded'], 'a=b'));
        self::assertSame(415, $res->status);
        self::assertSame(413, Fixtures::post($app, [], raw: str_repeat('x', 33000))->status);
        self::assertSame(400, Fixtures::post($app, [], raw: '[1,2]')->status);
        self::assertSame(400, Fixtures::post($app, [], raw: '{broken')->status);
    }

    public function testRateLimit(): void
    {
        ['app' => $app, 'clock' => $clock] = Fixtures::app();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(400, Fixtures::post($app, Fixtures::validInput())->status); // no ALTCHA → 400
        }
        $res = Fixtures::post($app, Fixtures::validInput());
        self::assertSame(429, $res->status);
        self::assertSame('600', $res->headers['Retry-After']);
        self::assertSame('Zu viele Anfragen. Bitte in 10 Minuten erneut versuchen.', json_decode($res->body, true)['message']);
        self::assertSame(400, Fixtures::post($app, Fixtures::validInput(), ip: '198.51.100.1')->status, 'other IP');
        $clock->advance(601);
        self::assertSame(400, Fixtures::post($app, Fixtures::validInput())->status);
    }

    public function testHoneypotIsSilentlyAccepted(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $altcha = Fixtures::solve($app);
        $clock->advance(5);
        $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha, '_website' => 'http://spam.example']));
        self::assertSame(200, $res->status);
        self::assertTrue(json_decode($res->body, true)['ok']);
        self::assertCount(0, $mailer->sent);
        self::assertSame('honeypot', $this->logs($app)[0]['reason']);
    }

    public function testTooFastIsSilentlyAccepted(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $altcha = Fixtures::solve($app);
        $clock->advance(2);
        self::assertSame(200, Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]))->status);
        self::assertCount(0, $mailer->sent);
        self::assertSame(['status' => 'spam', 'reason' => 'too_fast', 'autoresponder' => 0], $this->logs($app)[0]);
    }

    public function testChallengeRequiredAndSingleUse(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $res = Fixtures::post($app, Fixtures::validInput());
        self::assertSame(400, $res->status);
        self::assertSame('challenge_invalid', json_decode($res->body, true)['error']);

        $altcha = Fixtures::solve($app);
        $clock->advance(5);
        self::assertSame(200, Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]))->status);
        self::assertSame(400, Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]))->status, 'replay');

        $demo = Fixtures::solve($app, 'demo');
        $clock->advance(5);
        self::assertSame(400, Fixtures::post($app, Fixtures::validInput(['altcha' => $demo]))->status, 'other site');
        self::assertCount(2, $mailer->sent);
    }

    public function testValidationErrorsPerFieldAndLanguage(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $altcha = Fixtures::solve($app);
        $clock->advance(5);
        $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha, 'email' => 'nope', 'locale' => 'en', 'datenschutz' => false]));
        self::assertSame(422, $res->status);
        $json = json_decode($res->body, true);
        self::assertSame('validation', $json['error']);
        self::assertSame(['email' => 'Please enter a valid email address.', 'datenschutz' => 'Please confirm.'], $json['fields']);
        self::assertCount(0, $mailer->sent);
    }

    public function testRedirectOverride(): void
    {
        ['app' => $app, 'clock' => $clock] = Fixtures::app();
        foreach (['/danke-zimmer' => '/danke-zimmer', 'https://evil.example/x' => '/en/thank-you'] as $target => $expected) {
            $altcha = Fixtures::solve($app);
            $clock->advance(5);
            $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha, '_redirect' => $target, 'locale' => 'en']), ip: uniqid());
            self::assertSame($expected, json_decode($res->body, true)['redirect']);
        }
    }

    public function testSmtpFailure(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $mailer->failWith = new MailException('timeout', 'smtp_connect');
        $altcha = Fixtures::solve($app);
        $clock->advance(5);
        $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]));
        self::assertSame(502, $res->status);
        self::assertSame(['status' => 'error', 'reason' => 'smtp_connect', 'autoresponder' => 0], $this->logs($app)[0]);
    }

    public function testAutoresponderLimitPerAddress(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        for ($i = 0; $i < 4; $i++) {
            $altcha = Fixtures::solve($app);
            $clock->advance(5);
            self::assertSame(200, Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]), ip: "10.0.0.$i")->status);
        }
        $toSender = array_filter($mailer->sent, static fn ($m) => $m->to === ['erika@example.com']);
        self::assertCount(3, $toSender);
        self::assertCount(7, $mailer->sent);
    }

    public function testDryRunSendsNothingButShowsPreview(): void
    {
        ['app' => $app, 'mailer' => $mailer, 'clock' => $clock] = Fixtures::app();
        $altcha = Fixtures::solve($app, 'demo');
        $clock->advance(5);
        $res = Fixtures::post($app, Fixtures::validInput(['altcha' => $altcha]), '/v1/demo/kontakt');
        self::assertSame(200, $res->status, $res->body);
        $json = json_decode($res->body, true);
        self::assertTrue($json['dryRun']);
        self::assertSame('Neue Anfrage: Zimmeranfrage von Erika Muster', $json['preview']['mail']['subject']);
        self::assertSame('Danke für Ihre Anfrage', $json['preview']['autoresponder']['subject']);
        self::assertCount(0, $mailer->sent);
        self::assertSame('dry_run', $this->logs($app)[0]['reason']);
    }

    public function testSpecHidesRecipientAndTemplates(): void
    {
        ['app' => $app] = Fixtures::app();
        $res = $app->handle(new Request('GET', '/v1/beispielhotel/kontakt/spec', [], ['origin' => Fixtures::ORIGIN]));
        self::assertSame(200, $res->status);
        $spec = json_decode($res->body, true);
        self::assertArrayNotHasKey('recipient', $spec);
        self::assertArrayNotHasKey('mail', $spec);
        self::assertArrayNotHasKey('autoresponder', $spec);
        self::assertStringNotContainsString('beispielhotel.example"', $res->body);
        self::assertSame(['de', 'en'], $spec['locales']);
        self::assertCount(8, $spec['fields']);
        self::assertSame(Fixtures::ORIGIN, $res->headers['Access-Control-Allow-Origin']);

        $foreign = $app->handle(new Request('GET', '/v1/beispielhotel/kontakt/spec', [], ['origin' => 'https://evil.example']));
        self::assertSame(403, $foreign->status);
        $challenge = $app->handle(new Request('GET', '/v1/challenge', ['site' => 'beispielhotel'], ['origin' => 'https://evil.example']));
        self::assertSame(403, $challenge->status);
    }

    public function testPreflight(): void
    {
        ['app' => $app] = Fixtures::app();
        $ok = $app->handle(new Request('OPTIONS', '/v1/beispielhotel/kontakt', [], ['origin' => Fixtures::ORIGIN]));
        self::assertSame(204, $ok->status);
        self::assertSame(Fixtures::ORIGIN, $ok->headers['Access-Control-Allow-Origin']);
        self::assertSame('Content-Type', $ok->headers['Access-Control-Allow-Headers']);
        $bad = $app->handle(new Request('OPTIONS', '/v1/beispielhotel/kontakt', [], ['origin' => 'https://evil.example']));
        self::assertSame(403, $bad->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $bad->headers);
    }

    public function testHealth(): void
    {
        ['app' => $app] = Fixtures::app();
        $res = $app->handle(new Request('GET', '/health'));
        self::assertSame(200, $res->status);
        self::assertTrue(json_decode($res->body, true)['ok']);
    }
}
