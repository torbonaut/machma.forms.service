<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

final class MailMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param array<string, string> $headers extra headers
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $fromName,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fromName' => $this->fromName,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'replyTo' => $this->replyTo,
            'subject' => $this->subject,
            'body' => $this->body,
        ];
    }
}
