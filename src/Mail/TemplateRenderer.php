<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

/**
 * Minimal {{feld}} renderer. Single pass: values are never re-parsed,
 * so a value containing "{{x}}" stays literal text.
 */
final class TemplateRenderer
{
    public const PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';
    public const BUILTINS = ['_site', '_form', '_date'];

    /** @param array<string, string> $vars already formatted display values */
    public static function render(string $template, array $vars, bool $singleLine = false): string
    {
        $out = (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m) => self::escape($vars[$m[1]] ?? '', $singleLine),
            $template,
        );
        return $singleLine ? HeaderSanitizer::line($out) : $out;
    }

    /** @return list<string> placeholder names used in the template */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $m);
        return array_values(array_unique($m[1]));
    }

    public static function hasPlaceholders(string $template): bool
    {
        return (bool) preg_match('/\{\{.*?\}\}/s', $template);
    }

    /** Plain-text escaping: strip control characters; for headers also line breaks. */
    public static function escape(string $value, bool $singleLine): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);
        return $singleLine ? HeaderSanitizer::line($value) : $value;
    }
}
