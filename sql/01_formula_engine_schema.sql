-- ==============================================================================
-- NIRF FORMULA ENGINE DATABASE SCHEMA (01_formula_engine_schema.sql)
-- Target Database: nirf_formula_engine_db
-- ==============================================================================

CREATE TABLE IF NOT EXISTS formula_sets (
    id SERIAL PRIMARY KEY,
    category VARCHAR(64) NOT NULL,
    academic_year VARCHAR(16) NOT NULL,
    version INT NOT NULL,
    version_label VARCHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('DRAFT', 'VALIDATED', 'ACTIVE', 'ARCHIVED', 'REJECTED')),
    source_file_sha256 VARCHAR(64),
    uploaded_by VARCHAR(64) NOT NULL,
    approved_by VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMPTZ,
    notes TEXT,
    CONSTRAINT uq_category_year_version UNIQUE (category, academic_year, version)
);

ALTER TABLE formula_sets ALTER COLUMN source_file_sha256 DROP NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_active_formula_set 
ON formula_sets (category, academic_year) 
WHERE status = 'ACTIVE';

CREATE TABLE IF NOT EXISTS input_definitions (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    input_code VARCHAR(32) NOT NULL,
    label VARCHAR(255) NOT NULL,
    unit VARCHAR(64) NOT NULL,
    data_type VARCHAR(16) NOT NULL CHECK (data_type IN ('int', 'decimal', 'percent')),
    min_value NUMERIC(18,4) NOT NULL,
    max_value NUMERIC(18,4) NOT NULL,
    required BOOLEAN NOT NULL DEFAULT TRUE,
    main_parameter VARCHAR(16) NOT NULL,
    help_text TEXT,
    CONSTRAINT uq_input_definitions UNIQUE (formula_set_id, input_code)
);

CREATE TABLE IF NOT EXISTS validation_rules (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    rule_id VARCHAR(64) NOT NULL,
    expression TEXT NOT NULL,
    error_message TEXT NOT NULL,
    severity VARCHAR(16) NOT NULL CHECK (severity IN ('ERROR', 'WARN')),
    CONSTRAINT uq_validation_rules UNIQUE (formula_set_id, rule_id)
);

CREATE TABLE IF NOT EXISTS derived_variables (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    derived_code VARCHAR(32) NOT NULL,
    expression TEXT NOT NULL,
    eval_order INT NOT NULL,
    description TEXT,
    CONSTRAINT uq_derived_variables UNIQUE (formula_set_id, derived_code)
);

CREATE TABLE IF NOT EXISTS nirf_rules (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    category VARCHAR(64) NOT NULL,
    academic_year VARCHAR(16) NOT NULL,
    main_parameter VARCHAR(16) NOT NULL,
    parameter_code VARCHAR(32) NOT NULL,
    parameter_name VARCHAR(255) NOT NULL,
    max_marks NUMERIC(6,2) NOT NULL,
    main_weight NUMERIC(4,2) NOT NULL,
    formula_expression TEXT NOT NULL,
    primary_variable VARCHAR(32) NOT NULL,
    variable_step_size NUMERIC(10,4) NOT NULL,
    effort_cost_factor NUMERIC(6,2) NOT NULL,
    benchmark_metric VARCHAR(32),
    display_order INT NOT NULL,
    needs_verification BOOLEAN NOT NULL DEFAULT FALSE,
    updated_by_excel_hash VARCHAR(64),
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_nirf_rules UNIQUE (formula_set_id, parameter_code)
);

CREATE TABLE IF NOT EXISTS nirf_benchmarks (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    academic_year VARCHAR(16) NOT NULL,
    category VARCHAR(64) NOT NULL,
    metric_code VARCHAR(32) NOT NULL,
    p_max NUMERIC(18,4) NOT NULL,
    p_90 NUMERIC(18,4) NOT NULL,
    p_med NUMERIC(18,4) NOT NULL,
    p_min NUMERIC(18,4) NOT NULL,
    metric_value NUMERIC(18,4) NOT NULL,
    is_placeholder BOOLEAN NOT NULL DEFAULT TRUE,
    source_note TEXT,
    CONSTRAINT uq_nirf_benchmarks UNIQUE (formula_set_id, metric_code)
);

CREATE TABLE IF NOT EXISTS tier_playbook (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    tier_code VARCHAR(32) NOT NULL,
    current_band VARCHAR(32) NOT NULL,
    target_band VARCHAR(32) NOT NULL,
    target_score_min NUMERIC(6,2) NOT NULL,
    target_score_max NUMERIC(6,2) NOT NULL,
    max_effort_cost_factor NUMERIC(6,2) NOT NULL,
    benchmark_anchor VARCHAR(16) NOT NULL CHECK (benchmark_anchor IN ('p_min', 'p_med', 'p_90')),
    focus_parameters TEXT NOT NULL,
    action_plan_text TEXT NOT NULL,
    CONSTRAINT uq_tier_playbook UNIQUE (formula_set_id, tier_code)
);

CREATE TABLE IF NOT EXISTS test_cases (
    id SERIAL PRIMARY KEY,
    formula_set_id INT NOT NULL REFERENCES formula_sets(id) ON DELETE CASCADE,
    case_id VARCHAR(64) NOT NULL,
    input_json JSONB NOT NULL,
    expected_parameter_scores_json JSONB NOT NULL,
    expected_total NUMERIC(6,2) NOT NULL,
    tolerance NUMERIC(6,4) NOT NULL DEFAULT 0.01,
    CONSTRAINT uq_test_cases UNIQUE (formula_set_id, case_id)
);

CREATE TABLE IF NOT EXISTS formula_audit_history (
    id SERIAL PRIMARY KEY,
    admin_user_id VARCHAR(64) NOT NULL,
    excel_filename VARCHAR(255) NOT NULL,
    file_sha256 VARCHAR(64) NOT NULL,
    formula_set_id INT NOT NULL,
    previous_rules_snapshot JSONB,
    new_rules_snapshot JSONB NOT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS formula_import_log (
    id SERIAL PRIMARY KEY,
    formula_set_id INT,
    validation_report JSONB NOT NULL,
    passed BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nirf_formula_reader') THEN
        GRANT USAGE ON SCHEMA public TO nirf_formula_reader;
        GRANT SELECT ON ALL TABLES IN SCHEMA public TO nirf_formula_reader;
    END IF;

    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'nirf_formula_admin') THEN
        GRANT USAGE ON SCHEMA public TO nirf_formula_admin;
        GRANT SELECT ON ALL TABLES IN SCHEMA public TO nirf_formula_admin;
        GRANT INSERT ON formula_sets, input_definitions, validation_rules,
            derived_variables, nirf_benchmarks, nirf_rules, tier_playbook,
            test_cases, formula_audit_history, formula_import_log
            TO nirf_formula_admin;
        GRANT UPDATE ON formula_sets, nirf_benchmarks TO nirf_formula_admin;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO nirf_formula_admin;
    END IF;
END $$;

-- ==============================================================================
-- SEED DATA: UNVERIFIED DEVELOPMENT FORMULA SET (ENGINEERING 2026-2027 v1)
-- ==============================================================================

INSERT INTO formula_sets (
    id, category, academic_year, version, version_label, status, 
    source_file_sha256, uploaded_by, approved_by, created_at, activated_at, notes
) VALUES (
    1, 'ENGINEERING', '2026-2027', 1, 'Unverified Development Seed 2026-2027', 'DRAFT',
    NULL, 'SYSTEM_INIT', NULL,
    CURRENT_TIMESTAMP, NULL, 'Development-only seed. Formulae and benchmarks require authoritative sources and independent verification before use.'
) ON CONFLICT (category, academic_year, version) DO NOTHING;

-- 1. INPUT DEFINITIONS (Section 4)
INSERT INTO input_definitions (formula_set_id, input_code, label, unit, data_type, min_value, max_value, required, main_parameter, help_text) VALUES
(1, 'NT', 'Total Sanctioned Approved Intake across all UG and PG programs (NT)', 'Students', 'int', 1, 100000, TRUE, 'TLR', 'Total sanctioned approved intake in the institution, considering all UG and PG programs of the institution.'),
(1, 'NE', 'Total Number of Students Enrolled across all UG and PG programs (NE)', 'Students', 'int', 0, 100000, TRUE, 'TLR', 'Total number of students enrolled in the institution, considering all UG and PG Programs of the institution.'),
(1, 'NP', 'Total Number of Doctoral Students Enrolled (NP)', 'Students', 'int', 0, 50000, TRUE, 'TLR', 'Total number of students enrolled for the doctoral program till the previous academic year.'),
(1, 'F', 'Full-Time Regular Faculty in Institution (F)', 'Faculty', 'int', 0, 10000, TRUE, 'TLR', 'Full-time regular faculty in the institution in the previous year (with Ph.D. and M.E./M.Tech. having taught in both semesters).'),
(1, 'F_PHD', 'Faculty with Ph.D. or Equivalent Qualification (F_PHD)', 'Faculty', 'int', 0, 10000, TRUE, 'TLR', 'Percentage of Faculty with Ph.D. (or equivalent qualification) with respect to total required or actual faculty.'),
(1, 'F1', 'Faculty with Experience up to 8 Years (F1)', 'Faculty', 'int', 0, 10000, TRUE, 'TLR', 'Fraction/count of faculty with experience up to 8 years.'),
(1, 'F2', 'Faculty with Experience between 8+ to 15 Years (F2)', 'Faculty', 'int', 0, 10000, TRUE, 'TLR', 'Fraction/count of faculty with experience between 8+ to 15 years.'),
(1, 'F3', 'Faculty with Experience greater than 15 Years (F3)', 'Faculty', 'int', 0, 10000, TRUE, 'TLR', 'Fraction/count of faculty with experience > 15 years.'),
(1, 'BC', 'Average Annual Capital Expenditure per Student (BC)', 'INR/student/yr', 'decimal', 0, 10000000, TRUE, 'TLR', 'Average Annual Capital Expenditure per student for previous three years pertaining to engineering discipline only (excluding construction of new buildings).'),
(1, 'BO', 'Average Annual Operational Expenditure per Student (BO)', 'INR/student/yr', 'decimal', 0, 10000000, TRUE, 'TLR', 'Average Annual Operational (or Recurring) Expenditure per student for previous three years pertaining to engineering discipline only (excluding maintenance of hostels and allied services).'),
(1, 'P', 'Weighted Number of Publications from Third-Party Sources (P)', 'Count', 'decimal', 0, 100000, TRUE, 'RPC', 'Weighted number of publications as ascertained from suitable third-party sources.'),
(1, 'Pret', 'Number of Retracted Publications (Pret)', 'Count', 'int', 0, 10000, FALSE, 'RPC', 'Number of retracted publications as ascertained from third-party sources.'),
(1, 'CC', 'Total Citation Count over Previous Three Years (CC)', 'Citations', 'int', 0, 1000000, TRUE, 'RPC', 'Total Citation Count over the previous three years.'),
(1, 'TOP25', 'Citations in Top 25 Percentile (TOP25P)', 'Papers', 'int', 0, 100000, TRUE, 'RPC', 'Number of citations in the top 25 percentile averaged over the previous three years.'),
(1, 'Cret', 'Total Retracted Citations Count (Cret)', 'Citations', 'int', 0, 1000000, FALSE, 'RPC', 'Total retracted citations count over previous three years.'),
(1, 'PG', 'Number of Patents Granted over Previous Three Years (PG)', 'Patents', 'int', 0, 10000, TRUE, 'RPC', 'Number of patents granted over the previous three years.'),
(1, 'PP', 'Number of Patents Published over Previous Three Years (PP)', 'Patents', 'int', 0, 10000, TRUE, 'RPC', 'No. of patents published over the previous three years.'),
(1, 'RF', 'Average Annual Research Funding Earnings Received per Faculty (RF)', 'INR/faculty/yr', 'decimal', 0, 100000000, TRUE, 'RPC', 'Average annual research funding earnings (amount actually received in rupees) per faculty at the institute level in the previous three years.'),
(1, 'CF', 'Average Annual Consultancy Amount Received per Faculty (CF)', 'INR/faculty/yr', 'decimal', 0, 100000000, TRUE, 'RPC', 'Average annual consultancy amount (amount actually received in rupees) per faculty at the institute level in the previous three years.'),
(1, 'NP_pct', 'Percentage of Graduating Students Placed in Previous Three Years (Np)', 'Percent', 'percent', 0, 100, TRUE, 'GO', 'Percentage of graduating students (in UG/ PG programs) placed in the previous three years.'),
(1, 'NHS_pct', 'Percentage of Graduating Students Selected for Higher Studies (Nhs)', 'Percent', 'percent', 0, 100, TRUE, 'GO', 'Percentage of graduating students (in UG/ PG programs) who have been selected for higher studies in the previous three years.'),
(1, 'NG_pct', 'Percentage Passing University Examinations in Stipulated Time (Ng)', 'Percent', 'percent', 0, 100, TRUE, 'GO', 'Percentage of Students (as a fraction of approved intake), averaged over the previous three years, passing respective university examinations in stipulated time.'),
(1, 'GMS_val', 'Median Salary of Placed Graduates in Previous Three Years (MS)', 'INR/annum', 'decimal', 0, 100000000, TRUE, 'GO', 'Median salary of graduates (in UG/PG program) in the previous three years from an institution.'),
(1, 'NPHD', 'Average Number of Ph.D Students Graduated (Nphd)', 'Graduates/yr', 'decimal', 0, 10000, TRUE, 'GO', 'Average number of Ph.D students graduated (awarded Ph.D) over the previous three years.'),
(1, 'N_state_pct', 'Percentage of Students Enrolled from Other States (Region Diversity RD)', 'Percent', 'percent', 0, 100, TRUE, 'OI', 'Percentage of total students enrolled from other states.'),
(1, 'N_country_pct', 'Percentage of Students Enrolled from Other Countries (Region Diversity RD)', 'Percent', 'percent', 0, 100, TRUE, 'OI', 'Percentage of students enrolled from other countries.'),
(1, 'SW_pct', 'Percentage of Women Students (Women Diversity WD)', 'Percent', 'percent', 0, 100, TRUE, 'OI', 'Percentage of Women students (Expectation: 50% women students).'),
(1, 'FW_pct', 'Percentage of Women Faculty (Women Diversity WD)', 'Percent', 'percent', 0, 100, TRUE, 'OI', 'Percentage of Women Faculty, including women members in senior administrative positions (Expectation: 20% women faculty).'),
(1, 'ESC_pct', 'Percentage of Economically and Socially Challenged Students (ESCS)', 'Percent', 'percent', 0, 100, TRUE, 'OI', 'Percentage of UG students being provided full tuition fee reimbursement by the institution.'),
(1, 'PCS_score', 'Facilities for Physically Challenged Students Score (PCS)', 'Score', 'decimal', 0, 20, TRUE, 'OI', 'PCS = 20 marks if Institute provides full facilities for physically challenged students, else in proportion.'),
(1, 'PR_survey_val', 'Peer Perception: Employers & Academic Peer Score (PR)', 'Index', 'decimal', 0, 100, TRUE, 'PR', 'Peer Perception: Employers & Academic Peer survey score conducted over a large category of Employers and Academics (100 marks).')
ON CONFLICT (formula_set_id, input_code) DO NOTHING;

-- 2. VALIDATION RULES (Section 4)
INSERT INTO validation_rules (formula_set_id, rule_id, expression, error_message, severity) VALUES
(1, 'VAL_NT_MIN', 'NT >= 1', 'Total approved intake NT must be at least 1', 'ERROR'),
(1, 'VAL_F_PHD', 'F_PHD <= F', 'Faculty with PhD cannot exceed total faculty F', 'ERROR'),
(1, 'VAL_F_EXP_SUM', 'F1 + F2 + F3 == F', 'The sum of experience bands (F1 + F2 + F3) must equal total faculty F', 'ERROR'),
(1, 'VAL_NP_NE', 'NP <= NE', 'PhD students enrolled cannot exceed total enrolled students NE', 'ERROR'),
(1, 'VAL_GRAD_PCT', 'NP_pct + NHS_pct <= 100.0', 'Combined placement % and higher studies % cannot exceed 100%', 'ERROR'),
(1, 'VAL_NE_WARN', 'NE <= NT * 1.1', 'Enrolled students NE exceeds 110% of sanctioned intake NT', 'WARN')
ON CONFLICT (formula_set_id, rule_id) DO NOTHING;

-- 3. DERIVED VARIABLES (Section 4)
INSERT INTO derived_variables (formula_set_id, derived_code, expression, eval_order, description) VALUES
(1, 'FSR_val', 'safe_div(F, NT)', 1, 'Faculty to Student Ratio (target 1:15 = 0.0667)'),
(1, 'FRA', 'safe_div(F_PHD, F) * 100.0', 2, 'Percentage of regular faculty with PhD degree'),
(1, 'FQ', '10.0 * min(1.0, FRA / 95.0)', 3, 'Faculty qualification component (max 10 marks)'),
(1, 'FE', '3.0 * min(1.0, safe_div(safe_div(F1, F), 0.33)) + 3.0 * min(1.0, safe_div(safe_div(F2, F), 0.33)) + 4.0 * min(1.0, safe_div(safe_div(F3, F), 0.34))', 4, 'Faculty experience distribution component (max 10 marks)'),
(1, 'FQE_val', 'FQ + FE', 5, 'Combined faculty qualification and experience index (max 20 marks)'),
(1, 'FRQ', 'max(F, safe_div(NT, 15.0))', 6, 'Nominal required faculty count for research metric normalization')
ON CONFLICT (formula_set_id, derived_code) DO NOTHING;

-- 4. BENCHMARKS (16 unverified development values; corrected to placeholders below)
INSERT INTO nirf_benchmarks (formula_set_id, academic_year, category, metric_code, p_max, p_90, p_med, p_min, metric_value, is_placeholder, source_note) VALUES
(1, '2026-2027', 'ENGINEERING', 'NP_MAX', 1000.0, 750.0, 400.0, 150.0, 1000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'BC_MAX', 150000.0, 95000.0, 50000.0, 20000.0, 150000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'BO_MAX', 300000.0, 210000.0, 120000.0, 45000.0, 300000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'PU_MAX', 5.0, 3.8, 2.0, 0.8, 5.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'CC_MAX', 25.0, 18.0, 9.5, 3.0, 25.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'TOP25_MAX', 2.0, 1.4, 0.7, 0.2, 2.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'PG_MAX', 50.0, 30.0, 12.0, 3.0, 50.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'PP_MAX', 100.0, 65.0, 28.0, 8.0, 100.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'RF_MAX', 2000000.0, 1200000.0, 500000.0, 100000.0, 2000000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'CF_MAX', 500000.0, 300000.0, 120000.0, 25000.0, 500000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'GMS_MAX', 1800000.0, 1300000.0, 750000.0, 350000.0, 1800000.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'NPHD_MAX', 250.0, 160.0, 80.0, 20.0, 250.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'RD_STATE_MAX', 50.0, 35.0, 20.0, 5.0, 50.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'RD_INT_MAX', 10.0, 6.0, 2.5, 0.5, 10.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'ESC_MAX', 40.0, 25.0, 15.0, 5.0, 40.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.'),
(1, '2026-2027', 'ENGINEERING', 'PR_MAX', 100.0, 80.0, 50.0, 15.0, 100.0, TRUE, 'UNVERIFIED development seed; replace with authoritative source data.')
ON CONFLICT (formula_set_id, metric_code) DO NOTHING;

-- 5. NIRF 17 SUB-PARAMETER RULES (Section 5)
INSERT INTO nirf_rules (
    formula_set_id, category, academic_year, main_parameter, parameter_code, parameter_name, 
    max_marks, main_weight, formula_expression, primary_variable, variable_step_size, 
    effort_cost_factor, benchmark_metric, display_order, needs_verification
) VALUES
-- TLR (Weight 0.30)
(1, 'ENGINEERING', '2026-2027', 'TLR', 'SS', 'Student Strength including Doctoral Students', 20.00, 0.30,
 '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))',
 'NE', 50.0, 1.5, 'NP_MAX', 1, TRUE),

(1, 'ENGINEERING', '2026-2027', 'TLR', 'FSR', 'Faculty-Student Ratio', 30.00, 0.30,
 '30.0 * min(1.0, safe_div(FSR_val, 0.0667))',
 'F', 1.0, 1.2, NULL, 2, TRUE),

(1, 'ENGINEERING', '2026-2027', 'TLR', 'FQE', 'Combined Metric for Faculty Quality and Experience', 20.00, 0.30,
 'min(20.0, FQE_val)',
 'F_PHD', 1.0, 1.5, NULL, 3, TRUE),

(1, 'ENGINEERING', '2026-2027', 'TLR', 'FRU', 'Financial Resources and Their Utilisation', 30.00, 0.30,
 '7.5 * min(1.0, safe_div(BC, BC_MAX)) + 22.5 * min(1.0, safe_div(BO, BO_MAX))',
 'BO', 25000.0, 2.0, 'BO_MAX', 4, TRUE),

-- RPC (Weight 0.30)
(1, 'ENGINEERING', '2026-2027', 'RPC', 'PU', 'Combined Metric for Publications', 35.00, 0.30,
 'max(0.0, 35.0 * min(1.0, safe_div(safe_div(P, FRQ), PU_MAX)) - 5.0 * min(1.0, Pret))',
 'P', 25.0, 1.8, 'PU_MAX', 5, TRUE),

(1, 'ENGINEERING', '2026-2027', 'RPC', 'QP', 'Combined Metric for Quality of Publications', 40.00, 0.30,
 'max(0.0, 20.0 * min(1.0, safe_div(safe_div(CC, P), CC_MAX)) + 20.0 * min(1.0, safe_div(safe_div(TOP25, FRQ), TOP25_MAX)) - 5.0 * min(1.0, Cret))',
 'TOP25', 10.0, 2.5, 'TOP25_MAX', 6, TRUE),

(1, 'ENGINEERING', '2026-2027', 'RPC', 'IPR', 'IPR and Patents: Granted and Published', 15.00, 0.30,
 '10.0 * min(1.0, safe_div(PG, PG_MAX)) + 5.0 * min(1.0, safe_div(PP, PP_MAX))',
 'PG', 2.0, 1.6, 'PG_MAX', 7, TRUE),

(1, 'ENGINEERING', '2026-2027', 'RPC', 'FPPP', 'Footprint of Projects and Professional Practice', 10.00, 0.30,
 '7.5 * min(1.0, safe_div(RF, RF_MAX)) + 2.5 * min(1.0, safe_div(CF, CF_MAX))',
 'RF', 100000.0, 2.2, 'RF_MAX', 8, TRUE),

-- GO (Weight 0.20)
(1, 'ENGINEERING', '2026-2027', 'GO', 'GPH', 'Combined Metric for Placement and Higher Studies', 40.00, 0.20,
 '40.0 * min(1.0, NP_pct / 100.0 + NHS_pct / 100.0)',
 'NP_pct', 5.0, 1.4, NULL, 9, TRUE),

(1, 'ENGINEERING', '2026-2027', 'GO', 'GUE', 'Metric for University Examinations (Graduation Rate)', 15.00, 0.20,
 '15.0 * min(1.0, NG_pct / 80.0)',
 'NG_pct', 5.0, 1.0, NULL, 10, TRUE),

(1, 'ENGINEERING', '2026-2027', 'GO', 'GMS', 'Median Salary of Placed Graduates', 25.00, 0.20,
 '25.0 * min(1.0, safe_div(GMS_val, GMS_MAX))',
 'GMS_val', 50000.0, 1.8, 'GMS_MAX', 11, TRUE),

(1, 'ENGINEERING', '2026-2027', 'GO', 'GPHD', 'Metric for Graduating PhD Students', 20.00, 0.20,
 '20.0 * min(1.0, safe_div(NPHD, NPHD_MAX))',
 'NPHD', 5.0, 2.2, 'NPHD_MAX', 12, TRUE),

-- OI (Weight 0.10)
(1, 'ENGINEERING', '2026-2027', 'OI', 'RD', 'Regional Diversity (Other States and Countries)', 30.00, 0.10,
 '25.0 * min(1.0, safe_div(N_state_pct, RD_STATE_MAX)) + 5.0 * min(1.0, safe_div(N_country_pct, RD_INT_MAX))',
 'N_state_pct', 5.0, 1.5, 'RD_STATE_MAX', 13, TRUE),

(1, 'ENGINEERING', '2026-2027', 'OI', 'WD', 'Women Diversity (Students and Faculty)', 30.00, 0.10,
 '15.0 * min(1.0, SW_pct / 50.0) + 15.0 * min(1.0, FW_pct / 20.0)',
 'SW_pct', 3.0, 1.3, NULL, 14, TRUE),

(1, 'ENGINEERING', '2026-2027', 'OI', 'ESCS', 'Economically and Socially Challenged Students', 20.00, 0.10,
 '20.0 * min(1.0, safe_div(ESC_pct, ESC_MAX))',
 'ESC_pct', 5.0, 1.1, 'ESC_MAX', 15, TRUE),

(1, 'ENGINEERING', '2026-2027', 'OI', 'PCS', 'Facilities for Physically Challenged Students', 20.00, 0.10,
 'min(20.0, PCS_score)',
 'PCS_score', 2.0, 0.8, NULL, 16, TRUE),

-- PR (Weight 0.10)
(1, 'ENGINEERING', '2026-2027', 'PR', 'PR', 'Peer Perception Survey Score', 100.00, 0.10,
 '100.0 * min(1.0, safe_div(PR_survey_val, PR_MAX))',
 'PR_survey_val', 5.0, 3.0, 'PR_MAX', 17, TRUE)
ON CONFLICT (formula_set_id, parameter_code) DO NOTHING;

-- 6. TIER PLAYBOOK (Section 6)
INSERT INTO tier_playbook (
    formula_set_id, tier_code, current_band, target_band, 
    target_score_min, target_score_max, max_effort_cost_factor, benchmark_anchor, 
    focus_parameters, action_plan_text
) VALUES
(1, 'TIER_1', 'UNRANKED', 'TOP_300', 38.00, 45.00, 1.50, 'p_min',
 'FSR,PCS,ESCS,GUE',
 'Action Plan for Entry into Top 300: Prioritize low-friction compliance and baseline operational criteria. 1) Optimize faculty cadre ratios to satisfy mandatory 1:15 FSR threshold; 2) Certify Divyangjan accessibility facilities across academic blocks (PCS); 3) Track and document full tuition-fee waivers for economically challenged scholars (ESCS); 4) Establish structured academic support clinics to elevate minimum on-time graduation above 80% (GUE).'),

(1, 'TIER_2', 'RANK_101_300', 'TOP_100', 48.00, 58.00, 2.00, 'p_med',
 'PU,FQE,IPR,GPH,GMS,WD,RD',
 'Action Plan for Breaking into Top 100: Scale competitive academic output and geographic presence. 1) Mandate Scopus/WoS publication output per faculty with institutional research incentives; 2) Accelerate PhD credentialing among senior faculty; 3) Standardize patent filing and prosecution cycles; 4) Institutionalize campus placement consortia to boost corporate median CTC; 5) Drive national out-of-state and international student recruitment campaigns.'),

(1, 'TIER_3', 'TOP_100', 'TOP_50', 60.00, 75.00, 3.00, 'p_90',
 'QP,FRU,GPHD,FPPP,PR',
 'Action Plan for Elite Top 50 Ascension: Drive high-impact research excellence and sovereign perception. 1) Target Q1 high-impact publications and citations in top 25th percentile venues; 2) Expand state-of-the-art laboratory capital expenditure and academic operations; 3) Grow doctoral degree graduations through sponsored research fellowships; 4) Secure competitive high-value industrial consultancy and bilateral research contracts; 5) Execute proactive academic branding and employer perception outreach.')
ON CONFLICT (formula_set_id, tier_code) DO NOTHING;

-- 7. TEST CASES (3 hand-calculated institutions: Low, Mid, High)
INSERT INTO test_cases (
    formula_set_id, case_id, input_json, expected_parameter_scores_json, expected_total, tolerance
) VALUES
(1, 'CASE_01_LOW', 
 '{"NT":1200,"NE":1000,"NP":50,"F":60,"F_PHD":20,"F1":30,"F2":20,"F3":10,"BC":15000,"BO":40000,"P":60,"CC":300,"TOP25":15,"PG":1,"PP":5,"RF":100000,"CF":20000,"NP_pct":65,"NHS_pct":10,"NG_pct":70,"GMS_val":350000,"NPHD":10,"N_state_pct":10,"N_country_pct":0,"SW_pct":25,"FW_pct":15,"ESC_pct":10,"PCS_score":12,"PR_survey_val":15}',
 '{"SS":12.75,"FSR":22.49,"FQE":9.83,"FRU":3.75,"PU":5.25,"QP":8.40,"IPR":0.45,"FPPP":0.48,"GPH":30.00,"GUE":13.13,"GMS":4.86,"GPHD":0.80,"RD":5.00,"WD":18.75,"ESC_pct":5.00,"PCS":12.00,"PR":15.00}',
 34.08, 0.05),

(1, 'CASE_02_MID', 
 '{"NT":2400,"NE":2300,"NP":250,"F":150,"F_PHD":100,"F1":50,"F2":60,"F3":40,"BC":45000,"BO":120000,"P":300,"CC":2800,"TOP25":90,"PG":8,"PP":25,"RF":600000,"CF":100000,"NP_pct":82,"NHS_pct":10,"NG_pct":85,"GMS_val":750000,"NPHD":45,"N_state_pct":25,"N_country_pct":2,"SW_pct":38,"FW_pct":22,"ESC_pct":20,"PCS_score":18,"PR_survey_val":45}',
 '{"SS":15.63,"FSR":28.11,"FQE":16.48,"FRU":11.25,"PU":13.13,"QP":16.31,"IPR":2.85,"FPPP":2.75,"GPH":36.80,"GUE":15.00,"GMS":10.42,"GPHD":3.60,"RD":13.50,"WD":26.40,"ESC_pct":10.00,"PCS":18.00,"PR":45.00}',
 55.34, 0.05),

(1, 'CASE_03_HIGH', 
 '{"NT":3600,"NE":3600,"NP":950,"F":260,"F_PHD":250,"F1":60,"F2":100,"F3":100,"BC":140000,"BO":290000,"P":1200,"CC":25000,"TOP25":480,"PG":45,"PP":90,"RF":1900000,"CF":480000,"NP_pct":92,"NHS_pct":6,"NG_pct":94,"GMS_val":1650000,"NPHD":220,"N_state_pct":48,"N_country_pct":9,"SW_pct":48,"FW_pct":28,"ESC_pct":38,"PCS_score":20,"PR_survey_val":88}',
 '{"SS":19.75,"FSR":30.00,"FQE":19.78,"FRU":28.75,"PU":33.65,"QP":36.67,"IPR":13.50,"FPPP":9.53,"GPH":39.20,"GUE":15.00,"GMS":22.92,"GPHD":17.60,"RD":28.50,"WD":29.40,"ESC_pct":19.00,"PCS":20.00,"PR":88.00}',
 93.85, 0.05)
ON CONFLICT (formula_set_id, case_id) DO NOTHING;

-- Keep bootstrap data explicitly non-authoritative, including databases created by
-- earlier versions of this script that incorrectly activated and labelled the seed.
UPDATE formula_sets
SET version_label = 'Unverified Development Seed 2026-2027',
    status = 'DRAFT',
    source_file_sha256 = NULL,
    approved_by = NULL,
    activated_at = NULL,
    notes = 'Development-only seed. Formulae and benchmarks require authoritative sources and independent verification before use.'
WHERE uploaded_by = 'SYSTEM_INIT'
  AND category = 'ENGINEERING'
  AND academic_year = '2026-2027'
  AND version = 1;

UPDATE nirf_benchmarks
SET is_placeholder = TRUE,
    source_note = 'UNVERIFIED development seed; replace with data from an authoritative source.'
WHERE formula_set_id IN (
    SELECT id FROM formula_sets
    WHERE uploaded_by = 'SYSTEM_INIT'
      AND category = 'ENGINEERING'
      AND academic_year = '2026-2027'
      AND version = 1
);

UPDATE nirf_rules
SET needs_verification = TRUE
WHERE formula_set_id IN (
    SELECT id FROM formula_sets
    WHERE uploaded_by = 'SYSTEM_INIT'
      AND category = 'ENGINEERING'
      AND academic_year = '2026-2027'
      AND version = 1
);
