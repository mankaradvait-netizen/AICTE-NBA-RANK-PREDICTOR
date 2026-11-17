<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\DatabaseManager;
use App\Engine\GapOptimizer;
use App\Engine\ScoreEngine;
use App\Formula\SafeExpressionEvaluator;
use PDO;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class GapOptimizerTest extends TestCase
{
    private PDO $formulaPdo;
    private GapOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->formulaPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->createSchemaAndSeed($this->formulaPdo);

        $dbManager = new DatabaseManager([]);
        $dbManager->setConnectionsForTesting($this->formulaPdo, $this->formulaPdo, $this->formulaPdo);

        $evaluator = new SafeExpressionEvaluator();
        $scoreEngine = new ScoreEngine($dbManager, $evaluator);
        $this->optimizer = new GapOptimizer($scoreEngine, $evaluator);
    }

    public function testTier1OptimizationOnlyRanksLowEffortLevers(): void
    {
        // Low baseline institution (current score ~34.08)
        $inputs = [
            'NT' => 1200, 'NE' => 1000, 'NP' => 50, 'F' => 60, 'F_PHD' => 20,
            'F1' => 30, 'F2' => 20, 'F3' => 10, 'BC' => 15000, 'BO' => 40000,
            'P' => 60, 'CC' => 300, 'TOP25' => 15, 'PG' => 1, 'PP' => 5,
            'RF' => 100000, 'CF' => 20000, 'NP_pct' => 65, 'NHS_pct' => 10,
            'NG_pct' => 70, 'GMS_val' => 350000, 'NPHD' => 10, 'N_state_pct' => 10,
            'N_country_pct' => 0, 'SW_pct' => 25, 'FW_pct' => 15, 'ESC_pct' => 10,
            'PCS_score' => 12, 'PR_survey_val' => 15
        ];

        $plan = $this->optimizer->optimize($inputs, 'ENGINEERING', '2026-2027', 'UNRANKED', 'TOP_300');

        $this->assertEquals('TIER_1', $plan['tier_code']);
        $this->assertEquals(38.0, $plan['target_score_min']);
        $this->assertNotEmpty($plan['top_levers']);

        // Every recommended lever in Tier 1 MUST have effort_cost_factor <= 1.5
        foreach ($plan['top_levers'] as $lever) {
            $this->assertLessThanOrEqual(1.5, $lever['effort_cost_factor']);
            $this->assertGreaterThan(0.0, $lever['required_delta']);
            $this->assertEquals('p_min', $lever['benchmark_anchor']);
        }

        // Verify projected score increased towards target
        $this->assertGreaterThan($plan['current_score'], $plan['projected_score']);
        $this->assertNotEmpty($plan['action_plan_text']);
    }

    public function testRejectsInvalidRankBandProgression(): void
    {
        $inputs = ['NT' => 1000, 'NE' => 800];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid rank progression');
        // Downgrade: TOP_100 -> TOP_300 is invalid
        $this->optimizer->optimize($inputs, 'ENGINEERING', '2026-2027', 'TOP_100', 'TOP_300');
    }

    private function createSchemaAndSeed(PDO $pdo): void
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
        ");

        $pdo->exec("
            INSERT INTO formula_sets (id, category, academic_year, version, version_label, status, source_file_sha256, uploaded_by, approved_by, activated_at)
            VALUES (1, 'ENGINEERING', '2026-2027', 1, 'Verified Master', 'ACTIVE', 'sha', 'admin', 'admin2', CURRENT_TIMESTAMP);

            INSERT INTO input_definitions (formula_set_id, input_code, label, unit, data_type, min_value, max_value, required, main_parameter) VALUES
            (1, 'NT', 'Sanctioned Intake', 'int', 'int', 1, 10000, 1, 'TLR'),
            (1, 'NE', 'Enrolled Students', 'int', 'int', 0, 10000, 1, 'TLR'),
            (1, 'NP', 'PhD Students', 'int', 'int', 0, 5000, 1, 'TLR'),
            (1, 'F', 'Faculty', 'int', 'int', 0, 1000, 1, 'TLR'),
            (1, 'F_PHD', 'Faculty PhD', 'int', 'int', 0, 1000, 1, 'TLR'),
            (1, 'F1', 'F1', 'int', 'int', 0, 1000, 1, 'TLR'),
            (1, 'F2', 'F2', 'int', 'int', 0, 1000, 1, 'TLR'),
            (1, 'F3', 'F3', 'int', 'int', 0, 1000, 1, 'TLR'),
            (1, 'BC', 'CapEx', 'INR', 'decimal', 0, 1000000, 1, 'TLR'),
            (1, 'BO', 'OpEx', 'INR', 'decimal', 0, 1000000, 1, 'TLR'),
            (1, 'P', 'Pubs', 'Count', 'decimal', 0, 10000, 1, 'RPC'),
            (1, 'CC', 'Citations', 'Count', 'int', 0, 100000, 1, 'RPC'),
            (1, 'TOP25', 'Top25', 'Count', 'int', 0, 10000, 1, 'RPC'),
            (1, 'PG', 'PG', 'Count', 'int', 0, 1000, 1, 'RPC'),
            (1, 'PP', 'PP', 'Count', 'int', 0, 1000, 1, 'RPC'),
            (1, 'RF', 'RF', 'INR', 'decimal', 0, 10000000, 1, 'RPC'),
            (1, 'CF', 'CF', 'INR', 'decimal', 0, 10000000, 1, 'RPC'),
            (1, 'NP_pct', 'Placement', '%', 'percent', 0, 100, 1, 'GO'),
            (1, 'NHS_pct', 'Higher', '%', 'percent', 0, 100, 1, 'GO'),
            (1, 'NG_pct', 'Graduation', '%', 'percent', 0, 100, 1, 'GO'),
            (1, 'GMS_val', 'Salary', 'INR', 'decimal', 0, 10000000, 1, 'GO'),
            (1, 'NPHD', 'PhD Grad', 'Count', 'decimal', 0, 1000, 1, 'GO'),
            (1, 'N_state_pct', 'State', '%', 'percent', 0, 100, 1, 'OI'),
            (1, 'N_country_pct', 'Intl', '%', 'percent', 0, 100, 1, 'OI'),
            (1, 'SW_pct', 'Women S', '%', 'percent', 0, 100, 1, 'OI'),
            (1, 'FW_pct', 'Women F', '%', 'percent', 0, 100, 1, 'OI'),
            (1, 'ESC_pct', 'Fee Waiver', '%', 'percent', 0, 100, 1, 'OI'),
            (1, 'PCS_score', 'Physically Ch', 'Score', 'decimal', 0, 20, 1, 'OI'),
            (1, 'PR_survey_val', 'PR', 'Index', 'decimal', 0, 100, 1, 'PR');

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

            INSERT INTO tier_playbook (formula_set_id, tier_code, current_band, target_band, target_score_min, target_score_max, max_effort_cost_factor, benchmark_anchor, focus_parameters, action_plan_text) VALUES
            (1, 'TIER_1', 'UNRANKED', 'TOP_300', 38.0, 45.0, 1.5, 'p_min', 'FSR,PCS,ESCS,GUE', 'Tier 1 Action Plan Text');
        ");
    }
}
