<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

class PostgresSecurityPolicyTest extends TestCase
{
    private function createRuntimeConnection(): PDO
    {
        $dsn = getenv('SECURITY_TEST_DSN');
        $user = getenv('SECURITY_TEST_USER');
        $password = getenv('SECURITY_TEST_PASSWORD');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set SECURITY_TEST_DSN/USER/PASSWORD to a dedicated PostgreSQL runtime-role database to run this check.');
            throw new \LogicException('Skipped test execution should not continue.');
        }

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function testRuntimeRoleUsesForcedRlsAndCannotModifyAuditRows(): void
    {
        $pdo = $this->createRuntimeConnection();
        $statement = $pdo->query("
            SELECT
                CASE WHEN c.relrowsecurity THEN 'true' ELSE 'false' END AS rls_enabled,
                CASE WHEN c.relforcerowsecurity THEN 'true' ELSE 'false' END AS rls_forced,
                CASE WHEN c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
                    THEN 'true' ELSE 'false' END AS is_owner,
                (SELECT CASE WHEN rolbypassrls THEN 'true' ELSE 'false' END
                 FROM pg_roles WHERE rolname = current_user) AS bypasses_rls,
                (SELECT CASE WHEN relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
                    THEN 'true' ELSE 'false' END
                 FROM pg_class audit
                 JOIN pg_namespace audit_ns ON audit_ns.oid = audit.relnamespace
                 WHERE audit_ns.nspname = 'public' AND audit.relname = 'security_audit_logs') AS owns_audit,
                CASE WHEN has_table_privilege(current_user, 'public.security_audit_logs', 'SELECT')
                    THEN 'true' ELSE 'false' END AS can_select_audit,
                CASE WHEN has_table_privilege(current_user, 'public.security_audit_logs', 'INSERT')
                    THEN 'true' ELSE 'false' END AS can_insert_audit,
                CASE WHEN has_sequence_privilege(current_user, 'public.security_audit_logs_id_seq', 'USAGE')
                    THEN 'true' ELSE 'false' END AS can_use_audit_id,
                CASE WHEN has_table_privilege(current_user, 'public.security_audit_logs', 'UPDATE')
                    THEN 'true' ELSE 'false' END AS can_update_audit,
                CASE WHEN has_table_privilege(current_user, 'public.security_audit_logs', 'DELETE')
                    THEN 'true' ELSE 'false' END AS can_delete_audit,
                CASE WHEN has_table_privilege(current_user, 'public.rate_limits', 'SELECT,INSERT,UPDATE,DELETE')
                    THEN 'true' ELSE 'false' END AS can_manage_rate_limits
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = 'institution_submissions'
        ");
        $row = $statement->fetch();

        self::assertIsArray($row, 'The security schema must be migrated before running this integration test.');
        self::assertSame('true', $row['rls_enabled']);
        self::assertSame('true', $row['rls_forced']);
        self::assertSame('false', $row['is_owner']);
        self::assertSame('false', $row['bypasses_rls']);
        self::assertSame('false', $row['owns_audit']);
        self::assertSame('true', $row['can_select_audit']);
        self::assertSame('true', $row['can_insert_audit']);
        self::assertSame('true', $row['can_use_audit_id']);
        self::assertSame('false', $row['can_update_audit']);
        self::assertSame('false', $row['can_delete_audit']);
        self::assertSame('true', $row['can_manage_rate_limits']);
    }

    public function testEncryptedApplicationValuesUseTextColumns(): void
    {
        $pdo = $this->createRuntimeConnection();
        $statement = $pdo->query("
            SELECT table_name, column_name, udt_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND (table_name, column_name) IN (
                  ('users', 'totp_secret_encrypted'),
                  ('api_clients', 'secret_encrypted'),
                  ('institution_submissions', 'encrypted_raw_metrics'),
                  ('institution_submissions', 'encryption_iv'),
                  ('institution_submissions', 'auth_tag'),
                  ('institution_submissions', 'payload_hmac')
              )
        ");
        $rows = $statement->fetchAll();

        self::assertCount(6, $rows);
        foreach ($rows as $row) {
            self::assertSame('text', $row['udt_name'], "{$row['table_name']}.{$row['column_name']} must be text.");
        }
    }
}
