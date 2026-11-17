<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\DatabaseManager;
use App\Security\AuditLogger;
use App\Security\CryptoService;
use App\Security\PasswordPolicy;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly CryptoService $cryptoService
    ) {}

    public function listUsers(Request $request, Response $response): Response
    {
        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->query("SELECT id, email, role, institution_code, mfa_enabled, failed_attempts, is_active, created_at FROM users ORDER BY created_at DESC");
        $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return $this->json($response, ['users' => $users]);
    }

    public function createUser(Request $request, Response $response): Response
    {
        $admin = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $email = trim((string)($body['email'] ?? ''));
        $role = trim((string)($body['role'] ?? ''));
        $instCode = !empty($body['institution_code']) ? trim((string)$body['institution_code']) : null;
        $tempPassword = (string)($body['temporary_password'] ?? '');

        $allowedRoles = ['SYSTEM_ADMIN', 'FORMULA_ADMIN', 'IQAC_EDITOR', 'MANAGEMENT_VIEWER', 'AUDITOR'];
        if (!in_array($role, $allowedRoles, true)) {
            return $this->error($response, "Invalid role.", 400, 'INVALID_ROLE');
        }

        $valErrors = PasswordPolicy::validate($tempPassword, []);
        if (!empty($valErrors)) {
            return $this->json($response, ['error' => ['code' => 'WEAK_PASSWORD', 'message' => 'Password policy violation.', 'details' => $valErrors]], 422);
        }

        $hash = PasswordPolicy::hash($tempPassword);
        $pdo = $this->dbManager->getInstitutionCore();

        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, role, institution_code, must_change_password)
            VALUES (:email, :hash, :role, :inst, TRUE)
        ");

        try {
            $stmt->execute([
                ':email' => $email,
                ':hash' => $hash,
                ':role' => $role,
                ':inst' => $instCode,
            ]);
            $newId = (string) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            return $this->error($response, "User with email {$email} already exists.", 409, 'DUPLICATE_USER');
        }

        AuditLogger::log($pdo, (string)$admin['id'], $this->getIp($request), 'ADMIN_CREATE_USER', $newId, ['email' => $email, 'role' => $role]);

        return $this->json($response, ['message' => 'User created successfully.', 'id' => $newId], 201);
    }

    public function disableUser(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $admin = $request->getAttribute('user');
        $pdo = $this->dbManager->getInstitutionCore();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE users SET is_active = FALSE, auth_version = auth_version + 1
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            $this->revokeUserSessions($pdo, (string) $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log($pdo, (string)$admin['id'], $this->getIp($request), 'ADMIN_DISABLE_USER', $id);

        return $this->json($response, ['message' => 'User disabled successfully.']);
    }

    public function listInstitutions(Request $request, Response $response): Response
    {
        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->query("SELECT id, institution_code, name, status, created_at FROM institutions ORDER BY name ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return $this->json($response, ['institutions' => $rows]);
    }

    public function createInstitution(Request $request, Response $response): Response
    {
        $admin = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $code = strtoupper(trim((string)($body['institution_code'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));

        if (empty($code) || empty($name)) {
            return $this->error($response, "Institution code and name are required.", 400, 'INVALID_INPUT');
        }

        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->prepare("INSERT INTO institutions (institution_code, name) VALUES (:code, :name)");

        try {
            $stmt->execute([':code' => $code, ':name' => $name]);
        } catch (\PDOException) {
            return $this->error($response, "Institution code already exists.", 409, 'DUPLICATE_INSTITUTION');
        }

        AuditLogger::log($pdo, (string)$admin['id'], $this->getIp($request), 'ADMIN_CREATE_INSTITUTION', $code);

        return $this->json($response, ['message' => 'Institution registered.', 'institution_code' => $code], 201);
    }

    public function listApiClients(Request $request, Response $response): Response
    {
        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->query("SELECT id, institution_code, name, key_id, is_active, last_used_at FROM api_clients ORDER BY id DESC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return $this->json($response, ['api_clients' => $rows]);
    }

    public function createApiClient(Request $request, Response $response): Response
    {
        $admin = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $instCode = trim((string)($body['institution_code'] ?? ''));
        $name = trim((string)($body['name'] ?? ''));

        if (empty($instCode) || empty($name)) {
            return $this->error($response, "institution_code and name are required.", 400, 'INVALID_INPUT');
        }

        $keyId = 'ak_' . bin2hex(random_bytes(16));
        $secretPlain = bin2hex(random_bytes(32)); // 256-bit API secret

        // Encrypt secret with CryptoService before persisting
        $encContainer = $this->cryptoService->encrypt($secretPlain);
        $encJson = json_encode($encContainer);

        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->prepare("
            INSERT INTO api_clients (institution_code, name, key_id, secret_encrypted)
            VALUES (:inst, :name, :key_id, :secret)
        ");
        $stmt->execute([
            ':inst' => $instCode,
            ':name' => $name,
            ':key_id' => $keyId,
            ':secret' => $encJson,
        ]);

        AuditLogger::log($pdo, (string)$admin['id'], $this->getIp($request), 'ADMIN_CREATE_API_CLIENT', $keyId, ['institution_code' => $instCode]);

        // Secret is returned ONCE to the administrator
        return $this->json($response, [
            'message' => 'API client provisioned. Store secret securely; it will not be shown again.',
            'key_id' => $keyId,
            'secret' => $secretPlain,
            'institution_code' => $instCode,
        ], 201);
    }

    public function getAuditLogs(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $pdo = $this->dbManager->getInstitutionCore();
        $stmt = $pdo->prepare("SELECT id, user_id, ip_address, action_type, resource_id, metadata, prev_hash, row_hash, created_at FROM security_audit_logs ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, [
            'limit' => $limit,
            'offset' => $offset,
            'audit_logs' => $rows,
        ]);
    }

    private function getIp(Request $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private function revokeUserSessions(PDO $pdo, string $userId): void
    {
        $stmt = $pdo->prepare("
            UPDATE refresh_tokens
            SET revoked_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id AND revoked_at IS NULL
        ");
        $stmt->execute([':user_id' => $userId]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, string $message, int $status, string $code): Response
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => bin2hex(random_bytes(8)),
            ]
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
