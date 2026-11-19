#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prints a one-time link (24 h) to set a password – for the very first admin,
 * or when SMTP is not working yet. The user must exist in users.yaml.
 *
 *   php bin/invite.php torben@machma.app
 */

use Machma\Forms\Admin\Auth;
use Machma\Forms\Config\Config;
use Machma\Forms\Security\Hasher;
use Machma\Forms\Security\RateLimiter;
use Machma\Forms\Storage\Database;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$email = strtolower(trim($argv[1] ?? ''));
$secrets = require $root . '/secrets.php';
$config = Config::load($root . '/config/compiled.php');
if ($email === '' || $config->user($email) === null) {
    fwrite(STDERR, "Aufruf: php bin/invite.php <email>  (die Adresse muss in users.yaml stehen)\n");
    exit(1);
}
$db = new Database($root . '/var/forms.sqlite');
$auth = new Auth($db, $config, new RateLimiter($db), new Hasher($secrets['ip_hash_salt']), $secrets['session_key']);
$base = rtrim((string) ($secrets['base_url'] ?? 'https://forms.machma.app'), '/');
echo $base . '/admin/invite/' . $auth->createToken($email, 'invite') . PHP_EOL;
