<?php

declare(strict_types=1);

namespace Machma\Forms\Config;

use Machma\Forms\Form\SubmissionValidator;
use Machma\Forms\Mail\HeaderSanitizer;
use Machma\Forms\Mail\TemplateRenderer;
use Machma\Forms\Security\RedirectResolver;
use Machma\Forms\Submission\SubmissionHandler;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Compiles the config repo (sites/*.yaml, forms/<site>/*.json, users.yaml) into one PHP array.
 * Runs in CI and locally – the server never parses YAML. Any error aborts the deploy.
 */
final class ConfigCompiler
{
    /** Sites that only exist locally and are skipped in production builds. */
    public const DEV_SITES = ['dev'];

    /** @var list<string> */
    private array $errors = [];

    private readonly string $schemaFile;

    /** @param string|null $schemaFile meta schema; defaults to <dir>/schema/form.schema.json */
    public function __construct(private readonly string $dir, ?string $schemaFile = null)
    {
        $this->schemaFile = $schemaFile ?? $dir . '/schema/form.schema.json';
    }

    /**
     * @return array{generatedAt:string, sites:array<string, array<string, mixed>>, users:array<string, array{role:string, sites:list<string>}>}
     * @throws ConfigException with all errors
     */
    public function compile(bool $production = false): array
    {
        $this->errors = [];
        if (!is_file($this->schemaFile)) {
            throw new ConfigException(["Meta-Schema fehlt: {$this->schemaFile}"]);
        }
        $schema = json_decode((string) file_get_contents($this->schemaFile));
        if (!is_object($schema)) {
            throw new ConfigException(['schema/form.schema.json ist kein gültiges JSON']);
        }

        $sites = [];
        foreach (glob($this->dir . '/sites/*.yaml') ?: [] as $file) {
            $id = basename($file, '.yaml');
            if ($production && in_array($id, self::DEV_SITES, true)) {
                continue;
            }
            $site = $this->compileSite($file, $id);
            if ($site !== null) {
                $site['forms'] = $this->compileForms($site, $schema);
                $sites[$id] = $site;
            }
        }
        ksort($sites);

        $users = $this->compileUsers($sites);

        // The helpers above append to $this->errors; PHPStan cannot see that.
        if ($this->errors !== []) { // @phpstan-ignore notIdentical.alwaysFalse
            throw new ConfigException($this->errors);
        }

        return ['generatedAt' => gmdate('c'), 'sites' => $sites, 'users' => $users];
    }

    /** Validates a single form file (used by the AI skill before output). */
    public function checkForm(string $file, ?array $site = null): array
    {
        $this->errors = [];
        $schema = json_decode((string) file_get_contents($this->schemaFile));
        $this->compileForm($file, $site, $schema);
        return $this->errors;
    }

    public static function write(array $compiled, string $out): void
    {
        $code = "<?php\n\n// Generiert von bin/compile-config.php – nicht von Hand bearbeiten.\n\nreturn "
            . var_export($compiled, true) . ";\n";
        $tmp = $out . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $code) === false || !rename($tmp, $out)) {
            throw new \RuntimeException("Konnte $out nicht schreiben");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($out, true);
        }
    }

    private function compileSite(string $file, string $id): ?array
    {
        $where = 'sites/' . basename($file);
        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable $e) {
            $this->errors[] = "$where: YAML-Fehler: " . $e->getMessage();
            return null;
        }
        if (!is_array($data)) {
            $this->errors[] = "$where: muss ein Objekt sein";
            return null;
        }
        $allowed = ['id', 'origins', 'fromName', 'recipients', 'rateLimit', 'locales', 'dryRun'];
        foreach (array_diff(array_keys($data), $allowed) as $unknown) {
            $this->errors[] = "$where: unbekannter Schlüssel „{$unknown}“";
        }
        if (($data['id'] ?? null) !== $id || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            $this->errors[] = "$where: id muss dem Dateinamen entsprechen und ein Slug sein";
        }

        $origins = [];
        foreach ((array) ($data['origins'] ?? []) as $origin) {
            $normalized = is_string($origin) ? RedirectResolver::normalizeOrigin($origin) : null;
            $path = is_string($origin) ? parse_url($origin, PHP_URL_PATH) : null;
            if ($normalized === null || ($path !== null && $path !== '' && $path !== '/')
                || str_contains((string) $origin, '?') || str_contains((string) $origin, '#')) {
                $this->errors[] = "$where: ungültige Origin „" . (is_string($origin) ? $origin : json_encode($origin)) . '“ (nur https://host[:port])';
                continue;
            }
            $origins[] = $normalized;
        }
        if ($origins === []) {
            $this->errors[] = "$where: origins darf nicht leer sein";
        }

        $fromName = $data['fromName'] ?? '';
        if (!is_string($fromName) || $fromName === '' || HeaderSanitizer::line($fromName, 120) !== $fromName) {
            $this->errors[] = "$where: fromName fehlt, ist zu lang oder enthält Zeilenumbrüche";
        }

        $recipients = [];
        foreach ((array) ($data['recipients'] ?? []) as $alias => $r) {
            if (!is_string($alias) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $alias) || !is_array($r)) {
                $this->errors[] = "$where: ungültiger Empfänger-Alias „{$alias}“";
                continue;
            }
            $entry = [];
            foreach (['to', 'cc', 'bcc'] as $kind) {
                $list = $r[$kind] ?? [];
                if (!is_array($list)) {
                    $this->errors[] = "$where: recipients.$alias.$kind muss eine Liste sein";
                    $list = [];
                }
                foreach ($list as $address) {
                    if (HeaderSanitizer::email($address) === null) {
                        $this->errors[] = "$where: recipients.$alias.$kind: ungültige Adresse „{$address}“";
                    }
                }
                $entry[$kind] = array_values(array_map('strval', $list));
            }
            if ($entry['to'] === []) {
                $this->errors[] = "$where: recipients.$alias.to darf nicht leer sein";
            }
            foreach (array_diff(array_keys($r), ['to', 'cc', 'bcc']) as $unknown) {
                $this->errors[] = "$where: recipients.$alias: unbekannter Schlüssel „{$unknown}“";
            }
            $recipients[$alias] = $entry;
        }
        if ($recipients === []) {
            $this->errors[] = "$where: recipients darf nicht leer sein";
        }

        $rate = $data['rateLimit'] ?? ['perIp' => 5, 'windowMinutes' => 10];
        if (!is_array($rate) || !is_int($rate['perIp'] ?? null) || !is_int($rate['windowMinutes'] ?? null)
            || $rate['perIp'] < 1 || $rate['perIp'] > 1000 || $rate['windowMinutes'] < 1 || $rate['windowMinutes'] > 1440) {
            $this->errors[] = "$where: rateLimit braucht perIp (1–1000) und windowMinutes (1–1440)";
            $rate = ['perIp' => 5, 'windowMinutes' => 10];
        }

        $locales = $data['locales'] ?? ['de'];
        if (!is_array($locales) || $locales === [] || array_filter($locales, static fn ($l) => !is_string($l) || !preg_match('/^[a-z]{2}$/', $l))) {
            $this->errors[] = "$where: locales muss eine Liste wie [de, en] sein";
            $locales = ['de'];
        }

        if (isset($data['dryRun']) && !is_bool($data['dryRun'])) {
            $this->errors[] = "$where: dryRun muss true oder false sein";
        }

        return [
            'id' => $id,
            'origins' => $origins,
            'fromName' => (string) $fromName,
            'recipients' => $recipients,
            'rateLimit' => ['perIp' => (int) $rate['perIp'], 'windowMinutes' => (int) $rate['windowMinutes']],
            'locales' => array_values($locales),
            'dryRun' => (bool) ($data['dryRun'] ?? false),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function compileForms(array $site, object $schema): array
    {
        $forms = [];
        foreach (glob($this->dir . '/forms/' . $site['id'] . '/*.json') ?: [] as $file) {
            $form = $this->compileForm($file, $site, $schema);
            if ($form !== null) {
                $forms[$form['id']] = $form;
            }
        }
        ksort($forms);
        return $forms;
    }

    private function compileForm(string $file, ?array $site, object $schema): ?array
    {
        $where = 'forms/' . basename(dirname($file)) . '/' . basename($file);
        $raw = (string) file_get_contents($file);
        $json = json_decode($raw);
        if ($json === null) {
            $this->errors[] = "$where: kein gültiges JSON (" . json_last_error_msg() . ')';
            return null;
        }

        $validator = new Validator();
        $validator->parser()->setOption('allowFormats', true);
        $validator->setMaxErrors(20);
        $result = $validator->validate($json, $schema);
        if (!$result->isValid()) {
            $formatted = (new ErrorFormatter())->format($result->error(), true);
            foreach ($formatted as $path => $messages) {
                foreach ((array) $messages as $message) {
                    $this->errors[] = "$where: $path: $message";
                }
            }
            return null;
        }

        $form = json_decode($raw, true);
        unset($form['$schema']);
        $before = count($this->errors);

        if ($form['id'] !== basename($file, '.json')) {
            $this->errors[] = "$where: id „{$form['id']}“ muss dem Dateinamen entsprechen";
        }
        if ($site !== null && !isset($site['recipients'][$form['recipient']])) {
            $this->errors[] = "$where: Empfänger-Alias „{$form['recipient']}“ fehlt in sites/{$site['id']}.yaml";
        }

        $names = [];
        $replyTo = 0;
        foreach ($form['fields'] as $i => $field) {
            $name = $field['name'];
            if (isset($names[$name])) {
                $this->errors[] = "$where: Feldname „{$name}“ ist doppelt";
            }
            $names[$name] = true;
            if (in_array($name, SubmissionHandler::RESERVED, true)) {
                $this->errors[] = "$where: Feldname „{$name}“ ist reserviert";
            }
            if (!empty($field['replyTo'])) {
                $replyTo++;
            }
            if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
                $this->errors[] = "$where: fields[$i] ($name): min ist größer als max";
            }
            if (isset($field['minLength'], $field['maxLength']) && $field['minLength'] > $field['maxLength']) {
                $this->errors[] = "$where: fields[$i] ($name): minLength ist größer als maxLength";
            }
            if (in_array($field['type'], ['text', 'email', 'tel', 'textarea', 'hidden'], true) && !isset($field['maxLength'])) {
                // Every text field gets a maxLength (default 500, textarea 5000) – made explicit in the compiled form.
                $form['fields'][$i]['maxLength'] = $field['type'] === 'textarea'
                    ? SubmissionValidator::DEFAULT_MAX_LENGTH_TEXTAREA : SubmissionValidator::DEFAULT_MAX_LENGTH;
            }
            if ($site !== null && $field['type'] !== 'hidden') {
                foreach ($site['locales'] as $locale) {
                    if (!isset($field['label'][$locale])) {
                        $this->errors[] = "$where: fields[$i] ($name): label.$locale fehlt";
                    }
                    foreach ($field['options'] ?? [] as $o) {
                        if (!isset($o['label'][$locale])) {
                            $this->errors[] = "$where: fields[$i] ($name): Option „{$o['value']}“ ohne label.$locale";
                        }
                    }
                }
            }
            if (isset($field['options'])) {
                $values = array_column($field['options'], 'value');
                if (count($values) !== count(array_unique($values))) {
                    $this->errors[] = "$where: fields[$i] ($name): Optionswerte sind doppelt";
                }
            }
        }
        if ($replyTo > 1) {
            $this->errors[] = "$where: höchstens ein Feld darf replyTo sein";
        }

        // Placeholders only for existing fields plus {{_site}}, {{_form}}, {{_date}}
        $allowed = array_merge(array_keys($names), TemplateRenderer::BUILTINS);
        foreach (['subject', 'body'] as $part) {
            foreach ($form['mail'][$part] ?? [] as $locale => $template) {
                foreach (TemplateRenderer::placeholders($template) as $placeholder) {
                    if (!in_array($placeholder, $allowed, true)) {
                        $this->errors[] = "$where: mail.$part.$locale: Platzhalter {{{$placeholder}}} zeigt auf kein Feld";
                    }
                }
                if (preg_match('/\{\{(?!\s*[A-Za-z_][A-Za-z0-9_]*\s*\}\})/', $template)) {
                    $this->errors[] = "$where: mail.$part.$locale: fehlerhafter Platzhalter";
                }
            }
        }

        // Autoresponder: fixed text only, otherwise the service could be abused to send spam
        $auto = $form['autoresponder'] ?? null;
        if ($auto !== null) {
            foreach (['subject', 'body'] as $part) {
                foreach ($auto[$part] ?? [] as $locale => $text) {
                    if (TemplateRenderer::hasPlaceholders($text)) {
                        $this->errors[] = "$where: autoresponder.$part.$locale darf keine Platzhalter enthalten";
                    }
                }
            }
            if (!empty($auto['enabled'])) {
                if ($replyTo === 0) {
                    $this->errors[] = "$where: autoresponder braucht ein E-Mail-Feld mit replyTo: true";
                }
                if (empty($auto['subject']) || empty($auto['body'])) {
                    $this->errors[] = "$where: autoresponder braucht subject und body";
                }
                foreach ($site['locales'] ?? [] as $locale) {
                    if (!isset($auto['subject'][$locale], $auto['body'][$locale]) && !isset($auto['subject']['de'], $auto['body']['de'])) {
                        $this->errors[] = "$where: autoresponder: Text für $locale fehlt";
                    }
                }
            }
        }

        foreach ($form['redirect'] ?? [] as $locale => $target) {
            if (!RedirectResolver::isAllowed($target, $site['origins'] ?? [])) {
                $this->errors[] = "$where: redirect.$locale muss ein relativer Pfad oder eine URL auf eine der origins sein";
            }
        }

        return count($this->errors) === $before ? $form : null;
    }

    /** @return array<string, array{role:string, sites:list<string>}> */
    private function compileUsers(array $sites): array
    {
        $file = $this->dir . '/users.yaml';
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = Yaml::parseFile($file) ?? [];
        } catch (\Throwable $e) {
            $this->errors[] = 'users.yaml: YAML-Fehler: ' . $e->getMessage();
            return [];
        }
        $users = [];
        foreach ((array) $data as $i => $user) {
            $email = is_array($user) ? HeaderSanitizer::email($user['email'] ?? null) : null;
            if ($email === null) {
                $this->errors[] = "users.yaml[$i]: ungültige E-Mail";
                continue;
            }
            foreach (array_diff(array_keys($user), ['email', 'role', 'sites']) as $unknown) {
                $this->errors[] = "users.yaml[$i]: unbekannter Schlüssel „{$unknown}“ (Passwörter gehören nie ins Git)";
            }
            $role = $user['role'] ?? 'client';
            if (!in_array($role, ['admin', 'client'], true)) {
                $this->errors[] = "users.yaml[$i]: role muss admin oder client sein";
                continue;
            }
            $userSites = array_values((array) ($user['sites'] ?? []));
            if ($role === 'client') {
                if ($userSites === []) {
                    $this->errors[] = "users.yaml[$i]: client braucht mindestens eine Seite";
                }
                // In production builds dev sites are skipped – just drop them from the user.
                $userSites = array_values(array_filter($userSites, function ($s) use ($sites, $i) {
                    if (isset($sites[$s])) {
                        return true;
                    }
                    if (!in_array($s, self::DEV_SITES, true)) {
                        $this->errors[] = "users.yaml[$i]: Seite „{$s}“ existiert nicht";
                    }
                    return false;
                }));
            } else {
                $userSites = [];
            }
            $key = strtolower($email);
            if (isset($users[$key])) {
                $this->errors[] = "users.yaml[$i]: $email ist doppelt";
            }
            $users[$key] = ['role' => $role, 'sites' => $userSites];
        }
        return $users;
    }
}
