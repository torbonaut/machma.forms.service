<?php

declare(strict_types=1);

// Front controller. Only public/ is reachable from the web.

use Machma\Forms\App;
use Machma\Forms\Http\Request;
use Machma\Forms\Submission\SubmissionHandler;

$root = dirname(__DIR__);

// PHP built-in server (php -S): serve existing files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($path === '/v1/embed.js') {
        header('Content-Type: text/javascript; charset=utf-8');
        readfile(__DIR__ . '/assets/embed.v1.js');
        return true;
    }
    $file = __DIR__ . $path;
    if ($path !== '/' && (is_file($file) || is_file(rtrim($file, '/') . '/index.html'))) {
        return false;
    }
    if ($path === '/') {
        readfile(__DIR__ . '/index.html');
        return true;
    }
}

require $root . '/vendor/autoload.php';

App::fromFiles($root)->handle(Request::fromGlobals(SubmissionHandler::MAX_BODY))->send();
