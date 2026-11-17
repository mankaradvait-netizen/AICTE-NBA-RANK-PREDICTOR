<?php

declare(strict_types=1);

namespace App\Excel;

use App\Config\DatabaseManager;
use App\Formula\FormulaLinter;
use App\Formula\SafeExpressionEvaluator;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use InvalidArgumentException;

class FormulaWorkbookManager
{
    private const MAX_FILE_SIZE = 5242880; // 5 MB
    private const ZIP_MAGIC = "\x50\x4B\x03\x04";

    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly FormulaLinter $linter,
        private readonly SafeExpressionEvaluator $evaluator
    ) {}

    /**
     * Imports, hardens, validates, dry-runs, and saves an Excel workbook as a new formula set
     * @return array{formula_set_id: int, status: string, report: array<string, mixed>}
     */
    public function importWorkbook(string $filePath, string $adminUserId): array
    {
        // 1. File Upload Hardening
        $this->validateFileSecurity($filePath);

        $fileSha256 = hash_file('sha256', $filePath);
        if ($fileSha256 === false) {
            throw new RuntimeException("Unable to compute SHA-256 digest of uploaded file.");
        }

        // 2. Load Workbook with PhpSpreadsheet (external entities disabled)
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // 3. Scan for Excel Formula Injection in cells (Reject any cell starting with '=')
        $this->scanForFormulaInjection($spreadsheet);

        // 4. Validate Sheets Presence
        $requiredSheets = ['Meta', 'Inputs', 'Validation_Rules', 'Derived', 'NIRF_Formulas', 'Benchmarks', 'Tiers', 'TestCases'];
        $errors = [];
        $warnings = [];

        foreach ($requiredSheets as $sName) {
            if ($spreadsheet->getSheetByName($sName) === null) {
                $errors[] = ['sheet' => 'Workbook', 'row' => 0, 'col' => '', 'message' => "Required sheet '{$sName}' is missing."];
            }
        }
        if (!empty($errors)) {
            return $this->buildFailureResult($errors, $warnings);
        }

        // 5. Parse Meta Sheet
        $metaSheet = $spreadsheet->getSheetByName('Meta');
        $metaRows = $metaSheet ? $metaSheet->toArray() : [];
        $metaData = $this->parseMeta($metaRows, $errors);

        // 6. Parse Inputs Sheet
        $inputSheet = $spreadsheet->getSheetByName('Inputs');
        $inputs = $this->parseInputs($inputSheet ? $inputSheet->toArray() : [], $errors);

        // 7. Parse Validation Rules
        $valSheet = $spreadsheet->getSheetByName('Validation_Rules');
        $valRules = $this->parseValidationRules($valSheet ? $valSheet->toArray() : [], $inputs, $errors);

        // 8. Parse Derived Variables
        $derivedSheet = $spreadsheet->getSheetByName('Derived');
        $derivedVars = $this->parseDerived($derivedSheet ? $derivedSheet->toArray() : [], $inputs, $errors);

        // 9. Parse Benchmarks
        $bmSheet = $spreadsheet->getSheetByName('Benchmarks');
        $benchmarks = $this->parseBenchmarks($bmSheet ? $bmSheet->toArray() : [], $errors);

        // 10. Parse NIRF Formulas
        $formulaSheet = $spreadsheet->getSheetByName('NIRF_Formulas');
        $rules = $this->parseFormulas($formulaSheet ? $formulaSheet->toArray() : [], $inputs, $derivedVars, $benchmarks, $errors, $warnings);

        // 11. Parse Tiers
        $tierSheet = $spreadsheet->getSheetByName('Tiers');
        $tiers = $this->parseTiers($tierSheet ? $tierSheet->toArray() : [], $errors);

        // 12. Parse TestCases
        $testSheet = $spreadsheet->getSheetByName('TestCases');
        $testCases = $this->parseTestCases($testSheet ? $testSheet->toArray() : [], $errors);

        // If structural errors exist, reject before dry run
        if (!empty($errors)) {
            return $this->buildFailureResult($errors, $warnings);
        }

        // 13. Dry-Run Execution against all Test Cases
        $this->executeDryRun($testCases, $inputs, $derivedVars, $benchmarks, $rules, $errors);
        if (!empty($errors)) {
            return $this->buildFailureResult($errors, $warnings);
        }

        // 14. Transactional Database Insertion
        $pdo = $this->dbManager->getFormulaAdmin();
        $pdo->beginTransaction();

        try {
            // Determine version number for this (category, academic_year)
            $vStmt = $pdo->prepare("
                SELECT COALESCE(MAX(version), 0) + 1 AS next_v 
                FROM formula_sets 
                WHERE category = :cat AND academic_year = :year
            ");
            $vStmt->execute([':cat' => $metaData['category'], ':year' => $metaData['academic_year']]);
            $nextVersion = (int) $vStmt->fetchColumn();

            $setStmt = $pdo->prepare("
                INSERT INTO formula_sets (
                    category, academic_year, version, version_label, status, 
                    source_file_sha256, uploaded_by, notes
                ) VALUES (
                    :category, :academic_year, :version, :version_label, 'VALIDATED', 
                    :source_file_sha256, :uploaded_by, :notes
                )
            ");
            $setStmt->execute([
                ':category' => $metaData['category'],
                ':academic_year' => $metaData['academic_year'],
                ':version' => $nextVersion,
                ':version_label' => $metaData['version_label'] ?? "v{$nextVersion}",
                ':source_file_sha256' => $fileSha256,
                ':uploaded_by' => $adminUserId,
                ':notes' => $metaData['notes'] ?? null,
            ]);
            $setId = (int) $pdo->lastInsertId();

            // Insert Input Definitions
            $inStmt = $pdo->prepare("
                INSERT INTO input_definitions (
                    formula_set_id, input_code, label, unit, data_type, min_value, max_value, required, main_parameter, help_text
                ) VALUES (:set_id, :code, :label, :unit, :type, :min, :max, :req, :param, :help)
            ");
            foreach ($inputs as $in) {
                $inStmt->execute([
                    ':set_id' => $setId,
                    ':code' => $in['input_code'],
                    ':label' => $in['label'],
                    ':unit' => $in['unit'],
                    ':type' => $in['data_type'],
                    ':min' => $in['min_value'],
                    ':max' => $in['max_value'],
                    ':req' => $in['required'] ? 1 : 0,
                    ':param' => $in['main_parameter'],
                    ':help' => $in['help_text'],
                ]);
            }

            // Insert Validation Rules
            $vrStmt = $pdo->prepare("
                INSERT INTO validation_rules (formula_set_id, rule_id, expression, error_message, severity)
                VALUES (:set_id, :rid, :expr, :msg, :sev)
            ");
            foreach ($valRules as $vr) {
                $vrStmt->execute([
                    ':set_id' => $setId,
                    ':rid' => $vr['rule_id'],
                    ':expr' => $vr['expression'],
                    ':msg' => $vr['error_message'],
                    ':sev' => $vr['severity'],
                ]);
            }

            // Insert Derived Variables
            $dvStmt = $pdo->prepare("
                INSERT INTO derived_variables (formula_set_id, derived_code, expression, eval_order, description)
                VALUES (:set_id, :code, :expr, :ord, :desc)
            ");
            foreach ($derivedVars as $dv) {
                $dvStmt->execute([
                    ':set_id' => $setId,
                    ':code' => $dv['derived_code'],
                    ':expr' => $dv['expression'],
                    ':ord' => $dv['eval_order'],
                    ':desc' => $dv['description'],
                ]);
            }

            // Insert Benchmarks
            $bmStmt = $pdo->prepare("
                INSERT INTO nirf_benchmarks (
                    formula_set_id, academic_year, category, metric_code, p_max, p_90, p_med, p_min, metric_value, is_placeholder, source_note
                ) VALUES (:set_id, :year, :cat, :code, :p_max, :p_90, :p_med, :p_min, :val, :is_ph, :note)
            ");
            foreach ($benchmarks as $bm) {
                $bmStmt->execute([
                    ':set_id' => $setId,
                    ':year' => $metaData['academic_year'],
                    ':cat' => $metaData['category'],
                    ':code' => $bm['metric_code'],
                    ':p_max' => $bm['p_max'],
                    ':p_90' => $bm['p_90'],
                    ':p_med' => $bm['p_med'],
                    ':p_min' => $bm['p_min'],
                    ':val' => $bm['metric_value'],
                    ':is_ph' => $bm['is_placeholder'] ? 1 : 0,
                    ':note' => $bm['source_note'],
                ]);
            }

            // Insert NIRF Rules
            $rStmt = $pdo->prepare("
                INSERT INTO nirf_rules (
                    formula_set_id, category, academic_year, main_parameter, parameter_code, parameter_name,
                    max_marks, main_weight, formula_expression, primary_variable, variable_step_size,
                    effort_cost_factor, benchmark_metric, display_order, needs_verification, updated_by_excel_hash
                ) VALUES (
                    :set_id, :cat, :year, :param, :code, :name, :max, :wt, :expr, :pvar, :step, :effort, :bm, :ord, :nv, :hash
                )
            ");
            foreach ($rules as $r) {
                $rStmt->execute([
                    ':set_id' => $setId,
                    ':cat' => $metaData['category'],
                    ':year' => $metaData['academic_year'],
                    ':param' => $r['main_parameter'],
                    ':code' => $r['parameter_code'],
                    ':name' => $r['parameter_name'],
                    ':max' => $r['max_marks'],
                    ':wt' => $r['main_weight'],
                    ':expr' => $r['formula_expression'],
                    ':pvar' => $r['primary_variable'],
                    ':step' => $r['variable_step_size'],
                    ':effort' => $r['effort_cost_factor'],
                    ':bm' => $r['benchmark_metric'],
                    ':ord' => $r['display_order'],
                    ':nv' => $r['needs_verification'] ? 1 : 0,
                    ':hash' => $fileSha256,
                ]);
            }

            // Insert Tiers
            $tStmt = $pdo->prepare("
                INSERT INTO tier_playbook (
                    formula_set_id, tier_code, current_band, target_band, target_score_min, target_score_max,
                    max_effort_cost_factor, benchmark_anchor, focus_parameters, action_plan_text
                ) VALUES (:set_id, :code, :curr, :target, :smin, :smax, :effort, :anchor, :focus, :plan)
            ");
            foreach ($tiers as $t) {
                $tStmt->execute([
                    ':set_id' => $setId,
                    ':code' => $t['tier_code'],
                    ':curr' => $t['current_band'],
                    ':target' => $t['target_band'],
                    ':smin' => $t['target_score_min'],
                    ':smax' => $t['target_score_max'],
                    ':effort' => $t['max_effort_cost_factor'],
                    ':anchor' => $t['benchmark_anchor'],
                    ':focus' => $t['focus_parameters'],
                    ':plan' => $t['action_plan_text'],
                ]);
            }

            // Insert Test Cases
            $tcStmt = $pdo->prepare("
                INSERT INTO test_cases (
                    formula_set_id, case_id, input_json, expected_parameter_scores_json, expected_total, tolerance
                ) VALUES (:set_id, :cid, :inj, :expj, :tot, :tol)
            ");
            foreach ($testCases as $tc) {
                $tcStmt->execute([
                    ':set_id' => $setId,
                    ':cid' => $tc['case_id'],
                    ':inj' => is_string($tc['input_json']) ? $tc['input_json'] : json_encode($tc['input_json']),
                    ':expj' => is_string($tc['expected_parameter_scores_json']) ? $tc['expected_parameter_scores_json'] : json_encode($tc['expected_parameter_scores_json']),
                    ':tot' => $tc['expected_total'],
                    ':tol' => $tc['tolerance'],
                ]);
            }

            // Write Formula Audit History Diff
            $historyStmt = $pdo->prepare("
                INSERT INTO formula_audit_history (
                    admin_user_id, excel_filename, file_sha256, formula_set_id, previous_rules_snapshot, new_rules_snapshot
                ) VALUES (:user_id, :file, :sha, :set_id, :prev, :new)
            ");
            $historyStmt->execute([
                ':user_id' => $adminUserId,
                ':file' => basename($filePath),
                ':sha' => $fileSha256,
                ':set_id' => $setId,
                ':prev' => json_encode([]),
                ':new' => json_encode($rules),
            ]);

            // Save Import Log
            $logStmt = $pdo->prepare("
                INSERT INTO formula_import_log (formula_set_id, validation_report, passed) 
                VALUES (:set_id, :report, TRUE)
            ");
            $report = ['errors' => [], 'warnings' => $warnings, 'test_cases_run' => count($testCases)];
            $logStmt->execute([
                ':set_id' => $setId,
                ':report' => json_encode($report),
            ]);

            $pdo->commit();

            return [
                'formula_set_id' => $setId,
                'status' => 'VALIDATED',
                'report' => $report,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Database error saving formula set: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Four-Eyes Approval and Activation Workflow
     */
    public function approveAndActivate(int $setId, string $approverUserId, bool $acknowledgeUnverified = false): array
    {
        $pdo = $this->dbManager->getFormulaAdmin();

        $stmt = $pdo->prepare("SELECT * FROM formula_sets WHERE id = :id");
        $stmt->execute([':id' => $setId]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$set) {
            throw new RuntimeException("Formula set {$setId} not found.");
        }

        // 1. Four-Eyes Principle: Uploader cannot approve their own set!
        if ($set['uploaded_by'] === $approverUserId) {
            throw new RuntimeException("Four-Eyes Violation: The administrator who uploaded the workbook cannot approve and activate it.");
        }

        // 2. Status Gate
        if ($set['status'] !== 'VALIDATED') {
            throw new RuntimeException("Formula set must be in VALIDATED status to activate (current: {$set['status']}).");
        }

        if (
            $set['uploaded_by'] === 'SYSTEM_INIT'
            || !is_string($set['source_file_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/i', $set['source_file_sha256'])
            || !is_string($set['notes'])
            || !preg_match('~https://\S+~i', $set['notes'])
        ) {
            throw new RuntimeException("Activation Blocked: Formula set must have an uploaded source file and an HTTPS methodology citation in its notes.");
        }

        // 3. Benchmark Gate: Require populated, sourced empirical benchmarks.
        $bmStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM nirf_benchmarks
            WHERE formula_set_id = :id
              AND (
                  is_placeholder = TRUE
                  OR source_note IS NULL
                  OR TRIM(source_note) = ''
                  OR LOWER(source_note) LIKE 'unverified%'
                  OR LOWER(source_note) NOT LIKE '%https://%'
              )
        ");
        $bmStmt->execute([':id' => $setId]);
        if ((int) $bmStmt->fetchColumn() > 0) {
            throw new RuntimeException("Activation Blocked: Every benchmark must be non-placeholder and include an HTTPS source citation before activation.");
        }

        // 4. Verification Gate: An acknowledgement cannot substitute for verification.
        $rStmt = $pdo->prepare("SELECT COUNT(*) FROM nirf_rules WHERE formula_set_id = :id AND needs_verification = TRUE");
        $rStmt->execute([':id' => $setId]);
        if ((int) $rStmt->fetchColumn() > 0) {
            $acknowledgement = $acknowledgeUnverified
                ? ' Acknowledging the risk does not replace verification.'
                : '';
            throw new RuntimeException("Activation Blocked: Formula set contains rules flagged as 'needs_verification'; verify them against the authoritative methodology first.{$acknowledgement}");
        }

        $pdo->beginTransaction();
        try {
            // Archive current active set for this category and academic_year
            $archiveStmt = $pdo->prepare("
                UPDATE formula_sets 
                SET status = 'ARCHIVED' 
                WHERE category = :cat AND academic_year = :year AND status = 'ACTIVE'
            ");
            $archiveStmt->execute([':cat' => $set['category'], ':year' => $set['academic_year']]);

            // Activate new set
            $activateStmt = $pdo->prepare("
                UPDATE formula_sets 
                SET status = 'ACTIVE', approved_by = :approver, activated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $activateStmt->execute([':approver' => $approverUserId, ':id' => $setId]);

            $pdo->commit();
            return ['formula_set_id' => $setId, 'status' => 'ACTIVE', 'approved_by' => $approverUserId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Activation failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rollback to a previous archived formula set
     */
    public function rollback(int $targetSetId, string $adminUserId): array
    {
        $pdo = $this->dbManager->getFormulaAdmin();

        $stmt = $pdo->prepare("SELECT * FROM formula_sets WHERE id = :id");
        $stmt->execute([':id' => $targetSetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target || $target['status'] !== 'ARCHIVED') {
            throw new RuntimeException("Rollback target set must exist and be in ARCHIVED status.");
        }

        $pdo->beginTransaction();
        try {
            // Archive currently active set
            $archiveCurrent = $pdo->prepare("
                UPDATE formula_sets 
                SET status = 'ARCHIVED' 
                WHERE category = :cat AND academic_year = :year AND status = 'ACTIVE'
            ");
            $archiveCurrent->execute([':cat' => $target['category'], ':year' => $target['academic_year']]);

            // Re-activate target set
            $reactivate = $pdo->prepare("
                UPDATE formula_sets 
                SET status = 'ACTIVE', approved_by = :admin, activated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $reactivate->execute([':admin' => $adminUserId, ':id' => $targetSetId]);

            $pdo->commit();
            return ['formula_set_id' => $targetSetId, 'status' => 'ACTIVE', 'rolled_back_by' => $adminUserId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Rollback failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $rawData
     */
    public function ingestTop100(int $setId, array $rawData, string $sourceReference): void
    {
        if (count($rawData) !== 100) {
            throw new InvalidArgumentException('Benchmark ingestion requires exactly 100 cohort rows.');
        }

        $parsedReference = parse_url($sourceReference);
        if (
            filter_var($sourceReference, FILTER_VALIDATE_URL) === false
            || !is_array($parsedReference)
            || strtolower((string) ($parsedReference['scheme'] ?? '')) !== 'https'
            || empty($parsedReference['host'])
        ) {
            throw new InvalidArgumentException('Benchmark source reference must be a valid HTTPS URL.');
        }

        $pdo = $this->dbManager->getFormulaAdmin();
        $pdo->beginTransaction();

        try {
            $setStmt = $pdo->prepare('SELECT status FROM formula_sets WHERE id = :id');
            $setStmt->execute([':id' => $setId]);
            if ($setStmt->fetchColumn() !== 'VALIDATED') {
                throw new RuntimeException('Benchmarks can only be ingested into an existing VALIDATED formula set.');
            }

            $codesStmt = $pdo->prepare('SELECT metric_code FROM nirf_benchmarks WHERE formula_set_id = :id ORDER BY metric_code');
            $codesStmt->execute([':id' => $setId]);
            $benchmarkCodes = array_map('strval', $codesStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($benchmarkCodes === []) {
                throw new RuntimeException('Formula set contains no benchmark metrics to ingest.');
            }

            $metricValues = array_fill_keys($benchmarkCodes, []);
            foreach ($rawData as $rowIndex => $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException("Cohort row {$rowIndex} must be an object of metric values.");
                }

                foreach (array_keys($row) as $code) {
                    if (!is_string($code) || !array_key_exists($code, $metricValues)) {
                        throw new InvalidArgumentException("Cohort row {$rowIndex} contains an unknown benchmark metric.");
                    }
                }
                foreach ($benchmarkCodes as $code) {
                    $value = $row[$code] ?? null;
                    if (!array_key_exists($code, $row) || !is_numeric($value)) {
                        throw new InvalidArgumentException("Cohort row {$rowIndex} is missing a numeric value for benchmark '{$code}'.");
                    }

                    $numericValue = (float) $value;
                    if (is_nan($numericValue) || is_infinite($numericValue) || $numericValue < 0) {
                        throw new InvalidArgumentException("Cohort row {$rowIndex} has an invalid value for benchmark '{$code}'.");
                    }
                    $metricValues[$code][] = $numericValue;
                }
            }

            $cohortJson = json_encode(
                $metricValues,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
            $cohortSha256 = hash('sha256', $cohortJson);

            $updateBm = $pdo->prepare("
                UPDATE nirf_benchmarks 
                SET p_max = :p_max, p_90 = :p_90, p_med = :p_med, p_min = :p_min, 
                    metric_value = :val, is_placeholder = FALSE, source_note = :source_note
                WHERE formula_set_id = :set_id AND metric_code = :code
            ");

            foreach ($metricValues as $code => $vals) {
                sort($vals);
                $n = count($vals);
                if ($n !== 100) {
                    throw new RuntimeException("Benchmark '{$code}' did not receive exactly 100 values.");
                }

                $pMin = (float) $vals[0];
                $pMax = (float) $vals[$n - 1];
                $pMed = $this->calculatePercentile($vals, 50.0);
                $p90 = $this->calculatePercentile($vals, 90.0);

                $updateBm->execute([
                    ':p_max' => $pMax,
                    ':p_90' => $p90,
                    ':p_med' => $pMed,
                    ':p_min' => $pMin,
                    ':val' => $pMax, // Active denominator defaults to p_max
                    ':source_note' => 'Cohort size: 100; source: ' . $sourceReference . '; cohort SHA-256: ' . $cohortSha256,
                    ':set_id' => $setId,
                    ':code' => $code,
                ]);
                if ($updateBm->rowCount() !== 1) {
                    throw new RuntimeException("Benchmark '{$code}' was not updated.");
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Failed to ingest Top 100 benchmark data: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Linear interpolated percentile calculation
     */
    public function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) return 0.0;
        if ($count === 1) return (float) $sortedValues[0];

        $rank = ($percentile / 100.0) * ($count - 1);
        $lowIndex = (int) floor($rank);
        $highIndex = (int) ceil($rank);
        $fraction = $rank - $lowIndex;

        return (float) ($sortedValues[$lowIndex] + $fraction * ($sortedValues[$highIndex] - $sortedValues[$lowIndex]));
    }

    /**
     * Exports a formula set back to Excel with locked headers and formula injection defense
     */
    public function exportSetToExcel(int $setId, string $outputPath): void
    {
        $pdo = $this->dbManager->getFormulaReader();

        $spreadsheet = new Spreadsheet();

        // 1. Meta Sheet
        $metaSheet = $spreadsheet->getActiveSheet();
        $metaSheet->setTitle('Meta');
        $metaSheet->fromArray([
            ['framework', 'category', 'academic_year', 'version_label', 'notes'],
            ['NIRF', 'ENGINEERING', '2026-2027', 'Exported Master', 'Exported from database for offline editing']
        ]);
        $metaSheet->freezePane('A2');

        // Add Inputs Sheet
        $inSheet = $spreadsheet->createSheet();
        $inSheet->setTitle('Inputs');
        $inHeaders = ['input_code', 'label', 'unit', 'data_type', 'min_value', 'max_value', 'required', 'main_parameter', 'help_text'];
        $inRows = [$inHeaders];
        $inStmt = $pdo->prepare("SELECT * FROM input_definitions WHERE formula_set_id = :id ORDER BY id ASC");
        $inStmt->execute([':id' => $setId]);
        while ($r = $inStmt->fetch(PDO::FETCH_ASSOC)) {
            $inRows[] = [
                $r['input_code'],
                $this->escapeFormulaInjection((string)$r['label']),
                $r['unit'],
                $r['data_type'],
                $r['min_value'],
                $r['max_value'],
                $r['required'] ? 'Y' : 'N',
                $r['main_parameter'],
                $this->escapeFormulaInjection((string)$r['help_text']),
            ];
        }
        $inSheet->fromArray($inRows);
        $inSheet->freezePane('A2');

        // Add Validation_Rules Sheet
        $vrSheet = $spreadsheet->createSheet();
        $vrSheet->setTitle('Validation_Rules');
        $vrRows = [['rule_id', 'expression', 'error_message', 'severity']];
        $vrStmt = $pdo->prepare("SELECT * FROM validation_rules WHERE formula_set_id = :id ORDER BY id ASC");
        $vrStmt->execute([':id' => $setId]);
        while ($r = $vrStmt->fetch(PDO::FETCH_ASSOC)) {
            $vrRows[] = [
                $r['rule_id'],
                $this->escapeFormulaInjection((string)$r['expression']),
                $this->escapeFormulaInjection((string)$r['error_message']),
                $r['severity'],
            ];
        }
        $vrSheet->fromArray($vrRows);
        $vrSheet->freezePane('A2');

        // Add Derived Sheet
        $dvSheet = $spreadsheet->createSheet();
        $dvSheet->setTitle('Derived');
        $dvRows = [['derived_code', 'expression', 'eval_order', 'description']];
        $dvStmt = $pdo->prepare("SELECT * FROM derived_variables WHERE formula_set_id = :id ORDER BY eval_order ASC");
        $dvStmt->execute([':id' => $setId]);
        while ($r = $dvStmt->fetch(PDO::FETCH_ASSOC)) {
            $dvRows[] = [
                $r['derived_code'],
                $this->escapeFormulaInjection((string)$r['expression']),
                $r['eval_order'],
                $this->escapeFormulaInjection((string)$r['description']),
            ];
        }
        $dvSheet->fromArray($dvRows);
        $dvSheet->freezePane('A2');

        // Add NIRF_Formulas Sheet
        $nfSheet = $spreadsheet->createSheet();
        $nfSheet->setTitle('NIRF_Formulas');
        $nfRows = [['category', 'academic_year', 'main_parameter', 'parameter_code', 'parameter_name', 'max_marks', 'main_weight', 'formula_expression', 'primary_variable', 'variable_step_size', 'effort_cost_factor', 'benchmark_metric', 'display_order', 'needs_verification']];
        $nfStmt = $pdo->prepare("SELECT * FROM nirf_rules WHERE formula_set_id = :id ORDER BY display_order ASC");
        $nfStmt->execute([':id' => $setId]);
        while ($r = $nfStmt->fetch(PDO::FETCH_ASSOC)) {
            $nfRows[] = [
                $r['category'],
                $r['academic_year'],
                $r['main_parameter'],
                $r['parameter_code'],
                $this->escapeFormulaInjection((string)$r['parameter_name']),
                $r['max_marks'],
                $r['main_weight'],
                $this->escapeFormulaInjection((string)$r['formula_expression']),
                $r['primary_variable'],
                $r['variable_step_size'],
                $r['effort_cost_factor'],
                $r['benchmark_metric'],
                $r['display_order'],
                $r['needs_verification'] ? 'Y' : 'N',
            ];
        }
        $nfSheet->fromArray($nfRows);
        $nfSheet->freezePane('A2');

        // Add Benchmarks Sheet
        $bmSheet = $spreadsheet->createSheet();
        $bmSheet->setTitle('Benchmarks');
        $bmRows = [['metric_code', 'p_max', 'p_90', 'p_med', 'p_min', 'active_value', 'is_placeholder', 'source_note']];
        $bmStmt = $pdo->prepare("SELECT * FROM nirf_benchmarks WHERE formula_set_id = :id ORDER BY id ASC");
        $bmStmt->execute([':id' => $setId]);
        while ($r = $bmStmt->fetch(PDO::FETCH_ASSOC)) {
            $bmRows[] = [
                $r['metric_code'],
                $r['p_max'],
                $r['p_90'],
                $r['p_med'],
                $r['p_min'],
                $r['metric_value'],
                $r['is_placeholder'] ? 'Y' : 'N',
                $this->escapeFormulaInjection((string)$r['source_note']),
            ];
        }
        $bmSheet->fromArray($bmRows);
        $bmSheet->freezePane('A2');

        // Add Tiers Sheet
        $tSheet = $spreadsheet->createSheet();
        $tSheet->setTitle('Tiers');
        $tRows = [['tier_code', 'current_band', 'target_band', 'target_score_min', 'target_score_max', 'max_effort_cost_factor', 'benchmark_anchor', 'focus_parameters', 'action_plan_text']];
        $tStmt = $pdo->prepare("SELECT * FROM tier_playbook WHERE formula_set_id = :id ORDER BY id ASC");
        $tStmt->execute([':id' => $setId]);
        while ($r = $tStmt->fetch(PDO::FETCH_ASSOC)) {
            $tRows[] = [
                $r['tier_code'],
                $r['current_band'],
                $r['target_band'],
                $r['target_score_min'],
                $r['target_score_max'],
                $r['max_effort_cost_factor'],
                $r['benchmark_anchor'],
                $r['focus_parameters'],
                $this->escapeFormulaInjection((string)$r['action_plan_text']),
            ];
        }
        $tSheet->fromArray($tRows);
        $tSheet->freezePane('A2');

        // Add TestCases Sheet
        $tcSheet = $spreadsheet->createSheet();
        $tcSheet->setTitle('TestCases');
        $tcRows = [['case_id', 'input_json', 'expected_parameter_scores_json', 'expected_total', 'tolerance']];
        $tcStmt = $pdo->prepare("SELECT * FROM test_cases WHERE formula_set_id = :id ORDER BY id ASC");
        $tcStmt->execute([':id' => $setId]);
        while ($r = $tcStmt->fetch(PDO::FETCH_ASSOC)) {
            $tcRows[] = [
                $r['case_id'],
                $this->escapeFormulaInjection((string)$r['input_json']),
                $this->escapeFormulaInjection((string)$r['expected_parameter_scores_json']),
                $r['expected_total'],
                $r['tolerance'],
            ];
        }
        $tcSheet->fromArray($tcRows);
        $tcSheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    /**
     * Defense against CSV / Excel Formula Injection:
     * Prefix cell value with single quote if it begins with '=', '+', '-', or '@'
     */
    public function escapeFormulaInjection(string $val): string
    {
        if (strlen($val) > 0 && in_array($val[0], ['=', '+', '-', '@'], true)) {
            return "'" . $val;
        }
        return $val;
    }

    private function validateFileSecurity(string $path): void
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Workbook file does not exist: {$path}");
        }

        if (filesize($path) > self::MAX_FILE_SIZE) {
            throw new RuntimeException("File size exceeds 5 MB limit.");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file for reading.");
        }
        $magic = fread($handle, 4);
        fclose($handle);

        if ($magic !== self::ZIP_MAGIC) {
            throw new RuntimeException("Invalid file format. File must be a valid .xlsx spreadsheet (magic bytes mismatch).");
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $res = $zip->open($path, \ZipArchive::CHECKCONS);
            if ($res !== true) {
                throw new RuntimeException("Corrupted or malformed ZIP archive (error code: {$res}).");
            }

            $numFiles = $zip->numFiles;
            if ($numFiles > 500) {
                $zip->close();
                throw new RuntimeException("Archive contains too many entries ({$numFiles} > 500 limit).");
            }

            $totalUncompressedSize = 0;
            $hasWorkbook = false;
            $hasContentTypes = false;

            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $entryName = $stat['name'];
                if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    $zip->close();
                    throw new RuntimeException("Security violation: path traversal detected in archive entry '{$entryName}'.");
                }
                if ($entryName === 'xl/workbook.xml') {
                    $hasWorkbook = true;
                }
                if ($entryName === '[Content_Types].xml') {
                    $hasContentTypes = true;
                }
                $totalUncompressedSize += (int) $stat['size'];
            }

            $zip->close();

            if ($totalUncompressedSize > 31457280) { // 30 MB
                throw new RuntimeException("Decompression bomb detected: uncompressed size exceeds 30 MB limit.");
            }

            if (!$hasWorkbook || !$hasContentTypes) {
                throw new RuntimeException("Invalid Excel structure: missing xl/workbook.xml or [Content_Types].xml.");
            }
        }
    }

    private function scanForFormulaInjection(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetName = $sheet->getTitle();
            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);
                foreach ($cellIterator as $cell) {
                    $rawVal = (string) $cell->getValue();
                    if (str_starts_with($rawVal, '=')) {
                        throw new RuntimeException("Security Violation: Real Excel formula ('{$rawVal}') detected at sheet '{$sheetName}', cell {$cell->getCoordinate()}. Excel formulas are strictly rejected.");
                    }
                }
            }
        }
    }

    private function parseMeta(array $rows, array &$errors): array
    {
        if (count($rows) < 2) {
            $errors[] = ['sheet' => 'Meta', 'row' => 1, 'col' => 'A', 'message' => 'Meta sheet is empty.'];
            return [];
        }
        $headers = array_map('strtolower', array_map('trim', $rows[0]));
        $data = $rows[1];
        $meta = [];
        foreach ($headers as $idx => $h) {
            $meta[$h] = isset($data[$idx]) ? trim((string)$data[$idx]) : '';
        }

        if (($meta['framework'] ?? '') !== 'NIRF') {
            $errors[] = ['sheet' => 'Meta', 'row' => 2, 'col' => 'A', 'message' => 'Meta framework must be NIRF.'];
        }
        if (empty($meta['category'])) {
            $errors[] = ['sheet' => 'Meta', 'row' => 2, 'col' => 'B', 'message' => 'Category is required in Meta.'];
        }
        if (empty($meta['academic_year'])) {
            $errors[] = ['sheet' => 'Meta', 'row' => 2, 'col' => 'C', 'message' => 'Academic year is required in Meta.'];
        }

        return $meta;
    }

    private function parseInputs(array $rows, array &$errors): array
    {
        $inputs = [];
        if (count($rows) < 2) return $inputs;

        $headers = array_map('strtolower', array_map('trim', $rows[0]));
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $item = [];
            foreach ($headers as $idx => $h) {
                $item[$h] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            }

            $code = $item['input_code'] ?? '';
            if (empty($code)) {
                $errors[] = ['sheet' => 'Inputs', 'row' => $rowNum, 'col' => 'A', 'message' => 'Missing input_code.'];
                continue;
            }

            $inputs[$code] = [
                'input_code' => $code,
                'label' => $item['label'] ?? $code,
                'unit' => $item['unit'] ?? '',
                'data_type' => in_array($item['data_type'] ?? '', ['int', 'decimal', 'percent']) ? $item['data_type'] : 'decimal',
                'min_value' => (float) ($item['min_value'] ?? 0),
                'max_value' => (float) ($item['max_value'] ?? 100000000),
                'required' => strtoupper((string)($item['required'] ?? 'Y')) === 'Y',
                'main_parameter' => $item['main_parameter'] ?? '',
                'help_text' => $item['help_text'] ?? '',
            ];
        }
        return $inputs;
    }

    private function parseValidationRules(array $rows, array $inputs, array &$errors): array
    {
        $rules = [];
        if (count($rows) < 2) return $rules;

        $allowedVars = array_keys($inputs);
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $rid = trim((string)($row[0] ?? ''));
            $expr = trim((string)($row[1] ?? ''));
            $msg = trim((string)($row[2] ?? ''));
            $sev = strtoupper(trim((string)($row[3] ?? 'ERROR')));

            if (empty($rid) || empty($expr)) {
                $errors[] = ['sheet' => 'Validation_Rules', 'row' => $rowNum, 'col' => 'B', 'message' => 'Rule ID and expression are required.'];
                continue;
            }

            $lint = $this->linter->lint($expr, $allowedVars);
            if (!$lint['valid']) {
                $errors[] = ['sheet' => 'Validation_Rules', 'row' => $rowNum, 'col' => 'B', 'message' => "Lint error in validation rule '{$rid}': {$lint['error']}"];
            }

            $rules[] = [
                'rule_id' => $rid,
                'expression' => $expr,
                'error_message' => $msg,
                'severity' => in_array($sev, ['ERROR', 'WARN']) ? $sev : 'ERROR',
            ];
        }
        return $rules;
    }

    private function parseDerived(array $rows, array $inputs, array &$errors): array
    {
        $derived = [];
        if (count($rows) < 2) return $derived;

        $allowedVars = array_keys($inputs);
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $code = trim((string)($row[0] ?? ''));
            $expr = trim((string)($row[1] ?? ''));
            $order = (int) ($row[2] ?? 1);
            $desc = trim((string)($row[3] ?? ''));

            if (empty($code) || empty($expr)) {
                $errors[] = ['sheet' => 'Derived', 'row' => $rowNum, 'col' => 'B', 'message' => 'Derived code and expression are required.'];
                continue;
            }

            $lint = $this->linter->lint($expr, $allowedVars);
            if (!$lint['valid']) {
                $errors[] = ['sheet' => 'Derived', 'row' => $rowNum, 'col' => 'B', 'message' => "Lint error in derived variable '{$code}': {$lint['error']}"];
            }

            // Each derived variable becomes available to subsequent derived expressions
            $allowedVars[] = $code;

            $derived[$code] = [
                'derived_code' => $code,
                'expression' => $expr,
                'eval_order' => $order,
                'description' => $desc,
            ];
        }
        return $derived;
    }

    private function parseBenchmarks(array $rows, array &$errors): array
    {
        $benchmarks = [];
        if (count($rows) < 2) return $benchmarks;

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $code = trim((string)($row[0] ?? ''));
            if (empty($code)) continue;

            $pMax = (float) ($row[1] ?? 0);
            $p90 = (float) ($row[2] ?? 0);
            $pMed = (float) ($row[3] ?? 0);
            $pMin = (float) ($row[4] ?? 0);
            $activeVal = isset($row[5]) && is_numeric($row[5]) ? (float) $row[5] : $pMax;
            $isPlaceholder = strtoupper(trim((string)($row[6] ?? 'Y'))) === 'Y';
            $note = trim((string)($row[7] ?? ''));

            $benchmarks[$code] = [
                'metric_code' => $code,
                'p_max' => $pMax,
                'p_90' => $p90,
                'p_med' => $pMed,
                'p_min' => $pMin,
                'metric_value' => $activeVal,
                'is_placeholder' => $isPlaceholder,
                'source_note' => $note,
            ];
        }
        return $benchmarks;
    }

    private function parseFormulas(
        array $rows,
        array $inputs,
        array $derived,
        array $benchmarks,
        array &$errors,
        array &$warnings
    ): array {
        $rules = [];
        if (count($rows) < 2) return $rules;

        $allowedVars = array_merge(array_keys($inputs), array_keys($derived), array_keys($benchmarks));
        $mainParamMarks = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $cat = trim((string)($row[0] ?? ''));
            $year = trim((string)($row[1] ?? ''));
            $mainParam = trim((string)($row[2] ?? ''));
            $paramCode = trim((string)($row[3] ?? ''));
            $paramName = trim((string)($row[4] ?? ''));
            $maxMarks = (float) ($row[5] ?? 0);
            $mainWeight = (float) ($row[6] ?? 0);
            $expr = trim((string)($row[7] ?? ''));
            $pVar = trim((string)($row[8] ?? ''));
            $step = (float) ($row[9] ?? 1.0);
            $effort = (float) ($row[10] ?? 1.0);
            $bm = trim((string)($row[11] ?? ''));
            $order = (int) ($row[12] ?? 1);
            $nv = strtoupper(trim((string)($row[13] ?? 'N'))) === 'Y';

            // Check primary_variable is a base input
            if (!isset($inputs[$pVar])) {
                $errors[] = ['sheet' => 'NIRF_Formulas', 'row' => $rowNum, 'col' => 'I', 'message' => "Primary variable '{$pVar}' must be a base input, not a derived or benchmark variable."];
            }

            // Lint formula expression
            $lint = $this->linter->lint($expr, $allowedVars);
            if (!$lint['valid']) {
                $errors[] = ['sheet' => 'NIRF_Formulas', 'row' => $rowNum, 'col' => 'H', 'message' => "Formula error in parameter '{$paramCode}': {$lint['error']}"];
            }

            $rules[$paramCode] = [
                'category' => $cat,
                'academic_year' => $year,
                'main_parameter' => $mainParam,
                'parameter_code' => $paramCode,
                'parameter_name' => $paramName,
                'max_marks' => $maxMarks,
                'main_weight' => $mainWeight,
                'formula_expression' => $expr,
                'primary_variable' => $pVar,
                'variable_step_size' => $step,
                'effort_cost_factor' => $effort,
                'benchmark_metric' => !empty($bm) ? $bm : null,
                'display_order' => $order,
                'needs_verification' => $nv,
            ];

            if (!isset($mainParamMarks[$mainParam])) {
                $mainParamMarks[$mainParam] = 0.0;
            }
            $mainParamMarks[$mainParam] += $maxMarks;
        }

        // Check sub-marks per main parameter sum to 100
        foreach ($mainParamMarks as $param => $totalMarks) {
            if (abs($totalMarks - 100.0) > 0.01) {
                $warnings[] = "Main parameter '{$param}' sub-marks sum to {$totalMarks}, expected 100.0.";
            }
        }

        return $rules;
    }

    private function parseTiers(array $rows, array &$errors): array
    {
        $tiers = [];
        if (count($rows) < 2) return $tiers;

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;

            $tiers[] = [
                'tier_code' => trim((string)($row[0] ?? '')),
                'current_band' => trim((string)($row[1] ?? '')),
                'target_band' => trim((string)($row[2] ?? '')),
                'target_score_min' => (float) ($row[3] ?? 0),
                'target_score_max' => (float) ($row[4] ?? 100),
                'max_effort_cost_factor' => (float) ($row[5] ?? 3.0),
                'benchmark_anchor' => in_array($row[6] ?? '', ['p_min', 'p_med', 'p_90']) ? $row[6] : 'p_med',
                'focus_parameters' => trim((string)($row[7] ?? '')),
                'action_plan_text' => trim((string)($row[8] ?? '')),
            ];
        }
        return $tiers;
    }

    private function parseTestCases(array $rows, array &$errors): array
    {
        $testCases = [];
        if (count($rows) < 2) return $testCases;

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) continue;
            $rowNum = $i + 1;

            $cid = trim((string)($row[0] ?? ''));
            $inJson = trim((string)($row[1] ?? ''));
            $expJson = trim((string)($row[2] ?? ''));
            $expTotal = (float) ($row[3] ?? 0);
            $tol = (float) ($row[4] ?? 0.01);

            $inDecoded = json_decode($inJson, true);
            if (!is_array($inDecoded)) {
                $errors[] = ['sheet' => 'TestCases', 'row' => $rowNum, 'col' => 'B', 'message' => "Malformed JSON in input_json for test case '{$cid}'."];
            }

            $testCases[] = [
                'case_id' => $cid,
                'input_json' => $inDecoded ?? [],
                'expected_parameter_scores_json' => json_decode($expJson, true) ?? [],
                'expected_total' => $expTotal,
                'tolerance' => $tol > 0 ? $tol : 0.01,
            ];
        }
        return $testCases;
    }

    private function executeDryRun(
        array $testCases,
        array $inputs,
        array $derived,
        array $benchmarks,
        array $rules,
        array &$errors
    ): void {
        foreach ($testCases as $tc) {
            $cid = $tc['case_id'];
            $inputData = $tc['input_json'];

            // Build evaluation context
            $context = [];
            foreach ($inputs as $code => $def) {
                $context[$code] = isset($inputData[$code]) ? (float) $inputData[$code] : 0.0;
            }

            // Evaluate derived variables
            foreach ($derived as $dCode => $dDef) {
                try {
                    $context[$dCode] = $this->evaluator->evaluate($dDef['expression'], $context);
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'TestCases', 'row' => 0, 'col' => '', 'message' => "Dry run failure in derived '{$dCode}' for test case '{$cid}': {$e->getMessage()}"];
                    return;
                }
            }

            // Apply benchmarks
            foreach ($benchmarks as $bmCode => $bmDef) {
                $context[$bmCode] = (float) $bmDef['metric_value'];
            }

            // Score rules
            $totalScore = 0.0;
            foreach ($rules as $paramCode => $rDef) {
                try {
                    $raw = $this->evaluator->evaluate($rDef['formula_expression'], $context);
                    $clamped = max(0.0, min((float)$rDef['max_marks'], $raw));
                    $weighted = $clamped * (float) $rDef['main_weight'];
                    $totalScore += $weighted;
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'TestCases', 'row' => 0, 'col' => '', 'message' => "Dry run failure in rule '{$paramCode}' for test case '{$cid}': {$e->getMessage()}"];
                    return;
                }
            }

            // Check total against expected
            $diff = abs($totalScore - (float)$tc['expected_total']);
            if ($diff > (float)$tc['tolerance']) {
                $errors[] = ['sheet' => 'TestCases', 'row' => 0, 'col' => '', 'message' => "Dry run test case '{$cid}' score discrepancy: calculated {$totalScore}, expected {$tc['expected_total']} (tolerance ±{$tc['tolerance']})."];
            }
        }
    }

    /**
     * @param list<array{sheet: string, row: int, col: string, message: string}> $errors
     * @param list<string> $warnings
     * @return array{formula_set_id: int, status: string, report: array<string, mixed>}
     */
    private function buildFailureResult(array $errors, array $warnings): array
    {
        return [
            'formula_set_id' => 0,
            'status' => 'REJECTED',
            'report' => [
                'passed' => false,
                'errors' => $errors,
                'warnings' => $warnings,
            ]
        ];
    }
}
