<?php

declare(strict_types=1);

namespace Machma\Forms\Submission;

use Machma\Forms\Storage\Database;

/** Metadata only – never field contents, email addresses, full IPs or user agents. */
final class SubmissionLog
{
    public const STATUSES = ['sent', 'spam', 'invalid', 'rate_limited', 'error'];
    public const RETENTION_DAYS = 90;

    public function __construct(private readonly Database $db)
    {
    }

    public function write(
        int $ts,
        string $site,
        string $form,
        string $status,
        ?string $reason,
        string $ipHash,
        ?string $locale,
        int $durationMs,
        bool $autoresponder = false,
    ): void {
        $this->db->run(
            'INSERT INTO logs (ts, site, form, status, reason, ip_hash, locale, duration_ms, autoresponder)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ts, $site, $form, $status, $reason, $ipHash, $locale, $durationMs, $autoresponder ? 1 : 0],
        );
    }

    /**
     * @param list<string> $sites
     * @return list<array<string, mixed>>
     */
    public function query(array $sites, ?string $form, ?string $status, ?int $from, ?int $to, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($sites, $form, $status, $from, $to);
        return $this->db->all(
            "SELECT * FROM logs WHERE $where ORDER BY ts DESC, id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );
    }

    /** @param list<string> $sites */
    public function count(array $sites, ?string $form, ?string $status, ?int $from, ?int $to): int
    {
        [$where, $params] = $this->where($sites, $form, $status, $from, $to);
        return (int) $this->db->one("SELECT COUNT(*) AS n FROM logs WHERE $where", $params)['n'];
    }

    /**
     * Submissions per day and status.
     *
     * @return array<string, array<string, int>> date => status => count
     */
    public function daily(string $site, ?string $form, int $from): array
    {
        $params = [$site, $from];
        $sql = "SELECT date(ts, 'unixepoch') AS day, status, COUNT(*) AS n FROM logs WHERE site = ? AND ts >= ?";
        if ($form !== null) {
            $sql .= ' AND form = ?';
            $params[] = $form;
        }
        $sql .= ' GROUP BY day, status';
        $out = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $out[$row['day']][$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<string, array<string, int>> form => status => count */
    public function totalsByForm(string $site, int $from): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT form, status, COUNT(*) AS n FROM logs WHERE site = ? AND ts >= ? GROUP BY form, status',
            [$site, $from],
        ) as $row) {
            $out[$row['form']][$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function where(array $sites, ?string $form, ?string $status, ?int $from, ?int $to): array
    {
        if ($sites === []) {
            return ['0', []];
        }
        $sql = 'site IN (' . implode(',', array_fill(0, count($sites), '?')) . ')';
        $params = $sites;
        if ($form !== null) {
            $sql .= ' AND form = ?';
            $params[] = $form;
        }
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($from !== null) {
            $sql .= ' AND ts >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND ts < ?';
            $params[] = $to;
        }
        return [$sql, $params];
    }
}
