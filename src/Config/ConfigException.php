<?php

declare(strict_types=1);

namespace Machma\Forms\Config;

final class ConfigException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
