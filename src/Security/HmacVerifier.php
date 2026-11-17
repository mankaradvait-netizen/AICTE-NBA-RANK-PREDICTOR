<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use RuntimeException;

class HmacVerifier
{
    private const WINDOW_SECONDS = 300; // 5-minute replay window

    public function __construct(
        private readonly CryptoService $cryptoService
    ) {}

    /**
     * Verifies server-to-server HMAC-SHA256 request signature
     * @return array{institution_code: string, client_id: int, client_name: string}
     */
    public function verify(
        PDO $pdo,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $body,
        string $presentedSignature
    ): array {
        // 1. Timestamp Freshness Check (300-second replay window)
        $reqTime = (int) $timestamp;
        if (abs(time() - $reqTime) > self::WINDOW_SECONDS) {
            throw new RuntimeException("HMAC timestamp expired or clock skew exceeds 300 seconds.");
        }

        // 2. Fetch API Client
        $stmt = $pdo->prepare("SELECT * FROM api_clients WHERE key_id = :key_id AND is_active = TRUE");
        $stmt->execute([':key_id' => $keyId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            throw new RuntimeException("Invalid or inactive API key identifier.");
        }

        // Decrypt API Client Secret
        $secretContainer = json_decode((string) $client['secret_encrypted'], true);
        if (!is_array($secretContainer)) {
            throw new RuntimeException("Corrupted API client credentials.");
        }
        $secret = $this->cryptoService->decrypt(
            $secretContainer['encrypted_payload'],
            $secretContainer['iv'],
            $secretContainer['auth_tag'] ?? null,
            $secretContainer['payload_hmac'] ?? null,
            $secretContainer['wrapped_dek'],
            $secretContainer['payload_sha256']
        );

        // 3. Nonce anti-replay verification
        $nonceKey = "hmac_nonce:{$keyId}:{$nonce}";
        $stmtNonce = $pdo->prepare("
            INSERT INTO rate_limits (key, window_start, count) 
            VALUES (:key, :window_start, 1)
        ");
        try {
            $stmtNonce->execute([
                ':key' => $nonceKey,
                ':window_start' => $reqTime,
            ]);
        } catch (\PDOException) {
            throw new RuntimeException("Replay attack detected: Nonce has already been consumed.");
        }

        // 4. Construct Canonical Signature Payload
        // timestamp \n nonce \n method \n path \n sha256(body)
        $bodyHash = hash('sha256', $body);
        $canonicalPayload = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $bodyHash
        ]);

        $expectedSignature = hash_hmac('sha256', $canonicalPayload, $secret);

        if (!hash_equals($expectedSignature, $presentedSignature)) {
            throw new RuntimeException("Invalid HMAC signature.");
        }

        // Update last_used_at
        $updateStmt = $pdo->prepare("UPDATE api_clients SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id");
        $updateStmt->execute([':id' => $client['id']]);

        return [
            'institution_code' => (string) $client['institution_code'],
            'client_id' => (int) $client['id'],
            'client_name' => (string) $client['name'],
        ];
    }
}
