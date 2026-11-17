<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use RuntimeException;

class PasswordPolicy
{
    private const MIN_LENGTH = 12;
    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    private const COMMON_PASSWORDS = [
        'password', 'password123', 'admin123456', 'administrator', '123456789012',
        'welcome12345', 'qwerty123456', 'letmein12345', 'changeme12345', 'nirfadmin123',
        'college12345', 'university123', 'secret123456', 'passphrase123'
    ];

    public static function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
    }

    public static function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    public static function validate(string $password, array $previousHashes = []): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = "Password must be at least " . self::MIN_LENGTH . " characters long.";
        }

        if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)) {
            $errors[] = "Password is too common and easily guessable.";
        }

        if (!preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain uppercase, lowercase, numbers, and special characters.";
        }

        foreach ($previousHashes as $oldHash) {
            if (password_verify($password, $oldHash)) {
                $errors[] = "Password cannot be identical to any of the last 5 previous passwords.";
                break;
            }
        }

        return $errors;
    }

    public static function checkLockout(array $user): bool
    {
        if (!empty($user['locked_until'])) {
            $lockedTime = strtotime((string) $user['locked_until']);
            if ($lockedTime > time()) {
                return true;
            }
        }
        return false;
    }

    public static function recordFailedAttempt(PDO $pdo, string $userId, int $currentFailures): void
    {
        $newFailures = $currentFailures + 1;
        $lockUntil = null;

        if ($newFailures >= self::MAX_FAILURES) {
            $lockUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS);
        }

        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_attempts = :failures, locked_until = :lock_until 
            WHERE id = :id
        ");
        $stmt->execute([
            ':failures' => $newFailures,
            ':lock_until' => $lockUntil,
            ':id' => $userId
        ]);
    }

    public static function resetFailedAttempts(PDO $pdo, string $userId): void
    {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_attempts = 0, locked_until = NULL 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $userId]);
    }
}
