<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\App;
use App\Config\DatabaseManager;
use App\Security\PasswordPolicy;
use App\Security\JwtService;
use App\Security\MfaService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class AuthApiTest extends TestCase
{
    private \Slim\App $app;
    private PDO $corePdo;
    private PDO $formulaPdo;

    protected function setUp(): void
    {
        $this->corePdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->formulaPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->createCoreSchema($this->corePdo);

        // Pre-create admin user
        $hash = PasswordPolicy::hash('SecureAdminP@ss2026!');
        $stmt = $this->corePdo->prepare("
            INSERT INTO users (id, email, password_hash, role, must_change_password, is_active)
            VALUES ('usr-100', 'admin@nirf.gov.in', :hash, 'SYSTEM_ADMIN', FALSE, TRUE)
        ");
        $stmt->execute([':hash' => $hash]);

        $this->app = App::create();
        $container = $this->app->getContainer();
        $dbManager = $container->get(DatabaseManager::class);
        $dbManager->setConnectionsForTesting($this->formulaPdo, $this->formulaPdo, $this->corePdo);
    }

    public function testLoginSuccessAndMfaRequirement(): void
    {
        $reqFactory = new ServerRequestFactory();
        $request = $reqFactory->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'email' => 'admin@nirf.gov.in',
            'password' => 'SecureAdminP@ss2026!',
        ]));

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['mfa_required']);
        $this->assertNotEmpty($body['temp_token']);
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $reqFactory = new ServerRequestFactory();
        $request = $reqFactory->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'email' => 'admin@nirf.gov.in',
            'password' => 'WrongPassword123!',
        ]));

        $response = $this->app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('INVALID_CREDENTIALS', $body['error']['code']);
    }

    public function testPostgresBooleanStringsAreNormalizedDuringLogin(): void
    {
        $hash = PasswordPolicy::hash('SecureViewerP@ss2026!');
        $stmt = $this->corePdo->prepare("
            INSERT INTO users (id, email, password_hash, role, mfa_enabled, must_change_password, is_active)
            VALUES ('usr-101', 'viewer@nirf.gov.in', :hash, 'MANAGEMENT_VIEWER', 'f', 'f', 't')
        ");
        $stmt->execute([':hash' => $hash]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'email' => 'viewer@nirf.gov.in',
            'password' => 'SecureViewerP@ss2026!',
        ]));

        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('mfa_required', $body);
        $this->assertFalse($body['user']['must_change_password']);
    }

    public function testMfaAttemptsAreLimitedAcrossDifferentIpAddresses(): void
    {
        $mfaService = $this->app->getContainer()->get(MfaService::class);
        $enrollment = $mfaService->enrollUser('admin@nirf.gov.in');
        $stmt = $this->corePdo->prepare("
            UPDATE users
            SET mfa_enabled = TRUE, totp_secret_encrypted = :secret
            WHERE id = 'usr-100'
        ");
        $stmt->execute([':secret' => $enrollment['encrypted_secret']]);

        $jwtService = $this->app->getContainer()->get(JwtService::class);
        $tempToken = $jwtService->createAccessToken('usr-100', 'MFA_PENDING', null);
        $factory = new ServerRequestFactory();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $request = $factory->createServerRequest(
                'POST',
                '/api/v1/auth/mfa/verify',
                ['REMOTE_ADDR' => "192.0.2.{$attempt}"]
            )->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode([
                'code' => 'not-a-code',
                'temp_token' => $tempToken,
            ]));
            $this->assertSame(401, $this->app->handle($request)->getStatusCode());
        }

        $request = $factory->createServerRequest(
            'POST',
            '/api/v1/auth/mfa/verify',
            ['REMOTE_ADDR' => '192.0.2.6']
        )->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'code' => 'not-a-code',
            'temp_token' => $tempToken,
        ]));

        $response = $this->app->handle($request);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    public function testRateLimitStorageFailureDoesNotAllowRequests(): void
    {
        $this->corePdo->exec('DROP TABLE rate_limits');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/health');
        $response = $this->app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testPasswordChangeRevokesRefreshAndAccessTokens(): void
    {
        $jwtService = $this->app->getContainer()->get(JwtService::class);
        $accessToken = $jwtService->createAccessToken('usr-100', 'SYSTEM_ADMIN', null);
        $refreshToken = $jwtService->issueRefreshToken($this->corePdo, 'usr-100');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/password/change')
            ->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'old_password' => 'SecureAdminP@ss2026!',
            'new_password' => 'Another-Strong-Passphrase-2026!',
        ]));

        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $row = $this->corePdo->query("SELECT auth_version FROM users WHERE id = 'usr-100'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $row['auth_version']);
        $tokenStmt = $this->corePdo->prepare('SELECT revoked_at FROM refresh_tokens WHERE token_hash = :hash');
        $tokenStmt->execute([':hash' => hash('sha256', $refreshToken['token'])]);
        $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($tokenRow['revoked_at']);

        $protectedRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/admin/users')
            ->withHeader('Authorization', 'Bearer ' . $accessToken);
        $protectedResponse = $this->app->handle($protectedRequest);
        $this->assertSame(401, $protectedResponse->getStatusCode());
    }

    public function testDisablingAccountInvalidatesAccessAndRefreshTokens(): void
    {
        $jwtService = $this->app->getContainer()->get(JwtService::class);
        $accessToken = $jwtService->createAccessToken('usr-100', 'SYSTEM_ADMIN', null);
        $refreshToken = $jwtService->issueRefreshToken($this->corePdo, 'usr-100');

        $disableRequest = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/v1/admin/users/usr-100/disable')
            ->withHeader('Authorization', 'Bearer ' . $accessToken);
        $disableResponse = $this->app->handle($disableRequest);
        $this->assertSame(200, $disableResponse->getStatusCode());

        $protectedRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/admin/users')
            ->withHeader('Authorization', 'Bearer ' . $accessToken);
        $this->assertSame(401, $this->app->handle($protectedRequest)->getStatusCode());

        $tokenStmt = $this->corePdo->prepare('SELECT revoked_at FROM refresh_tokens WHERE token_hash = :hash');
        $tokenStmt->execute([':hash' => hash('sha256', $refreshToken['token'])]);
        $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($tokenRow['revoked_at']);
    }

    public function testDisabledAccountCannotCompleteMfa(): void
    {
        $jwtService = $this->app->getContainer()->get(JwtService::class);
        $tempToken = $jwtService->createAccessToken('usr-100', 'MFA_PENDING', null);
        $this->corePdo->exec("UPDATE users SET is_active = FALSE WHERE id = 'usr-100'");

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/mfa/verify')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'code' => '123456',
            'temp_token' => $tempToken,
        ]));

        $response = $this->app->handle($request);
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('INACTIVE_USER', $body['error']['code']);
    }

    public function testLockoutAfterFiveConsecutiveFailures(): void
    {
        $reqFactory = new ServerRequestFactory();

        for ($i = 0; $i < 5; $i++) {
            $request = $reqFactory->createServerRequest('POST', '/api/v1/auth/login')
                ->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode([
                'email' => 'admin@nirf.gov.in',
                'password' => 'WrongAttempt' . $i,
            ]));
            $response = $this->app->handle($request);
            $this->assertEquals(401, $response->getStatusCode());
        }

        // 6th attempt should be locked out!
        $lockedRequest = $reqFactory->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json');
        $lockedRequest->getBody()->write((string) json_encode([
            'email' => 'admin@nirf.gov.in',
            'password' => 'SecureAdminP@ss2026!', // Even with correct password!
        ]));
        $lockedResponse = $this->app->handle($lockedRequest);

        $this->assertEquals(423, $lockedResponse->getStatusCode());
        $body = json_decode((string) $lockedResponse->getBody(), true);
        $this->assertEquals('ACCOUNT_LOCKED', $body['error']['code']);
    }

    private function createCoreSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE users (
                id TEXT PRIMARY KEY,
                institution_code TEXT,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                totp_secret_encrypted TEXT,
                mfa_enabled INTEGER NOT NULL DEFAULT 0,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT,
                auth_version INTEGER NOT NULL DEFAULT 1,
                must_change_password INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE password_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

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
            );

            CREATE TABLE api_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                institution_code TEXT NOT NULL,
                name TEXT NOT NULL,
                key_id TEXT NOT NULL UNIQUE,
                secret_encrypted TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_used_at TEXT
            );

            CREATE TABLE institutions (
                id TEXT PRIMARY KEY,
                institution_code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'ACTIVE',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE security_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                ip_address TEXT,
                action_type TEXT NOT NULL,
                resource_id TEXT,
                request_signature TEXT,
                metadata TEXT,
                prev_hash TEXT NOT NULL,
                row_hash TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE rate_limits (
                key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            );
        ");
    }
}
