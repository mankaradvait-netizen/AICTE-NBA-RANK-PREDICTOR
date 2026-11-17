<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\JwtService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JwtServiceTest extends TestCase
{
    private string $secret;
    private JwtService $jwtService;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->secret = 'super-secret-jwt-key-with-over-256-bits-of-entropy-2026';
        $this->jwtService = new JwtService($this->secret, 'nirf.test', 'nirf.clients', 900);

        // Setup in-memory SQLite table for refresh tokens
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec("
            CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                family_id TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                revoked_at TEXT,
                replaced_by TEXT,
                ip TEXT,
                user_agent TEXT
            )
        ");
    }

    public function testCreateAndVerifyAccessToken(): void
    {
        $userId = 'user-uuid-1234';
        $token = $this->jwtService->createAccessToken($userId, 'IQAC_EDITOR', 'INST_001');

        $claims = $this->jwtService->verifyAccessToken($token);

        $this->assertEquals($userId, $claims['sub']);
        $this->assertEquals('IQAC_EDITOR', $claims['role']);
        $this->assertEquals('INST_001', $claims['inst']);
        $this->assertNotEmpty($claims['jti']);
    }

    public function testRejectsAlgNone(): void
    {
        // Construct forged token with alg=none
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = base64_encode(json_encode([
            'iss' => 'nirf.test',
            'aud' => 'nirf.clients',
            'sub' => 'attacker',
            'role' => 'SYSTEM_ADMIN',
            'exp' => time() + 3600
        ]));
        $forgedToken = $header . '.' . $payload . '.';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only HS256 is permitted');
        $this->jwtService->verifyAccessToken($forgedToken);
    }

    public function testRotateRefreshTokenSuccessfully(): void
    {
        $initial = $this->jwtService->issueRefreshToken($this->pdo, 'user-100');
        $this->assertNotEmpty($initial['token']);

        $rotated = $this->jwtService->rotateRefreshToken($this->pdo, $initial['token']);
        $this->assertEquals('user-100', $rotated['user_id']);
        $this->assertNotEquals($initial['token'], $rotated['new_token']);
        $this->assertEquals($initial['family_id'], $rotated['family_id']);
    }

    public function testReuseDetectionRevokesEntireFamily(): void
    {
        $initial = $this->jwtService->issueRefreshToken($this->pdo, 'user-victim');

        // Legitimate rotation
        $rotated = $this->jwtService->rotateRefreshToken($this->pdo, $initial['token']);

        // Attacker attempts to replay the already used $initial token!
        try {
            $this->jwtService->rotateRefreshToken($this->pdo, $initial['token']);
            $this->fail("Expected refresh token reuse exception.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refresh token reuse detected', $e->getMessage());
        }

        // Now verify that even the legitimate new token is now revoked due to family revocation!
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token reuse detected');
        $this->jwtService->rotateRefreshToken($this->pdo, $rotated['new_token']);
    }
}
