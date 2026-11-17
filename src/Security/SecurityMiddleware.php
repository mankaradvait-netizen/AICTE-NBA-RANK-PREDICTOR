<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\DatabaseManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class SecurityMiddleware implements MiddlewareInterface
{
    private const MAX_JSON_SIZE = 1048576; // 1 MB JSON cap

    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly array $allowedOrigins = []
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $method = $request->getMethod();

        // 1. Preflight CORS handling
        if ($method === 'OPTIONS') {
            return $this->handlePreflight($origin);
        }

        // 2. Body size limit enforcement for JSON payloads
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if (strlen($body) > self::MAX_JSON_SIZE) {
                return $this->jsonError("Payload Too Large: JSON body exceeds 1 MB limit.", 413, 'PAYLOAD_TOO_LARGE');
            }
        }

        // 3. Rate limiting by Client IP
        $clientIp = $this->getClientIp($request);
        $path = $request->getUri()->getPath();

        // Login, MFA, and file uploads are throttled more strictly than general API traffic.
        $maxRequests = match ($path) {
            '/api/v1/auth/login' => 10,
            '/api/v1/auth/mfa/verify' => 5,
            '/api/v1/admin/formulas/upload' => 10,
            '/api/v1/admin/benchmarks/ingest' => 10,
            default => 120,
        };
        $window = 60;
        $corePdo = $this->dbManager->getInstitutionCore();
        $rate = RateLimiter::check($corePdo, "ip:{$clientIp}:{$path}", $maxRequests, $window);
        if (!$rate['allowed']) {
            $resp = $this->jsonError("Too Many Requests: Rate limit exceeded.", 429, 'RATE_LIMIT_EXCEEDED');
            return $resp->withHeader('Retry-After', (string) $rate['retry_after']);
        }

        // 4. CSRF enforcement for cookie-based auth endpoints (/auth/refresh, /auth/logout)
        if (in_array($path, ['/api/v1/auth/refresh', '/api/v1/auth/logout'], true)) {
            $csrfHeader = $request->getHeaderLine('X-Requested-With');
            $customCsrf = $request->getHeaderLine('X-CSRF-Token');
            if (empty($csrfHeader) && empty($customCsrf)) {
                return $this->jsonError("Forbidden: Missing required CSRF protection header.", 403, 'CSRF_MISSING');
            }
        }

        // Handle Request
        $response = $handler->handle($request);

        // 5. Append Defense-in-Depth Security Headers
        $isApi = str_starts_with($path, '/api/');
        $csp = $isApi
            ? "default-src 'none'; frame-ancestors 'none';"
            : "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        $cacheControl = $isApi || str_contains($path, 'dashboard') || str_contains($path, 'login')
            ? 'no-store, no-cache, must-revalidate, max-age=0'
            : 'public, max-age=300, stale-while-revalidate=600';

        $response = $response
            ->withHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Cache-Control', $cacheControl);

        // CORS Response Headers
        if ($this->isOriginAllowed($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Expose-Headers', 'Retry-After, X-Request-Id');
        }

        return $response;
    }

    private function handlePreflight(string $origin): Response
    {
        $response = new SlimResponse(204);
        if ($this->isOriginAllowed($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-CSRF-Token, X-Key-Id, X-Timestamp, X-Nonce, X-Signature, X-HMAC-Signature')
                ->withHeader('Access-Control-Max-Age', '86400');
        }
        return $response;
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }
        return in_array($origin, $this->allowedOrigins, true);
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        return (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
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
}
