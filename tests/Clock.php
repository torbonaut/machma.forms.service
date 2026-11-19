<?php

declare(strict_types=1);

namespace Machma\Forms\Tests;

final class Clock
{
    public function __construct(public int $now)
    {
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function closure(): \Closure
    {
        return fn (): int => $this->now;
    }
}
