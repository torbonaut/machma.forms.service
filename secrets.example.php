<?php
// Kopieren nach secrets.php (liegt nie im Git). Für lokal zeigt SMTP auf Mailpit.
// Keys erzeugen: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
return [
    'altcha_hmac_key' => 'dev-altcha-key-change-me',
    'session_key'     => 'dev-session-key-change-me',
    'ip_hash_salt'    => 'dev-ip-salt-change-me',
    'base_url'        => 'http://localhost:8080',   // für Einladungslinks
    'smtp' => [                      // ein zentrales Postfach für alle Kunden
        'host' => 'mailpit', 'port' => 1025,
        'user' => '', 'pass' => '',
        'secure' => '',              // '' (lokal), 'tls' (587, STARTTLS) oder 'ssl' (465)
        'from' => 'formulare@machma.app',
    ],
];
