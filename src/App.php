<?php

declare(strict_types=1);

namespace App;

use App\Config\DatabaseManager;
use App\Config\Environment;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\FormulaController;
use App\Controllers\HealthController;
use App\Controllers\InputSchemaController;
use App\Controllers\SubmissionController;
use App\Controllers\AccreditationAppController;
use App\AI\AiStrategyService;
use App\AI\NumberGuard;
use App\Engine\GapOptimizer;
use App\Engine\RankPredictor;
use App\Engine\ScoreEngine;
use App\Excel\FormulaWorkbookManager;
use App\Formula\FormulaLinter;
use App\Formula\SafeExpressionEvaluator;
use App\Ingestion\KpiMapper;
use App\Ingestion\ThreeWorkbookIngestionService;
use App\Ingestion\UniversalDocumentIngestionService;
use App\Report\PdfReportService;
use App\Security\AuthMiddleware;
use App\Security\CryptoService;
use App\Security\HmacVerifier;
use App\Security\JwtService;
use App\Security\MfaService;
use App\Security\SecurityMiddleware;
use App\Validation\NirfInputValidator;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

class App
{
    public static function create(): \Slim\App
    {
        // 1. Build PHP-DI Container
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions([
            DatabaseManager::class => function () {
                return new DatabaseManager($_ENV);
            },
            CryptoService::class => function () {
                $masterKey = Environment::require('MASTER_KEY_HEX');
                $keyId = Environment::require('ACTIVE_KEY_ID');
                $mode = Environment::get('CRYPTO_MODE', 'gcm');
                return new CryptoService($masterKey, $keyId, $mode);
            },
            HmacVerifier::class => function ($c) {
                return new HmacVerifier($c->get(CryptoService::class));
            },
            JwtService::class => function () {
                $secret = Environment::require('JWT_SECRET');
                $issuer = Environment::get('JWT_ISSUER', 'nirf.deterministic.internal');
                $aud = Environment::get('JWT_AUDIENCE', 'nirf.api.clients');
                $ttl = (int) Environment::get('JWT_EXPIRATION_SECONDS', 900);
                return new JwtService($secret, $issuer, $aud, $ttl);
            },
            MfaService::class => function ($c) {
                return new MfaService($c->get(CryptoService::class));
            },
            SafeExpressionEvaluator::class => function () {
                return new SafeExpressionEvaluator();
            },
            FormulaLinter::class => function ($c) {
                return new FormulaLinter($c->get(SafeExpressionEvaluator::class));
            },
            FormulaWorkbookManager::class => function ($c) {
                return new FormulaWorkbookManager(
                    $c->get(DatabaseManager::class),
                    $c->get(FormulaLinter::class),
                    $c->get(SafeExpressionEvaluator::class)
                );
            },
            NirfInputValidator::class => function ($c) {
                return new NirfInputValidator(
                    $c->get(DatabaseManager::class),
                    $c->get(SafeExpressionEvaluator::class)
                );
            },
            ScoreEngine::class => function ($c) {
                return new ScoreEngine(
                    $c->get(DatabaseManager::class),
                    $c->get(SafeExpressionEvaluator::class)
                );
            },
            GapOptimizer::class => function ($c) {
                return new GapOptimizer(
                    $c->get(ScoreEngine::class),
                    $c->get(SafeExpressionEvaluator::class)
                );
            },
            ThreeWorkbookIngestionService::class => function () {
                return new ThreeWorkbookIngestionService();
            },
            UniversalDocumentIngestionService::class => function () {
                return new UniversalDocumentIngestionService();
            },
            KpiMapper::class => function () {
                return new KpiMapper();
            },
            RankPredictor::class => function () {
                return new RankPredictor();
            },
            NumberGuard::class => function () {
                return new NumberGuard();
            },
            AiStrategyService::class => function ($c) {
                return new AiStrategyService($c->get(NumberGuard::class));
            },
            PdfReportService::class => function () {
                return new PdfReportService();
            },
            AccreditationAppController::class => function ($c) {
                return new AccreditationAppController(
                    $c->get(DatabaseManager::class),
                    $c->get(ScoreEngine::class),
                    $c->get(GapOptimizer::class),
                    $c->get(ThreeWorkbookIngestionService::class),
                    $c->get(KpiMapper::class),
                    $c->get(RankPredictor::class),
                    $c->get(AiStrategyService::class),
                    $c->get(PdfReportService::class),
                    $c->get(UniversalDocumentIngestionService::class)
                );
            },
        ]);

        $container = $containerBuilder->build();

        // 2. Instantiate Slim 4 App
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 3. Middlewares
        $app->addBodyParsingMiddleware();

        $allowedOrigins = array_filter(array_map('trim', explode(',', (string) Environment::get('CORS_ALLOWED_ORIGINS', ''))));
        $app->add(new SecurityMiddleware($container->get(DatabaseManager::class), $allowedOrigins));

        $errorMiddleware = $app->addErrorMiddleware(
            filter_var(Environment::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
            true,
            true
        );
        $errorMiddleware->setDefaultErrorHandler(function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app) {
            $requestId = bin2hex(random_bytes(8));
            $status = 500;
            $code = 'INTERNAL_SERVER_ERROR';
            $message = $displayErrorDetails ? $exception->getMessage() : "An internal server error occurred.";

            if ($exception instanceof \Slim\Exception\HttpException) {
                $status = $exception->getCode();
                $message = $exception->getMessage();
                $code = match ($status) {
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    400 => 'BAD_REQUEST',
                    403 => 'FORBIDDEN',
                    401 => 'UNAUTHORIZED',
                    default => 'HTTP_ERROR',
                };
            }

            $response = $app->getResponseFactory()->createResponse($status);
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'request_id' => $requestId,
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // 4. Exactly 3 Screens (In Order) & Supporting Endpoints
        // Screen 1: Sign In & Registration
        $app->get('/', [AccreditationAppController::class, 'showSignIn']);
        $app->post('/api/signin', [AccreditationAppController::class, 'handleSignIn']);
        $app->post('/api/register', [AccreditationAppController::class, 'handleRegister']);
        $app->post('/api/signout', [AccreditationAppController::class, 'handleSignOut']);
        $app->get('/api/signout', [AccreditationAppController::class, 'handleSignOut']);

        // Screen 2: Data Input & Ingestion
        $app->get('/input', [AccreditationAppController::class, 'showDataInput']);
        $app->get('/data-input', [AccreditationAppController::class, 'showDataInput']);
        $app->get('/api/template/excel', [AccreditationAppController::class, 'downloadTemplate']);
        $app->get('/api/template/masters', [AccreditationAppController::class, 'downloadMastersZip']);
        $app->post('/api/upload/workbooks', [AccreditationAppController::class, 'handleUploadWorkbooks']);
        $app->post('/api/issues/resolve', [AccreditationAppController::class, 'handleResolveIssue']);
        $app->post('/api/issues/resolve-all', [AccreditationAppController::class, 'handleResolveAllIssues']);
        $app->post('/api/dataset/freeze', [AccreditationAppController::class, 'handleDatasetFreeze']);
        $app->post('/api/analyse', [AccreditationAppController::class, 'handleAnalyse']);

        // Screen 3: Analysis, What-If Simulation, AI Strategy, and PDF
        $app->get('/analysis', [AccreditationAppController::class, 'showAnalysis']);
        $app->get('/results', [AccreditationAppController::class, 'showAnalysis']);
        $app->post('/api/simulate', [AccreditationAppController::class, 'handleSimulate']);
        $app->post('/api/ai/strategy', [AccreditationAppController::class, 'handleAiStrategy']);
        $app->get('/report/pdf', [AccreditationAppController::class, 'downloadPdf']);
        $app->post('/api/reset', [AccreditationAppController::class, 'handleReset']);

        // 5. REST API Routes Definition (Backend)
        $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container) {
            $jwtService = $container->get(JwtService::class);
            $dbManager = $container->get(DatabaseManager::class);
            $hmacVerifier = $container->get(HmacVerifier::class);
            $auth = fn(array $roles = []) => new AuthMiddleware($jwtService, $dbManager, $roles, $hmacVerifier);

            // Public Endpoints
            $group->get('/health', [HealthController::class, 'check']);
            $group->post('/auth/login', [AuthController::class, 'login']);
            $group->post('/auth/mfa/verify', [AuthController::class, 'verifyMfa']);
            $group->post('/auth/refresh', [AuthController::class, 'refresh']);
            $group->post('/auth/logout', [AuthController::class, 'logout']);
            $group->get('/inputs/schema', [InputSchemaController::class, 'getSchema']);

            // Authenticated General Endpoints
            $group->post('/auth/password/change', [AuthController::class, 'changePassword'])
                ->add($auth());

            // Admin Routes (SYSTEM_ADMIN only)
            $group->group('/admin', function (RouteCollectorProxy $admin) {
                $admin->get('/users', [AdminController::class, 'listUsers']);
                $admin->post('/users', [AdminController::class, 'createUser']);
                $admin->put('/users/{id}/disable', [AdminController::class, 'disableUser']);
                $admin->get('/institutions', [AdminController::class, 'listInstitutions']);
                $admin->post('/institutions', [AdminController::class, 'createInstitution']);
                $admin->get('/api-clients', [AdminController::class, 'listApiClients']);
                $admin->post('/api-clients', [AdminController::class, 'createApiClient']);
            })->add($auth(['SYSTEM_ADMIN']));

            // Audit Route (AUDITOR or SYSTEM_ADMIN)
            $group->get('/admin/audit', [AdminController::class, 'getAuditLogs'])
                ->add($auth(['SYSTEM_ADMIN', 'AUDITOR']));

            // Formula Routes (FORMULA_ADMIN only)
            $group->group('/admin/formulas', function (RouteCollectorProxy $formula) {
                $formula->get('/template', [FormulaController::class, 'getTemplate']);
                $formula->post('/upload', [FormulaController::class, 'upload']);
                $formula->get('', [FormulaController::class, 'list']);
                $formula->get('/{id:[0-9]+}', [FormulaController::class, 'get']);
                $formula->post('/{id:[0-9]+}/approve', [FormulaController::class, 'approve']);
                $formula->post('/{id:[0-9]+}/rollback', [FormulaController::class, 'rollback']);
                $formula->get('/{id:[0-9]+}/download', [FormulaController::class, 'download']);
            })->add($auth(['FORMULA_ADMIN']));

            $group->post('/admin/benchmarks/ingest', [FormulaController::class, 'ingestBenchmarks'])
                ->add($auth(['FORMULA_ADMIN']));

            // Submissions: Mutation Routes (IQAC_EDITOR only)
            $group->group('/submissions', function (RouteCollectorProxy $sub) {
                $sub->post('', [SubmissionController::class, 'create']);
                $sub->put('/{id}', [SubmissionController::class, 'update']);
                $sub->post('/{id}/submit', [SubmissionController::class, 'submit']);
            })->add($auth(['IQAC_EDITOR']));

            // Submissions: Read-Only Routes (IQAC_EDITOR, MANAGEMENT_VIEWER, SYSTEM_ADMIN, AUDITOR)
            $group->group('/submissions', function (RouteCollectorProxy $subView) {
                $subView->get('', [SubmissionController::class, 'list']);
                $subView->get('/{id}/results', [SubmissionController::class, 'getResults']);
                $subView->get('/{id}/export.xlsx', [SubmissionController::class, 'exportExcel']);
            })->add($auth(['IQAC_EDITOR', 'MANAGEMENT_VIEWER', 'SYSTEM_ADMIN', 'AUDITOR']));
        });

        return $app;
    }
}
