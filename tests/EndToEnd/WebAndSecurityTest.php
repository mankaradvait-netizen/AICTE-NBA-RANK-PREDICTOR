<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\App;
use App\Config\DatabaseManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class WebAndSecurityTest extends TestCase
{
    private \Slim\App $app;
    private PDO $corePdo;
    private PDO $formulaPdo;

    protected function setUp(): void
    {
        $this->corePdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->formulaPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->corePdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                institution_code TEXT,
                email TEXT UNIQUE,
                password_hash TEXT,
                role TEXT,
                totp_secret_encrypted TEXT,
                mfa_enabled INTEGER DEFAULT 0,
                failed_attempts INTEGER DEFAULT 0,
                locked_until TEXT,
                must_change_password INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at TEXT
            );
            CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            );
        ");

        $this->formulaPdo->exec("
            CREATE TABLE formula_sets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                academic_year TEXT NOT NULL,
                version INT NOT NULL,
                version_label TEXT NOT NULL,
                status TEXT NOT NULL,
                source_file_sha256 TEXT NOT NULL,
                uploaded_by TEXT NOT NULL,
                approved_by TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                activated_at TEXT,
                notes TEXT
            );

            CREATE TABLE input_definitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                input_code TEXT NOT NULL,
                label TEXT NOT NULL,
                unit TEXT NOT NULL,
                data_type TEXT NOT NULL,
                min_value REAL NOT NULL,
                max_value REAL NOT NULL,
                required INTEGER NOT NULL,
                main_parameter TEXT NOT NULL,
                help_text TEXT
            );

            CREATE TABLE derived_variables (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                derived_code TEXT NOT NULL,
                expression TEXT NOT NULL,
                eval_order INT NOT NULL,
                description TEXT
            );

            CREATE TABLE nirf_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                category TEXT NOT NULL,
                academic_year TEXT NOT NULL,
                main_parameter TEXT NOT NULL,
                parameter_code TEXT NOT NULL,
                parameter_name TEXT NOT NULL,
                max_marks REAL NOT NULL,
                main_weight REAL NOT NULL,
                formula_expression TEXT NOT NULL,
                primary_variable TEXT NOT NULL,
                variable_step_size REAL NOT NULL,
                effort_cost_factor REAL NOT NULL,
                benchmark_metric TEXT,
                display_order INT NOT NULL,
                needs_verification INTEGER NOT NULL,
                updated_by_excel_hash TEXT,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE nirf_benchmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                academic_year TEXT NOT NULL,
                category TEXT NOT NULL,
                metric_code TEXT NOT NULL,
                p_max REAL NOT NULL,
                p_90 REAL NOT NULL,
                p_med REAL NOT NULL,
                p_min REAL NOT NULL,
                metric_value REAL NOT NULL,
                is_placeholder INTEGER NOT NULL,
                source_note TEXT
            );

            CREATE TABLE tier_playbook (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                tier_code TEXT NOT NULL,
                current_band TEXT NOT NULL,
                target_band TEXT NOT NULL,
                target_score_min REAL NOT NULL,
                target_score_max REAL NOT NULL,
                max_effort_cost_factor REAL NOT NULL,
                benchmark_anchor TEXT NOT NULL,
                focus_parameters TEXT NOT NULL,
                action_plan_text TEXT NOT NULL
            );

            INSERT INTO formula_sets (id, category, academic_year, version, version_label, status, source_file_sha256, uploaded_by, approved_by, activated_at)
            VALUES (1, 'ENGINEERING', '2026-2027', 1, 'Verified Master', 'ACTIVE', 'sha', 'admin', 'admin2', CURRENT_TIMESTAMP);

            INSERT INTO derived_variables (formula_set_id, derived_code, expression, eval_order, description) VALUES
            (1, 'FSR_val', 'safe_div(F, NT)', 1, 'FSR'),
            (1, 'FRA', 'safe_div(F_PHD, F) * 100.0', 2, 'FRA'),
            (1, 'FQ', '10.0 * min(1.0, FRA / 95.0)', 3, 'FQ'),
            (1, 'FE', '3.0 * min(1.0, safe_div(safe_div(F1, F), 0.33)) + 3.0 * min(1.0, safe_div(safe_div(F2, F), 0.33)) + 4.0 * min(1.0, safe_div(safe_div(F3, F), 0.34))', 4, 'FE'),
            (1, 'FQE_val', 'FQ + FE', 5, 'FQE'),
            (1, 'FRQ', 'max(F, safe_div(NT, 15.0))', 6, 'FRQ');

            INSERT INTO nirf_benchmarks (formula_set_id, academic_year, category, metric_code, p_max, p_90, p_med, p_min, metric_value, is_placeholder) VALUES
            (1, '2026-2027', 'ENGINEERING', 'NP_MAX', 1000.0, 750.0, 400.0, 150.0, 1000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'BC_MAX', 150000.0, 95000.0, 50000.0, 20000.0, 150000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'BO_MAX', 300000.0, 210000.0, 120000.0, 45000.0, 300000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'PU_MAX', 5.0, 3.8, 2.0, 0.8, 5.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'CC_MAX', 25.0, 18.0, 9.5, 3.0, 25.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'TOP25_MAX', 2.0, 1.4, 0.7, 0.2, 2.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'PG_MAX', 50.0, 30.0, 12.0, 3.0, 50.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'PP_MAX', 100.0, 65.0, 28.0, 8.0, 100.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'RF_MAX', 2000000.0, 1200000.0, 500000.0, 100000.0, 2000000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'CF_MAX', 500000.0, 300000.0, 120000.0, 25000.0, 500000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'GMS_MAX', 1800000.0, 1300000.0, 750000.0, 350000.0, 1800000.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'NPHD_MAX', 250.0, 160.0, 80.0, 20.0, 250.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'RD_STATE_MAX', 50.0, 35.0, 20.0, 5.0, 50.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'RD_INT_MAX', 10.0, 6.0, 2.5, 0.5, 10.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'ESC_MAX', 40.0, 25.0, 15.0, 5.0, 40.0, 0),
            (1, '2026-2027', 'ENGINEERING', 'PR_MAX', 100.0, 80.0, 50.0, 15.0, 100.0, 0);

            INSERT INTO nirf_rules (formula_set_id, category, academic_year, main_parameter, parameter_code, parameter_name, max_marks, main_weight, formula_expression, primary_variable, variable_step_size, effort_cost_factor, benchmark_metric, display_order, needs_verification) VALUES
            (1, 'ENGINEERING', '2026-2027', 'TLR', 'SS', 'SS', 20.0, 0.30, '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))', 'NE', 50.0, 1.5, 'NP_MAX', 1, 0),
            (1, 'ENGINEERING', '2026-2027', 'TLR', 'FSR', 'FSR', 30.0, 0.30, '30.0 * min(1.0, safe_div(FSR_val, 0.0667))', 'F', 1.0, 1.2, NULL, 2, 0),
            (1, 'ENGINEERING', '2026-2027', 'TLR', 'FQE', 'FQE', 20.0, 0.30, 'min(20.0, FQE_val)', 'F_PHD', 1.0, 1.5, NULL, 3, 1),
            (1, 'ENGINEERING', '2026-2027', 'TLR', 'FRU', 'FRU', 30.0, 0.30, '7.5 * min(1.0, safe_div(BC, BC_MAX)) + 22.5 * min(1.0, safe_div(BO, BO_MAX))', 'BO', 25000.0, 2.0, 'BO_MAX', 4, 0),
            (1, 'ENGINEERING', '2026-2027', 'RPC', 'PU', 'PU', 35.0, 0.30, '35.0 * min(1.0, safe_div(safe_div(P, FRQ), PU_MAX))', 'P', 25.0, 1.8, 'PU_MAX', 5, 0),
            (1, 'ENGINEERING', '2026-2027', 'RPC', 'QP', 'QP', 40.0, 0.30, '20.0 * min(1.0, safe_div(safe_div(CC, P), CC_MAX)) + 20.0 * min(1.0, safe_div(safe_div(TOP25, FRQ), TOP25_MAX))', 'TOP25', 10.0, 2.5, 'TOP25_MAX', 6, 0),
            (1, 'ENGINEERING', '2026-2027', 'RPC', 'IPR', 'IPR', 15.0, 0.30, '10.0 * min(1.0, safe_div(PG, PG_MAX)) + 5.0 * min(1.0, safe_div(PP, PP_MAX))', 'PG', 2.0, 1.6, 'PG_MAX', 7, 0),
            (1, 'ENGINEERING', '2026-2027', 'RPC', 'FPPP', 'FPPP', 10.0, 0.30, '7.5 * min(1.0, safe_div(RF, RF_MAX)) + 2.5 * min(1.0, safe_div(CF, CF_MAX))', 'RF', 100000.0, 2.2, 'RF_MAX', 8, 1),
            (1, 'ENGINEERING', '2026-2027', 'GO', 'GPH', 'GPH', 40.0, 0.20, '40.0 * min(1.0, NP_pct / 100.0 + NHS_pct / 100.0)', 'NP_pct', 5.0, 1.4, NULL, 9, 0),
            (1, 'ENGINEERING', '2026-2027', 'GO', 'GUE', 'GUE', 15.0, 0.20, '15.0 * min(1.0, NG_pct / 80.0)', 'NG_pct', 5.0, 1.0, NULL, 10, 0),
            (1, 'ENGINEERING', '2026-2027', 'GO', 'GMS', 'GMS', 25.0, 0.20, '25.0 * min(1.0, safe_div(GMS_val, GMS_MAX))', 'GMS_val', 50000.0, 1.8, 'GMS_MAX', 11, 0),
            (1, 'ENGINEERING', '2026-2027', 'GO', 'GPHD', 'GPHD', 20.0, 0.20, '20.0 * min(1.0, safe_div(NPHD, NPHD_MAX))', 'NPHD', 5.0, 2.2, 'NPHD_MAX', 12, 1),
            (1, 'ENGINEERING', '2026-2027', 'OI', 'RD', 'RD', 30.0, 0.10, '25.0 * min(1.0, safe_div(N_state_pct, RD_STATE_MAX)) + 5.0 * min(1.0, safe_div(N_country_pct, RD_INT_MAX))', 'N_state_pct', 5.0, 1.5, 'RD_STATE_MAX', 13, 0),
            (1, 'ENGINEERING', '2026-2027', 'OI', 'WD', 'WD', 30.0, 0.10, '15.0 * min(1.0, SW_pct / 50.0) + 15.0 * min(1.0, FW_pct / 20.0)', 'SW_pct', 3.0, 1.3, NULL, 14, 0),
            (1, 'ENGINEERING', '2026-2027', 'OI', 'ESCS', 'ESCS', 20.0, 0.10, '20.0 * min(1.0, safe_div(ESC_pct, ESC_MAX))', 'ESC_pct', 5.0, 1.1, 'ESC_MAX', 15, 1),
            (1, 'ENGINEERING', '2026-2027', 'OI', 'PCS', 'PCS', 20.0, 0.10, 'min(20.0, PCS_score)', 'PCS_score', 2.0, 0.8, NULL, 16, 1),
            (1, 'ENGINEERING', '2026-2027', 'PR', 'PR', 'PR', 100.0, 0.10, '100.0 * min(1.0, safe_div(PR_survey_val, PR_MAX))', 'PR_survey_val', 5.0, 3.0, 'PR_MAX', 17, 0);
        ");

        // Seed test user
        $stmt = $this->corePdo->prepare("
            INSERT INTO users (institution_code, email, password_hash, role, is_active, created_at)
            VALUES ('INST001', 'admin@institute.edu', :hash, 'ADMIN', 1, datetime('now'))
        ");
        $stmt->execute(['hash' => password_hash('Password123!', PASSWORD_BCRYPT)]);

        $this->app = App::create();
        $container = $this->app->getContainer();
        /** @var DatabaseManager $dbManager */
        $dbManager = $container->get(DatabaseManager::class);
        $dbManager->setConnectionsForTesting($this->formulaPdo, $this->formulaPdo, $this->corePdo);
    }

    public function testScreen1SignInReturns200WithSecurityHeaders(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('<title>Sign In</title>', $body);
        $this->assertStringContainsString('#B91C1C', $body);
        $this->assertStringContainsString('type="email"', $body);
        $this->assertStringContainsString('type="password"', $body);
        // Security headers
        $this->assertStringContainsString('DENY', $resp->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testProtectedScreensRedirectUnauthenticatedUsersToSignIn(): void
    {
        $screens = ['/landing', '/input', '/analysis', '/report/pdf'];

        foreach ($screens as $screen) {
            $req = (new ServerRequestFactory())->createServerRequest('GET', $screen);
            $resp = $this->app->handle($req);

            $this->assertSame(302, $resp->getStatusCode(), "Screen {$screen} must redirect unauthenticated users");
            $this->assertSame('/', $resp->getHeaderLine('Location'));
        }
    }

    public function testSignInWithInvalidCredentialsReturnsGenericMessage(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/api/signin')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'email' => 'admin@institute.edu',
                'password' => 'WrongPassword!'
            ], JSON_THROW_ON_ERROR)));

        $resp = $this->app->handle($req);

        $this->assertSame(401, $resp->getStatusCode());
        $body = (array) json_decode((string) $resp->getBody(), true);
        $this->assertSame('Invalid credentials', $body['error']);
    }

    public function testSignInWithValidCredentialsSucceedsAndSetsCookie(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/api/signin')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'email' => 'admin@institute.edu',
                'password' => 'Password123!'
            ], JSON_THROW_ON_ERROR)));

        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $body = (array) json_decode((string) $resp->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('/landing', $body['redirect']);

        $cookieHeader = $resp->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('nirf_session=', $cookieHeader);
        $this->assertStringContainsString('HttpOnly', $cookieHeader);
        $this->assertStringContainsString('SameSite=Strict', $cookieHeader);

        preg_match('/nirf_session=([^;]+)/', $cookieHeader, $matches);
        return $matches[1] ?? '';
    }

    public function testScreen2LandingLoadsWithSession(): void
    {
        $sessionId = $this->testSignInWithValidCredentialsSucceedsAndSetsCookie();

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/landing')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('<title>Institutional Ranking Analysis</title>', $body);
        $this->assertStringContainsString('Continue', $body);
        $this->assertStringContainsString('id="demo-fsr"', $body);
    }

    public function testScreen3DataInputLoadsAndTemplateDownloadWorks(): void
    {
        $sessionId = $this->testSignInWithValidCredentialsSucceedsAndSetsCookie();

        // 1. Data Input Screen
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/input')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('<title>Data Input</title>', $body);
        $this->assertStringContainsString('Upload Excel', $body);
        $this->assertStringContainsString('Download Excel Template', $body);
        $this->assertStringContainsString('Analyse', $body);

        // 2. Excel Template Download
        $tplReq = (new ServerRequestFactory())->createServerRequest('GET', '/api/template/excel')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $tplResp = $this->app->handle($tplReq);

        $this->assertSame(200, $tplResp->getStatusCode());
        $this->assertStringContainsString('spreadsheetml', $tplResp->getHeaderLine('Content-Type'));
        $this->assertGreaterThan(1000, strlen((string) $tplResp->getBody()));
    }

    public function testScreen4AnalyseAndPdfReport(): void
    {
        $sessionId = $this->testSignInWithValidCredentialsSucceedsAndSetsCookie();

        // 1. POST /api/analyse with all 29 fields
        $payload = [
            'institution_name' => 'Apex Institute of Technology',
            'NT' => 2400, 'NE' => 2300, 'NP' => 250, 'F' => 150, 'F_PHD' => 100,
            'F1' => 50, 'F2' => 60, 'F3' => 40, 'BC' => 45000, 'BO' => 120000,
            'P' => 300, 'CC' => 2800, 'TOP25' => 90, 'PG' => 8, 'PP' => 25,
            'RF' => 600000, 'CF' => 100000, 'NP_pct' => 82, 'NHS_pct' => 10,
            'NG_pct' => 85, 'GMS_val' => 750000, 'NPHD' => 45, 'N_state_pct' => 25,
            'N_country_pct' => 2, 'SW_pct' => 38, 'FW_pct' => 22, 'ESC_pct' => 20,
            'PCS_score' => 18, 'PR_survey_val' => 45
        ];

        $analyseReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/analyse')
            ->withCookieParams(['nirf_session' => $sessionId])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        $analyseResp = $this->app->handle($analyseReq);
        $this->assertSame(200, $analyseResp->getStatusCode());
        $analyseData = (array) json_decode((string) $analyseResp->getBody(), true);
        $this->assertTrue($analyseData['success']);
        $this->assertSame('/analysis', $analyseData['redirect']);

        // 2. GET /analysis
        $analysisReq = (new ServerRequestFactory())->createServerRequest('GET', '/analysis')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $analysisResp = $this->app->handle($analysisReq);

        $this->assertSame(200, $analysisResp->getStatusCode());
        $analysisBody = (string) $analysisResp->getBody();
        $this->assertStringContainsString('<title>Analysis and Verdict</title>', $analysisBody);
        $this->assertStringContainsString('Calculated Total Score', $analysisBody);
        $this->assertStringContainsString('Download PDF', $analysisBody);
        $this->assertStringContainsString('Start New Analysis', $analysisBody);

        // 3. GET /report/pdf
        $pdfReq = (new ServerRequestFactory())->createServerRequest('GET', '/report/pdf')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $pdfResp = $this->app->handle($pdfReq);

        $this->assertSame(200, $pdfResp->getStatusCode());
        $pdfBody = (string) $pdfResp->getBody();
        $this->assertStringContainsString('Institutional Accreditation Assessment Report', $pdfBody);
        $this->assertStringContainsString('Apex Institute of Technology', $pdfBody);

        // 4. POST /api/reset
        $resetReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/reset')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $resetResp = $this->app->handle($resetReq);
        $this->assertSame(200, $resetResp->getStatusCode());
        $resetData = (array) json_decode((string) $resetResp->getBody(), true);
        $this->assertSame('/input', $resetData['redirect']);
    }

    public function testMastersZipDownloadReturns200WithZipContent(): void
    {
        $sessionId = $this->testSignInWithValidCredentialsSucceedsAndSetsCookie();

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/template/masters')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $resp = $this->app->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('application/zip', $resp->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="NIRF_Master_Workbooks.zip"', $resp->getHeaderLine('Content-Disposition'));
        $this->assertGreaterThan(1000, strlen((string) $resp->getBody()));
    }

    public function testThreeWorkbookIngestionAndDatasetFreezeWorkflow(): void
    {
        $sessionId = $this->testSignInWithValidCredentialsSucceedsAndSetsCookie();

        // 1. Ingest Sample Master Workbooks
        $uploadReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/upload/workbooks?sample=1')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $uploadResp = $this->app->handle($uploadReq);

        $this->assertSame(200, $uploadResp->getStatusCode());
        $uploadData = (array) json_decode((string) $uploadResp->getBody(), true);
        $this->assertTrue($uploadData['success']);
        $this->assertNotEmpty($uploadData['kpis']);
        $this->assertGreaterThan(0, $uploadData['counts']['faculty']);
        $this->assertGreaterThan(0, $uploadData['counts']['students']);

        // 2. Resolve an issue if present
        if (!empty($uploadData['issues'])) {
            $resolveReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/issues/resolve')
                ->withCookieParams(['nirf_session' => $sessionId])
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode([
                    'index' => 0,
                    'note' => 'IQAC Committee audit verified'
                ], JSON_THROW_ON_ERROR)));
            $resolveResp = $this->app->handle($resolveReq);
            $this->assertSame(200, $resolveResp->getStatusCode());
            $resolveData = (array) json_decode((string) $resolveResp->getBody(), true);
            $this->assertTrue($resolveData['success']);
            $this->assertSame('RESOLVED', $resolveData['issues'][0]['status']);
        }

        // 3. Freeze Dataset
        $freezeReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/dataset/freeze')
            ->withCookieParams(['nirf_session' => $sessionId])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'institution_name' => 'Ajeenkya DY Patil University'
            ], JSON_THROW_ON_ERROR)));
        $freezeResp = $this->app->handle($freezeReq);

        $this->assertSame(200, $freezeResp->getStatusCode());
        $freezeData = (array) json_decode((string) $freezeResp->getBody(), true);
        $this->assertTrue($freezeData['success']);
        $this->assertNotEmpty($freezeData['frozen_hash']);
        $this->assertGreaterThan(0, $freezeData['total_score']);
        $this->assertSame('/analysis', $freezeData['redirect']);

        // 4. Test What-If Simulation
        $simReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/simulate')
            ->withCookieParams(['nirf_session' => $sessionId])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'mode' => 'CLIMBER'
            ], JSON_THROW_ON_ERROR)));
        $simResp = $this->app->handle($simReq);

        $this->assertSame(200, $simResp->getStatusCode());
        $simData = (array) json_decode((string) $simResp->getBody(), true);
        $this->assertTrue($simData['success']);
        $this->assertArrayHasKey('baseline_score', $simData['simulation']);
        $this->assertArrayHasKey('optimized_score', $simData['simulation']);

        // 5. Test AI Strategy
        $aiReq = (new ServerRequestFactory())->createServerRequest('POST', '/api/ai/strategy')
            ->withCookieParams(['nirf_session' => $sessionId]);
        $aiResp = $this->app->handle($aiReq);

        $this->assertSame(200, $aiResp->getStatusCode());
        $aiData = (array) json_decode((string) $aiResp->getBody(), true);
        $this->assertTrue($aiData['success']);
        $this->assertArrayHasKey('number_guard', $aiData['ai_strategy']);
        $this->assertSame('NUMBER_GUARD_CERTIFIED', $aiData['ai_strategy']['number_guard']['status']);
    }
}
