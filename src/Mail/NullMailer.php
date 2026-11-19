<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

/** Collects messages instead of sending them (tests). */
final class NullMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public ?MailException $failWith = null;

    public function send(MailMessage $message): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = $message;
    }
}
