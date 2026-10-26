<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;
use mysqli_sql_exception;

/**
 * Fixed-window counters, one row per key. Callers must check the broad (per-IP) key first
 * and stop when it is over the limit, so one client can't create unlimited rows.
 */
final class Throttle
{
    private const MYSQL_DEADLOCK = 1213;

    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Records one hit and returns the number of hits in the current window, including this one.
     * Increment and read happen in one statement, so parallel requests can't slip past the limit.
     */
    public function hit(string $key, int $windowSeconds): int
    {
        $hashedKey = hash('sha256', $key);
        $stmt = $this->db->prepare(
            'INSERT INTO login_throttle (throttle_key, hits, window_start)
             VALUES (?, LAST_INSERT_ID(1), NOW())
             ON DUPLICATE KEY UPDATE
                 hits = LAST_INSERT_ID(IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1)),
                 window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)'
        );
        $stmt->bind_param('sii', $hashedKey, $windowSeconds, $windowSeconds);

        try {
            $this->executeWithRetry($stmt);
        } finally {
            $stmt->close();
        }

        // LAST_INSERT_ID(expr) hands the new count back to this connection without another query.
        return (int) $this->db->insert_id;
    }

    public function clear(string $key): void
    {
        $hashedKey = hash('sha256', $key);
        $stmt = $this->db->prepare('DELETE FROM login_throttle WHERE throttle_key = ?');
        $stmt->bind_param('s', $hashedKey);

        try {
            $this->executeWithRetry($stmt);
        } finally {
            $stmt->close();
        }
    }

    private function executeWithRetry(\mysqli_stmt $stmt): void
    {
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== self::MYSQL_DEADLOCK) {
                throw $e;
            }
            $stmt->execute();
        }
    }
}
