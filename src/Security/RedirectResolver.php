<?php

declare(strict_types=1);

namespace Machma\Forms\Security;

use Machma\Forms\Form\Messages;

/**
 * Decides the redirect target after a successful submission.
 * An override (_redirect) is only accepted if it is a relative path or points
 * exactly at one of the site's origins – otherwise the default is used silently.
 */
final class RedirectResolver
{
    /**
     * @param array<string, string>|null $formRedirect redirect per locale from the form JSON
     * @param list<string> $origins
     */
    public static function resolve(mixed $override, ?array $formRedirect, string $locale, array $origins): ?string
    {
        if (is_string($override) && $override !== '' && self::isAllowed($override, $origins)) {
            return $override;
        }
        $default = Messages::pick($formRedirect, $locale);
        return $default === '' ? null : $default;
    }

    /** @param list<string> $origins */
    public static function isAllowed(string $target, array $origins): bool
    {
        if (strlen($target) > 500 || preg_match('/[\x00-\x20\x7F\\\\]/', $target)) {
            return false;
        }
        // Relative path: "/danke", but never protocol-relative "//evil.com".
        if ($target[0] === '/') {
            return !isset($target[1]) || ($target[1] !== '/');
        }
        $origin = self::originOf($target);
        if ($origin === null) {
            return false;
        }
        foreach ($origins as $allowed) {
            if (self::normalizeOrigin($allowed) === $origin) {
                return true;
            }
        }
        return false;
    }

    /** "https://Example.com:443/x?y" → "https://example.com" */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        if (!preg_match('~^' . preg_quote($parts['scheme'], '~') . '://~i', $url)) {
            return null; // e.g. "https:evil.com"
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    public static function normalizeOrigin(string $origin): ?string
    {
        return self::originOf(rtrim($origin, '/'));
    }
}
