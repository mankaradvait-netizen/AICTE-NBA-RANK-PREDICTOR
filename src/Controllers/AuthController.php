<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\DatabaseManager;
use App\Security\AuditLogger;
use App\Security\JwtService;
use App\Security\InvalidRefreshTokenException;
use App\Security\MfaService;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private const MFA_MANDATORY_ROLES = ['SYSTEM_ADMIN', 'FORMULA_ADMIN', 'IQAC_EDITOR', 'AUDITOR'];

    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly JwtService $jwtService,
        private readonly MfaService $mfaService
    ) {}

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $pdo = $this->dbManager->getInstitutionCore();

        // 1. Fetch user (or dummy hash to prevent timing attacks)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $dummyHash = '$argon2id$v=19$m=65536,t=4,p=2$VEVTVF9TQUxUXzIwMjY$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

        if (!$user) {
            // Constant time password verify against dummy hash
            PasswordPolicy::verify($password, $dummyHash);
            return $this->error($response, "Invalid credentials.", 401, 'INVALID_CREDENTIALS');
        }

        // Check if account is locked out
        if (PasswordPolicy::checkLockout($user)) {
            AuditLogger::log($pdo, $user['id'], $this->getIp($request), 'AUTH_LOGIN_LOCKED', $user['id']);
            return $this->error($response, "Account temporarily locked due to consecutive failed attempts. Try again in 15 minutes.", 423, 'ACCOUNT_LOCKED');
        }

        // Verify password
        if (!PasswordPolicy::verify($password, (string) $user['password_hash'])) {
            PasswordPolicy::recordFailedAttempt($pdo, (string) $user['id'], (int) $user['failed_attempts']);
            AuditLogger::log($pdo, $user['id'], $this->getIp($request), 'AUTH_LOGIN_FAILURE', $user['id']);
            return $this->error($response, "Invalid credentials.", 401, 'INVALID_CREDENTIALS');
        }

        if (!$this->databaseBoolean($user['is_active'] ?? false)) {
            return $this->error($response, "Invalid credentials.", 401, 'INVALID_CREDENTIALS');
        }

        // Reset failed attempts on success
        PasswordPolicy::resetFailedAttempts($pdo, (string) $user['id']);

        $role = (string) $user['role'];
        $mfaRequired = in_array($role, self::MFA_MANDATORY_ROLES, true);

        // If MFA required or user enabled MFA
        $mfaEnabled = $this->databaseBoolean($user['mfa_enabled'] ?? false);
        if ($mfaRequired || $mfaEnabled) {
            if (!$mfaEnabled) {
                // Must enroll MFA first
                $enrollment = $this->mfaService->enrollUser($email);
                $updateSecret = $pdo->prepare("UPDATE users SET totp_secret_encrypted = :sec WHERE id = :id");
                $updateSecret->execute([':sec' => $enrollment['encrypted_secret'], ':id' => $user['id']]);

                return $this->json($response, [
                    'mfa_required' => true,
                    'mfa_enrolled' => false,
                    'provisioning_uri' => $enrollment['provisioning_uri'],
                    'temp_token' => $this->jwtService->createAccessToken(
                        (string) $user['id'],
                        'MFA_PENDING',
                        $user['institution_code'] !== null ? (string) $user['institution_code'] : null,
                        (int) $user['auth_version']
                    ),
                ]);
            }

            return $this->json($response, [
                'mfa_required' => true,
                'mfa_enrolled' => true,
                'temp_token' => $this->jwtService->createAccessToken(
                    (string) $user['id'],
                    'MFA_PENDING',
                    $user['institution_code'] !== null ? (string) $user['institution_code'] : null,
                    (int) $user['auth_version']
                ),
            ]);
        }

        // Non-MFA role login (e.g. MANAGEMENT_VIEWER)
        return $this->issueAuthResponse($response, $pdo, $user, $request);
    }

    public function verifyMfa(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $code = trim((string) ($body['code'] ?? ''));
        $tempToken = trim((string) ($body['temp_token'] ?? ''));

        try {
            $claims = $this->jwtService->verifyAccessToken($tempToken);
            if (($claims['role'] ?? '') !== 'MFA_PENDING') {
                return $this->error($response, "Invalid token role for MFA verification.", 401, 'INVALID_TOKEN');
            }
        } catch (\Throwable) {
            return $this->error($response, "Expired or invalid temporary token.", 401, 'INVALID_TOKEN');
        }

        $userId = (string) $claims['sub'];
        $pdo = $this->dbManager->getInstitutionCore();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$this->databaseBoolean($user['is_active'] ?? false)) {
            return $this->error($response, "Account inactive.", 403, 'INACTIVE_USER');
        }

        if ((int) $user['auth_version'] !== (int) ($claims['auth_version'] ?? 1)) {
            return $this->error($response, "Expired or invalid temporary token.", 401, 'INVALID_TOKEN');
        }
        if (empty($user['totp_secret_encrypted'])) {
            return $this->error($response, "User has not initiated MFA setup.", 400, 'MFA_NOT_CONFIGURED');
        }

        $mfaRate = RateLimiter::check($pdo, "mfa-user:{$userId}", 5, 300);
        if (!$mfaRate['allowed']) {
            return $this->error($response, "MFA verification rate limit exceeded.", 429, 'RATE_LIMIT_EXCEEDED')
                ->withHeader('Retry-After', (string) $mfaRate['retry_after']);
        }

        $valid = $this->mfaService->verifyCode((string)$user['totp_secret_encrypted'], $code);
        if (!$valid) {
            AuditLogger::log($pdo, $userId, $this->getIp($request), 'AUTH_MFA_FAILURE', $userId);
            return $this->error($response, "Invalid 6-digit TOTP verification code.", 401, 'INVALID_MFA_CODE');
        }

        // Enable MFA if not already enabled
        if (!$this->databaseBoolean($user['mfa_enabled'] ?? false)) {
            $enableStmt = $pdo->prepare("UPDATE users SET mfa_enabled = TRUE WHERE id = :id");
            $enableStmt->execute([':id' => $userId]);
            $user['mfa_enabled'] = true;
        }

        AuditLogger::log($pdo, $userId, $this->getIp($request), 'AUTH_LOGIN_SUCCESS', $userId, ['method' => 'PASSWORD+TOTP']);

        return $this->issueAuthResponse($response, $pdo, $user, $request);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $cookies = $request->getCookieParams();
        $token = $cookies['nirf_refresh_token'] ?? null;

        if (!$token) {
            return $this->error($response, "Missing refresh token cookie.", 401, 'UNAUTHORIZED');
        }

        $pdo = $this->dbManager->getInstitutionCore();

        try {
            $rotation = $this->jwtService->rotateRefreshToken($pdo, (string)$token, $this->getIp($request));
        } catch (InvalidRefreshTokenException $e) {
            AuditLogger::log($pdo, null, $this->getIp($request), 'AUTH_REFRESH_REUSE_OR_INVALID', null, ['msg' => $e->getMessage()]);
            return $this->error($response, "Refresh token is invalid or expired.", 401, 'REFRESH_REVOKED');
        }

        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $userStmt->execute([':id' => $rotation['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$this->databaseBoolean($user['is_active'] ?? false)) {
            $this->jwtService->revokeAllRefreshTokensForUser($pdo, (string) $rotation['user_id']);
            return $this->error($response, "Account inactive.", 403, 'INACTIVE_USER');
        }

        $newAccessToken = $this->jwtService->createAccessToken(
            (string) $user['id'],
            (string) $user['role'],
            $user['institution_code'] !== null ? (string) $user['institution_code'] : null,
            (int) $user['auth_version']
        );

        $cookieHeader = "nirf_refresh_token={$rotation['new_token']}; Path=/api/v1/auth; HttpOnly; Secure; SameSite=Strict; Max-Age=604800";

        return $this->json($response, [
            'access_token' => $newAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ])->withHeader('Set-Cookie', $cookieHeader);
    }

    public function logout(Request $request, Response $response): Response
    {
        $cookies = $request->getCookieParams();
        $token = $cookies['nirf_refresh_token'] ?? null;

        if ($token) {
            $pdo = $this->dbManager->getInstitutionCore();
            $this->jwtService->revokeRefreshToken($pdo, (string)$token);
        }

        $clearCookie = "nirf_refresh_token=; Path=/api/v1/auth; HttpOnly; Secure; SameSite=Strict; Max-Age=0";
        return $this->json($response, ['message' => 'Logged out successfully.'])->withHeader('Set-Cookie', $clearCookie);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (string) ($user['id'] ?? '');

        $body = (array) $request->getParsedBody();
        $oldPass = (string) ($body['old_password'] ?? '');
        $newPass = (string) ($body['new_password'] ?? '');

        $pdo = $this->dbManager->getInstitutionCore();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !PasswordPolicy::verify($oldPass, (string)$row['password_hash'])) {
            return $this->error($response, "Current password verification failed.", 400, 'OLD_PASSWORD_INCORRECT');
        }

        // Fetch last 5 password hashes
        $histStmt = $pdo->prepare("SELECT password_hash FROM password_history WHERE user_id = :id ORDER BY created_at DESC LIMIT 5");
        $histStmt->execute([':id' => $userId]);
        $hashes = $histStmt->fetchAll(PDO::FETCH_COLUMN);

        $valErrors = PasswordPolicy::validate($newPass, $hashes);
        if (!empty($valErrors)) {
            return $this->json($response, [
                'error' => [
                    'code' => 'PASSWORD_POLICY_VIOLATION',
                    'message' => 'Password does not satisfy policy requirements.',
                    'details' => $valErrors,
                ]
            ], 422);
        }

        $newHash = PasswordPolicy::hash($newPass);

        $pdo->beginTransaction();
        try {
            // Update user
            $upStmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :hash,
                    must_change_password = FALSE,
                    auth_version = auth_version + 1
                WHERE id = :id
            ");
            $upStmt->execute([':hash' => $newHash, ':id' => $userId]);

            $this->jwtService->revokeAllRefreshTokensForUser($pdo, $userId);

            // Add to history
            $inHist = $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (:id, :hash)");
            $inHist->execute([':id' => $userId, ':hash' => $newHash]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($pdo, $userId, $this->getIp($request), 'USER_PASSWORD_CHANGE', $userId);

        return $this->json($response, ['message' => 'Password updated successfully.']);
    }

    private function issueAuthResponse(Response $response, PDO $pdo, array $user, Request $request): Response
    {
        $accessToken = $this->jwtService->createAccessToken(
            (string) $user['id'],
            (string) $user['role'],
            $user['institution_code'] !== null ? (string) $user['institution_code'] : null,
            (int) ($user['auth_version'] ?? 1)
        );

        $refreshTokenData = $this->jwtService->issueRefreshToken(
            $pdo,
            (string) $user['id'],
            null,
            $this->getIp($request),
            $request->getHeaderLine('User-Agent')
        );

        $cookieHeader = "nirf_refresh_token={$refreshTokenData['token']}; Path=/api/v1/auth; HttpOnly; Secure; SameSite=Strict; Max-Age=604800";

        return $this->json($response, [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'institution_code' => $user['institution_code'],
                'must_change_password' => $this->databaseBoolean($user['must_change_password'] ?? false),
            ]
        ])->withHeader('Set-Cookie', $cookieHeader);
    }

    private function getIp(Request $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array(
            strtolower((string) $value),
            ['1', 't', 'true', 'yes', 'on'],
            true
        );
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
