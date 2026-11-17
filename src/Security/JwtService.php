<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use RuntimeException;
use InvalidArgumentException;

class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly string $issuer = 'nirf.deterministic.internal',
        private readonly string $audience = 'nirf.api.clients',
        private readonly int $accessTtl = 900 // 15 minutes
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException("JWT secret must be at least 256 bits (32 characters).");
        }
    }

    /**
     * Issues an access token with sub, role, inst, jti, iat, exp, iss, aud
     */
    public function createAccessToken(
        string $userId,
        string $role,
        ?string $institutionCode,
        int $authVersion = 1
    ): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $userId,
            'role' => $role,
            'inst' => $institutionCode,
            'auth_version' => $authVersion,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Decodes and validates JWT token strictly enforcing HS256 (rejecting alg=none and confusion)
     */
    public function verifyAccessToken(string $jwt): array
    {
        // Explicitly guard against alg=none by inspecting header before decoding
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException("Malformed JWT structure.");
        }

        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($headerJson === false) {
            throw new RuntimeException("Invalid JWT header base64 encoding.");
        }
        $header = json_decode($headerJson, true);
        if (!is_array($header) || strtolower((string)($header['alg'] ?? '')) !== 'hs256') {
            throw new RuntimeException("Unsupported or forbidden JWT algorithm: only HS256 is permitted.");
        }

        // Firebase JWT validation with explicit Key instance
        $decoded = JWT::decode($jwt, new Key($this->secret, 'HS256'));
        $data = (array) $decoded;

        if (($data['iss'] ?? null) !== $this->issuer) {
            throw new RuntimeException("Invalid token issuer.");
        }
        if (($data['aud'] ?? null) !== $this->audience) {
            throw new RuntimeException("Invalid token audience.");
        }

        return $data;
    }

    /**
     * Generates a new 256-bit opaque refresh token and creates/updates token family
     * @return array{token: string, family_id: string, expires_at: string}
     */
    public function issueRefreshToken(
        PDO $pdo,
        string $userId,
        ?string $familyId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): array {
        $token = bin2hex(random_bytes(32)); // 256-bit opaque random token
        $tokenHash = hash('sha256', $token);
        $family = $familyId ?? bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + (86400 * 7)); // 7 days

        $stmt = $pdo->prepare("
            INSERT INTO refresh_tokens (
                user_id, token_hash, family_id, expires_at, ip, user_agent
            ) VALUES (
                :user_id, :token_hash, :family_id, :expires_at, :ip, :user_agent
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':family_id' => $family,
            ':expires_at' => $expiresAt,
            ':ip' => $ip,
            ':user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
        ]);

        return [
            'token' => $token,
            'family_id' => $family,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Rotates a refresh token. If reuse is detected, revokes entire family!
     * @return array{user_id: string, new_token: string, family_id: string}
     */
    public function rotateRefreshToken(
        PDO $pdo,
        string $presentedToken,
        ?string $ip = null,
        ?string $userAgent = null
    ): array {
        $ownsTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTx = true;
        }

        try {
            $tokenHash = hash('sha256', $presentedToken);

            $stmt = $pdo->prepare("SELECT * FROM refresh_tokens WHERE token_hash = :hash");
            $stmt->execute([':hash' => $tokenHash]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new InvalidRefreshTokenException("Invalid refresh token.");
            }

            $familyId = (string) $record['family_id'];
            $userId = (string) $record['user_id'];

            // REUSE DETECTION: If token was already revoked, someone is replaying a used token!
            // Immediately revoke the entire token family!
            if (!empty($record['revoked_at'])) {
                $revokeFamily = $pdo->prepare("
                    UPDATE refresh_tokens 
                    SET revoked_at = CURRENT_TIMESTAMP 
                    WHERE family_id = :family_id AND revoked_at IS NULL
                ");
                $revokeFamily->execute([':family_id' => $familyId]);

                if ($ownsTx) {
                    $pdo->commit();
                    $ownsTx = false;
                }

                throw new InvalidRefreshTokenException("Refresh token reuse detected; associated sessions have been revoked.");
            }

            // Check expiration
            if (strtotime((string) $record['expires_at']) < time()) {
                throw new InvalidRefreshTokenException("Refresh token has expired.");
            }

            // Issue new token in same family
            $newTokenData = $this->issueRefreshToken($pdo, $userId, $familyId, $ip, $userAgent);
            $newTokenHash = hash('sha256', $newTokenData['token']);

            // Mark current token as revoked and replaced
            $updateOld = $pdo->prepare("
                UPDATE refresh_tokens 
                SET revoked_at = CURRENT_TIMESTAMP, replaced_by = :new_hash 
                WHERE id = :id AND revoked_at IS NULL
            ");
            $updateOld->execute([
                ':new_hash' => $newTokenHash,
                ':id' => $record['id']
            ]);

            if ($updateOld->rowCount() === 0) {
                throw new InvalidRefreshTokenException("Refresh token collision detected during rotation.");
            }

            if ($ownsTx) {
                $pdo->commit();
            }

            return [
                'user_id' => $userId,
                'new_token' => $newTokenData['token'],
                'family_id' => $familyId,
            ];
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revokes a refresh token on logout
     */
    public function revokeRefreshToken(PDO $pdo, string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("
            UPDATE refresh_tokens 
            SET revoked_at = CURRENT_TIMESTAMP 
            WHERE token_hash = :hash AND revoked_at IS NULL
        ");
        $stmt->execute([':hash' => $tokenHash]);
    }

    public function revokeAllRefreshTokensForUser(PDO $pdo, string $userId): void
    {
        $stmt = $pdo->prepare("
            UPDATE refresh_tokens
            SET revoked_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id AND revoked_at IS NULL
        ");
        $stmt->execute([':user_id' => $userId]);
    }
}
