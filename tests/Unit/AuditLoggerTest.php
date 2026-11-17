<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\AuditLogger;
use PDO;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
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
                created_at TEXT NOT NULL
            )
        ");
    }

    public function testHashChainIntegrityValid(): void
    {
        AuditLogger::log($this->pdo, 'user-1', '192.168.1.1', 'AUTH_LOGIN_SUCCESS', null, ['method' => 'PASSWORD']);
        AuditLogger::log($this->pdo, 'user-1', '192.168.1.1', 'FORMULA_UPLOAD_DRAFT', 'set-10', ['file' => 'test.xlsx']);
        AuditLogger::log($this->pdo, 'user-2', '192.168.1.2', 'FORMULA_ACTIVATE', 'set-10', ['approved' => true]);

        $result = AuditLogger::verifyChain($this->pdo);

        $this->assertTrue($result['valid']);
        $this->assertEquals(3, $result['checked_count']);
        $this->assertNull($result['broken_row_id']);
    }

    public function testDetectsTamperedRowContent(): void
    {
        AuditLogger::log($this->pdo, 'user-1', '192.168.1.1', 'AUTH_LOGIN_SUCCESS');
        $row2Id = AuditLogger::log($this->pdo, 'user-1', '192.168.1.1', 'SUBMISSION_EVALUATE', 'sub-101');
        AuditLogger::log($this->pdo, 'user-1', '192.168.1.1', 'SUBMISSION_LOCK', 'sub-101');

        // Attacker alters row 2 action_type in database
        $this->pdo->exec("UPDATE security_audit_logs SET action_type = 'SUBMISSION_TAMPERED' WHERE id = {$row2Id}");

        $result = AuditLogger::verifyChain($this->pdo);

        $this->assertFalse($result['valid']);
        $this->assertEquals($row2Id, $result['broken_row_id']);
        $this->assertStringContainsString('Tampered audit row detected', $result['error'] ?? '');
    }

    public function testDetectsDeletedRowInChain(): void
    {
        AuditLogger::log($this->pdo, 'user-1', '10.0.0.1', 'ACTION_1');
        $row2Id = AuditLogger::log($this->pdo, 'user-1', '10.0.0.1', 'ACTION_2');
        AuditLogger::log($this->pdo, 'user-1', '10.0.0.1', 'ACTION_3');

        // Attacker deletes row 2 to hide evidence
        $this->pdo->exec("DELETE FROM security_audit_logs WHERE id = {$row2Id}");

        $result = AuditLogger::verifyChain($this->pdo);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Broken hash chain', $result['error'] ?? '');
    }

    public function testSanitizesSensitiveMetadata(): void
    {
        $id = AuditLogger::log($this->pdo, 'user-1', '10.0.0.1', 'USER_PASSWORD_CHANGE', null, [
            'password' => 'plaintext123!',
            'secret' => 'topsecret',
            'browser' => 'Firefox'
        ]);

        $stmt = $this->pdo->query("SELECT metadata FROM security_audit_logs WHERE id = {$id}");
        $row = $stmt ? $stmt->fetch() : null;
        $this->assertNotNull($row);

        $meta = json_decode((string)$row['metadata'], true);
        $this->assertEquals('[REDACTED]', $meta['password']);
        $this->assertEquals('[REDACTED]', $meta['secret']);
        $this->assertEquals('Firefox', $meta['browser']);
    }
}
