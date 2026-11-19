<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use Machma\Forms\Form\SubmissionValidator;
use Machma\Forms\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class SubmissionValidatorTest extends TestCase
{
    private function validate(array $input, string $locale = 'de'): array
    {
        return (new SubmissionValidator())->validate(Fixtures::form(), $input, $locale);
    }

    public function testValidInputIsNormalized(): void
    {
        $r = $this->validate(Fixtures::validInput());
        self::assertSame([], $r['errors']);
        self::assertSame(2, $r['values']['personen']);
        self::assertSame(['parkplatz'], $r['values']['extras']);
        self::assertTrue($r['values']['datenschutz']);
        self::assertSame("Hallo,\nhaben Sie ein Zimmer frei?", $r['values']['nachricht']);
    }

    public function testUnknownFieldsAreDropped(): void
    {
        $r = $this->validate(Fixtures::validInput(['to' => 'evil@example.com', 'recipient' => 'x']));
        self::assertArrayNotHasKey('to', $r['values']);
        self::assertArrayNotHasKey('recipient', $r['values']);
    }

    public function testRequiredFieldsInBothLanguages(): void
    {
        $r = $this->validate(['name' => '  ', 'datenschutz' => false]);
        self::assertSame('Bitte dieses Feld ausfüllen.', $r['errors']['name']);
        self::assertSame('Bitte bestätigen.', $r['errors']['datenschutz']);
        self::assertSame('Bitte eine Auswahl treffen.', $r['errors']['thema']);
        self::assertArrayNotHasKey('anreise', $r['errors']);

        $en = $this->validate([], 'en');
        self::assertSame('Please fill in this field.', $en['errors']['name']);
    }

    public function testEmailAndLengthAndRanges(): void
    {
        $r = $this->validate(Fixtures::validInput([
            'email' => "erika@example.com\r\nBcc: victim@example.com",
            'name' => str_repeat('a', 101),
            'personen' => 13,
            'anreise' => '2027-02-30',
            'thema' => 'hacked',
            'extras' => ['parkplatz', 'pool'],
        ]));
        self::assertSame('Bitte eine gültige E-Mail-Adresse angeben.', $r['errors']['email']);
        self::assertSame('Bitte höchstens 100 Zeichen eingeben.', $r['errors']['name']);
        self::assertSame('Bitte eine Zahl bis 12 angeben.', $r['errors']['personen']);
        self::assertSame('Bitte ein gültiges Datum angeben.', $r['errors']['anreise']);
        self::assertSame('Bitte eine gültige Option wählen.', $r['errors']['thema']);
        self::assertSame('Bitte eine gültige Option wählen.', $r['errors']['extras']);
    }

    public function testWrongTypes(): void
    {
        $r = $this->validate(Fixtures::validInput(['name' => ['array'], 'personen' => 'zwei', 'datenschutz' => 'maybe']));
        self::assertSame('Ungültige Eingabe.', $r['errors']['name']);
        self::assertSame('Bitte eine Zahl angeben.', $r['errors']['personen']);
        self::assertSame('Bitte bestätigen.', $r['errors']['datenschutz']);
    }

    public function testSingleLineFieldsLoseLineBreaksAndControlChars(): void
    {
        $r = $this->validate(Fixtures::validInput(['name' => "Erika\r\nMuster\x07"]));
        self::assertSame('Erika Muster', $r['values']['name']);
    }

    public function testDefaultMaxLength(): void
    {
        $schema = SubmissionValidator::fieldSchema(['name' => 'x', 'type' => 'text']);
        self::assertSame(500, $schema['maxLength']);
        $schema = SubmissionValidator::fieldSchema(['name' => 'x', 'type' => 'textarea']);
        self::assertSame(5000, $schema['maxLength']);
    }
}
