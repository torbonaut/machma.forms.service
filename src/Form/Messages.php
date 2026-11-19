<?php

declare(strict_types=1);

namespace Machma\Forms\Form;

/** User-facing texts for errors and mails, in DE and EN. */
final class Messages
{
    private const TEXTS = [
        'de' => [
            'required' => 'Bitte dieses Feld ausfüllen.',
            'required_consent' => 'Bitte bestätigen.',
            'required_choice' => 'Bitte eine Auswahl treffen.',
            'too_long' => 'Bitte höchstens {max} Zeichen eingeben.',
            'too_short' => 'Bitte mindestens {min} Zeichen eingeben.',
            'email' => 'Bitte eine gültige E-Mail-Adresse angeben.',
            'tel' => 'Bitte eine gültige Telefonnummer angeben.',
            'date' => 'Bitte ein gültiges Datum angeben.',
            'number' => 'Bitte eine Zahl angeben.',
            'min' => 'Bitte eine Zahl ab {min} angeben.',
            'max' => 'Bitte eine Zahl bis {max} angeben.',
            'step' => 'Bitte eine gültige Zahl angeben.',
            'option' => 'Bitte eine gültige Option wählen.',
            'invalid' => 'Ungültige Eingabe.',
            'error_validation' => 'Bitte die markierten Felder prüfen.',
            'error_rate_limited' => 'Zu viele Anfragen. Bitte in {minutes} Minuten erneut versuchen.',
            'error_challenge_invalid' => 'Die Spamschutz-Prüfung ist fehlgeschlagen. Bitte die Seite neu laden und erneut senden.',
            'error_send_failed' => 'Die Nachricht konnte gerade nicht gesendet werden. Bitte später erneut versuchen.',
            'error_forbidden' => 'Diese Website darf das Formular nicht verwenden.',
            'error_unsupported' => 'Ungültige Anfrage.',
            'error_too_large' => 'Die Anfrage ist zu groß.',
            'error_not_found' => 'Formular nicht gefunden.',
            'yes' => 'Ja',
            'no' => 'Nein',
            'empty' => '–',
            'default_subject' => 'Neue Einsendung: {form}',
            'default_footer' => 'Gesendet über machma forms ({site}/{form}) am {date}.',
        ],
        'en' => [
            'required' => 'Please fill in this field.',
            'required_consent' => 'Please confirm.',
            'required_choice' => 'Please make a selection.',
            'too_long' => 'Please enter at most {max} characters.',
            'too_short' => 'Please enter at least {min} characters.',
            'email' => 'Please enter a valid email address.',
            'tel' => 'Please enter a valid phone number.',
            'date' => 'Please enter a valid date.',
            'number' => 'Please enter a number.',
            'min' => 'Please enter a number of at least {min}.',
            'max' => 'Please enter a number of at most {max}.',
            'step' => 'Please enter a valid number.',
            'option' => 'Please choose a valid option.',
            'invalid' => 'Invalid input.',
            'error_validation' => 'Please check the highlighted fields.',
            'error_rate_limited' => 'Too many requests. Please try again in {minutes} minutes.',
            'error_challenge_invalid' => 'The spam protection check failed. Please reload the page and send again.',
            'error_send_failed' => 'The message could not be sent right now. Please try again later.',
            'error_forbidden' => 'This website is not allowed to use the form.',
            'error_unsupported' => 'Invalid request.',
            'error_too_large' => 'The request is too large.',
            'error_not_found' => 'Form not found.',
            'yes' => 'Yes',
            'no' => 'No',
            'empty' => '–',
            'default_subject' => 'New submission: {form}',
            'default_footer' => 'Sent via machma forms ({site}/{form}) on {date}.',
        ],
    ];

    /** @param array<string, string|int|float> $vars */
    public static function get(string $key, string $locale, array $vars = []): string
    {
        $text = self::TEXTS[$locale][$key] ?? self::TEXTS['de'][$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Picks a translation from an i18n object: wanted locale, then $fallback, then the first one.
     *
     * @param array<string, string>|null $i18n
     */
    public static function pick(?array $i18n, string $locale, string $fallback = 'de'): string
    {
        if (!$i18n) {
            return '';
        }
        return $i18n[$locale] ?? $i18n[$fallback] ?? (string) reset($i18n);
    }
}
