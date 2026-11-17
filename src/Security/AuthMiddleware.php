<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\DatabaseManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string> $allowedRoles Empty array means any authenticated user
     */
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly DatabaseManager $dbManager,
        private readonly array $allowedRoles = [],
        private readonly ?HmacVerifier $hmacVerifier = null
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        // Check if server-to-server HMAC authentication is presented
        if ($this->hmacVerifier !== null && ($request->hasHeader('X-Signature') || $request->hasHeader('X-HMAC-Signature') || str_starts_with(strtolower($authHeader), 'hmac-sha256'))) {
            return $this->handleHmacAuth($request, $handler);
        }

        if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $this->jsonError("Missing or malformed Authorization header.", 401, 'UNAUTHORIZED');
        }

        $jwt = $matches[1];

        try {
            $claims = $this->jwtService->verifyAccessToken($jwt);
        } catch (\Throwable) {
            return $this->jsonError("Token is invalid or expired.", 401, 'INVALID_TOKEN');
        }

        $role = (string) ($claims['role'] ?? '');
        $institutionCode = isset($claims['inst']) ? (string) $claims['inst'] : null;
        $userId = (string) ($claims['sub'] ?? '');

        $userStmt = $this->dbManager->getInstitutionCore()->prepare(
            'SELECT is_active, role, auth_version, institution_code FROM users WHERE id = :id'
        );
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch(\PDO::FETCH_ASSOC);
        if (
            !$user
            || !$this->isActive($user['is_active'] ?? false)
            || (string) $user['role'] !== $role
            || (int) $user['auth_version'] !== (int) ($claims['auth_version'] ?? 1)
        ) {
            return $this->jsonError("Account or session is no longer active.", 401, 'INVALID_TOKEN');
        }

        // Verify that JWT institution claim matches current database institution membership
        $dbInstitutionCode = isset($user['institution_code']) ? (string) $user['institution_code'] : null;
        if ($institutionCode !== null && $dbInstitutionCode !== null && $institutionCode !== $dbInstitutionCode) {
            return $this->jsonError("Institution membership has changed. Please re-authenticate.", 401, 'INVALID_TOKEN');
        }

        // RBAC Check
        if (!empty($this->allowedRoles) && !in_array($role, $this->allowedRoles, true)) {
            return $this->jsonError("Forbidden: Insufficient role permissions for this resource.", 403, 'FORBIDDEN');
        }

        // Attach claims to request attributes
        $request = $request->withAttribute('user', [
            'id' => $userId,
            'role' => $role,
            'institution_code' => $institutionCode,
            'jti' => $claims['jti'] ?? null,
        ]);

        try {
            $this->dbManager->setTenantContext($institutionCode, $role);
            return $handler->handle($request);
        } finally {
            $pdo = $this->dbManager->getInstitutionCore();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->dbManager->setTenantContext(null, '');
        }
    }

    private function handleHmacAuth(Request $request, RequestHandler $handler): Response
    {
        $keyId = $request->getHeaderLine('X-Key-Id');
        $timestamp = $request->getHeaderLine('X-Timestamp');
        $nonce = $request->getHeaderLine('X-Nonce');
        $signature = $request->getHeaderLine('X-Signature') ?: $request->getHeaderLine('X-HMAC-Signature');

        $authHeader = $request->getHeaderLine('Authorization');
        if ($signature === '' && preg_match('/HMAC-SHA256\s+(.+)/i', $authHeader, $m)) {
            parse_str(str_replace(['"', ' '], ['', '&'], $m[1]), $params);
            $parsedKey = $params['keyId'] ?? $params['key_id'] ?? '';
            $parsedTs = $params['timestamp'] ?? '';
            $parsedNonce = $params['nonce'] ?? '';
            $parsedSig = $params['signature'] ?? '';

            if (is_string($parsedKey) && $parsedKey !== '') {
                $keyId = $parsedKey;
            }
            if (is_string($parsedTs) && $parsedTs !== '') {
                $timestamp = $parsedTs;
            }
            if (is_string($parsedNonce) && $parsedNonce !== '') {
                $nonce = $parsedNonce;
            }
            if (is_string($parsedSig) && $parsedSig !== '') {
                $signature = $parsedSig;
            }
        }

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->jsonError("Incomplete HMAC authentication headers. Required: X-Key-Id, X-Timestamp, X-Nonce, X-Signature.", 401, 'INVALID_HMAC_HEADERS');
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();

        $pdo = $this->dbManager->getInstitutionCore();

        try {
            /** @var HmacVerifier $verifier */
            $verifier = $this->hmacVerifier;
            $client = $verifier->verify(
                $pdo,
                $keyId,
                $timestamp,
                $nonce,
                $method,
                $path,
                $body,
                $signature
            );
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 401, 'HMAC_VERIFICATION_FAILED');
        }

        $role = 'API_CLIENT';
        $institutionCode = $client['institution_code'];

        // RBAC Check: Allow if allowedRoles is empty OR contains API_CLIENT or IQAC_EDITOR
        if (!empty($this->allowedRoles) && !in_array($role, $this->allowedRoles, true) && !in_array('IQAC_EDITOR', $this->allowedRoles, true)) {
            return $this->jsonError("Forbidden: Insufficient role permissions for this resource.", 403, 'FORBIDDEN');
        }

        $request = $request->withAttribute('user', [
            'id' => (string) $client['client_id'],
            'role' => $role,
            'institution_code' => $institutionCode,
            'client_name' => $client['client_name'],
            'key_id' => $keyId,
        ]);

        try {
            $this->dbManager->setTenantContext($institutionCode, $role);
            return $handler->handle($request);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->dbManager->setTenantContext(null, '');
        }
    }

    private function jsonError(string $message, int $status, string $code): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => bin2hex(random_bytes(8)),
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function isActive(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
