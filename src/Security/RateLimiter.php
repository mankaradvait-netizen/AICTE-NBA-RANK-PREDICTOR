<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

class RateLimiter
{
    /**
     * Checks and increments rate limit counter with atomic conditional updates
     * @return array{allowed: bool, current: int, limit: int, retry_after: int}
     */
    public static function check(PDO $pdo, string $key, int $maxRequests, int $windowSeconds): array
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $dbKey = hash('sha256', $key) . ":{$windowStart}";

        // Clean older windows (probabilistic garbage collection)
        if (random_int(1, 100) === 1) {
            $cleanupTime = $now - ($windowSeconds * 3);
            $cleanup = $pdo->prepare("DELETE FROM rate_limits WHERE window_start < :cutoff");
            $cleanup->execute([':cutoff' => $cleanupTime]);
        }

        // Initialize window row with 0 count if missing
        $init = $pdo->prepare("
            INSERT INTO rate_limits (key, window_start, count)
            VALUES (:key, :ws, 0)
            ON CONFLICT (key) DO NOTHING
        ");
        $init->execute([':key' => $dbKey, ':ws' => $windowStart]);

        // Atomic conditional increment: only increments if count < maxRequests
        $update = $pdo->prepare("UPDATE rate_limits SET count = count + 1 WHERE key = :key AND count < :max");
        $update->execute([':key' => $dbKey, ':max' => $maxRequests]);

        if ($update->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE key = :key");
            $stmt->execute([':key' => $dbKey]);
            $count = (int) $stmt->fetchColumn();

            return [
                'allowed' => true,
                'current' => $count,
                'limit' => $maxRequests,
                'retry_after' => 0,
            ];
        }

        // Limit reached: query current count to report accurately
        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE key = :key");
        $stmt->execute([':key' => $dbKey]);
        $currentCount = (int) $stmt->fetchColumn();

        $retryAfter = ($windowStart + $windowSeconds) - $now;
        return [
            'allowed' => false,
            'current' => max($currentCount, $maxRequests),
            'limit' => $maxRequests,
            'retry_after' => max(1, $retryAfter),
        ];
    }
}
