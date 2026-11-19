<?php

declare(strict_types=1);

namespace Machma\Forms\Security;

use Machma\Forms\Storage\Database;

/** Sliding-window rate limit in SQLite. Buckets are opaque strings (always hashed, never raw IPs/emails). */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Counts a hit and returns null if allowed, or the seconds until the next slot (Retry-After).
     */
    public function hit(string $bucket, int $limit, int $windowSeconds, int $now): ?int
    {
        $retry = $this->check($bucket, $limit, $windowSeconds, $now);
        if ($retry !== null) {
            return $retry;
        }
        $this->db->run('INSERT INTO rate_hits (bucket, ts) VALUES (?, ?)', [$bucket, $now]);
        return null;
    }

    /** Like hit() but without counting. */
    public function check(string $bucket, int $limit, int $windowSeconds, int $now): ?int
    {
        $since = $now - $windowSeconds;
        $row = $this->db->one(
            'SELECT COUNT(*) AS n, MIN(ts) AS oldest FROM rate_hits WHERE bucket = ? AND ts > ?',
            [$bucket, $since],
        );
        if ((int) $row['n'] < $limit) {
            return null;
        }
        // Next free slot once the oldest hits leave the window.
        $nth = $this->db->one(
            'SELECT ts FROM rate_hits WHERE bucket = ? AND ts > ? ORDER BY ts DESC LIMIT 1 OFFSET ?',
            [$bucket, $since, $limit - 1],
        );
        $oldestRelevant = (int) ($nth['ts'] ?? $row['oldest']);
        return max(1, $oldestRelevant + $windowSeconds - $now);
    }

    public function record(string $bucket, int $now): void
    {
        $this->db->run('INSERT INTO rate_hits (bucket, ts) VALUES (?, ?)', [$bucket, $now]);
    }

    public function clear(string $bucket): void
    {
        $this->db->run('DELETE FROM rate_hits WHERE bucket = ?', [$bucket]);
    }
}
