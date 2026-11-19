<?php

declare(strict_types=1);

namespace Machma\Forms\Mail;

use Machma\Forms\Form\Messages;

/** Builds the recipient mail and the autoresponder from site config, form JSON and validated values. */
final class MailBuilder
{
    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $form
     * @param array<string, mixed> $values validated values
     */
    public static function recipientMail(array $site, array $form, array $values, string $locale, int $now): MailMessage
    {
        $recipient = $site['recipients'][$form['recipient']];
        $mail = $form['mail'] ?? [];
        // Recipient mail: template in the submission's language, else German (mail.*.de).
        $mailLocale = isset($mail['subject'][$locale]) || isset($mail['body'][$locale]) ? $locale : 'de';
        $vars = self::vars($site, $form, $values, $mailLocale, $now);

        $subject = isset($mail['subject'])
            ? TemplateRenderer::render(Messages::pick($mail['subject'], $mailLocale), $vars, true)
            : Messages::get('default_subject', $mailLocale, ['form' => self::formTitle($form)]);

        $body = isset($mail['body'])
            ? TemplateRenderer::render(Messages::pick($mail['body'], $mailLocale), $vars)
            : self::defaultBody($site, $form, $vars, $mailLocale);

        $replyTo = null;
        foreach ($form['fields'] as $field) {
            if (!empty($field['replyTo'])) {
                $replyTo = HeaderSanitizer::email($values[$field['name']] ?? null);
                break;
            }
        }

        return new MailMessage(
            to: $recipient['to'],
            subject: HeaderSanitizer::line($subject),
            body: $body,
            fromName: (string) $site['fromName'],
            cc: $recipient['cc'] ?? [],
            bcc: $recipient['bcc'] ?? [],
            replyTo: $replyTo,
        );
    }

    /**
     * Autoresponder: fixed text only (no placeholders), in the submission's language.
     * Returns null if disabled or there is no valid sender address.
     */
    public static function autoresponder(array $site, array $form, array $values, string $locale): ?MailMessage
    {
        $auto = $form['autoresponder'] ?? null;
        if (!$auto || empty($auto['enabled']) || empty($auto['subject']) || empty($auto['body'])) {
            return null;
        }
        $address = null;
        foreach ($form['fields'] as $field) {
            if (!empty($field['replyTo'])) {
                $address = HeaderSanitizer::email($values[$field['name']] ?? null);
                break;
            }
        }
        if ($address === null) {
            return null;
        }
        $recipient = $site['recipients'][$form['recipient']];

        return new MailMessage(
            to: [$address],
            subject: HeaderSanitizer::line(Messages::pick($auto['subject'], $locale)),
            body: Messages::pick($auto['body'], $locale),
            fromName: (string) $site['fromName'],
            // Answers to the thank-you mail go to the customer, never back to formulare@machma.app.
            replyTo: $recipient['to'][0] ?? null,
            headers: ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All'],
        );
    }

    /** @return array<string, string> display values for placeholders */
    public static function vars(array $site, array $form, array $values, string $locale, int $now): array
    {
        $vars = [
            '_site' => (string) $site['id'],
            '_form' => (string) $form['id'],
            '_date' => date($locale === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $now),
        ];
        foreach ($form['fields'] as $field) {
            $vars[$field['name']] = self::display($field, $values[$field['name']] ?? null, $locale);
        }
        return $vars;
    }

    public static function display(array $field, mixed $value, string $locale): string
    {
        if ($value === null || $value === '' || $value === []) {
            return $field['type'] === 'consent' || ($field['type'] === 'checkbox' && !isset($field['options']))
                ? Messages::get('no', $locale)
                : Messages::get('empty', $locale);
        }
        if (is_bool($value)) {
            return Messages::get($value ? 'yes' : 'no', $locale);
        }
        if (isset($field['options'])) {
            $labels = [];
            foreach ((array) $value as $v) {
                foreach ($field['options'] as $option) {
                    if ($option['value'] === $v) {
                        $labels[] = Messages::pick($option['label'], $locale);
                    }
                }
            }
            return implode(', ', $labels);
        }
        if ($field['type'] === 'date' && is_string($value) && $locale === 'de'
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return "$m[3].$m[2].$m[1]";
        }
        return (string) $value;
    }

    private static function defaultBody(array $site, array $form, array $vars, string $locale): string
    {
        $lines = [];
        foreach ($form['fields'] as $field) {
            $label = Messages::pick($field['label'] ?? null, $locale) ?: $field['name'];
            $value = TemplateRenderer::escape($vars[$field['name']], false);
            $lines[] = $field['type'] === 'textarea'
                ? "\n$label:\n$value\n"
                : "$label: $value";
        }
        $lines[] = "\n-- \n" . Messages::get('default_footer', $locale, [
            'site' => $site['id'], 'form' => $form['id'], 'date' => $vars['_date'],
        ]);
        return trim(implode("\n", $lines)) . "\n";
    }

    private static function formTitle(array $form): string
    {
        return (string) $form['id'];
    }
}
