<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Integration;

use Machma\Forms\Config\ConfigCompiler;
use Machma\Forms\Config\ConfigException;
use PHPUnit\Framework\TestCase;

/** Uses the real config repo next to the service (../forms-config or FORMS_CONFIG_DIR). */
final class ConfigCompilerTest extends TestCase
{
    private string $configDir;
    private string $tmp;

    protected function setUp(): void
    {
        $dir = getenv('FORMS_CONFIG_DIR') ?: dirname(__DIR__, 3) . '/forms-config';
        if (!is_file("$dir/schema/form.schema.json")) {
            self::markTestSkipped("Config-Repo nicht gefunden ($dir)");
        }
        $this->configDir = $dir;
        $this->tmp = sys_get_temp_dir() . '/mf-config-' . bin2hex(random_bytes(4));
        mkdir("$this->tmp/schema", 0777, true);
        mkdir("$this->tmp/sites");
        mkdir("$this->tmp/forms/s", 0777, true);
        copy("$dir/schema/form.schema.json", "$this->tmp/schema/form.schema.json");
        file_put_contents("$this->tmp/sites/s.yaml", "id: s\norigins: [https://s.example]\nfromName: S\nrecipients:\n  r: { to: [r@s.example] }\nlocales: [de]\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    private function form(array $overrides): void
    {
        $form = array_replace([
            'id' => 'f', 'version' => 1, 'recipient' => 'r',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => ['de' => 'Name']],
                ['name' => 'email', 'type' => 'email', 'replyTo' => true, 'label' => ['de' => 'E-Mail']],
            ],
        ], $overrides);
        file_put_contents("$this->tmp/forms/s/f.json", json_encode($form));
    }

    private function errors(): array
    {
        try {
            (new ConfigCompiler($this->tmp))->compile();
            return [];
        } catch (ConfigException $e) {
            return $e->errors;
        }
    }

    public function testRealConfigCompiles(): void
    {
        $compiled = (new ConfigCompiler($this->configDir))->compile(true);
        self::assertArrayNotHasKey('dev', $compiled['sites'], 'dev site skipped in production');
        self::assertArrayHasKey('demo', $compiled['sites']);
        self::assertSame(5000, $compiled['sites']['demo']['forms']['kontakt']['fields'][5]['maxLength']);
        self::assertSame(500, $compiled['sites']['demo']['forms']['kontakt']['fields'][1]['maxLength'], 'default maxLength made explicit');
    }

    public function testValidMinimalForm(): void
    {
        $this->form([]);
        self::assertSame([], $this->errors());
    }

    public function testUnknownPlaceholder(): void
    {
        $this->form(['mail' => ['subject' => ['de' => 'Von {{name}} {{telefon}}']]]);
        self::assertStringContainsString('{{telefon}}', implode("\n", $this->errors()));
    }

    public function testAutoresponderMustNotUsePlaceholders(): void
    {
        $this->form(['autoresponder' => ['enabled' => true, 'subject' => ['de' => 'Danke'], 'body' => ['de' => 'Hallo {{name}}']]]);
        self::assertStringContainsString('autoresponder.body.de darf keine Platzhalter', implode("\n", $this->errors()));
    }

    public function testUnknownAliasAndReservedNameAndSchemaErrors(): void
    {
        $this->form(['recipient' => 'nope', 'fields' => [
            ['name' => 'locale', 'type' => 'text', 'label' => ['de' => 'x']],
        ]]);
        $errors = implode("\n", $this->errors());
        self::assertStringContainsString('Empfänger-Alias „nope“', $errors);
        self::assertStringContainsString('reserviert', $errors);

        $this->form(['fields' => [['name' => 'datei', 'type' => 'file', 'label' => ['de' => 'x']]], 'to' => 'evil@example.com']);
        self::assertNotSame([], $this->errors(), 'file type and extra keys rejected by meta schema');
    }

    public function testBadRedirectAndOrigin(): void
    {
        $this->form(['redirect' => ['de' => 'https://evil.example/']]);
        self::assertStringContainsString('redirect.de', implode("\n", $this->errors()));

        file_put_contents("$this->tmp/sites/s.yaml", "id: s\norigins: [https://s.example/pfad]\nfromName: \"S\\nBcc: x\"\nrecipients:\n  r: { to: [nope] }\n");
        $errors = implode("\n", $this->errors());
        self::assertStringContainsString('ungültige Origin', $errors);
        self::assertStringContainsString('fromName', $errors);
        self::assertStringContainsString('ungültige Adresse', $errors);
    }

    public function testUsersMustNotContainPasswords(): void
    {
        $this->form([]);
        file_put_contents("$this->tmp/users.yaml", "- email: a@b.at\n  role: client\n  sites: [s]\n  password: geheim\n- email: c@d.at\n  role: client\n  sites: [x]\n");
        $errors = implode("\n", $this->errors());
        self::assertStringContainsString('Passwörter gehören nie ins Git', $errors);
        self::assertStringContainsString('Seite „x“ existiert nicht', $errors);
    }
}
