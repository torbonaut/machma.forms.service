<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use Machma\Forms\Mail\HeaderSanitizer;
use Machma\Forms\Mail\MailBuilder;
use Machma\Forms\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class HeaderInjectionTest extends TestCase
{
    public function testEmailWithLineBreaksIsRejected(): void
    {
        self::assertNull(HeaderSanitizer::email("a@b.at\r\nBcc: x@y.z"));
        self::assertNull(HeaderSanitizer::email('a@b.at, x@y.z'));
        self::assertNull(HeaderSanitizer::email('"x" <a@b.at>'));
        self::assertNull(HeaderSanitizer::email(['a@b.at']));
        self::assertSame('a@b.at', HeaderSanitizer::email('a@b.at'));
    }

    public function testSubjectAndRecipientsCannotBeInjected(): void
    {
        $values = [
            'name' => "Eve\r\nBcc: victim@example.com", 'email' => 'eve@example.com', 'thema' => 'zimmer',
            'nachricht' => 'x', 'datenschutz' => true,
        ];
        $mail = MailBuilder::recipientMail(Fixtures::site(), Fixtures::form(), $values, 'de', 0);
        self::assertStringNotContainsString("\n", $mail->subject);
        self::assertSame(['rezeption@beispielhotel.example'], $mail->to);
        self::assertSame(['chef@beispielhotel.example'], $mail->cc);
        self::assertSame(['archiv@beispielhotel.example'], $mail->bcc);
        self::assertSame('eve@example.com', $mail->replyTo);
    }

    public function testInvalidReplyToIsDropped(): void
    {
        $mail = MailBuilder::recipientMail(Fixtures::site(), Fixtures::form(), ['email' => "x@y.z\nBcc: a@b.c"], 'de', 0);
        self::assertNull($mail->replyTo);
    }

    public function testAutoresponderUsesFixedTextOnly(): void
    {
        $values = ['name' => 'Buy cheap pills at spam.example', 'email' => 'victim@example.com'];
        $auto = MailBuilder::autoresponder(Fixtures::site(), Fixtures::form(), $values, 'en');
        self::assertSame(['victim@example.com'], $auto->to);
        self::assertSame('Thank you', $auto->subject);
        self::assertSame('We will get back to you.', $auto->body);
        self::assertStringNotContainsString('spam.example', $auto->body . $auto->subject);
        self::assertSame('rezeption@beispielhotel.example', $auto->replyTo);
        self::assertSame('auto-replied', $auto->headers['Auto-Submitted']);
    }

    public function testDefaultMailListsAllFields(): void
    {
        $form = Fixtures::form();
        unset($form['mail']);
        $mail = MailBuilder::recipientMail(Fixtures::site(), $form, ['name' => 'Erika', 'thema' => 'zimmer', 'nachricht' => "Zeile 1\nZeile 2"], 'de', 0);
        self::assertSame('Neue Einsendung: kontakt', $mail->subject);
        self::assertStringContainsString('Name: Erika', $mail->body);
        self::assertStringContainsString('Thema: Zimmeranfrage', $mail->body);
        self::assertStringContainsString("Nachricht:\nZeile 1\nZeile 2", $mail->body);
        self::assertStringContainsString('Personen: –', $mail->body);
    }
}
