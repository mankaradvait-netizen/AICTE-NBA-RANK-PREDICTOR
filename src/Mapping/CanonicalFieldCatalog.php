<?php

declare(strict_types=1);

namespace App\Mapping;

/**
 * Official NIRF Engineering Canonical Field Catalog
 * Defines the 71 canonical fields across institutional, academic, financial, and research domains.
 */
class CanonicalFieldCatalog
{
    /**
     * @var array<string, array{domain: string, type: string, unit: string, metric: string, description: string, aliases: list<string>}>
     */
    private static array $catalog = [
        // Institution
        'institution.registered_name' => [
            'domain' => 'institution',
            'type' => 'STRING',
            'unit' => 'text',
            'metric' => 'META',
            'description' => 'Official statutory registered name of the institution',
            'aliases' => ['institution name', 'university name', 'college name', 'name of the institute', 'institute name'],
        ],
        'institution.aishe_id' => [
            'domain' => 'institution',
            'type' => 'STRING',
            'unit' => 'identifier',
            'metric' => 'META',
            'description' => 'AISHE identifier code',
            'aliases' => ['aishe code', 'aishe id', 'aishe_id', 'institute code', 'registration number'],
        ],
        'institution.sanctioned_intake' => [
            'domain' => 'program',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total sanctioned intake approved by AICTE/UGC for UG and PG programs',
            'aliases' => ['sanctioned intake', 'approved intake', 'total intake', 'approved capacity', 'intake capacity', 'sanctioned approved intake'],
        ],

        // Faculty
        'faculty.total_count' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'FSR',
            'description' => 'Total regular full-time faculty members teaching in approved programs',
            'aliases' => ['faculty count', 'total faculty', 'teaching staff', 'number of faculty', 'faculty members', 'regular faculty', 'full time faculty'],
        ],
        'faculty.name' => [
            'domain' => 'faculty',
            'type' => 'STRING',
            'unit' => 'text',
            'metric' => 'FSR',
            'description' => 'Name of faculty member',
            'aliases' => ['faculty name', 'teacher name', 'employee name', 'professor name', 'name of faculty'],
        ],
        'faculty.qualification' => [
            'domain' => 'faculty',
            'type' => 'STRING',
            'unit' => 'text',
            'metric' => 'FQE',
            'description' => 'Highest educational qualification (PhD / Masters)',
            'aliases' => ['qualification', 'highest qualification', 'degree', 'highest degree', 'educational qualification'],
        ],
        'faculty.has_phd' => [
            'domain' => 'faculty',
            'type' => 'BOOLEAN',
            'unit' => 'boolean',
            'metric' => 'FQE',
            'description' => 'Whether the faculty holds a doctoral degree (PhD)',
            'aliases' => ['phd', 'has phd', 'is phd', 'phd holder', 'doctorate'],
        ],
        'faculty.experience_years' => [
            'domain' => 'faculty',
            'type' => 'DECIMAL',
            'unit' => 'years',
            'metric' => 'FQE',
            'description' => 'Total full-time academic and research teaching experience in years',
            'aliases' => ['experience', 'experience years', 'teaching experience', 'total experience', 'years of experience', 'strt of teaching date'],
        ],
        'faculty.designation' => [
            'domain' => 'faculty',
            'type' => 'STRING',
            'unit' => 'text',
            'metric' => 'FSR',
            'description' => 'Academic cadre / designation (Professor, Associate Professor, Assistant Professor)',
            'aliases' => ['designation', 'cadre', 'rank', 'post', 'position'],
        ],

        // Students & Student Strength
        'student.total_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled students across all approved UG and PG programs',
            'aliases' => ['enrolled students', 'total enrolled', 'total students', 'student strength', 'headcount', 'on rolls'],
        ],
        'student.ug_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled undergraduate (UG) students',
            'aliases' => ['ug enrolled', 'undergraduate students', 'ug students', 'ug count', 'total ug'],
        ],
        'student.pg_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled postgraduate (PG) students',
            'aliases' => ['pg enrolled', 'postgraduate students', 'pg students', 'pg count', 'total pg'],
        ],
        'student.outside_state_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Number of students from states outside the home state',
            'aliases' => ['outside state', 'other states', 'non-home state', 'outside state count', 'national diversity'],
        ],
        'student.outside_country_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Number of international / foreign national students',
            'aliases' => ['outside country', 'foreign students', 'international students', 'overseas students'],
        ],
        'student.women_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Total female / women students enrolled',
            'aliases' => ['women students', 'female students', 'girls count', 'female count', 'women count', 'gender diversity'],
        ],
        'student.economically_backward_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Socially and economically challenged / backward students (EWS/TFW)',
            'aliases' => ['economically backward', 'socially challenged', 'ews count', 'tfw count', 'freeship', 'tuition fee waiver'],
        ],

        // Graduation Outcomes & Placements
        'outcome.graduated_stipulated_time' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GUE',
            'description' => 'Students graduating within minimum stipulated program time',
            'aliases' => ['graduated in stipulated time', 'passed in stipulated time', 'regular graduation', 'graduated count', 'graduating students'],
        ],
        'outcome.placed_count' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPH',
            'description' => 'Number of graduating students placed through campus or off-campus recruitment',
            'aliases' => ['placed count', 'students placed', 'placed students', 'placement count', 'total placed', 'placed or not'],
        ],
        'outcome.higher_studies_count' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPH',
            'description' => 'Number of graduating students admitted to higher study programs (GATE/CAT/GRE/MS/M.Tech/PhD)',
            'aliases' => ['higher studies', 'higher study count', 'admitted to higher studies', 'higher education count', 'went for higher studies'],
        ],
        'outcome.median_salary' => [
            'domain' => 'outcome',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'GMS',
            'description' => 'Median annual compensation package of placed graduates in INR',
            'aliases' => ['median salary', 'median package', 'median ctc', 'salary median', 'average package', 'ctc median'],
        ],
        'outcome.phd_graduated' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPHD',
            'description' => 'Number of full-time doctoral scholars graduated in the last 3 academic years',
            'aliases' => ['phd graduated', 'phd awarded', 'doctorates produced', 'phd completions', 'doctoral completions'],
        ],

        // Finance & Resource Utilization
        'finance.academic_capex' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FRU',
            'description' => 'Eligible academic capital expenditure on library, lab equipment, workshops (EXCLUDING Building Construction)',
            'aliases' => ['academic capex', 'capital expenditure', 'capex', 'library expenditure', 'lab equipment expenditure', 'lab spendings'],
        ],
        'finance.building_construction_cost' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'STATUTORY_EXCLUSION',
            'description' => 'Statutory Exclusion: Building and civil infrastructure construction costs (excluded from NIRF Capex)',
            'aliases' => ['building and construction cost', 'building construction', 'civil works', 'building cost', 'infrastructure capex', 'land development'],
        ],
        'finance.academic_opex' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FRU',
            'description' => 'Eligible academic operational expenditure on faculty salaries, seminars, lab maintenance (EXCLUDING Hostel & Mess)',
            'aliases' => ['academic opex', 'operational expenditure', 'opex', 'faculty salaries', 'academic operational expenditure', 'teaching salaries'],
        ],
        'finance.hostel_management_cost' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'STATUTORY_EXCLUSION',
            'description' => 'Statutory Exclusion: Hostel and allied residential/mess running costs (excluded from NIRF Opex)',
            'aliases' => ['hostel and allied management cost', 'hostel expenses', 'mess cost', 'hostel running cost', 'residential cost', 'hostel management cost'],
        ],

        // Research & Intellectual Property
        'research.publications_scopus_wos' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'publications',
            'metric' => 'PU',
            'description' => 'Total peer-reviewed articles indexed in Scopus and Web of Science over 3 years',
            'aliases' => ['publications', 'scopus publications', 'wos publications', 'indexed papers', 'journal publications', 'publication passed'],
        ],
        'research.citations_count' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'citations',
            'metric' => 'QP',
            'description' => 'Total citations received by publications over the 3-year reporting period',
            'aliases' => ['citations', 'citation count', 'total citations', 'scopus citations', 'wos citations'],
        ],
        'research.patents_granted' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'patents',
            'metric' => 'IPR',
            'description' => 'Number of national and international patents granted over the 3-year period',
            'aliases' => ['patents granted', 'granted patents', 'patents awarded', 'ipr granted'],
        ],
        'research.patents_published' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'patents',
            'metric' => 'IPR',
            'description' => 'Number of patent applications published over the 3-year period',
            'aliases' => ['patents published', 'published patents', 'patent applications', 'ipr published'],
        ],
        'research.sponsored_research_inr' => [
            'domain' => 'research',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FPPP',
            'description' => 'Total research grants received from government and industry funding agencies',
            'aliases' => ['sponsored research', 'research grants', 'funded projects', 'sponsored projects', 'project funding', 'earnings from publications'],
        ],
        'research.consultancy_earnings_inr' => [
            'domain' => 'research',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FPPP',
            'description' => 'Total earnings generated from commercial industrial consultancy services',
            'aliases' => ['consultancy earnings', 'consultancy amount', 'industrial consultancy', 'consultancy revenue'],
        ],

        // Facilities & Perception
        'facilities.pcs_score' => [
            'domain' => 'facilities',
            'type' => 'DECIMAL',
            'unit' => 'marks',
            'metric' => 'PCS',
            'description' => 'Facilities for physically challenged students and barrier-free environment score (out of 20)',
            'aliases' => ['pcs facilities score', 'pcs score', 'barrier free', 'physically challenged facilities', 'disability access'],
        ],
        'perception.peer_score' => [
            'domain' => 'perception',
            'type' => 'DECIMAL',
            'unit' => 'marks',
            'metric' => 'PR',
            'description' => 'NIRF academic and employer perception score (out of 100)',
            'aliases' => ['perception score', 'peer perception', 'employer perception', 'pr score'],
        ],
    ];

    /**
     * Resolves raw label or header string to a canonical field ID
     */
    public static function matchField(string $rawLabel): ?string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $rawLabel) ?? ''));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        // 1. Direct key match
        if (isset(self::$catalog[$normalized])) {
            return $normalized;
        }

        // 2. Exact alias match
        foreach (self::$catalog as $fieldId => $def) {
            foreach ($def['aliases'] as $alias) {
                if ($normalized === $alias) {
                    return $fieldId;
                }
            }
        }

        // 3. Substring / Token containment match
        foreach (self::$catalog as $fieldId => $def) {
            foreach ($def['aliases'] as $alias) {
                if (strlen($alias) >= 4 && str_contains($normalized, $alias)) {
                    return $fieldId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{domain: string, type: string, unit: string, metric: string, description: string, aliases: list<string>}>
     */
    public static function all(): array
    {
        return self::$catalog;
    }

    public static function get(string $fieldId): ?array
    {
        return self::$catalog[$fieldId] ?? null;
    }
}
