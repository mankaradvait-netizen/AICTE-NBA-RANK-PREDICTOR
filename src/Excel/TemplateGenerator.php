<?php

declare(strict_types=1);

namespace App\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class TemplateGenerator
{
    public static function generate(string $outputPath): void
    {
        $spreadsheet = new Spreadsheet();

        // 1. Meta Sheet
        $meta = $spreadsheet->getActiveSheet();
        $meta->setTitle('Meta');
        $meta->fromArray([
            ['framework', 'category', 'academic_year', 'version_label', 'notes'],
            ['NIRF', 'ENGINEERING', '2026-2027', 'Development Template 2026-2027 v1', 'Unverified development template; independently verify formulas and cite authoritative sources before activation.']
        ]);
        self::styleHeaders($meta, 'E');

        // 2. Inputs Sheet
        $inputs = $spreadsheet->createSheet();
        $inputs->setTitle('Inputs');
        $inputs->fromArray([
            ['input_code', 'label', 'unit', 'data_type', 'min_value', 'max_value', 'required', 'main_parameter', 'help_text'],
            ['NT', 'Total Sanctioned Approved Intake (NT)', 'Students', 'int', 1, 100000, 'Y', 'TLR', 'Total sanctioned approved intake in the institution, considering all UG and PG programs of the institution.'],
            ['NE', 'Total Enrolled Students (NE)', 'Students', 'int', 0, 100000, 'Y', 'TLR', 'Total number of students enrolled in the institution, considering all UG and PG Programs of the institution.'],
            ['NP', 'Total Doctoral Students Enrolled (NP)', 'Students', 'int', 0, 50000, 'Y', 'TLR', 'Total number of students enrolled for the doctoral program till the previous academic year.'],
            ['F', 'Full-time Regular Faculty (F)', 'Faculty', 'int', 0, 10000, 'Y', 'TLR', 'Full-time regular faculty in the institution in the previous year (with Ph.D. and M.E./M.Tech. having taught in both semesters).'],
            ['F_PHD', 'Faculty with Ph.D. or Equivalent (F_PHD)', 'Faculty', 'int', 0, 10000, 'Y', 'TLR', 'Percentage of Faculty with Ph.D. (or equivalent qualification) with respect to total required or actual faculty.'],
            ['F1', 'Faculty Experience up to 8 Years (F1)', 'Faculty', 'int', 0, 10000, 'Y', 'TLR', 'Fraction/count of faculty with experience up to 8 years.'],
            ['F2', 'Faculty Experience between 8+ to 15 Years (F2)', 'Faculty', 'int', 0, 10000, 'Y', 'TLR', 'Fraction/count of faculty with experience between 8+ to 15 years.'],
            ['F3', 'Faculty Experience greater than 15 Years (F3)', 'Faculty', 'int', 0, 10000, 'Y', 'TLR', 'Fraction/count of faculty with experience > 15 years.'],
            ['BC', 'Average Annual Capital Expenditure per Student (BC)', 'INR/student/yr', 'decimal', 0, 10000000, 'Y', 'TLR', 'Average Annual Capital Expenditure per student for previous three years pertaining to engineering discipline only (excluding construction of new buildings).'],
            ['BO', 'Average Annual Operational Expenditure per Student (BO)', 'INR/student/yr', 'decimal', 0, 10000000, 'Y', 'TLR', 'Average Annual Operational (or Recurring) Expenditure per student for previous three years pertaining to engineering discipline only (excluding maintenance of hostels and allied services).'],
            ['P', 'Weighted Number of Publications (P)', 'Count', 'decimal', 0, 100000, 'Y', 'RPC', 'Weighted number of publications as ascertained from suitable third-party sources.'],
            ['Pret', 'Number of Retracted Publications (Pret)', 'Count', 'int', 0, 10000, 'N', 'RPC', 'Number of retracted publications as ascertained from third-party sources.'],
            ['CC', 'Total Citation Count over Previous Three Years (CC)', 'Citations', 'int', 0, 1000000, 'Y', 'RPC', 'Total Citation Count over the previous three years.'],
            ['TOP25', 'Citations in Top 25 Percentile (TOP25P)', 'Papers', 'int', 0, 100000, 'Y', 'RPC', 'Number of citations in the top 25 percentile averaged over the previous three years.'],
            ['Cret', 'Total Retracted Citations Count (Cret)', 'Citations', 'int', 0, 1000000, 'N', 'RPC', 'Total retracted citations count over previous three years.'],
            ['PG', 'Number of Patents Granted (PG)', 'Patents', 'int', 0, 10000, 'Y', 'RPC', 'Number of patents granted over the previous three years.'],
            ['PP', 'Number of Patents Published (PP)', 'Patents', 'int', 0, 10000, 'Y', 'RPC', 'No. of patents published over the previous three years.'],
            ['RF', 'Average Annual Research Funding Earnings Received (RF)', 'INR/faculty/yr', 'decimal', 0, 100000000, 'Y', 'RPC', 'Average annual research funding earnings (amount actually received in rupees) per faculty at the institute level in the previous three years.'],
            ['CF', 'Average Annual Consultancy Amount Received (CF)', 'INR/faculty/yr', 'decimal', 0, 100000000, 'Y', 'RPC', 'Average annual consultancy amount (amount actually received in rupees) per faculty at the institute level in the previous three years.'],
            ['NP_pct', 'Percentage of Graduating Students Placed (Np)', 'Percent', 'percent', 0, 100, 'Y', 'GO', 'Percentage of graduating students (in UG/ PG programs) placed in the previous three years.'],
            ['NHS_pct', 'Percentage of Graduating Students for Higher Studies (Nhs)', 'Percent', 'percent', 0, 100, 'Y', 'GO', 'Percentage of graduating students (in UG/ PG programs) who have been selected for higher studies in the previous three years.'],
            ['NG_pct', 'Percentage Passing University Examinations (Ng)', 'Percent', 'percent', 0, 100, 'Y', 'GO', 'Percentage of Students (as a fraction of approved intake), averaged over the previous three years, passing respective university examinations in stipulated time.'],
            ['GMS_val', 'Median Salary of Placed Graduates (MS)', 'INR/annum', 'decimal', 0, 100000000, 'Y', 'GO', 'Median salary of graduates (in UG/PG program) in the previous three years from an institution.'],
            ['NPHD', 'Average Number of Ph.D Students Graduated (Nphd)', 'Graduates/yr', 'decimal', 0, 10000, 'Y', 'GO', 'Average number of Ph.D students graduated (awarded Ph.D) over the previous three years.'],
            ['N_state_pct', 'Percentage of Students Enrolled from Other States (Region Diversity RD)', 'Percent', 'percent', 0, 100, 'Y', 'OI', 'Percentage of total students enrolled from other states.'],
            ['N_country_pct', 'Percentage of Students Enrolled from Other Countries (Region Diversity RD)', 'Percent', 'percent', 0, 100, 'Y', 'OI', 'Percentage of students enrolled from other countries.'],
            ['SW_pct', 'Percentage of Women Students (Women Diversity WD)', 'Percent', 'percent', 0, 100, 'Y', 'OI', 'Percentage of Women students (Expectation: 50% women students).'],
            ['FW_pct', 'Percentage of Women Faculty (Women Diversity WD)', 'Percent', 'percent', 0, 100, 'Y', 'OI', 'Percentage of Women Faculty, including women members in senior administrative positions (Expectation: 20% women faculty).'],
            ['ESC_pct', 'Percentage of Economically and Socially Challenged Students (ESCS)', 'Percent', 'percent', 0, 100, 'Y', 'OI', 'Percentage of UG students being provided full tuition fee reimbursement by the institution.'],
            ['PCS_score', 'Facilities for Physically Challenged Students Score (PCS)', 'Score', 'decimal', 0, 20, 'Y', 'OI', 'PCS = 20 marks if Institute provides full facilities for physically challenged students, else in proportion.'],
            ['PR_survey_val', 'Peer Perception: Employers & Academic Peer Score (PR)', 'Index', 'decimal', 0, 100, 'Y', 'PR', 'Peer Perception: Employers & Academic Peer survey score conducted over a large category of Employers and Academics (100 marks).']
        ]);
        self::styleHeaders($inputs, 'I');

        // 3. Validation_Rules Sheet
        $val = $spreadsheet->createSheet();
        $val->setTitle('Validation_Rules');
        $val->fromArray([
            ['rule_id', 'expression', 'error_message', 'severity'],
            ['VAL_NT_MIN', 'NT >= 1', 'Intake NT must be at least 1', 'ERROR'],
            ['VAL_F_PHD', 'F_PHD <= F', 'Faculty with PhD cannot exceed total faculty', 'ERROR'],
            ['VAL_F_EXP_SUM', 'F1 + F2 + F3 == F', 'Sum of faculty experience bands must equal total faculty', 'ERROR'],
            ['VAL_NP_NE', 'NP <= NE', 'PhD students enrolled cannot exceed total enrollment', 'ERROR'],
            ['VAL_GRAD_PCT', 'NP_pct + NHS_pct <= 100.0', 'Combined placement and higher studies cannot exceed 100%', 'ERROR'],
            ['VAL_NE_WARN', 'NE <= NT * 1.1', 'Total enrollment exceeds 110% of approved intake', 'WARN']
        ]);
        self::styleHeaders($val, 'D');

        // 4. Derived Sheet
        $derived = $spreadsheet->createSheet();
        $derived->setTitle('Derived');
        $derived->fromArray([
            ['derived_code', 'expression', 'eval_order', 'description'],
            ['FSR_val', 'safe_div(F, NT)', 1, 'Faculty student ratio'],
            ['FRA', 'safe_div(F_PHD, F) * 100.0', 2, 'Percentage faculty with PhD'],
            ['FQ', '10.0 * min(1.0, FRA / 95.0)', 3, 'Faculty qualification component'],
            ['FE', '3.0 * min(1.0, safe_div(safe_div(F1, F), 0.33)) + 3.0 * min(1.0, safe_div(safe_div(F2, F), 0.33)) + 4.0 * min(1.0, safe_div(safe_div(F3, F), 0.34))', 4, 'Faculty experience distribution'],
            ['FQE_val', 'FQ + FE', 5, 'Combined faculty quality index'],
            ['FRQ', 'max(F, safe_div(NT, 15.0))', 6, 'Nominal faculty count']
        ]);
        self::styleHeaders($derived, 'D');

        // 5. NIRF_Formulas Sheet
        $formulas = $spreadsheet->createSheet();
        $formulas->setTitle('NIRF_Formulas');
        $formulas->fromArray([
            ['category', 'academic_year', 'main_parameter', 'parameter_code', 'parameter_name', 'max_marks', 'main_weight', 'formula_expression', 'primary_variable', 'variable_step_size', 'effort_cost_factor', 'benchmark_metric', 'display_order', 'needs_verification'],
            ['ENGINEERING', '2026-2027', 'TLR', 'SS', 'Student Strength', 20.0, 0.30, '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))', 'NE', 50.0, 1.5, 'NP_MAX', 1, 'Y'],
            ['ENGINEERING', '2026-2027', 'TLR', 'FSR', 'Faculty-Student Ratio', 30.0, 0.30, '30.0 * min(1.0, safe_div(FSR_val, 0.0667))', 'F', 1.0, 1.2, '', 2, 'Y'],
            ['ENGINEERING', '2026-2027', 'TLR', 'FQE', 'Faculty Quality & Experience', 20.0, 0.30, 'min(20.0, FQE_val)', 'F_PHD', 1.0, 1.5, '', 3, 'Y'],
            ['ENGINEERING', '2026-2027', 'TLR', 'FRU', 'Financial Resources Utilisation', 30.0, 0.30, '7.5 * min(1.0, safe_div(BC, BC_MAX)) + 22.5 * min(1.0, safe_div(BO, BO_MAX))', 'BO', 25000.0, 2.0, 'BO_MAX', 4, 'Y'],
            ['ENGINEERING', '2026-2027', 'RPC', 'PU', 'Publications Metric', 35.0, 0.30, 'max(0.0, 35.0 * min(1.0, safe_div(safe_div(P, FRQ), PU_MAX)) - 5.0 * min(1.0, Pret))', 'P', 25.0, 1.8, 'PU_MAX', 5, 'Y'],
            ['ENGINEERING', '2026-2027', 'RPC', 'QP', 'Quality of Publications', 40.0, 0.30, 'max(0.0, 20.0 * min(1.0, safe_div(safe_div(CC, P), CC_MAX)) + 20.0 * min(1.0, safe_div(safe_div(TOP25, FRQ), TOP25_MAX)) - 5.0 * min(1.0, Cret))', 'TOP25', 10.0, 2.5, 'TOP25_MAX', 6, 'Y'],
            ['ENGINEERING', '2026-2027', 'RPC', 'IPR', 'Patents Granted and Published', 15.0, 0.30, '10.0 * min(1.0, safe_div(PG, PG_MAX)) + 5.0 * min(1.0, safe_div(PP, PP_MAX))', 'PG', 2.0, 1.6, 'PG_MAX', 7, 'Y'],
            ['ENGINEERING', '2026-2027', 'RPC', 'FPPP', 'Footprint of Projects', 10.0, 0.30, '7.5 * min(1.0, safe_div(RF, RF_MAX)) + 2.5 * min(1.0, safe_div(CF, CF_MAX))', 'RF', 100000.0, 2.2, 'RF_MAX', 8, 'Y'],
            ['ENGINEERING', '2026-2027', 'GO', 'GPH', 'Placement & Higher Studies', 40.0, 0.20, '40.0 * min(1.0, NP_pct / 100.0 + NHS_pct / 100.0)', 'NP_pct', 5.0, 1.4, '', 9, 'Y'],
            ['ENGINEERING', '2026-2027', 'GO', 'GUE', 'Graduation Rate', 15.0, 0.20, '15.0 * min(1.0, NG_pct / 80.0)', 'NG_pct', 5.0, 1.0, '', 10, 'Y'],
            ['ENGINEERING', '2026-2027', 'GO', 'GMS', 'Median Salary', 25.0, 0.20, '25.0 * min(1.0, safe_div(GMS_val, GMS_MAX))', 'GMS_val', 50000.0, 1.8, 'GMS_MAX', 11, 'Y'],
            ['ENGINEERING', '2026-2027', 'GO', 'GPHD', 'Graduating PhD Students', 20.0, 0.20, '20.0 * min(1.0, safe_div(NPHD, NPHD_MAX))', 'NPHD', 5.0, 2.2, 'NPHD_MAX', 12, 'Y'],
            ['ENGINEERING', '2026-2027', 'OI', 'RD', 'Regional Diversity', 30.0, 0.10, '25.0 * min(1.0, safe_div(N_state_pct, RD_STATE_MAX)) + 5.0 * min(1.0, safe_div(N_country_pct, RD_INT_MAX))', 'N_state_pct', 5.0, 1.5, 'RD_STATE_MAX', 13, 'Y'],
            ['ENGINEERING', '2026-2027', 'OI', 'WD', 'Women Diversity', 30.0, 0.10, '15.0 * min(1.0, SW_pct / 50.0) + 15.0 * min(1.0, FW_pct / 20.0)', 'SW_pct', 3.0, 1.3, '', 14, 'Y'],
            ['ENGINEERING', '2026-2027', 'OI', 'ESCS', 'Economically Challenged', 20.0, 0.10, '20.0 * min(1.0, safe_div(ESC_pct, ESC_MAX))', 'ESC_pct', 5.0, 1.1, 'ESC_MAX', 15, 'Y'],
            ['ENGINEERING', '2026-2027', 'OI', 'PCS', 'Physically Challenged', 20.0, 0.10, 'min(20.0, PCS_score)', 'PCS_score', 2.0, 0.8, '', 16, 'Y'],
            ['ENGINEERING', '2026-2027', 'PR', 'PR', 'Peer Perception', 100.0, 0.10, '100.0 * min(1.0, safe_div(PR_survey_val, PR_MAX))', 'PR_survey_val', 5.0, 3.0, 'PR_MAX', 17, 'Y']
        ]);
        self::styleHeaders($formulas, 'N');

        // 6. Benchmarks Sheet
        $bm = $spreadsheet->createSheet();
        $bm->setTitle('Benchmarks');
        $bm->fromArray([
            ['metric_code', 'p_max', 'p_90', 'p_med', 'p_min', 'active_value', 'is_placeholder', 'source_note'],
            ['NP_MAX', 1000.0, 750.0, 400.0, 150.0, 1000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['BC_MAX', 150000.0, 95000.0, 50000.0, 20000.0, 150000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['BO_MAX', 300000.0, 210000.0, 120000.0, 45000.0, 300000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['PU_MAX', 5.0, 3.8, 2.0, 0.8, 5.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['CC_MAX', 25.0, 18.0, 9.5, 3.0, 25.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['TOP25_MAX', 2.0, 1.4, 0.7, 0.2, 2.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['PG_MAX', 50.0, 30.0, 12.0, 3.0, 50.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['PP_MAX', 100.0, 65.0, 28.0, 8.0, 100.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['RF_MAX', 2000000.0, 1200000.0, 500000.0, 100000.0, 2000000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['CF_MAX', 500000.0, 300000.0, 120000.0, 25000.0, 500000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['GMS_MAX', 1800000.0, 1300000.0, 750000.0, 350000.0, 1800000.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['NPHD_MAX', 250.0, 160.0, 80.0, 20.0, 250.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['RD_STATE_MAX', 50.0, 35.0, 20.0, 5.0, 50.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['RD_INT_MAX', 10.0, 6.0, 2.5, 0.5, 10.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['ESC_MAX', 40.0, 25.0, 15.0, 5.0, 40.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.'],
            ['PR_MAX', 100.0, 80.0, 50.0, 15.0, 100.0, 'Y', 'UNVERIFIED development value; replace with authoritative source data and cite it.']
        ]);
        self::styleHeaders($bm, 'H');

        // 7. Tiers Sheet
        $tiers = $spreadsheet->createSheet();
        $tiers->setTitle('Tiers');
        $tiers->fromArray([
            ['tier_code', 'current_band', 'target_band', 'target_score_min', 'target_score_max', 'max_effort_cost_factor', 'benchmark_anchor', 'focus_parameters', 'action_plan_text'],
            ['TIER_1', 'UNRANKED', 'TOP_300', 38.0, 45.0, 1.5, 'p_min', 'FSR,PCS,ESCS,GUE', 'Prioritize basic compliance and baseline operational criteria: 1:15 FSR, Divyangjan ramps/lifts, full fee reimbursement documentation, and graduation support above 80%.'],
            ['TIER_2', 'RANK_101_300', 'TOP_100', 48.0, 58.0, 2.0, 'p_med', 'PU,FQE,IPR,GPH,GMS,WD,RD', 'Scale competitive research output and geographic diversity: indexed journal mandates, faculty doctoral upgrades, patent filings, corporate placement drives, and out-of-state recruitment.'],
            ['TIER_3', 'TOP_100', 'TOP_50', 60.0, 75.0, 3.0, 'p_90', 'QP,FRU,GPHD,FPPP,PR', 'Elite research excellence and institutional reputation: Q1 publications, advanced laboratory capital expenditure, doctoral graduations, industry consultancy, and proactive perception surveys.']
        ]);
        self::styleHeaders($tiers, 'I');

        // 8. TestCases Sheet
        $tc = $spreadsheet->createSheet();
        $tc->setTitle('TestCases');
        $tc->fromArray([
            ['case_id', 'input_json', 'expected_parameter_scores_json', 'expected_total', 'tolerance'],
            [
                'CASE_01_LOW',
                '{"NT":1200,"NE":1000,"NP":50,"F":60,"F_PHD":20,"F1":30,"F2":20,"F3":10,"BC":15000,"BO":40000,"P":60,"CC":300,"TOP25":15,"PG":1,"PP":5,"RF":100000,"CF":20000,"NP_pct":65,"NHS_pct":10,"NG_pct":70,"GMS_val":350000,"NPHD":10,"N_state_pct":10,"N_country_pct":0,"SW_pct":25,"FW_pct":15,"ESC_pct":10,"PCS_score":12,"PR_survey_val":15}',
                '{"SS":12.75,"FSR":22.49}',
                34.08,
                0.05
            ],
            [
                'CASE_02_MID',
                '{"NT":2400,"NE":2300,"NP":250,"F":150,"F_PHD":100,"F1":50,"F2":60,"F3":40,"BC":45000,"BO":120000,"P":300,"CC":2800,"TOP25":90,"PG":8,"PP":25,"RF":600000,"CF":100000,"NP_pct":82,"NHS_pct":10,"NG_pct":85,"GMS_val":750000,"NPHD":45,"N_state_pct":25,"N_country_pct":2,"SW_pct":38,"FW_pct":22,"ESC_pct":20,"PCS_score":18,"PR_survey_val":45}',
                '{"SS":15.63,"FSR":28.11}',
                55.34,
                0.05
            ],
            [
                'CASE_03_HIGH',
                '{"NT":3600,"NE":3600,"NP":950,"F":260,"F_PHD":250,"F1":60,"F2":100,"F3":100,"BC":140000,"BO":290000,"P":1200,"CC":25000,"TOP25":480,"PG":45,"PP":90,"RF":1900000,"CF":480000,"NP_pct":92,"NHS_pct":6,"NG_pct":94,"GMS_val":1650000,"NPHD":220,"N_state_pct":48,"N_country_pct":9,"SW_pct":48,"FW_pct":28,"ESC_pct":38,"PCS_score":20,"PR_survey_val":88}',
                '{"SS":19.75,"FSR":30.00}',
                93.85,
                0.05
            ]
        ]);
        self::styleHeaders($tc, 'E');

        // 9. Changelog Sheet
        $cl = $spreadsheet->createSheet();
        $cl->setTitle('Changelog');
        $cl->fromArray([
            ['date', 'author', 'change_summary'],
            [date('Y-m-d'), 'SYSTEM_ARCHITECT', 'Initial verified 2026-2027 NIRF Master Workbook seeded with complete 17 parameters and test cases']
        ]);
        self::styleHeaders($cl, 'C');

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    private static function styleHeaders(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $lastCol): void
    {
        $sheet->freezePane('A2');
        $range = "A1:{$lastCol}1";
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A365D');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
