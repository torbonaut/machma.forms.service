<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use Machma\Forms\Security\RedirectResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectResolverTest extends TestCase
{
    private const ORIGINS = ['https://www.beispielhotel.example', 'http://localhost:4321'];
    private const DEFAULT = ['de' => '/danke', 'en' => '/en/thank-you'];

    public static function allowed(): array
    {
        return [
            ['/danke-zimmer'], ['/'], ['/en/thank-you?x=1#top'],
            ['https://www.beispielhotel.example/danke'], ['https://WWW.beispielhotel.example:443/danke'], ['http://localhost:4321/ok'],
        ];
    }

    public static function rejected(): array
    {
        return [
            ['//evil.com/phish'], ['/\\evil.com'], ['https://evil.com/danke'], ['https://www.beispielhotel.example.evil.com/'],
            ['javascript:alert(1)'], ['data:text/html,x'], ['https://user@www.beispielhotel.example/'], ['http://www.beispielhotel.example/'],
            ['https:www.beispielhotel.example'], ['danke'], ["/danke\nLocation: x"], ['http://localhost:8080/'], [' /danke'],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $target): void
    {
        self::assertSame($target, RedirectResolver::resolve($target, self::DEFAULT, 'de', self::ORIGINS));
    }

    #[DataProvider('rejected')]
    public function testRejectedFallsBackSilently(string $target): void
    {
        self::assertSame('/danke', RedirectResolver::resolve($target, self::DEFAULT, 'de', self::ORIGINS));
    }

    public function testDefaultPerLocale(): void
    {
        self::assertSame('/en/thank-you', RedirectResolver::resolve(null, self::DEFAULT, 'en', self::ORIGINS));
        self::assertSame('/danke', RedirectResolver::resolve(null, self::DEFAULT, 'fr', self::ORIGINS));
        self::assertNull(RedirectResolver::resolve(null, null, 'de', self::ORIGINS));
        self::assertNull(RedirectResolver::resolve(['array'], null, 'de', self::ORIGINS));
    }
}
