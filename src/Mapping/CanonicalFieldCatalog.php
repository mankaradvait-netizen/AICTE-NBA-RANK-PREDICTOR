<?php

declare(strict_types=1);

namespace App\Mapping;

/**
 * Official NIRF Engineering Canonical Field Catalog
 * Defines the canonical fields across institutional, academic, financial, diversity, and research domains.
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
            'aliases' => ['institution name', 'university name', 'college name', 'name of the institute', 'institute name', 'institute name official', 'institute name local'],
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
            'aliases' => [
                'sanctioned intake', 'approved intake', 'total intake', 'approved capacity',
                'intake capacity', 'sanctioned approved intake', 'sanctioned seats', 'approved seats',
                'ug intake', 'pg intake', 'intake', 'student intake', 'approved seats count',
                'sanctioned approved',
            ],
        ],

        // Faculty
        'faculty.total_count' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'FSR',
            'description' => 'Total regular full-time faculty members teaching in approved programs',
            'aliases' => [
                'faculty count', 'total faculty', 'number of faculty', 'faculty members',
                'regular faculty', 'full time faculty', 'no of faculty', 'no of faculty members',
                'no faculty', 'teaching staff count', 'faculty on roll', 'faculty eligible',
                'eligible faculty',
            ],
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
            'aliases' => [
                'has phd', 'is phd', 'phd holder', 'doctorate', 'no of phd', 'number of phd',
                'phd count', 'phd faculty', 'no phd', 'faculty with phd',
            ],
        ],
        'faculty.phd_percentage' => [
            'domain' => 'faculty',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'FQE',
            'description' => 'Percentage of faculty holding a doctoral degree (PhD)',
            'aliases' => ['percentage phd', 'percent phd', 'phd percentage', 'percentage of phd', 'phd percent', 'faculty with phd percentage'],
        ],
        'faculty.experience_years' => [
            'domain' => 'faculty',
            'type' => 'DECIMAL',
            'unit' => 'years',
            'metric' => 'FQE',
            'description' => 'Total full-time academic and research teaching experience in years',
            'aliases' => [
                'experience years', 'teaching experience', 'total experience', 'years of experience',
                'strt of teaching date', 'faculty experience',
            ],
        ],
        'faculty.experience_f1' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'FQE',
            'description' => 'Faculty with experience up to 8 years (F1)',
            'aliases' => ['less than 8 yrs', 'less than 8 years', 'faculty experience less than 8 yrs', 'f1', 'experience up to 8 years'],
        ],
        'faculty.experience_f2' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'FQE',
            'description' => 'Faculty with experience between 8+ and 15 years (F2)',
            'aliases' => ['in 8 to 15 yrs', '8 to 15 yrs', '8 to 15 years', 'faculty experience in 8 to 15 yrs', 'f2', 'experience between 8 to 15 years'],
        ],
        'faculty.experience_f3' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'FQE',
            'description' => 'Faculty with experience greater than 15 years (F3)',
            'aliases' => ['above 15', 'above 15 yrs', 'above 15 years', 'faculty experience above 15', 'f3', 'experience greater than 15 years'],
        ],
        'faculty.female_faculty_count' => [
            'domain' => 'faculty',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'WD',
            'description' => 'Total female faculty members count',
            'aliases' => ['female faculty count', 'female faculty', 'female faculty members', 'women faculty count', 'female_faculty_count'],
        ],
        'faculty.female_faculty_percentage' => [
            'domain' => 'faculty',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'WD',
            'description' => 'Percentage of women / female faculty members (FW_pct)',
            'aliases' => ['female faculty percentage', 'female faculty percent', 'fw pct', 'women faculty percentage', 'female_faculty_percentage'],
        ],
        'faculty.designation' => [
            'domain' => 'faculty',
            'type' => 'STRING',
            'unit' => 'text',
            'metric' => 'META',
            'description' => 'Academic cadre / designation (Professor, Associate Professor, Assistant Professor)',
            'aliases' => ['faculty designation', 'academic designation', 'designation', 'faculty cadre', 'cadre'],
        ],

        // Students & Student Strength
        'student.total_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled students across all approved UG and PG programs',
            'aliases' => [
                'enrolled students', 'total enrolled', 'total students', 'student strength',
                'headcount', 'on rolls', 'total enrolled ug pg', 'total enorolled ug pg',
                'total student', 'admitted students', 'total enrolled ug and pg',
            ],
        ],
        'student.ug_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled undergraduate (UG) students',
            'aliases' => [
                'ug enrolled', 'undergraduate students', 'ug students', 'ug count', 'total ug',
                'ug student', 'number of ug students', 'students enrolled beginning', 'total ug admissions',
                'ug admitted', 'ug student 5yrs', 'student ug 4 yrs intake', 'ug students enrolled',
                'admitted students ug',
            ],
        ],
        'student.pg_enrolled' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'SS',
            'description' => 'Total enrolled postgraduate (PG) students',
            'aliases' => [
                'pg enrolled', 'postgraduate students', 'pg students', 'pg count', 'total pg',
                'pg admitted', 'pg intake', 'admitted students pg',
            ],
        ],
        'student.outside_state_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Number of students from states outside the home state',
            'aliases' => [
                'outside state', 'other states', 'non home state', 'outside state count',
                'national diversity', 'ug outside state', 'students outside state',
            ],
        ],
        'student.outside_country_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Number of international / foreign national students',
            'aliases' => [
                'outside country', 'foreign students', 'international students', 'overseas students',
                'ug outside country',
            ],
        ],
        'student.women_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Total female / women students enrolled',
            'aliases' => [
                'women students', 'female students', 'girls count', 'female count', 'women count',
                'gender diversity', 'ug female', 'female admitted', 'female students count',
            ],
        ],
        'student.economically_backward_count' => [
            'domain' => 'student',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'OI',
            'description' => 'Socially and economically challenged / backward students (EWS/TFW)',
            'aliases' => [
                'economically backward', 'socially challenged', 'ews count', 'tfw count', 'freeship',
                'tuition fee waiver', 'total sc students', 'total st students', 'total obc students',
                'total ews students', 'fee reimbursement from institute funds', 'ug fee reimbursement from institute funds',
                'economically backward students',
            ],
        ],
        'diversity.women_percentage' => [
            'domain' => 'diversity',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'OI',
            'description' => 'Percentage of women students enrolled',
            'aliases' => [
                'women percentage', 'percent female admitted', 'percentage female admitted',
                'percent female', 'female percentage', 'percent women', 'percentage women',
                'female admitted percentage', 'percent female admitted count', 'female admitted percent',
            ],
        ],
        'diversity.outside_state_percentage' => [
            'domain' => 'diversity',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'OI',
            'description' => 'Percentage of students enrolled from outside the home state',
            'aliases' => [
                'outside state percentage', 'percent outside state', 'percentage outside state',
                'percent outside state count', 'outside state percent',
            ],
        ],
        'diversity.outside_country_percentage' => [
            'domain' => 'diversity',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'OI',
            'description' => 'Percentage of international students enrolled',
            'aliases' => [
                'outside country percentage', 'percent outside country', 'percentage outside country',
            ],
        ],

        // Graduation Outcomes & Placements
        'outcome.graduated_stipulated_time' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GUE',
            'description' => 'Students graduating within minimum stipulated program time',
            'aliases' => [
                'graduated in stipulated time', 'passed in stipulated time', 'regular graduation',
                'graduated count', 'graduating students', 'ug studnets graduating in stipulated time',
                'ug students graduating in stipulated time', 'students passed', 'total ug passed',
                'students appeared exam', 'passed in stipulated time', 'stipulated time',
                'graduating batch', 'graduating cohort', 'total graduating',
            ],
        ],
        'outcome.pass_percentage' => [
            'domain' => 'outcome',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'GUE',
            'description' => 'Graduation pass percentage within stipulated time',
            'aliases' => ['pass percentage', 'percent passed', 'percentage passed', 'pass percent', 'passed percent', 'percent pass'],
        ],
        'outcome.placed_count' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPH',
            'description' => 'Number of graduating students placed through campus or off-campus recruitment',
            'aliases' => [
                'placed count', 'students placed', 'placed students', 'placement count',
                'total placed', 'placed or not', 'ug placed', 'total students placed',
                'matched placed', 'placement records', 'placed records', 'matched',
            ],
        ],
        'outcome.placement_percentage' => [
            'domain' => 'outcome',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'GPH',
            'description' => 'Percentage of graduating students placed',
            'aliases' => [
                'placement percentage', 'percent placement ug', 'percentage placement ug',
                'percent placed', 'match percentage', 'placement match percent',
                'match', 'match percent', 'match pct', 'matched percent', 'matched percentage',
                'placement match percentage', 'placement percent', 'percent placement', 'placement percent ug',
            ],
        ],
        'outcome.higher_studies_count' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPH',
            'description' => 'Number of graduating students admitted to higher study programs (GATE/CAT/GRE/MS/M.Tech/PhD)',
            'aliases' => [
                'higher studies', 'higher study count', 'admitted to higher studies',
                'higher education count', 'went for higher studies', 'ug higher studies',
                'students in higher studies', 'students higher studies', 'selected for higher studies',
            ],
        ],
        'outcome.higher_studies_percentage' => [
            'domain' => 'outcome',
            'type' => 'DECIMAL',
            'unit' => 'percentage',
            'metric' => 'GPH',
            'description' => 'Percentage of graduating students admitted to higher studies',
            'aliases' => [
                'percent higher stuides', 'percentage higher studies', 'higher studies percentage',
                'percent higher studies', 'higher studies percent', 'higher stuides percent',
            ],
        ],
        'outcome.median_salary' => [
            'domain' => 'outcome',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'GMS',
            'description' => 'Median annual compensation package of placed graduates in INR',
            'aliases' => [
                'median salary', 'median package', 'median ctc', 'salary median',
                'average package', 'ctc median', 'median salary ug', 'ug median salary',
                'avg package lpa', 'placed package lpa', 'average packaging', 'highest packaging',
                'avg package', 'median salary pg', 'package lpa', 'avg package in lpa',
                'package in lpa', 'average package lpa',
            ],
        ],
        'outcome.phd_graduated' => [
            'domain' => 'outcome',
            'type' => 'INTEGER',
            'unit' => 'persons',
            'metric' => 'GPHD',
            'description' => 'Number of full-time doctoral scholars graduated in the last 3 academic years',
            'aliases' => [
                'phd graduated', 'phd awarded', 'doctorates produced', 'phd completions',
                'doctoral completions', 'doctoral graduation status', 'gphd',
            ],
        ],

        // Finance & Resource Utilization
        'finance.total_expenditure' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FRU',
            'description' => 'Total annual academic expenditure (combined capital and operational)',
            'aliases' => [
                'total expenditure', 'total expense', 'total annual expenditure', 'total expenses',
                'total cost', 'total academic expenditure', 'total a b', 'total a plus b',
                'total expenditure capital and operational', 'total academic expenses',
                'overall expenditure', 'gross expenditure', 'total spend', 'total spendings',
                'total annual expenses', 'annual total expenditure', 'total expense inr',
            ],
        ],
        'finance.academic_capex' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FRU',
            'description' => 'Eligible academic capital expenditure on library, lab equipment, workshops (EXCLUDING Building Construction)',
            'aliases' => [
                'academic capex', 'capital expenditure', 'capex', 'library expenditure',
                'lab equipment expenditure', 'lab spendings', 'library books journals and e resources only',
                'library amount', 'new equipment and software for laboratories', 'equiment software',
                'equipment software', 'engineering workshops', 'curricula workshops', 'other capital aasests',
                'other capital assets', 'other expenditure on creation of capital assets', 'total a capex',
                'capiture expenditure', 'capital expenditure total', 'creation of capital assets',
                'total annual capital expenditure', 'annual capital expenditure', 'total capital expenditure',
                'total capex',
            ],
        ],
        'finance.building_construction_cost' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'STATUTORY_EXCLUSION',
            'description' => 'Statutory Exclusion: Building and civil infrastructure construction costs (excluded from NIRF Capex)',
            'aliases' => [
                'building and construction cost', 'building construction', 'civil works',
                'building cost', 'infrastructure capex', 'land development',
            ],
        ],
        'finance.academic_opex' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FRU',
            'description' => 'Eligible academic operational expenditure on faculty salaries, seminars, lab maintenance (EXCLUDING Hostel & Mess)',
            'aliases' => [
                'academic opex', 'operational expenditure', 'opex', 'faculty salaries',
                'academic operational expenditure', 'teaching salaries', 'salary of teaching staff',
                'salary of non teaching staff', 'salary of teaching staff b salary of non teaching staff',
                'faculty salary expenditure', 'maintenance of academic infrastructure',
                'maintenance of academic infrastructure or consumables and other running expenditures',
                'maintenance', 'national international level seminars conferences workshops symposia conducted by the institute',
                'seminar workshops', 'seminars conducted', 'administrative expenditure', 'total b opex',
                'total operational expenditure', 'total annual operational expenditure', 'annual operational expenditure',
                'total opex', 'total b', 'total operational expenditure opex', 'salary', 'salaries',
            ],
        ],
        'finance.hostel_management_cost' => [
            'domain' => 'finance',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'STATUTORY_EXCLUSION',
            'description' => 'Statutory Exclusion: Hostel and allied residential/mess running costs (excluded from NIRF Opex)',
            'aliases' => [
                'hostel and allied management cost', 'hostel expenses', 'mess cost',
                'hostel running cost', 'residential cost', 'hostel management cost',
            ],
        ],

        // Research & Intellectual Property
        'research.publications_scopus_wos' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'publications',
            'metric' => 'PU',
            'description' => 'Total peer-reviewed articles indexed in Scopus and Web of Science over 3 years',
            'aliases' => [
                'publications', 'scopus publications', 'wos publications', 'indexed papers',
                'journal publications', 'publication passed', 'total publications', 'pu passed',
            ],
        ],
        'research.citations_count' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'citations',
            'metric' => 'QP',
            'description' => 'Total citations received by publications over the 3-year reporting period',
            'aliases' => [
                'citations', 'citation count', 'total citations', 'scopus citations',
                'wos citations', 'citations passed',
            ],
        ],
        'research.top25_percentile' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'papers',
            'metric' => 'QP',
            'description' => 'Number of publications in top 25 percentile (TOP25P)',
            'aliases' => [
                'top 25 percentile', 'citations in top 25 percentile', 'top25p', 'top25', 'top 25 percent',
                'top 25 percent publications', 'citations in top 25 percentile top25p',
            ],
        ],
        'research.patents_granted' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'patents',
            'metric' => 'IPR',
            'description' => 'Number of national and international patents granted over the 3-year period',
            'aliases' => [
                'patents granted', 'granted patents', 'patents awarded', 'ipr granted',
                'no of patents granted in last 3 yrs', 'patents granted in last 3 yrs', 'patents granted 3 yrs',
            ],
        ],
        'research.patents_published' => [
            'domain' => 'research',
            'type' => 'INTEGER',
            'unit' => 'patents',
            'metric' => 'IPR',
            'description' => 'Number of patent applications published over the 3-year period',
            'aliases' => [
                'patents published', 'published patents', 'patent applications', 'ipr published',
                'no of patents published in last 3 yrs', 'patents published in last 3 yrs', 'patents published 3 yrs',
            ],
        ],
        'research.sponsored_research_inr' => [
            'domain' => 'research',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FPPP',
            'description' => 'Total research grants received from government and industry funding agencies',
            'aliases' => [
                'sponsored research', 'research grants', 'funded projects', 'sponsored projects',
                'project funding', 'earnings from publications', 'total amount received r d',
                'research grant total', 'research expenditure', 'no of sponsered projects',
                'sponsored research inr', 'amount received r d',
            ],
        ],
        'research.consultancy_earnings_inr' => [
            'domain' => 'research',
            'type' => 'DECIMAL',
            'unit' => 'INR',
            'metric' => 'FPPP',
            'description' => 'Total earnings generated from commercial industrial consultancy services',
            'aliases' => [
                'consultancy earnings', 'consultancy amount', 'industrial consultancy',
                'consultancy revenue', 'total amount received consultancy', 'no of consultancy projects',
                'consultancy earnings inr', 'consultancy project total amount', 'total amount received',
            ],
        ],

        // Facilities & Perception
        'facilities.pcs_score' => [
            'domain' => 'facilities',
            'type' => 'DECIMAL',
            'unit' => 'marks',
            'metric' => 'PCS',
            'description' => 'Facilities for physically challenged students and barrier-free environment score (out of 20)',
            'aliases' => [
                'pcs facilities score', 'pcs score', 'barrier free', 'physically challenged facilities',
                'disability access', 'total classrooms', 'total laboratories', 'total computer labs',
                'total buildings', 'toilet facilities for physically challenged students',
                'lifts for physically challenged students', 'ramps for physically challenged students',
            ],
        ],
        'perception.peer_score' => [
            'domain' => 'perception',
            'type' => 'DECIMAL',
            'unit' => 'marks',
            'metric' => 'PR',
            'description' => 'NIRF academic and employer perception score (out of 100)',
            'aliases' => [
                'perception score', 'peer perception', 'employer perception', 'pr score',
                'academic reputation score avg', 'employer reputation score avg', 'perception summary aggregate',
            ],
        ],
    ];

    public const IGNORE_LABELS = [
        'institute id', 'academic year', 'ay', 'financial year', 'fy', 'program id', 'program name',
        'program level', 'duration years', 'sr no', 's no', 'serial no', 'sr no ', 'sr. no.', 'sr. no',
        'enrollment no', 'roll no', 'first admission year', 'student name', 'school short name', 'stream',
        'course type', 'current class', 'in words', 'amount in words', 'amount in rupees', 'utilized amount',
        'remark', 'remarks', 'compliance notes', 'target benchmark', 'target / benchmark', 'parameter', 'nirf data',
        'status', 'rank', 'ranking 2024', 'ranking 2025', 'ranking 2026', 'ranking 2027',
        'result not yet declare by sppu', 'institution basic information', 'academic programs summary',
        'infrastructure summary', 'financial summary', 'student demographics', 'ug within state',
        'total ug programs', 'total pg programs', 'total phd programs', 'total diploma programs',
        'total distance programs', 'total online programs', 'total buildings', 'total classrooms',
        'hostel boys capacity', 'hostel girls capacity', 'hostel boys count', 'hostel girls count',
        'mess available', 'canteen available', 'sports complex', 'gymnasium',
        'total auditoriums', 'auditoriums', 'auditorium', 'total seminar halls', 'seminar halls', 'seminar hall', 'workshop',
        'poster presentation', 'poster presentation on 17 sustainable development goals sdgs',
        'position ranked', 'nirf ranked position', 'nirf ranked position overall', 'nirf ranked position university',
        'respondent designation', 'respondent type', 'respondent id', 'respondent name',
        'company name', 'company industry', 'years of employer experience',
    ];

    /**
     * Checks if a raw label represents non-metric metadata that should be ignored
     */
    public static function isIgnored(string $rawLabel): bool
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $rawLabel) ?? ''));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        if ($normalized === '' || in_array($normalized, self::IGNORE_LABELS, true)) {
            return true;
        }

        // Single words or acronyms for academic year / serial number
        if ($normalized === 'ay' || $normalized === 'sr' || $normalized === 'sno') {
            return true;
        }

        return false;
    }

    /**
     * Resolves raw label or header string to a canonical field ID
     */
    public static function matchField(string $rawLabel): ?string
    {
        // Normalize common abbreviations, percentage signs, and Indian spreadsheet typos
        $preprocessed = str_ireplace(
            ['%', 'percent', 'pct', 'studnets', 'enorolled', 'sallery', 'stuides', 'aasests', 'equiment', 'sponsered', 'cource'],
            [' percent ', ' percent ', ' percent ', 'students', 'enrolled', 'salary', 'studies', 'assets', 'equipment', 'sponsored', 'course'],
            $rawLabel
        );

        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $preprocessed) ?? ''));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        if ($normalized === '' || self::isIgnored($rawLabel)) {
            return null;
        }

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

        // 3. Substring / Token containment match - prefer longest matching alias
        $bestMatch = null;
        $longestLen = 0;

        foreach (self::$catalog as $fieldId => $def) {
            foreach ($def['aliases'] as $alias) {
                $aliasLen = strlen($alias);
                if ($aliasLen >= 4 && str_contains($normalized, $alias)) {
                    // Priority safeguard: if the string mentions salary/expenditure/cost/rupees, never match faculty count
                    if ($fieldId === 'faculty.total_count' && (str_contains($normalized, 'salary') || str_contains($normalized, 'expenditure') || str_contains($normalized, 'cost') || str_contains($normalized, 'rupees'))) {
                        continue;
                    }
                    if ($aliasLen > $longestLen) {
                        $longestLen = $aliasLen;
                        $bestMatch = $fieldId;
                    }
                }
            }
        }

        return $bestMatch;
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
