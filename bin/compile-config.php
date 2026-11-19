#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Compiles the config repo into config/compiled.php.
 *
 *   php bin/compile-config.php <config-dir> [--production] [--out=config/compiled.php]
 *   php bin/compile-config.php <config-dir> --check            # validate only, write nothing
 *   php bin/compile-config.php <config-dir> --check-form=forms/<site>/<form>.json
 *   php bin/compile-config.php <config-dir> --schema=<meta-schema.json>   # e.g. for tests/e2e/config
 *
 * Exits with 1 and lists every problem if anything is wrong – then nothing is deployed.
 */

use Machma\Forms\Config\ConfigCompiler;
use Machma\Forms\Config\ConfigException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$dir = null;
$opts = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $opts[$k] = $v;
    } else {
        $dir = $arg;
    }
}
if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "Aufruf: php bin/compile-config.php <config-dir> [--production] [--check] [--check-form=<datei>] [--out=<datei>]\n");
    exit(2);
}
$dir = rtrim((string) realpath($dir), '/');
$compiler = new ConfigCompiler($dir, is_string($opts['schema'] ?? null) ? (string) realpath((string) $opts['schema']) : null);

if (isset($opts['check-form'])) {
    $file = str_starts_with((string) $opts['check-form'], '/') ? (string) $opts['check-form'] : $dir . '/' . $opts['check-form'];
    if (!is_file($file)) {
        fwrite(STDERR, "Datei nicht gefunden: $file\n");
        exit(2);
    }
    // Validate together with its site (if it exists) for alias, locale and redirect checks.
    $siteId = basename(dirname($file));
    $site = null;
    if (is_file("$dir/sites/$siteId.yaml")) {
        $data = Yaml::parseFile("$dir/sites/$siteId.yaml");
        $site = ['id' => $siteId, 'recipients' => $data['recipients'] ?? [], 'locales' => $data['locales'] ?? ['de'], 'origins' => $data['origins'] ?? []];
    } else {
        fwrite(STDERR, "Hinweis: sites/$siteId.yaml existiert noch nicht – Alias und Sprachen werden nicht geprüft.\n");
    }
    $errors = $compiler->checkForm($file, $site);
    if ($errors) {
        fwrite(STDERR, "✗ " . count($errors) . " Problem(e):\n  - " . implode("\n  - ", $errors) . "\n");
        exit(1);
    }
    echo "✓ $file ist gültig.\n";
    exit(0);
}

try {
    $compiled = $compiler->compile(isset($opts['production']));
} catch (ConfigException $e) {
    fwrite(STDERR, "✗ Config ungültig – nichts geschrieben:\n  - " . implode("\n  - ", $e->errors) . "\n");
    exit(1);
}

$forms = array_sum(array_map(static fn (array $s) => count($s['forms']), $compiled['sites']));
$summary = sprintf('%d Seite(n), %d Formular(e), %d Benutzer', count($compiled['sites']), $forms, count($compiled['users']));

if (isset($opts['check'])) {
    echo "✓ Config gültig: $summary\n";
    exit(0);
}

$out = is_string($opts['out'] ?? null) ? $opts['out'] : dirname(__DIR__) . '/config/compiled.php';
ConfigCompiler::write($compiled, $out);
echo "✓ $out geschrieben: $summary" . (isset($opts['production']) ? ' (production, ohne dev-Seiten)' : '') . "\n";
