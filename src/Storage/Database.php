<?php

declare(strict_types=1);

namespace Machma\Forms\Storage;

use PDO;

/** One SQLite file for logs, rate limits, ALTCHA replay protection, users and sessions. */
final class Database
{
    private const SCHEMA_VERSION = 1;

    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                site TEXT NOT NULL,
                form TEXT NOT NULL,
                status TEXT NOT NULL,
                reason TEXT,
                ip_hash TEXT NOT NULL,
                locale TEXT,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                autoresponder INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS logs_site_ts ON logs (site, ts);
            CREATE INDEX IF NOT EXISTS logs_status_ts ON logs (status, ts);

            CREATE TABLE IF NOT EXISTS rate_hits (
                bucket TEXT NOT NULL,
                ts INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS rate_hits_bucket_ts ON rate_hits (bucket, ts);

            CREATE TABLE IF NOT EXISTS altcha_used (
                signature TEXT PRIMARY KEY,
                expires INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS users (
                email TEXT PRIMARY KEY,
                password_hash TEXT NOT NULL,
                updated INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS tokens (
                token_hash TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                purpose TEXT NOT NULL,
                expires INTEGER NOT NULL,
                used INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id_hash TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                csrf TEXT NOT NULL,
                expires INTEGER NOT NULL
            );
            SQL);
        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Deletes expired rows. Returns deleted counts per table. */
    public function cleanup(int $now, int $logRetentionDays): array
    {
        return [
            'logs' => $this->run('DELETE FROM logs WHERE ts < ?', [$now - $logRetentionDays * 86400])->rowCount(),
            'rate_hits' => $this->run('DELETE FROM rate_hits WHERE ts < ?', [$now - 2 * 86400])->rowCount(),
            'altcha_used' => $this->run('DELETE FROM altcha_used WHERE expires < ?', [$now])->rowCount(),
            'tokens' => $this->run('DELETE FROM tokens WHERE expires < ? OR used = 1', [$now])->rowCount(),
            'sessions' => $this->run('DELETE FROM sessions WHERE expires < ?', [$now])->rowCount(),
        ];
    }
}
