<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\DatabaseManager;
use App\Excel\FormulaWorkbookManager;
use App\Excel\TemplateGenerator;
use App\Formula\FormulaLinter;
use App\Formula\SafeExpressionEvaluator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FormulaWorkbookManagerTest extends TestCase
{
    private PDO $formulaPdo;
    private DatabaseManager $dbManager;
    private FormulaWorkbookManager $manager;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/nirf_test_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        // Setup in-memory SQLite schema for formula database
        $this->formulaPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->createFormulaSchema($this->formulaPdo);

        $corePdo = new PDO('sqlite::memory:');
        $this->dbManager = new DatabaseManager([]);
        $this->dbManager->setConnectionsForTesting($this->formulaPdo, $this->formulaPdo, $corePdo);

        $evaluator = new SafeExpressionEvaluator();
        $linter = new FormulaLinter($evaluator);
        $this->manager = new FormulaWorkbookManager($this->dbManager, $linter, $evaluator);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testGenerateAndImportValidWorkbook(): void
    {
        $xlsxPath = $this->tempDir . '/valid_template.xlsx';
        TemplateGenerator::generate($xlsxPath);

        $this->assertFileExists($xlsxPath);

        $importResult = $this->manager->importWorkbook($xlsxPath, 'admin_uploader_1');

        $this->assertEquals('VALIDATED', $importResult['status']);
        $this->assertGreaterThan(0, $importResult['formula_set_id']);

        $setId = $importResult['formula_set_id'];

        // Four-Eyes check: uploader cannot approve!
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Four-Eyes Violation');
        $this->manager->approveAndActivate($setId, 'admin_uploader_1');
    }

    public function testGeneratedTemplateMarksAllRulesAndBenchmarksUnverified(): void
    {
        $xlsxPath = $this->tempDir . '/development_template.xlsx';
        TemplateGenerator::generate($xlsxPath);
        $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($xlsxPath);

        $formulas = $spreadsheet->getSheetByName('NIRF_Formulas');
        $benchmarks = $spreadsheet->getSheetByName('Benchmarks');

        $this->assertSame(17, $formulas->getHighestRow() - 1);
        $this->assertSame(16, $benchmarks->getHighestRow() - 1);
        for ($row = 2; $row <= $formulas->getHighestRow(); $row++) {
            $this->assertSame('Y', $formulas->getCell("N{$row}")->getValue());
        }
        for ($row = 2; $row <= $benchmarks->getHighestRow(); $row++) {
            $this->assertSame('Y', $benchmarks->getCell("G{$row}")->getValue());
        }
    }

    public function testApproveAndActivateWithSecondAdmin(): void
    {
        $xlsxPath = $this->tempDir . '/valid_template.xlsx';
        TemplateGenerator::generate($xlsxPath);
        $this->addTestCitationsAndMarkRulesVerified($xlsxPath);
        $importResult = $this->manager->importWorkbook($xlsxPath, 'admin_uploader_1');
        $setId = $importResult['formula_set_id'];

        $activationResult = $this->manager->approveAndActivate($setId, 'admin_approver_2');

        $this->assertEquals('ACTIVE', $activationResult['status']);
        $this->assertEquals('admin_approver_2', $activationResult['approved_by']);
    }

    public function testAcknowledgementCannotActivateUnverifiedFormulaRules(): void
    {
        $xlsxPath = $this->tempDir . '/unverified_template.xlsx';
        TemplateGenerator::generate($xlsxPath);
        $this->addTestCitationsAndMarkBenchmarksSourced($xlsxPath);
        $importResult = $this->manager->importWorkbook($xlsxPath, 'admin_uploader_1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Acknowledging the risk does not replace verification.');
        $this->manager->approveAndActivate($importResult['formula_set_id'], 'admin_approver_2', true);
    }

    public function testTop100IngestionRequiresOneHundredRowsAndRecordsSource(): void
    {
        $xlsxPath = $this->tempDir . '/cohort_template.xlsx';
        TemplateGenerator::generate($xlsxPath);
        $importResult = $this->manager->importWorkbook($xlsxPath, 'admin_uploader_1');
        $setId = $importResult['formula_set_id'];

        $stmt = $this->formulaPdo->prepare('SELECT metric_code FROM nirf_benchmarks WHERE formula_set_id = :id');
        $stmt->execute([':id' => $setId]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $cohort = [];
        for ($value = 1; $value <= 100; $value++) {
            $cohort[] = array_fill_keys($codes, $value);
        }

        $this->manager->ingestTop100($setId, $cohort, 'https://example.invalid/test-cohort.csv');

        $benchmark = $this->formulaPdo->query(
            "SELECT p_min, p_med, p_90, p_max, is_placeholder, source_note
             FROM nirf_benchmarks WHERE formula_set_id = {$setId} ORDER BY metric_code LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1.0, (float) $benchmark['p_min']);
        $this->assertSame(50.5, (float) $benchmark['p_med']);
        $this->assertSame(90.1, (float) $benchmark['p_90']);
        $this->assertSame(100.0, (float) $benchmark['p_max']);
        $this->assertFalse((bool) $benchmark['is_placeholder']);
        $this->assertStringContainsString('https://example.invalid/test-cohort.csv', $benchmark['source_note']);
        $this->assertMatchesRegularExpression('/cohort SHA-256: [a-f0-9]{64}$/', $benchmark['source_note']);
    }

    public function testTop100IngestionRejectsShortCohort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 100 cohort rows');
        $this->manager->ingestTop100(1, [], 'https://example.invalid/test-cohort.csv');
    }

    public function testRejectsCellStartingWithEquals(): void
    {
        $xlsxPath = $this->tempDir . '/bad_formula.xlsx';
        TemplateGenerator::generate($xlsxPath);

        // Corrupt a cell with an actual Excel formula
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($xlsxPath);
        $spreadsheet->getSheetByName('Meta')->setCellValue('B2', '=SUM(1,2)');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($xlsxPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Real Excel formula');
        $this->manager->importWorkbook($xlsxPath, 'admin_uploader_1');
    }

    public function testRejectsInvalidMagicBytes(): void
    {
        $fakePath = $this->tempDir . '/fake.xlsx';
        file_put_contents($fakePath, "NOT_A_ZIP_FILE_CONTENT");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('magic bytes mismatch');
        $this->manager->importWorkbook($fakePath, 'admin_uploader_1');
    }

    public function testFormulaInjectionDefenseOnExport(): void
    {
        $escaped = $this->manager->escapeFormulaInjection('=cmd|/c calc!A0');
        $this->assertEquals("'=cmd|/c calc!A0", $escaped);

        $escapedPlus = $this->manager->escapeFormulaInjection('+12345');
        $this->assertEquals("'+12345", $escapedPlus);

        $normal = $this->manager->escapeFormulaInjection('Normal Text');
        $this->assertEquals('Normal Text', $normal);
    }

    private function addTestCitationsAndMarkBenchmarksSourced(string $path): void
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($path);
        $spreadsheet->getSheetByName('Meta')->setCellValue(
            'E2',
            'Test-only fixture citation: https://example.invalid/methodology'
        );

        $benchmarks = $spreadsheet->getSheetByName('Benchmarks');
        for ($row = 2; $row <= $benchmarks->getHighestRow(); $row++) {
            $benchmarks->setCellValue("G{$row}", 'N');
            $benchmarks->setCellValue(
                "H{$row}",
                'Test-only fixture citation: https://example.invalid/benchmark'
            );
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function addTestCitationsAndMarkRulesVerified(string $path): void
    {
        $this->addTestCitationsAndMarkBenchmarksSourced($path);

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($path);
        $formulas = $spreadsheet->getSheetByName('NIRF_Formulas');
        for ($row = 2; $row <= $formulas->getHighestRow(); $row++) {
            $formulas->setCellValue("N{$row}", 'N');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function createFormulaSchema(PDO $pdo): void
    {
        $pdo->exec("
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

            CREATE TABLE validation_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                rule_id TEXT NOT NULL,
                expression TEXT NOT NULL,
                error_message TEXT NOT NULL,
                severity TEXT NOT NULL
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

            CREATE TABLE test_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL,
                case_id TEXT NOT NULL,
                input_json TEXT NOT NULL,
                expected_parameter_scores_json TEXT NOT NULL,
                expected_total REAL NOT NULL,
                tolerance REAL NOT NULL
            );

            CREATE TABLE formula_audit_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id TEXT NOT NULL,
                excel_filename TEXT NOT NULL,
                file_sha256 TEXT NOT NULL,
                formula_set_id INT NOT NULL,
                previous_rules_snapshot TEXT,
                new_rules_snapshot TEXT NOT NULL,
                applied_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE formula_import_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT,
                validation_report TEXT NOT NULL,
                passed INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }
}
