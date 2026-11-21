<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\App;
use App\Config\DatabaseManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class RegistrationTest extends TestCase
{
    private \Slim\App $app;
    private PDO $corePdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->corePdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->corePdo->exec("
            CREATE TABLE IF NOT EXISTS institutions (
                id TEXT PRIMARY KEY,
                institution_code TEXT UNIQUE,
                name TEXT,
                status TEXT,
                created_at TEXT
            );
            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                institution_code TEXT,
                email TEXT UNIQUE,
                password_hash TEXT,
                role TEXT,
                totp_secret_encrypted TEXT,
                mfa_enabled INTEGER DEFAULT 0,
                failed_attempts INTEGER DEFAULT 0,
                locked_until TEXT,
                must_change_password INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at TEXT
            );
            CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            );
        ");

        $this->app = App::create();
        $container = $this->app->getContainer();
        /** @var DatabaseManager $dbManager */
        $dbManager = $container->get(DatabaseManager::class);
        $dbManager->setConnectionsForTesting($this->corePdo, $this->corePdo, $this->corePdo);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSuccessfulInstitutionRegistrationWithMinimalCredentials(): void
    {
        $uniqueCode = 'REG-' . bin2hex(random_bytes(3));
        $uniqueEmail = 'nodal.' . bin2hex(random_bytes(3)) . '@testuniv.edu.in';

        $payload = [
            'name' => 'Apex Technological University',
            'code' => $uniqueCode,
            'email' => $uniqueEmail,
            'password' => 'SecurePass2026!',
        ];

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/api/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $body = (array) json_decode((string) $resp->getBody(), true);
        $this->assertTrue($body['success'] ?? false);

        // Test sign in with newly registered account
        $signInReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/signin')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'email' => $uniqueEmail,
                'password' => 'SecurePass2026!'
            ], JSON_THROW_ON_ERROR)));

        $signInResp = $this->app->handle($signInReq);
        $this->assertSame(200, $signInResp->getStatusCode());
        $signInData = (array) json_decode((string) $signInResp->getBody(), true);
        $this->assertTrue($signInData['success'] ?? false);
    }

    public function testRegistrationRejectsShortPasswordOrMissingFields(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/api/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'name' => 'Incomplete College',
                'code' => '',
                'email' => 'dean@college.edu',
                'password' => '123'
            ], JSON_THROW_ON_ERROR)));

        $resp = $this->app->handle($req);
        $this->assertSame(400, $resp->getStatusCode());
        $body = (array) json_decode((string) $resp->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
