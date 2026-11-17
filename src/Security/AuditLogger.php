<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use RuntimeException;

class AuditLogger
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function log(
        PDO $pdo,
        ?string $userId,
        ?string $ipAddress,
        string $actionType,
        ?string $resourceId = null,
        array $metadata = [],
        ?string $requestSignature = null
    ): int {
        // Strip sensitive data from metadata
        $safeMetadata = self::sanitizeMetadata($metadata);
        ksort($safeMetadata);
        $canonicalMetadata = json_encode($safeMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalMetadata === false) {
            $canonicalMetadata = '{}';
        }

        $ownsTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTx = true;
        }

        try {
            // Fetch latest row hash to form the cryptographic chain
            $stmtPrev = $pdo->query("SELECT row_hash FROM security_audit_logs ORDER BY id DESC LIMIT 1");
            $lastRow = $stmtPrev ? $stmtPrev->fetch(PDO::FETCH_ASSOC) : null;
            $prevHash = $lastRow && !empty($lastRow['row_hash']) ? (string) $lastRow['row_hash'] : self::GENESIS_HASH;

            $createdAt = date('Y-m-d H:i:s');
            $canonicalRow = implode('|', [
                $prevHash,
                $userId ?? '',
                $ipAddress ?? '',
                $actionType,
                $resourceId ?? '',
                $canonicalMetadata,
                $createdAt
            ]);
            $rowHash = hash('sha256', $canonicalRow);

            $stmt = $pdo->prepare("
                INSERT INTO security_audit_logs (
                    user_id, ip_address, action_type, resource_id, request_signature, 
                    metadata, prev_hash, row_hash, created_at
                ) VALUES (
                    :user_id, :ip_address, :action_type, :resource_id, :request_signature, 
                    :metadata, :prev_hash, :row_hash, :created_at
                )
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':ip_address' => $ipAddress,
                ':action_type' => $actionType,
                ':resource_id' => $resourceId,
                ':request_signature' => $requestSignature,
                ':metadata' => $canonicalMetadata,
                ':prev_hash' => $prevHash,
                ':row_hash' => $rowHash,
                ':created_at' => $createdAt,
            ]);

            $insertId = (int) $pdo->lastInsertId();
            if ($ownsTx) {
                $pdo->commit();
            }
            return $insertId;
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verifies the cryptographic integrity of the entire audit hash chain
     * @return array{valid: bool, checked_count: int, broken_row_id: ?int, error: ?string}
     */
    public static function verifyChain(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT * FROM security_audit_logs ORDER BY id ASC");
        if (!$stmt) {
            return ['valid' => true, 'checked_count' => 0, 'broken_row_id' => null, 'error' => null];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expectedPrevHash = self::GENESIS_HASH;
        $count = 0;

        foreach ($rows as $row) {
            $count++;
            $rowId = (int) $row['id'];
            $actualPrevHash = (string) $row['prev_hash'];

            // 1. Verify link to previous row
            if (!hash_equals($expectedPrevHash, $actualPrevHash)) {
                return [
                    'valid' => false,
                    'checked_count' => $count,
                    'broken_row_id' => $rowId,
                    'error' => "Broken hash chain at row {$rowId}: expected prev_hash {$expectedPrevHash}, found {$actualPrevHash}",
                ];
            }

            // 2. Recompute row hash
            $meta = is_string($row['metadata']) ? json_decode($row['metadata'], true) : ($row['metadata'] ?? []);
            if (!is_array($meta)) {
                $meta = [];
            }
            ksort($meta);
            $canonicalMeta = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $canonicalRow = implode('|', [
                $actualPrevHash,
                $row['user_id'] ?? '',
                $row['ip_address'] ?? '',
                $row['action_type'],
                $row['resource_id'] ?? '',
                $canonicalMeta,
                $row['created_at']
            ]);
            $recalculatedHash = hash('sha256', $canonicalRow);

            if (!hash_equals($recalculatedHash, (string) $row['row_hash'])) {
                return [
                    'valid' => false,
                    'checked_count' => $count,
                    'broken_row_id' => $rowId,
                    'error' => "Tampered audit row detected at ID {$rowId}: stored hash doesn't match recomputed hash.",
                ];
            }

            $expectedPrevHash = (string) $row['row_hash'];
        }

        return [
            'valid' => true,
            'checked_count' => $count,
            'broken_row_id' => null,
            'error' => null,
        ];
    }

    private static function sanitizeMetadata(array $data): array
    {
        $redactedKeys = ['password', 'secret', 'token', 'raw_metrics', 'encrypted_raw_metrics', 'totp_secret'];
        $clean = [];

        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $redactedKeys, true)) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = self::sanitizeMetadata($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}
