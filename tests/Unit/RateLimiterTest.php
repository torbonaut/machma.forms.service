<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Storage\Database;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testSlidingWindow(): void
    {
        $limiter = new RateLimiter(new Database(':memory:'));
        $t = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($limiter->hit('b', 5, 600, $t + $i * 10));
        }
        self::assertSame(550, $limiter->hit('b', 5, 600, $t + 50)); // first hit leaves at t+600
        self::assertNull($limiter->hit('other', 5, 600, $t + 50));
        self::assertNull($limiter->hit('b', 5, 600, $t + 601));
    }

    public function testCheckDoesNotCount(): void
    {
        $limiter = new RateLimiter(new Database(':memory:'));
        for ($i = 0; $i < 10; $i++) {
            self::assertNull($limiter->check('c', 1, 60, 100));
        }
    }

    public function testIpHashRotatesDaily(): void
    {
        $h = new Hasher('salt');
        $day = 1_800_000_000;
        self::assertSame($h->ip('203.0.113.9', $day), $h->ip('203.0.113.9', $day + 60));
        self::assertNotSame($h->ip('203.0.113.9', $day), $h->ip('203.0.113.9', $day + 86400));
        self::assertStringNotContainsString('203', $h->ip('203.0.113.9', $day));
    }
}
