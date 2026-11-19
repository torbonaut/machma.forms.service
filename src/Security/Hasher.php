<?php

declare(strict_types=1);

namespace Machma\Forms\Security;

/**
 * Non-reversible identifiers for logs and rate limits.
 * IP hashes use a daily salt derived from ip_hash_salt and the date,
 * so they cannot be linked across days.
 */
final class Hasher
{
    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
    }

    public function ip(string $ip, int $now): string
    {
        $daySalt = hash_hmac('sha256', gmdate('Y-m-d', $now), $this->secret);
        return substr(hash_hmac('sha256', $ip, $daySalt), 0, 24);
    }

    /** Hash of an email address (autoresponder limit, login limit) – stable across days. */
    public function email(string $email): string
    {
        return substr(hash_hmac('sha256', 'email:' . strtolower(trim($email)), $this->secret), 0, 32);
    }
}
