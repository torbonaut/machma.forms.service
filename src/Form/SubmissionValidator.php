<?php

declare(strict_types=1);

namespace Machma\Forms\Form;

use Opis\JsonSchema\Validator;

/**
 * Validates a submission against the form JSON. Every field is normalized,
 * then checked against a JSON Schema derived from its definition (opis/json-schema).
 * Unknown keys are dropped.
 */
final class SubmissionValidator
{
    public const DEFAULT_MAX_LENGTH = 500;
    public const DEFAULT_MAX_LENGTH_TEXTAREA = 5000;
    private const EMAIL_MAX = 254;

    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->parser()->setOption('allowFormats', true);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $form, array $input, string $locale): array
    {
        $values = [];
        $errors = [];

        foreach ($form['fields'] as $field) {
            $name = $field['name'];
            $value = self::normalize($field, $input[$name] ?? null);

            if (self::isEmpty($value)) {
                if (!empty($field['required'])) {
                    $errors[$name] = Messages::get(self::requiredKey($field), $locale);
                }
                $values[$name] = null;
                continue;
            }

            $error = $this->check($field, $value);
            if ($error !== null) {
                $errors[$name] = Messages::get($error[0], $locale, $error[1]);
                continue;
            }
            $values[$name] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** JSON Schema for a single (normalized, non-empty) field value. */
    public static function fieldSchema(array $field): array
    {
        $type = $field['type'];
        $maxLength = (int) ($field['maxLength'] ?? ($type === 'textarea' ? self::DEFAULT_MAX_LENGTH_TEXTAREA : self::DEFAULT_MAX_LENGTH));

        return match ($type) {
            'text', 'textarea', 'hidden' => array_filter([
                'type' => 'string',
                'maxLength' => $maxLength,
                'minLength' => isset($field['minLength']) ? (int) $field['minLength'] : null,
            ], static fn ($v) => $v !== null),
            'email' => ['type' => 'string', 'maxLength' => min($maxLength, self::EMAIL_MAX), 'format' => 'email'],
            'tel' => ['type' => 'string', 'maxLength' => min($maxLength, 40), 'pattern' => '^\+?[0-9 ()/.\-]{3,40}$'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'number' => array_filter([
                'type' => 'number',
                'minimum' => $field['min'] ?? null,
                'maximum' => $field['max'] ?? null,
                'multipleOf' => $field['step'] ?? null,
            ], static fn ($v) => $v !== null),
            'select', 'radio' => ['type' => 'string', 'enum' => self::optionValues($field)],
            'checkbox' => isset($field['options'])
                ? ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string', 'enum' => self::optionValues($field)]]
                : ['type' => 'boolean'],
            'consent' => ['type' => 'boolean', 'const' => true],
            default => ['not' => true],
        };
    }

    /** @return array{0:string, 1:array<string, mixed>}|null */
    private function check(array $field, mixed $value): ?array
    {
        $schema = json_decode((string) json_encode(self::fieldSchema($field)));
        $result = $this->validator->validate(json_decode((string) json_encode($value)), $schema);

        if ($field['type'] === 'email' && $result->isValid()
            && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return ['email', []];
        }
        if ($result->isValid()) {
            return null;
        }

        $keyword = $result->error()?->keyword() ?? 'invalid';
        $type = $field['type'];
        $maxLength = $field['maxLength'] ?? ($type === 'textarea' ? self::DEFAULT_MAX_LENGTH_TEXTAREA : self::DEFAULT_MAX_LENGTH);

        return match (true) {
            $keyword === 'maxLength' && $type === 'email' => ['email', []],
            $keyword === 'maxLength' && $type === 'tel' => ['tel', []],
            $keyword === 'maxLength' => ['too_long', ['max' => $maxLength]],
            $keyword === 'minLength' => ['too_short', ['min' => $field['minLength'] ?? 1]],
            $type === 'email' => ['email', []],
            $type === 'tel' => ['tel', []],
            $type === 'date' => ['date', []],
            $keyword === 'minimum' => ['min', ['min' => $field['min']]],
            $keyword === 'maximum' => ['max', ['max' => $field['max']]],
            $keyword === 'multipleOf' => ['step', []],
            $type === 'number' => ['number', []],
            $type === 'consent' => ['required_consent', []],
            in_array($type, ['select', 'radio', 'checkbox'], true) => ['option', []],
            default => ['invalid', []],
        };
    }

    /** Converts raw browser input into the canonical type for the field. */
    public static function normalize(array $field, mixed $raw): mixed
    {
        $type = $field['type'];

        if ($type === 'consent' || ($type === 'checkbox' && !isset($field['options']))) {
            return match (true) {
                $raw === true, $raw === 1, $raw === '1', $raw === 'on', $raw === 'true', $raw === 'yes' => true,
                $raw === null, $raw === false, $raw === 0, $raw === '0', $raw === '', $raw === 'false' => false,
                default => $raw, // anything else fails the boolean schema
            };
        }

        if ($type === 'checkbox') {
            if (is_string($raw)) {
                $raw = $raw === '' ? [] : [$raw];
            }
            if (!is_array($raw)) {
                return $raw;
            }
            $list = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    return $raw; // fails schema
                }
                if ($item !== '') {
                    $list[] = $item;
                }
            }
            return array_values(array_unique($list));
        }

        if ($type === 'number') {
            if (is_string($raw)) {
                $raw = trim($raw);
                if ($raw === '') {
                    return null;
                }
                $raw = str_replace(',', '.', $raw);
                if (!is_numeric($raw)) {
                    return $raw;
                }
                return str_contains($raw, '.') || stripos($raw, 'e') !== false ? (float) $raw : (int) $raw;
            }
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw)) {
            return $raw;
        }
        // Remove control characters except newline/tab; normalize line breaks.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = (string) preg_replace('/[^\P{C}\n\t]/u', '', $raw);
        if ($type !== 'textarea') {
            $raw = (string) preg_replace('/\s+/u', ' ', $raw);
        }
        return trim($raw);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false || $value === [];
    }

    private static function requiredKey(array $field): string
    {
        return match ($field['type']) {
            'consent' => 'required_consent',
            'select', 'radio', 'checkbox' => isset($field['options']) ? 'required_choice' : 'required_consent',
            default => 'required',
        };
    }

    /** @return list<string> */
    private static function optionValues(array $field): array
    {
        return array_map(static fn (array $o) => (string) $o['value'], $field['options'] ?? []);
    }
}
