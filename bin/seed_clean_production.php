<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Environment;
use App\Config\DatabaseManager;

Environment::load(__DIR__ . '/..');
$dbManager = new DatabaseManager();
$pdo = $dbManager->getInstitutionCore();

echo "=== SEEDING CLEAN PRODUCTION DATABASE ===\n";

// 1. Purge rate limits and mock user sessions
$pdo->exec("DELETE FROM rate_limits");
$pdo->exec("DELETE FROM users");
$pdo->exec("DELETE FROM institutions");

// 2. Insert single clean institution
$instStmt = $pdo->prepare(
    "INSERT INTO institutions (id, institution_code, name, status, created_at)
     VALUES (:id, :code, :name, 'ACTIVE', CURRENT_TIMESTAMP)"
);
$instStmt->execute([
    ':id' => 'inst_adypu',
    ':code' => 'U-0987',
    ':name' => 'Ajeenkya DY Patil University (ADYPU)',
]);
echo "1. Seeded Institution: Ajeenkya DY Patil University (AISHE: U-0987)\n";

// 3. Insert single clean, hashed administrative user
$pwdHash = password_hash('Password123!', PASSWORD_ARGON2ID);
$userStmt = $pdo->prepare(
    "INSERT INTO users (id, institution_code, email, password_hash, role, mfa_enabled, failed_attempts, is_active, created_at)
     VALUES (:id, :code, :email, :hash, :role, 0, 0, 1, CURRENT_TIMESTAMP)"
);
$userStmt->execute([
    ':id' => 'usr_adypu_admin',
    ':code' => 'U-0987',
    ':email' => 'admin@adypu.edu.in',
    ':hash' => $pwdHash,
    ':role' => 'SYSTEM_ADMIN',
]);
echo "2. Seeded Admin User: admin@adypu.edu.in (Argon2id Hashed)\n";

echo "3. Verified clean state: " . $pdo->query("SELECT count(*) FROM users")->fetchColumn() . " user(s), " .
     $pdo->query("SELECT count(*) FROM institutions")->fetchColumn() . " institution(s).\n";
echo "=== DATABASE SEEDED CLEANLY AS A BRAND NEW LAUNCHED APP ===\n";
