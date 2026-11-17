<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\DatabaseManager;
use App\Security\AuthMiddleware;
use App\Security\CryptoService;
use App\Security\HmacVerifier;
use App\Security\JwtService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class HmacVerifierTest extends TestCase
{
    private PDO $pdo;
    private CryptoService $cryptoService;
    private HmacVerifier $verifier;
    private string $clientKeyId;
    private string $clientSecretPlain;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
            CREATE TABLE institutions (
                institution_code TEXT PRIMARY KEY,
                name TEXT NOT NULL
            );
            CREATE TABLE api_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                institution_code TEXT NOT NULL,
                name TEXT NOT NULL,
                key_id TEXT UNIQUE NOT NULL,
                secret_encrypted TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_used_at DATETIME
            );
            CREATE TABLE rate_limits (
                key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            );
        ");

        $this->pdo->exec("INSERT INTO institutions (institution_code, name) VALUES ('INST_IITM', 'IIT Madras')");

        $masterKeyHex = bin2hex(random_bytes(32));
        $this->cryptoService = new CryptoService($masterKeyHex, 'key-2026-v1', 'gcm');
        $this->verifier = new HmacVerifier($this->cryptoService);

        $this->clientKeyId = 'ak_' . bin2hex(random_bytes(8));
        $this->clientSecretPlain = bin2hex(random_bytes(16));

        $encContainer = $this->cryptoService->encrypt($this->clientSecretPlain);
        $encJson = json_encode($encContainer);

        $stmt = $this->pdo->prepare("
            INSERT INTO api_clients (institution_code, name, key_id, secret_encrypted, is_active)
            VALUES ('INST_IITM', 'ERP Integration Client', :key_id, :secret, 1)
        ");
        $stmt->execute([
            ':key_id' => $this->clientKeyId,
            ':secret' => $encJson,
        ]);
    }

    public function testVerifySucceedsWithValidSignature(): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $method = 'POST';
        $path = '/api/v1/submissions';
        $body = json_encode(['academic_year' => '2025-2026', 'category' => 'Engineering']);

        $canonicalPayload = implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $body)
        ]);
        $signature = hash_hmac('sha256', $canonicalPayload, $this->clientSecretPlain);

        $result = $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            $signature
        );

        $this->assertSame('INST_IITM', $result['institution_code']);
        $this->assertSame('ERP Integration Client', $result['client_name']);
        $this->assertGreaterThan(0, $result['client_id']);

        // Verify last_used_at is updated
        $stmt = $this->pdo->prepare("SELECT last_used_at FROM api_clients WHERE key_id = :key_id");
        $stmt->execute([':key_id' => $this->clientKeyId]);
        $row = $stmt->fetch();
        $this->assertNotEmpty($row['last_used_at']);
    }

    public function testVerifyFailsWithExpiredTimestamp(): void
    {
        $timestamp = (string) (time() - 360); // 6 minutes ago (> 300s window)
        $nonce = bin2hex(random_bytes(16));
        $method = 'GET';
        $path = '/api/v1/submissions';
        $body = '';

        $canonicalPayload = implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $body)
        ]);
        $signature = hash_hmac('sha256', $canonicalPayload, $this->clientSecretPlain);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HMAC timestamp expired or clock skew exceeds 300 seconds.');

        $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            $signature
        );
    }

    public function testVerifyFailsWithReplayNonce(): void
    {
        $timestamp = (string) time();
        $nonce = 'duplicate-nonce-12345';
        $method = 'POST';
        $path = '/api/v1/submissions';
        $body = '{}';

        $canonicalPayload = implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $body)
        ]);
        $signature = hash_hmac('sha256', $canonicalPayload, $this->clientSecretPlain);

        // First verification succeeds
        $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            $signature
        );

        // Second verification with identical nonce must fail
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replay attack detected: Nonce has already been consumed.');

        $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            $signature
        );
    }

    public function testVerifyFailsWithInvalidSignature(): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $method = 'POST';
        $path = '/api/v1/submissions';
        $body = '{}';
        $signature = 'invalid_signature_hex';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid HMAC signature.');

        $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            $signature
        );
    }

    public function testVerifyFailsWithInactiveClient(): void
    {
        $this->pdo->exec("UPDATE api_clients SET is_active = 0 WHERE key_id = '{$this->clientKeyId}'");

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $method = 'GET';
        $path = '/api/v1/submissions';
        $body = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or inactive API key identifier.');

        $this->verifier->verify(
            $this->pdo,
            $this->clientKeyId,
            $timestamp,
            $nonce,
            $method,
            $path,
            $body,
            'any-sig'
        );
    }

    public function testVerifyFailsWithNonexistentKey(): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or inactive API key identifier.');

        $this->verifier->verify(
            $this->pdo,
            'ak_nonexistent',
            $timestamp,
            $nonce,
            'GET',
            '/api/v1/submissions',
            '',
            'any-sig'
        );
    }

    public function testAuthMiddlewareHandlesHmacHeaders(): void
    {
        $dbManager = new DatabaseManager([]);
        $dbManager->setConnectionsForTesting($this->pdo, $this->pdo, $this->pdo);

        $jwtService = new JwtService('test-secret-at-least-32-chars-long!!', 'test', 'test', 900);
        $middleware = new AuthMiddleware($jwtService, $dbManager, ['IQAC_EDITOR'], $this->verifier);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $method = 'POST';
        $path = '/api/v1/submissions';
        $body = json_encode(['score' => 95]);

        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $body)
        ]);
        $sig = hash_hmac('sha256', $canonical, $this->clientSecretPlain);

        $req = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withHeader('X-Key-Id', $this->clientKeyId)
            ->withHeader('X-Timestamp', $timestamp)
            ->withHeader('X-Nonce', $nonce)
            ->withHeader('X-Signature', $sig)
            ->withBody((new StreamFactory())->createStream($body));

        $capturedUser = null;
        $handler = new class($capturedUser) implements \Psr\Http\Server\RequestHandlerInterface {
            public mixed $capturedUser;
            public function __construct(&$capturedUser) {
                $this->capturedUser = &$capturedUser;
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $this->capturedUser = $request->getAttribute('user');
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $resp = $middleware->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotNull($capturedUser);
        $this->assertSame('INST_IITM', $capturedUser['institution_code']);
        $this->assertSame('API_CLIENT', $capturedUser['role']);
    }
}
