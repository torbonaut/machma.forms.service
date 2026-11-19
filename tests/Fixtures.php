<?php

declare(strict_types=1);

namespace Machma\Forms\Tests;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Hasher\Algorithm;
use Machma\Forms\App;
use Machma\Forms\Config\Config;
use Machma\Forms\Http\Request;
use Machma\Forms\Mail\NullMailer;
use Machma\Forms\Storage\Database;

/** Shared test config: one site with a contact form, plus a dryRun site. */
final class Fixtures
{
    public const ORIGIN = 'https://www.beispielhotel.example';
    public const SECRETS = [
        'altcha_hmac_key' => 'test-altcha',
        'session_key' => 'test-session',
        'ip_hash_salt' => 'test-salt',
        'base_url' => 'https://forms.example.test',
        'smtp' => ['host' => 'localhost', 'port' => 1025, 'from' => 'formulare@machma.app'],
    ];

    public static function form(): array
    {
        return [
            'id' => 'kontakt',
            'version' => 1,
            'recipient' => 'rezeption',
            'redirect' => ['de' => '/danke', 'en' => '/en/thank-you'],
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true, 'maxLength' => 100, 'label' => ['de' => 'Name', 'en' => 'Name']],
                ['name' => 'email', 'type' => 'email', 'required' => true, 'replyTo' => true, 'maxLength' => 500, 'label' => ['de' => 'E-Mail', 'en' => 'Email']],
                ['name' => 'anreise', 'type' => 'date', 'label' => ['de' => 'Anreise', 'en' => 'Arrival']],
                ['name' => 'personen', 'type' => 'number', 'min' => 1, 'max' => 12, 'label' => ['de' => 'Personen', 'en' => 'Guests']],
                ['name' => 'thema', 'type' => 'select', 'required' => true, 'label' => ['de' => 'Thema', 'en' => 'Topic'], 'options' => [
                    ['value' => 'zimmer', 'label' => ['de' => 'Zimmeranfrage', 'en' => 'Room request']],
                    ['value' => 'sonstiges', 'label' => ['de' => 'Sonstiges', 'en' => 'Other']],
                ]],
                ['name' => 'extras', 'type' => 'checkbox', 'label' => ['de' => 'Extras', 'en' => 'Extras'], 'options' => [
                    ['value' => 'parkplatz', 'label' => ['de' => 'Parkplatz', 'en' => 'Parking']],
                    ['value' => 'hund', 'label' => ['de' => 'Hund', 'en' => 'Dog']],
                ]],
                ['name' => 'nachricht', 'type' => 'textarea', 'required' => true, 'maxLength' => 5000, 'label' => ['de' => 'Nachricht', 'en' => 'Message']],
                ['name' => 'datenschutz', 'type' => 'consent', 'required' => true, 'label' => ['de' => 'Datenschutz', 'en' => 'Privacy']],
            ],
            'mail' => [
                'subject' => ['de' => 'Neue Anfrage: {{thema}} von {{name}}'],
                'body' => ['de' => "Name: {{name}}\nE-Mail: {{email}}\nAnreise: {{anreise}}\nPersonen: {{personen}}\n\n{{nachricht}}"],
            ],
            'autoresponder' => [
                'enabled' => true,
                'subject' => ['de' => 'Danke für Ihre Anfrage', 'en' => 'Thank you'],
                'body' => ['de' => 'Wir melden uns.', 'en' => 'We will get back to you.'],
            ],
        ];
    }

    public static function site(string $id = 'beispielhotel', bool $dryRun = false): array
    {
        return [
            'id' => $id,
            'origins' => [self::ORIGIN, 'https://vorschau.beispielhotel.example'],
            'fromName' => 'Hotel Seeblick via machma forms',
            'recipients' => ['rezeption' => ['to' => ['rezeption@beispielhotel.example'], 'cc' => ['chef@beispielhotel.example'], 'bcc' => ['archiv@beispielhotel.example']]],
            'rateLimit' => ['perIp' => 5, 'windowMinutes' => 10],
            'locales' => ['de', 'en'],
            'dryRun' => $dryRun,
            'forms' => ['kontakt' => self::form()],
        ];
    }

    public static function config(): Config
    {
        return new Config([
            'generatedAt' => '2026-11-19T20:00:00+00:00',
            'sites' => ['beispielhotel' => self::site(), 'demo' => self::site('demo', true)],
            'users' => [
                'torben@machma.app' => ['role' => 'admin', 'sites' => []],
                'office@beispielhotel.example' => ['role' => 'client', 'sites' => ['beispielhotel']],
            ],
        ]);
    }

    /** @return array{app: App, mailer: NullMailer, clock: Clock} */
    public static function app(?Config $config = null): array
    {
        $clock = new Clock(time()); // real time: the ALTCHA library also checks expiry against time()
        $mailer = new NullMailer();
        $app = new App($config ?? self::config(), self::SECRETS, new Database(':memory:'), $mailer, $clock->closure(), 2000);
        return ['app' => $app, 'mailer' => $mailer, 'clock' => $clock];
    }

    public static function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Erika Muster',
            'email' => 'erika@example.com',
            'anreise' => '2027-07-01',
            'personen' => '2',
            'thema' => 'zimmer',
            'extras' => ['parkplatz'],
            'nachricht' => "Hallo,\nhaben Sie ein Zimmer frei?",
            'datenschutz' => true,
            'locale' => 'de',
            '_website' => '',
        ], $overrides);
    }

    /** Fetches and solves a challenge via the API (like the embed script). */
    public static function solve(App $app, string $site = 'beispielhotel'): string
    {
        $res = $app->handle(new Request('GET', '/v1/challenge', ['site' => $site], ['origin' => self::ORIGIN], ip: '203.0.113.9'));
        $ch = json_decode($res->body, true);
        $solution = (new Altcha('unused'))->solveChallenge($ch['challenge'], $ch['salt'], Algorithm::SHA256, $ch['maxnumber']);
        return base64_encode(json_encode([
            'algorithm' => $ch['algorithm'], 'challenge' => $ch['challenge'], 'number' => $solution->number,
            'salt' => $ch['salt'], 'signature' => $ch['signature'],
        ]));
    }

    public static function post(App $app, array $body, string $path = '/v1/beispielhotel/kontakt', ?string $origin = self::ORIGIN, string $ip = '203.0.113.9', ?string $raw = null): \Machma\Forms\Http\Response
    {
        $headers = ['content-type' => 'application/json'];
        if ($origin !== null) {
            $headers['origin'] = $origin;
        }
        return $app->handle(new Request('POST', $path, [], $headers, $raw ?? json_encode($body), $ip));
    }
}
