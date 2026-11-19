<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use Machma\Forms\Mail\MailBuilder;
use Machma\Forms\Mail\TemplateRenderer;
use Machma\Forms\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function testRendersPlaceholders(): void
    {
        self::assertSame('Hallo Erika, Thema: Zimmer', TemplateRenderer::render('Hallo {{name}}, Thema: {{ thema }}', ['name' => 'Erika', 'thema' => 'Zimmer']));
        self::assertSame('Leer: ', TemplateRenderer::render('Leer: {{missing}}', []));
    }

    public function testValuesAreNotReparsed(): void
    {
        self::assertSame('Name: {{email}}', TemplateRenderer::render('Name: {{name}}', ['name' => '{{email}}', 'email' => 'x@y.z']));
    }

    public function testSubjectIsSingleLine(): void
    {
        $out = TemplateRenderer::render('Anfrage von {{name}}', ['name' => "Eve\r\nBcc: a@b.c"], true);
        self::assertStringNotContainsString("\n", $out);
        self::assertStringNotContainsString("\r", $out);
        self::assertSame('Anfrage von Eve Bcc: a@b.c', $out);
    }

    public function testPlaceholderList(): void
    {
        self::assertSame(['a', 'b', '_date'], TemplateRenderer::placeholders('{{a}} {{ b }} {{a}} {{_date}}'));
        self::assertTrue(TemplateRenderer::hasPlaceholders('Hi {{ name }}'));
        self::assertFalse(TemplateRenderer::hasPlaceholders('Hi there'));
    }

    public function testDisplayValues(): void
    {
        $form = Fixtures::form();
        $vars = MailBuilder::vars(Fixtures::site(), $form, [
            'thema' => 'zimmer', 'extras' => ['parkplatz', 'hund'], 'datenschutz' => true, 'anreise' => '2027-07-01', 'personen' => null,
        ], 'de', 0);
        self::assertSame('Zimmeranfrage', $vars['thema']);
        self::assertSame('Parkplatz, Hund', $vars['extras']);
        self::assertSame('Ja', $vars['datenschutz']);
        self::assertSame('01.07.2027', $vars['anreise']);
        self::assertSame('–', $vars['personen']);
        self::assertSame('beispielhotel', $vars['_site']);
    }
}
