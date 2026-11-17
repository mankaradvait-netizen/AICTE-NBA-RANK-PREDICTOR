<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\DatabaseManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseManagerTest extends TestCase
{
    public function testMissingDatabaseDsnFailsClosedInsteadOfFallingBackToSqlite(): void
    {
        $keys = ['DB_CORE_DSN', 'DB_INSTITUTION_DSN'];
        $environment = [];
        foreach ($keys as $key) {
            $environment[$key] = [
                'process' => getenv($key),
                'env_present' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_present' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            $manager = new DatabaseManager([
                'DB_CORE_DSN' => '',
                'DB_INSTITUTION_DSN' => '',
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Missing required database configuration');
            $manager->getInstitutionCore();
        } finally {
            foreach ($environment as $key => $values) {
                if ($values['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $values['process']);
                }
                if ($values['env_present']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }
                if ($values['server_present']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
