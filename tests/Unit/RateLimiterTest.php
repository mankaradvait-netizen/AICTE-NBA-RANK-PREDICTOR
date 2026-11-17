<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
            CREATE TABLE rate_limits (
                key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            )
        ");
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $key = 'test:ip:127.0.0.1';
        $limit = 5;
        $window = 60;

        for ($i = 1; $i <= $limit; $i++) {
            $result = RateLimiter::check($this->pdo, $key, $limit, $window);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed.");
            $this->assertEquals($i, $result['current']);
            $this->assertEquals($limit, $result['limit']);
            $this->assertEquals(0, $result['retry_after']);
        }
    }

    public function testBlocksRequestsExceedingLimit(): void
    {
        $key = 'test:login:10.0.0.1';
        $limit = 3;
        $window = 60;

        // Exhaust limit
        for ($i = 1; $i <= $limit; $i++) {
            $result = RateLimiter::check($this->pdo, $key, $limit, $window);
            $this->assertTrue($result['allowed']);
        }

        // Exceeded request
        $blocked = RateLimiter::check($this->pdo, $key, $limit, $window);
        $this->assertFalse($blocked['allowed']);
        $this->assertGreaterThanOrEqual($limit, $blocked['current']);
        $this->assertEquals($limit, $blocked['limit']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
        $this->assertLessThanOrEqual(60, $blocked['retry_after']);
    }

    public function testDistinctKeysAreIsolated(): void
    {
        $keyA = 'ip:192.168.1.1:login';
        $keyB = 'ip:192.168.1.2:login';
        $limit = 2;
        $window = 60;

        RateLimiter::check($this->pdo, $keyA, $limit, $window);
        RateLimiter::check($this->pdo, $keyA, $limit, $window);

        // Key A exhausted
        $resA = RateLimiter::check($this->pdo, $keyA, $limit, $window);
        $this->assertFalse($resA['allowed']);

        // Key B still fresh
        $resB = RateLimiter::check($this->pdo, $keyB, $limit, $window);
        $this->assertTrue($resB['allowed']);
        $this->assertEquals(1, $resB['current']);
    }
}
