<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

/** Protection against header injection in subject, names and Reply-To. */
final class HeaderSanitizer
{
    public static function line(string $value, int $maxLength = 250): string
    {
        $value = (string) preg_replace('/[\r\n\t\0\x0B\x0C]+/', ' ', $value);
        $value = (string) preg_replace('/[^\P{C}]/u', '', $value);
        $value = trim((string) preg_replace('/ {2,}/', ' ', $value));
        return mb_substr($value, 0, $maxLength);
    }

    /** Returns a safe email address or null. */
    public static function email(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || preg_match('/[\r\n\0,;<>"\s]/', $value)) {
            return null;
        }
        $email = filter_var($value, FILTER_VALIDATE_EMAIL);
        return $email === false ? null : $email;
    }
}
