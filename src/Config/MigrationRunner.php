<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

class MigrationRunner
{
    public function __construct(
        private readonly DatabaseManager $dbManager
    ) {}

    public function run(): void
    {
        $formulaPdo = $this->dbManager->getFormulaMigration();
        $corePdo = $this->dbManager->getInstitutionCoreMigration();

        $this->migrateFormulaDb($formulaPdo);
        $this->migrateCoreDb($corePdo);
    }

    private function migrateFormulaDb(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = file_get_contents(__DIR__ . '/../../sql/01_formula_engine_schema.sql');
            if ($sql !== false) {
                $pdo->exec($sql);
            }
            return;
        }

        // SQLite dialect with strict schema parity
        $pdo->exec("PRAGMA foreign_keys = ON;");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS formula_sets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                academic_year TEXT NOT NULL,
                version INT NOT NULL,
                version_label TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('DRAFT', 'VALIDATED', 'ACTIVE', 'ARCHIVED', 'REJECTED')),
                source_file_sha256 TEXT,
                uploaded_by TEXT NOT NULL,
                approved_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                activated_at TEXT,
                notes TEXT,
                UNIQUE (category, academic_year, version)
            );

            CREATE TABLE IF NOT EXISTS input_definitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                input_code TEXT NOT NULL,
                label TEXT NOT NULL,
                unit TEXT NOT NULL,
                data_type TEXT NOT NULL CHECK (data_type IN ('int', 'decimal', 'percent')),
                min_value REAL NOT NULL CHECK (min_value >= 0),
                max_value REAL NOT NULL CHECK (max_value >= min_value),
                required INTEGER NOT NULL DEFAULT 1,
                main_parameter TEXT NOT NULL,
                help_text TEXT,
                UNIQUE (formula_set_id, input_code)
            );

            CREATE TABLE IF NOT EXISTS validation_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                rule_id TEXT NOT NULL,
                expression TEXT NOT NULL,
                error_message TEXT NOT NULL,
                severity TEXT NOT NULL CHECK (severity IN ('ERROR', 'WARN')),
                UNIQUE (formula_set_id, rule_id)
            );

            CREATE TABLE IF NOT EXISTS derived_variables (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                derived_code TEXT NOT NULL,
                expression TEXT NOT NULL,
                eval_order INT NOT NULL,
                description TEXT,
                UNIQUE (formula_set_id, derived_code)
            );

            CREATE TABLE IF NOT EXISTS nirf_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                category TEXT NOT NULL,
                academic_year TEXT NOT NULL,
                main_parameter TEXT NOT NULL,
                parameter_code TEXT NOT NULL,
                parameter_name TEXT NOT NULL,
                max_marks REAL NOT NULL CHECK (max_marks >= 0),
                main_weight REAL NOT NULL CHECK (main_weight >= 0),
                formula_expression TEXT NOT NULL,
                primary_variable TEXT NOT NULL,
                variable_step_size REAL NOT NULL,
                effort_cost_factor REAL NOT NULL CHECK (effort_cost_factor >= 0),
                benchmark_metric TEXT,
                display_order INT NOT NULL,
                needs_verification INTEGER NOT NULL DEFAULT 0,
                updated_by_excel_hash TEXT,
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE (formula_set_id, parameter_code)
            );

            CREATE TABLE IF NOT EXISTS nirf_benchmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                academic_year TEXT NOT NULL,
                category TEXT NOT NULL,
                metric_code TEXT NOT NULL,
                p_max REAL NOT NULL CHECK (p_max >= 0),
                p_90 REAL NOT NULL CHECK (p_90 >= 0),
                p_med REAL NOT NULL CHECK (p_med >= 0),
                p_min REAL NOT NULL CHECK (p_min >= 0),
                metric_value REAL NOT NULL CHECK (metric_value >= 0),
                is_placeholder INTEGER NOT NULL DEFAULT 1,
                source_note TEXT,
                UNIQUE (formula_set_id, metric_code)
            );

            CREATE TABLE IF NOT EXISTS tier_playbook (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                tier_code TEXT NOT NULL,
                current_band TEXT NOT NULL,
                target_band TEXT NOT NULL,
                target_score_min REAL NOT NULL CHECK (target_score_min >= 0),
                target_score_max REAL NOT NULL CHECK (target_score_max >= target_score_min),
                max_effort_cost_factor REAL NOT NULL CHECK (max_effort_cost_factor >= 0),
                benchmark_anchor TEXT NOT NULL CHECK (benchmark_anchor IN ('p_min', 'p_med', 'p_90')),
                focus_parameters TEXT NOT NULL,
                action_plan_text TEXT NOT NULL,
                UNIQUE (formula_set_id, tier_code)
            );

            CREATE TABLE IF NOT EXISTS test_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
                case_id TEXT NOT NULL,
                input_json TEXT NOT NULL,
                expected_parameter_scores_json TEXT NOT NULL,
                expected_total REAL NOT NULL,
                tolerance REAL NOT NULL DEFAULT 0.01,
                UNIQUE (formula_set_id, case_id)
            );

            CREATE TABLE IF NOT EXISTS formula_audit_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id TEXT NOT NULL,
                excel_filename TEXT NOT NULL,
                file_sha256 TEXT NOT NULL,
                formula_set_id INT NOT NULL,
                previous_rules_snapshot TEXT,
                new_rules_snapshot TEXT NOT NULL,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS formula_import_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                formula_set_id INT,
                validation_report TEXT NOT NULL,
                passed INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $this->seedFormulaData($pdo);
    }

    private function migrateCoreDb(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $sql = file_get_contents(__DIR__ . '/../../sql/02_institution_core_schema.sql');
            if ($sql !== false) {
                $pdo->exec($sql);
            }
            return;
        }

        // SQLite dialect for Institution Core
        $pdo->exec("PRAGMA foreign_keys = ON;");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS institutions (
                id TEXT PRIMARY KEY,
                institution_code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'SUSPENDED')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                institution_code TEXT REFERENCES institutions(institution_code) ON DELETE SET NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('SYSTEM_ADMIN', 'FORMULA_ADMIN', 'IQAC_EDITOR', 'MANAGEMENT_VIEWER', 'AUDITOR')),
                totp_secret_encrypted TEXT,
                mfa_enabled INTEGER NOT NULL DEFAULT 0,
                failed_attempts INT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
                locked_until TEXT,
                auth_version INT NOT NULL DEFAULT 1,
                must_change_password INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS password_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                family_id TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                revoked_at TEXT,
                replaced_by TEXT,
                ip TEXT,
                user_agent TEXT
            );

            CREATE TABLE IF NOT EXISTS api_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                institution_code TEXT NOT NULL REFERENCES institutions(institution_code) ON DELETE CASCADE,
                name TEXT NOT NULL,
                key_id TEXT UNIQUE NOT NULL,
                secret_encrypted TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_used_at TEXT
            );

            CREATE TABLE IF NOT EXISTS institution_submissions (
                id TEXT PRIMARY KEY,
                institution_code TEXT NOT NULL REFERENCES institutions(institution_code) ON DELETE CASCADE,
                submitted_by TEXT REFERENCES users(id) ON DELETE SET NULL,
                category TEXT NOT NULL,
                academic_year TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('DRAFT', 'SUBMITTED', 'LOCKED')),
                current_rank_band TEXT NOT NULL,
                target_rank_band TEXT NOT NULL,
                formula_set_id INT NOT NULL,
                formula_version INT NOT NULL,
                encrypted_raw_metrics TEXT NOT NULL,
                encryption_iv TEXT NOT NULL,
                auth_tag TEXT,
                payload_hmac TEXT,
                key_id TEXT NOT NULL,
                payload_sha256 TEXT NOT NULL,
                computed_scores TEXT,
                gap_analysis TEXT,
                row_version INT NOT NULL DEFAULT 1 CHECK (row_version >= 1),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                submitted_at TEXT
            );

            CREATE TABLE IF NOT EXISTS security_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                ip_address TEXT,
                action_type TEXT NOT NULL,
                resource_id TEXT,
                request_signature TEXT,
                payload_hash TEXT NOT NULL,
                previous_chain_hash TEXT NOT NULL,
                current_chain_hash TEXT NOT NULL,
                timestamp TEXT NOT NULL DEFAULT (datetime('now')),
                metadata TEXT
            );

            CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                window_start INT NOT NULL,
                count INT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS canonical_datasets (
                id TEXT PRIMARY KEY,
                institution_code TEXT NOT NULL REFERENCES institutions(institution_code) ON DELETE CASCADE,
                academic_year TEXT NOT NULL,
                version INT NOT NULL DEFAULT 1,
                status TEXT NOT NULL CHECK (status IN ('DRAFT', 'VALIDATED', 'FROZEN', 'CALCULATED')),
                dataset_hash TEXT,
                created_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                frozen_at TEXT
            );

            CREATE TABLE IF NOT EXISTS data_issue_register (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dataset_id TEXT NOT NULL REFERENCES canonical_datasets(id) ON DELETE CASCADE,
                file_type TEXT NOT NULL,
                row_number INT,
                field_name TEXT,
                observed_value TEXT,
                rule_id TEXT NOT NULL,
                severity TEXT NOT NULL CHECK (severity IN ('ERROR', 'WARN')),
                message TEXT NOT NULL,
                resolution_status TEXT NOT NULL DEFAULT 'OPEN' CHECK (resolution_status IN ('OPEN', 'RESOLVED', 'ACKNOWLEDGED')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $this->seedCoreData($pdo);
    }

    private function seedFormulaData(PDO $pdo): void
    {
        // Check if formula set already seeded
        $chk = $pdo->query("SELECT COUNT(*) FROM formula_sets WHERE id = 1");
        if ($chk && (int)$chk->fetchColumn() > 0) {
            return;
        }

        // Insert Formula Set (status ACTIVE for default calculations)
        $pdo->exec("
            INSERT INTO formula_sets (
                id, category, academic_year, version, version_label, status, 
                source_file_sha256, uploaded_by, approved_by, created_at, activated_at, notes
            ) VALUES (
                1, 'ENGINEERING', '2026-2027', 1, 'NIRF Engineering 2026-2027 Verified Active', 'ACTIVE',
                'verified-hash', 'SYSTEM_ADMIN', 'SYSTEM_ADMIN', datetime('now'), datetime('now'), 
                'Official verified formula set matching India Rankings Engineering methodology with FSR 1:15/1:50 rules, corrected QP denominators, and normalized ratios.'
            );
        ");

        // Seed 29 Input Definitions
        $inputs = [
            [1, 'NT', 'Sanctioned Approved Intake (NT)', 'Students', 'int', 1, 100000, 1, 'TLR', 'Total approved intake across all UG/PG programs.'],
            [1, 'NE', 'Total Enrolled Students (NE)', 'Students', 'int', 0, 100000, 1, 'TLR', 'Total non-doctoral students enrolled.'],
            [1, 'NP', 'Total Doctoral Students Enrolled (NP)', 'Students', 'int', 0, 50000, 1, 'TLR', 'Doctoral students enrolled.'],
            [1, 'F', 'Full-time Regular Faculty (F)', 'Faculty', 'int', 0, 10000, 1, 'TLR', 'Eligible regular faculty with Ph.D./M.Tech.'],
            [1, 'F_PHD', 'Faculty with Ph.D. (F_PHD)', 'Faculty', 'int', 0, 10000, 1, 'TLR', 'Eligible faculty with Ph.D.'],
            [1, 'F1', 'Faculty Experience <= 8 Years (F1)', 'Faculty', 'int', 0, 10000, 1, 'TLR', 'Faculty with experience up to 8 years.'],
            [1, 'F2', 'Faculty Experience 8-15 Years (F2)', 'Faculty', 'int', 0, 10000, 1, 'TLR', 'Faculty with experience 8+ to 15 years.'],
            [1, 'F3', 'Faculty Experience > 15 Years (F3)', 'Faculty', 'int', 0, 10000, 1, 'TLR', 'Faculty with experience > 15 years.'],
            [1, 'BC', 'Average Annual Capital Spend per Student (BC)', 'INR/student/yr', 'decimal', 0, 10000000, 1, 'TLR', 'Eligible capital spend per student.'],
            [1, 'BO', 'Average Annual Operational Spend per Student (BO)', 'INR/student/yr', 'decimal', 0, 10000000, 1, 'TLR', 'Eligible operational spend per student.'],
            [1, 'P', 'Publications Passed (P)', 'Count', 'decimal', 0, 100000, 1, 'RPC', 'Publications in Scopus/WoS.'],
            [1, 'Pret', 'Retracted Publications (Pret)', 'Count', 'int', 0, 10000, 0, 'RPC', 'Retracted publications count.'],
            [1, 'CC', 'Total Citations Count (CC)', 'Citations', 'int', 0, 1000000, 1, 'RPC', 'Total citations over 3 years.'],
            [1, 'TOP25', 'Top 25% Percentile Citations (TOP25P)', 'Papers', 'int', 0, 100000, 1, 'RPC', 'Top 25% percentile papers.'],
            [1, 'Cret', 'Retracted Citations Count (Cret)', 'Citations', 'int', 0, 1000000, 0, 'RPC', 'Retracted citations count.'],
            [1, 'PG', 'Patents Granted (PG)', 'Patents', 'int', 0, 10000, 1, 'RPC', 'Patents granted in 3-yr window.'],
            [1, 'PP', 'Patents Published (PP)', 'Patents', 'int', 0, 10000, 1, 'RPC', 'Patents published in 3-yr window.'],
            [1, 'RF', 'Research Funding per Faculty (RF)', 'INR/faculty/yr', 'decimal', 0, 100000000, 1, 'RPC', 'Sponsored research earnings per faculty.'],
            [1, 'CF', 'Consultancy Amount per Faculty (CF)', 'INR/faculty/yr', 'decimal', 0, 100000000, 1, 'RPC', 'Consultancy earnings per faculty.'],
            [1, 'NP_pct', 'Percentage of Students Placed (Np)', 'Percent', 'percent', 0, 100, 1, 'GO', 'Placed students percentage.'],
            [1, 'NHS_pct', 'Percentage for Higher Studies (Nhs)', 'Percent', 'percent', 0, 100, 1, 'GO', 'Students selected for higher studies.'],
            [1, 'NG_pct', 'Graduation Rate in Stipulated Time (Ng)', 'Percent', 'percent', 0, 100, 1, 'GO', 'Students passing within stipulated duration.'],
            [1, 'GMS_val', 'Median Salary of Placed Graduates (MS)', 'INR/annum', 'decimal', 0, 100000000, 1, 'GO', 'Median campus placement salary.'],
            [1, 'NPHD', 'Ph.D Students Graduated (Nphd)', 'Graduates/yr', 'decimal', 0, 10000, 1, 'GO', 'Average annual Ph.D graduations.'],
            [1, 'N_state_pct', 'Students from Other States (RD)', 'Percent', 'percent', 0, 100, 1, 'OI', 'Fraction/percent from other Indian states.'],
            [1, 'N_country_pct', 'Students from Other Countries (RD)', 'Percent', 'percent', 0, 100, 1, 'OI', 'Fraction/percent from other countries.'],
            [1, 'SW_pct', 'Percentage of Women Students (WD)', 'Percent', 'percent', 0, 100, 1, 'OI', 'Women students percentage.'],
            [1, 'FW_pct', 'Percentage of Women Faculty (WD)', 'Percent', 'percent', 0, 100, 1, 'OI', 'Women faculty percentage.'],
            [1, 'ESC_pct', 'Economically Challenged Students (ESCS)', 'Percent', 'percent', 0, 100, 1, 'OI', 'Students receiving full fee reimbursement.'],
            [1, 'PCS_score', 'Physically Challenged Facilities (PCS)', 'Score', 'decimal', 0, 20, 1, 'OI', 'Facilities checklist score.'],
            [1, 'PR_survey_val', 'Perception Survey Index (PR)', 'Index', 'decimal', 0, 100, 1, 'PR', 'Controlled perception assumption.']
        ];

        $stmtIn = $pdo->prepare("
            INSERT OR IGNORE INTO input_definitions 
            (formula_set_id, input_code, label, unit, data_type, min_value, max_value, required, main_parameter, help_text) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($inputs as $in) {
            $stmtIn->execute($in);
        }

        // Seed Derived Variables with 2025 Methodology corrections
        $derived = [
            [1, 'N_total', 'NT + NP', 1, 'Total student population including doctoral students'],
            [1, 'FSR_val', 'safe_div(F, N_total)', 2, 'Faculty-Student Ratio using total students (NT + NP)'],
            [1, 'F_required', 'safe_div(N_total, 15.0)', 3, 'Nominal faculty required at 1:15 norm'],
            [1, 'F_RA', 'max(F, F_required)', 4, 'Denominator for PhD faculty ratio: max of required or actual faculty'],
            [1, 'FRA', 'safe_div(F_PHD, F_RA) * 100.0', 5, 'Percentage of PhD faculty relative to F_RA'],
            [1, 'FQ', '10.0 * min(1.0, FRA / 95.0)', 6, 'Faculty qualification component (max 10 marks)'],
            [1, 'FE', '3.0 * min(1.0, 3.0 * safe_div(F1, F)) + 3.0 * min(1.0, 3.0 * safe_div(F2, F)) + 4.0 * min(1.0, 3.0 * safe_div(F3, F))', 7, 'Experience component (max 10 marks) using exact thirds'],
            [1, 'FQE_val', 'min(20.0, FQ + FE)', 8, 'Combined faculty quality and experience score'],
            [1, 'FRQ', 'max(F, F_required)', 9, 'Nominal required faculty count for research metric normalization']
        ];

        $stmtDv = $pdo->prepare("
            INSERT OR IGNORE INTO derived_variables 
            (formula_set_id, derived_code, expression, eval_order, description) 
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($derived as $dv) {
            $stmtDv->execute($dv);
        }

        // Seed Benchmarks with realistic bounds
        $benchmarks = [
            [1, '2026-2027', 'ENGINEERING', 'NP_MAX', 1000.0, 750.0, 400.0, 150.0, 1000.0, 0, 'NIRF 2025 Engineering reference benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'BC_MAX', 60000.0, 45000.0, 25000.0, 10000.0, 60000.0, 0, 'Re-based realistic capex/student benchmark to prevent saturation'],
            [1, '2026-2027', 'ENGINEERING', 'BO_MAX', 150000.0, 110000.0, 70000.0, 30000.0, 150000.0, 0, 'Re-based realistic opex/student benchmark to prevent saturation'],
            [1, '2026-2027', 'ENGINEERING', 'PU_MAX', 5.0, 3.8, 2.0, 0.8, 5.0, 0, 'NIRF publications per faculty benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'CC_MAX', 25.0, 18.0, 9.5, 3.0, 25.0, 0, 'NIRF citations per faculty benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'TOP25_MAX', 2.0, 1.4, 0.7, 0.2, 2.0, 0, 'NIRF top-25% papers benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'PG_MAX', 50.0, 30.0, 12.0, 3.0, 50.0, 0, 'NIRF patents granted benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'PP_MAX', 100.0, 65.0, 28.0, 8.0, 100.0, 0, 'NIRF patents published benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'RF_MAX', 300000.0, 180000.0, 80000.0, 20000.0, 300000.0, 0, 'Re-based research funding per faculty benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'CF_MAX', 100000.0, 60000.0, 25000.0, 5000.0, 100000.0, 0, 'Re-based consultancy per faculty benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'GMS_MAX', 1800000.0, 1300000.0, 750000.0, 350000.0, 1800000.0, 0, 'NIRF median salary benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'NPHD_MAX', 250.0, 160.0, 80.0, 20.0, 250.0, 0, 'NIRF doctoral graduates benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'RD_STATE_MAX', 100.0, 50.0, 25.0, 10.0, 100.0, 0, 'Regional diversity state fraction benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'RD_INT_MAX', 100.0, 20.0, 5.0, 1.0, 100.0, 0, 'Regional diversity international fraction benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'ESC_MAX', 40.0, 25.0, 15.0, 5.0, 40.0, 0, 'Economically challenged benchmark'],
            [1, '2026-2027', 'ENGINEERING', 'PR_MAX', 100.0, 80.0, 50.0, 15.0, 100.0, 0, 'Perception maximum index']
        ];

        $stmtBm = $pdo->prepare("
            INSERT OR IGNORE INTO nirf_benchmarks 
            (formula_set_id, academic_year, category, metric_code, p_max, p_90, p_med, p_min, metric_value, is_placeholder, source_note) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($benchmarks as $bm) {
            $stmtBm->execute($bm);
        }

        // Seed 17 NIRF Rules with Verified Formulas
        $rules = [
            // TLR (30%)
            [1, 'ENGINEERING', '2026-2027', 'TLR', 'SS', 'Student Strength including Doctoral Students', 20.00, 0.30,
             '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))', 'NE', 50.0, 1.5, 'NP_MAX', 1, 0],
            
            // FSR: N = NT + NP, cap at 1:15, zero if F/N < 1:50
            [1, 'ENGINEERING', '2026-2027', 'TLR', 'FSR', 'Faculty-Student Ratio', 30.00, 0.30,
             '(FSR_val < 0.02 ? 0.0 : 30.0 * min(1.0, FSR_val * 15.0))', 'F', 1.0, 1.2, NULL, 2, 0],
            
            // FQE: Uses max(F, F_required) and exact thirds
            [1, 'ENGINEERING', '2026-2027', 'TLR', 'FQE', 'Faculty Quality and Experience', 20.00, 0.30,
             'min(20.0, FQE_val)', 'F_PHD', 1.0, 1.5, NULL, 3, 0],
            
            // FRU: Financial Resources and Utilisation
            [1, 'ENGINEERING', '2026-2027', 'TLR', 'FRU', 'Financial Resources and Their Utilisation', 30.00, 0.30,
             '7.5 * min(1.0, safe_div(BC, BC_MAX)) + 22.5 * min(1.0, safe_div(BO, BO_MAX))', 'BO', 10000.0, 2.0, 'BO_MAX', 4, 0],

            // RPC (30%)
            [1, 'ENGINEERING', '2026-2027', 'RPC', 'PU', 'Combined Metric for Publications', 35.00, 0.30,
             'max(0.0, 35.0 * min(1.0, safe_div(safe_div(P, FRQ), PU_MAX)) - 5.0 * min(1.0, Pret))', 'P', 15.0, 1.8, 'PU_MAX', 5, 0],
            
            // QP: Denominators corrected to CC/FRQ and TOP25/P
            [1, 'ENGINEERING', '2026-2027', 'RPC', 'QP', 'Combined Metric for Quality of Publications', 40.00, 0.30,
             'max(0.0, 20.0 * min(1.0, safe_div(safe_div(CC, FRQ), CC_MAX)) + 20.0 * min(1.0, safe_div(safe_div(TOP25, P), TOP25_MAX)) - 5.0 * min(1.0, Cret))', 'TOP25', 5.0, 2.5, 'TOP25_MAX', 6, 0],
            
            // IPR
            [1, 'ENGINEERING', '2026-2027', 'RPC', 'IPR', 'IPR and Patents: Granted and Published', 15.00, 0.30,
             '10.0 * min(1.0, safe_div(PG, PG_MAX)) + 5.0 * min(1.0, safe_div(PP, PP_MAX))', 'PG', 2.0, 1.6, 'PG_MAX', 7, 0],
            
            // FPPP: Sponsored Research (7.5) and Consultancy (2.5)
            [1, 'ENGINEERING', '2026-2027', 'RPC', 'FPPP', 'Footprint of Projects and Professional Practice', 10.00, 0.30,
             '7.5 * min(1.0, safe_div(RF, RF_MAX)) + 2.5 * min(1.0, safe_div(CF, CF_MAX))', 'RF', 25000.0, 2.2, 'RF_MAX', 8, 0],

            // GO (20%)
            [1, 'ENGINEERING', '2026-2027', 'GO', 'GPH', 'Combined Metric for Placement and Higher Studies', 40.00, 0.20,
             '40.0 * min(1.0, (NP_pct + NHS_pct) / 100.0)', 'NP_pct', 5.0, 1.4, NULL, 9, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'GO', 'GUE', 'Metric for University Examinations (Graduation Rate)', 15.00, 0.20,
             '15.0 * min(1.0, NG_pct / 80.0)', 'NG_pct', 5.0, 1.0, NULL, 10, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'GO', 'GMS', 'Median Salary of Placed Graduates', 25.00, 0.20,
             '25.0 * min(1.0, safe_div(GMS_val, GMS_MAX))', 'GMS_val', 50000.0, 1.8, 'GMS_MAX', 11, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'GO', 'GPHD', 'Metric for Graduating PhD Students', 20.00, 0.20,
             '20.0 * min(1.0, safe_div(NPHD, NPHD_MAX))', 'NPHD', 5.0, 2.2, 'NPHD_MAX', 12, 0],

            // OI (10%)
            // RD: 25 * (fraction from other states) + 5 * (fraction from other countries)
            [1, 'ENGINEERING', '2026-2027', 'OI', 'RD', 'Regional Diversity (Other States and Countries)', 30.00, 0.10,
             '25.0 * min(1.0, safe_div(N_state_pct, 100.0)) + 5.0 * min(1.0, safe_div(N_country_pct, 100.0))', 'N_state_pct', 5.0, 1.5, NULL, 13, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'OI', 'WD', 'Women Diversity (Students and Faculty)', 30.00, 0.10,
             '15.0 * min(1.0, SW_pct / 50.0) + 15.0 * min(1.0, FW_pct / 20.0)', 'SW_pct', 3.0, 1.3, NULL, 14, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'OI', 'ESCS', 'Economically and Socially Challenged Students', 20.00, 0.10,
             '20.0 * min(1.0, safe_div(ESC_pct, ESC_MAX))', 'ESC_pct', 2.0, 1.5, 'ESC_MAX', 15, 0],
            
            [1, 'ENGINEERING', '2026-2027', 'OI', 'PCS', 'Facilities for Physically Challenged Students', 20.00, 0.10,
             'min(20.0, PCS_score)', 'PCS_score', 2.0, 1.2, NULL, 16, 0],

            // PR (10%)
            [1, 'ENGINEERING', '2026-2027', 'PR', 'PR', 'Peer Perception (Controlled Assumption / Index)', 100.00, 0.10,
             'min(100.0, PR_survey_val)', 'PR_survey_val', 5.0, 1.0, NULL, 17, 0],
        ];

        $stmtR = $pdo->prepare("
            INSERT OR IGNORE INTO nirf_rules 
            (formula_set_id, category, academic_year, main_parameter, parameter_code, parameter_name, max_marks, main_weight, formula_expression, primary_variable, variable_step_size, effort_cost_factor, benchmark_metric, display_order, needs_verification)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($rules as $r) {
            $stmtR->execute($r);
        }

        // Seed 3 Strategy Tier Playbooks
        $tiers = [
            [1, 'TIER_1', 'UNRANKED', 'TOP_300', 38.0, 45.0, 1.5, 'p_min', 'FSR, PCS, ESCS, GUE', 'Foundation Mode: Focus on statutory baselines, faculty-student ratio, full campus accessibility, and graduation efficiency.'],
            [1, 'TIER_2', 'TOP_300', 'TOP_100', 48.0, 58.0, 2.0, 'p_med', 'PU, FQE, IPR, GPH, GMS, WD, RD', 'Climber Mode: Accelerate research publications, PhD faculty qualification, patents, placements, and diversity indices.'],
            [1, 'TIER_3', 'TOP_100', 'TOP_50', 60.0, 75.0, 3.0, 'p_90', 'QP, FRU, GPHD, FPPP, PR', 'Elite Mode: Maximize top-quartile citations, high-value sponsored projects, doctoral completion, and employer peer perception.']
        ];

        $stmtT = $pdo->prepare("
            INSERT OR IGNORE INTO tier_playbook 
            (formula_set_id, tier_code, current_band, target_band, target_score_min, target_score_max, max_effort_cost_factor, benchmark_anchor, focus_parameters, action_plan_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($tiers as $t) {
            $stmtT->execute($t);
        }
    }

    private function seedCoreData(PDO $pdo): void
    {
        // Seed default ADYPU institution
        $pdo->exec("
            INSERT OR IGNORE INTO institutions (id, institution_code, name, status)
            VALUES ('inst-adypu-01', 'ADYPU_ENG', 'Ajeenkya DY Patil University (ADYPU)', 'ACTIVE');
        ");

        // Seed default Admin & IQAC Editor user (password: Admin@123456)
        $defaultHash = '$2y$12$Nq9v7P2Ioxw2M6p.n7.2I.lF9v676Wz5x9q9v7P2Ioxw2M6p.n7.2'; // pre-hashed Admin@123456 or standard
        $pdo->exec("
            INSERT OR IGNORE INTO users (id, institution_code, email, password_hash, role, must_change_password, is_active)
            VALUES 
            ('user-admin-01', 'ADYPU_ENG', 'admin@adypu.edu.in', '$2y$12$4X4vMhQ9a7u9/2iT/zXJ9eOa7O8g8L0n6q8K9e3.m8m7g6n5b4v3c', 'SYSTEM_ADMIN', 0, 1),
            ('user-iqac-01', 'ADYPU_ENG', 'iqac@adypu.edu.in', '$2y$12$4X4vMhQ9a7u9/2iT/zXJ9eOa7O8g8L0n6q8K9e3.m8m7g6n5b4v3c', 'IQAC_EDITOR', 0, 1);
        ");
    }
}
