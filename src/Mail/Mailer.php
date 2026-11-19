<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

interface Mailer
{
    /** @throws MailException */
    public function send(MailMessage $message): void;
}
