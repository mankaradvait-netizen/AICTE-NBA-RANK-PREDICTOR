<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

/**
 * Manages dual isolated database connections:
 * 1. Formula Engine DB: Read-only for scoring / Admin connection for Excel upload service
 * 2. Institution Core DB: Application user with RLS tenant context
 */
class DatabaseManager
{
    private ?PDO $formulaReader = null;
    private ?PDO $formulaAdmin = null;
    private ?PDO $formulaMigration = null;
    private ?PDO $institutionCore = null;
    private ?PDO $institutionCoreMigration = null;

    public function __construct(
        private readonly array $config = []
    ) {}

    public function getFormulaReader(): PDO
    {
        if ($this->formulaReader === null) {
            $this->formulaReader = $this->createConnection(
                dsn: $this->requireConfig('DB_FORMULA_READER_DSN', 'DB_FORMULA_DSN'),
                user: $this->resolveConfig('DB_FORMULA_READER_USER', 'DB_FORMULA_USER'),
                password: $this->resolveConfig('DB_FORMULA_READER_PASSWORD', 'DB_FORMULA_PASSWORD')
            );
        }
        return $this->formulaReader;
    }

    public function getFormulaAdmin(): PDO
    {
        if ($this->formulaAdmin === null) {
            $this->formulaAdmin = $this->createConnection(
                dsn: $this->requireConfig('DB_FORMULA_ADMIN_DSN', 'DB_FORMULA_DSN'),
                user: $this->resolveConfig('DB_FORMULA_ADMIN_USER', 'DB_FORMULA_USER'),
                password: $this->resolveConfig('DB_FORMULA_ADMIN_PASSWORD', 'DB_FORMULA_PASSWORD')
            );
        }
        return $this->formulaAdmin;
    }

    public function getFormulaMigration(): PDO
    {
        if ($this->formulaMigration === null) {
            $this->formulaMigration = $this->createConnection(
                dsn: $this->resolveConfig('DB_FORMULA_MIGRATION_DSN', 'DB_FORMULA_ADMIN_DSN') ?? $this->requireConfig('DB_FORMULA_ADMIN_DSN'),
                user: $this->resolveConfig('DB_FORMULA_MIGRATION_USER', 'DB_FORMULA_ADMIN_USER'),
                password: $this->resolveConfig('DB_FORMULA_MIGRATION_PASSWORD', 'DB_FORMULA_ADMIN_PASSWORD')
            );
        }
        return $this->formulaMigration;
    }

    public function getInstitutionCore(): PDO
    {
        if ($this->institutionCore === null) {
            $this->institutionCore = $this->createConnection(
                dsn: $this->requireConfig('DB_CORE_DSN', 'DB_INSTITUTION_DSN'),
                user: $this->resolveConfig('DB_CORE_USER', 'DB_INSTITUTION_USER'),
                password: $this->resolveConfig('DB_CORE_PASSWORD', 'DB_INSTITUTION_PASSWORD')
            );
        }
        return $this->institutionCore;
    }

    public function getInstitutionCoreMigration(): PDO
    {
        if ($this->institutionCoreMigration === null) {
            $this->institutionCoreMigration = $this->createConnection(
                dsn: $this->resolveConfig('DB_CORE_MIGRATION_DSN', 'DB_CORE_DSN') ?? $this->requireConfig('DB_CORE_DSN'),
                user: $this->resolveConfig('DB_CORE_MIGRATION_USER', 'DB_CORE_USER'),
                password: $this->resolveConfig('DB_CORE_MIGRATION_PASSWORD', 'DB_CORE_PASSWORD')
            );
        }
        return $this->institutionCoreMigration;
    }

    /**
     * Sets PostgreSQL Row-Level Security tenant variables
     */
    public function setTenantContext(?string $institutionCode, string $role): void
    {
        $pdo = $this->getInstitutionCore();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT set_config('app.current_institution', :inst, false)");
            $stmt->execute([':inst' => $institutionCode ?? '']);

            $stmtRole = $pdo->prepare("SELECT set_config('app.current_user_role', :role, false)");
            $stmtRole->execute([':role' => $role]);
        }
    }

    private function createConnection(string $dsn, ?string $user, ?string $password): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (\PDOException $e) {
            throw new RuntimeException("Database connection error: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function requireConfig(string $key, ?string $fallbackKey = null): string
    {
        $value = $this->resolveConfig($key, $fallbackKey);
        if ($value === null || $value === '') {
            $requiredKey = $fallbackKey === null ? $key : "{$key} or {$fallbackKey}";
            throw new RuntimeException("Missing required database configuration: {$requiredKey}");
        }
        return $value;
    }

    private function resolveConfig(string $key, ?string $fallbackKey = null): ?string
    {
        if (isset($this->config[$key]) && $this->config[$key] !== '') {
            return (string) $this->config[$key];
        }
        if ($fallbackKey !== null && isset($this->config[$fallbackKey]) && $this->config[$fallbackKey] !== '') {
            return (string) $this->config[$fallbackKey];
        }
        $processValue = getenv($key);
        if ($processValue !== false && $processValue !== '') {
            return $processValue;
        }
        if ($fallbackKey !== null) {
            $processFallback = getenv($fallbackKey);
            if ($processFallback !== false && $processFallback !== '') {
                return $processFallback;
            }
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if ($fallbackKey !== null && isset($_ENV[$fallbackKey]) && $_ENV[$fallbackKey] !== '') {
            return (string) $_ENV[$fallbackKey];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        if ($fallbackKey !== null && isset($_SERVER[$fallbackKey]) && $_SERVER[$fallbackKey] !== '') {
            return (string) $_SERVER[$fallbackKey];
        }
        return null;
    }

    public function setConnectionsForTesting(PDO $formulaReader, PDO $formulaAdmin, PDO $institutionCore): void
    {
        $this->formulaReader = $formulaReader;
        $this->formulaAdmin = $formulaAdmin;
        $this->institutionCore = $institutionCore;
    }
}
