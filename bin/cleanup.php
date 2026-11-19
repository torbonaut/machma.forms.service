#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily cronjob (netcup): deletes expired logs (90 days), rate-limit hits,
 * sessions, links and ALTCHA replay entries.
 *
 *   php bin/cleanup.php
 */

use Machma\Forms\Storage\Database;
use Machma\Forms\Submission\SubmissionLog;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = new Database(dirname(__DIR__) . '/var/forms.sqlite');
$deleted = $db->cleanup(time(), SubmissionLog::RETENTION_DAYS);
$db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$db->pdo->exec('PRAGMA optimize');

echo date('c') . ' cleanup: ' . json_encode($deleted) . PHP_EOL;
