<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

final class MailException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'smtp_error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
