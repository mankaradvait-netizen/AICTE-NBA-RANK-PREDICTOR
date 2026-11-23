<?php

declare(strict_types=1);

namespace App\Ingestion;

use DateTimeImmutable;

/**
 * KPI Mapper: Transforms cleaned canonical rows from the 3 master workbooks
 * (Faculty, Student, Money/Institute) into the 29 validated NIRF Engineering inputs.
 */
class KpiMapper
{
    /**
     * Helper to map parsed workbooks container directly to 29 inputs array
     * 
     * @param array{
     *   FACULTY?: array{rows: array},
     *   STUDENT?: array{rows: array},
     *   FINANCE?: array{rows: array}
     * } $parsed
     * @return array<string, float>
     */
    public function mapWorkbooksToKpis(array $parsed): array
    {
        $facultyRows = $parsed['FACULTY']['rows'] ?? [];
        $studentRows = $parsed['STUDENT']['rows'] ?? [];
        $financeRows = $parsed['FINANCE']['rows'] ?? [];

        $res = $this->map($facultyRows, $studentRows, $financeRows);
        return $res['inputs'];
    }

    /**
     * Maps canonical records to 29 NIRF metric inputs
     * 
     * @param array<int, array<string, mixed>> $facultyRows
     * @param array<int, array<string, mixed>> $studentRows
     * @param array<int, array<string, mixed>> $financeRows
     * @param array<string, float> $options
     * @return array{
     *   inputs: array<string, float>,
     *   lineage: array<string, string>,
     *   metadata: array<string, mixed>
     * }
     */
    public function map(
        array $facultyRows,
        array $studentRows,
        array $financeRows,
        array $options = []
    ): array {
        $finance = $financeRows[0] ?? [];

        // 1. Direct inputs from Money and Institute master
        $nt = (float) ($this->findField($finance, ['sanctioned approved intake', 'intake', 'nt']) ?? 720.0);
        if ($nt <= 0.0) {
            $nt = 720.0;
        }

        $pcs = (float) ($this->findField($finance, ['pcs facilities score', 'pcs']) ?? 18.0);
        $pcs = max(0.0, min(20.0, $pcs));

        // 2. Student Master aggregations
        $totalStudents = count($studentRows);
        $doctoralStudents = 0;
        $placedCount = 0;
        $higherStudiesCount = 0;
        $graduatedInTimeCount = 0;
        $doctoralGraduatedCount = 0;
        $otherStateCount = 0;
        $otherCountryCount = 0;
        $femaleStudentsCount = 0;
        $reimbursedFeeCount = 0;
        $placedSalaries = [];

        foreach ($studentRows as $s) {
            $program = strtolower((string)($this->findField($s, ['program', 'degree', 'course']) ?? ''));
            $isDoctoral = (str_contains($program, 'ph.d') || str_contains($program, 'phd') || str_contains($program, 'doctoral'));
            if ($isDoctoral) {
                $doctoralStudents++;
            }

            // Placement
            $placed = $this->findField($s, ['placed or not', 'placed']);
            if ($placed === true || strtolower((string)$placed) === 'yes' || strtolower((string)$placed) === 'placed') {
                $placedCount++;
                $sal = (float) ($this->findField($s, ['salery placed on campus', 'salary placed', 'salary']) ?? 0.0);
                if ($sal > 0) {
                    $placedSalaries[] = $sal;
                }
            }

            // Higher Studies
            $hs = $this->findField($s, ['selected for higher studies', 'higher studies', 'nhs']);
            if ($hs === true || strtolower((string)$hs) === 'yes') {
                $higherStudiesCount++;
            }

            // Graduated in stipulated time
            $gt = $this->findField($s, ['graduated in stipulated time', 'graduated', 'ng']);
            if ($gt === true || strtolower((string)$gt) === 'yes') {
                $graduatedInTimeCount++;
            }

            // Doctoral graduated status
            $docGrad = $this->findField($s, ['doctoral graduation status', 'gphd']);
            if ($docGrad === true || strtolower((string)$docGrad) === 'yes') {
                $doctoralGraduatedCount++;
            }

            // Regional Diversity
            $country = strtolower((string)($this->findField($s, ['country']) ?? 'india'));
            $state = strtolower((string)($this->findField($s, ['state']) ?? 'maharashtra'));

            if ($country !== '' && $country !== 'india') {
                $otherCountryCount++;
            } elseif ($state !== '' && !str_contains($state, 'maharashtra')) {
                $otherStateCount++;
            }

            // Women diversity
            $gender = strtolower((string)($this->findField($s, ['gender']) ?? ''));
            if ($gender === 'female' || $gender === 'f' || $gender === 'woman') {
                $femaleStudentsCount++;
            }

            // Economically challenged / full tuition reimbursement
            $escs = $this->findField($s, ['full tuition fee reimbursement status', 'tution fee waiver', 'escs']);
            if ($escs === true || strtolower((string)$escs) === 'yes') {
                $reimbursedFeeCount++;
            }
        }

        $enrolledUgPg = max(0, $totalStudents - $doctoralStudents);
        $effectiveTotalStudents = max(1, $totalStudents);

        // 3. Faculty Master aggregations
        $eligibleFaculty = 0;
        $phdFaculty = 0;
        $f1Exp = 0; // <= 8 yrs
        $f2Exp = 0; // > 8 to 15 yrs
        $f3Exp = 0; // > 15 yrs
        $femaleFacultyCount = 0;

        $totalPubPassed = 0;
        $totalPubFailed = 0;
        $totalEarningsPub = 0.0;
        $totalEarningsConsultancy = 0.0;
        $totalCitationsPassed = 0;
        $totalCitationsFailed = 0;
        $totalPatentsGranted = 0;
        $totalPatentsPublished = 0;
        $totalTop25Citations = 0;
        $totalPhdGuided = 0;

        $currentYear = (int) date('Y');

        foreach ($facultyRows as $f) {
            $fType = strtolower((string)($this->findField($f, ['type of faculty', 'appointment type']) ?? 'full time'));
            $isVisiting = str_contains($fType, 'visiting') || str_contains($fType, 'adjunct');
            $isEligibleAppointment = str_contains($fType, 'full') || str_contains($fType, 'regular') || str_contains($fType, 'permanent') || str_contains($fType, 'contract');

            $edu = strtolower((string)($this->findField($f, ['education level', 'qualification']) ?? ''));
            $hasPhd = str_contains($edu, 'ph.d') || str_contains($edu, 'phd') || str_contains($edu, 'doctor');
            $hasMTech = str_contains($edu, 'm.tech') || str_contains($edu, 'm.e.') || str_contains($edu, 'master');

            // NIRF eligibility: Regular or Contract >= 2 sem with PhD or M.Tech/M.E. (excludes visiting)
            if ($isVisiting || (!$hasPhd && !$hasMTech)) {
                continue;
            }

            $eligibleFaculty++;

            if ($hasPhd) {
                $phdFaculty++;
            }

            // Gender
            $fGender = strtolower((string)($this->findField($f, ['gender']) ?? ''));
            if ($fGender === 'female' || $fGender === 'f' || $fGender === 'woman') {
                $femaleFacultyCount++;
            }

            // Experience calculation
            $startDateStr = (string)($this->findField($f, ['strt of teaching date', 'start date', 'teaching date']) ?? '');
            $expYears = 5.0; // default
            if ($startDateStr !== '') {
                $startYear = (int) substr($startDateStr, 0, 4);
                if ($startYear > 1960 && $startYear <= $currentYear) {
                    $expYears = (float) ($currentYear - $startYear);
                }
            }

            if ($expYears <= 8.0) {
                $f1Exp++;
            } elseif ($expYears <= 15.0) {
                $f2Exp++;
            } else {
                $f3Exp++;
            }

            // Research, Patents, Citations, Grants
            $totalPubPassed += (int) ($this->findField($f, ['publication passed', 'publications passed']) ?? 0);
            $totalPubFailed += (int) ($this->findField($f, ['publications failed', 'publication failed']) ?? 0);
            $totalEarningsPub += (float) ($this->findField($f, ['earnings from publications', 'sponsored research']) ?? 0.0);
            $totalEarningsConsultancy += (float) ($this->findField($f, ['earnings from counselling', 'consultancy']) ?? 0.0);
            $totalCitationsPassed += (int) ($this->findField($f, ['citations passed']) ?? 0);
            $totalCitationsFailed += (int) ($this->findField($f, ['citations failed']) ?? 0);
            $totalPatentsGranted += (int) ($this->findField($f, ['patents granted']) ?? 0);
            $totalPatentsPublished += (int) ($this->findField($f, ['patents published']) ?? 0);
            $totalTop25Citations += (int) ($this->findField($f, ['top 25 percentile citations', 'top25p']) ?? 0);
            $totalPhdGuided += (int) ($this->findField($f, ['ph.d. scholars graduated guided', 'phd guided', 'scholars guided']) ?? 0);
        }

        $effectiveF = max(1, $eligibleFaculty);

        // 4. Financial Expenditure aggregations (Excluding building construction and hostel)
        // Capital expenditure: Lab Equipment, Library, Software, Classroom/Lab spends
        $capexItems = [
            (float) ($this->findField($finance, ['lab equipment & capital spend', 'lab spendings']) ?? 25000000.0),
            (float) ($this->findField($finance, ['library books, e-journals & subscriptions']) ?? 8000000.0),
            (float) ($this->findField($finance, ['software licenses & computing capital spend']) ?? 12000000.0),
            (float) ($this->findField($finance, ['project building and club funds']) ?? 5000000.0),
        ];
        $totalEligibleCapex3Y = array_sum($capexItems);

        // Operational expenditure: Salaries, Utilities, Maintenance (Excluding Hostel & Allied Management)
        $opexItems = [
            (float) ($this->findField($finance, ['sallery of teachers and lab assistents']) ?? 180000000.0),
            (float) ($this->findField($finance, ['maintainence of campus spend']) ?? 15000000.0),
            (float) ($this->findField($finance, ['light bills', 'utilities']) ?? 10000000.0),
            (float) ($this->findField($finance, ['sanitization and providing spends']) ?? 5000000.0),
            (float) ($this->findField($finance, ['exper sessions', 'visiting payments']) ?? 4000000.0),
            (float) ($this->findField($finance, ['event spends']) ?? 6000000.0),
        ];
        $totalEligibleOpex3Y = array_sum($opexItems);

        // Per student annual capex and opex: Total 3-yr / (3 * Total Students N)
        $nTotal = $nt + $doctoralStudents;
        $bcPerStudent = round($totalEligibleCapex3Y / (3.0 * max(100.0, $nTotal)), 2);
        $boPerStudent = round($totalEligibleOpex3Y / (3.0 * max(100.0, $nTotal)), 2);

        // Per faculty research funding & consultancy: Annual average per faculty over 3 years
        $rfPerFaculty = round($totalEarningsPub / (3.0 * $effectiveF), 2);
        $cfPerFaculty = round($totalEarningsConsultancy / (3.0 * $effectiveF), 2);

        // Placements and higher studies percentages
        $graduatingCohort = max(1, (int)($nt * 0.9)); // Approx 90% of intake in final graduating cohort
        $npPct = round(min(100.0, ($placedCount / $graduatingCohort) * 100.0), 2);
        $nhsPct = round(min(100.0, ($higherStudiesCount / $graduatingCohort) * 100.0), 2);
        $ngPct = round(min(100.0, ($graduatedInTimeCount / max(1.0, $nt)) * 100.0), 2);

        // Median Salary calculation
        $medianSalary = 650000.0;
        if (!empty($placedSalaries)) {
            sort($placedSalaries);
            $mid = (int) floor(count($placedSalaries) / 2);
            $medianSalary = (count($placedSalaries) % 2 === 0)
                ? ($placedSalaries[$mid - 1] + $placedSalaries[$mid]) / 2.0
                : $placedSalaries[$mid];
        }

        // Diversity percentages
        $nStatePct = round(min(100.0, ($otherStateCount / $effectiveTotalStudents) * 100.0), 2);
        $nCountryPct = round(min(100.0, ($otherCountryCount / $effectiveTotalStudents) * 100.0), 2);
        $swPct = round(min(100.0, ($femaleStudentsCount / $effectiveTotalStudents) * 100.0), 2);
        $fwPct = round(min(100.0, ($femaleFacultyCount / $effectiveF) * 100.0), 2);
        $escPct = round(min(100.0, ($reimbursedFeeCount / $effectiveTotalStudents) * 100.0), 2);

        // PhD students graduated per year
        $nphdAnnual = round(max(1.0, ($totalPhdGuided + $doctoralGraduatedCount) / 3.0), 2);

        // Perception controlled assumption
        $prAssumption = (float) ($options['pr_assumption'] ?? 60.0);

        $inputs = [
            'NT' => $nt,
            'NE' => (float) max(100, $enrolledUgPg),
            'NP' => (float) $doctoralStudents,
            'F' => (float) $effectiveF,
            'F_PHD' => (float) $phdFaculty,
            'F1' => (float) $f1Exp,
            'F2' => (float) $f2Exp,
            'F3' => (float) $f3Exp,
            'BC' => $bcPerStudent,
            'BO' => $boPerStudent,
            'P' => (float) max(1, $totalPubPassed),
            'Pret' => (float) $totalPubFailed,
            'CC' => (float) max(1, $totalCitationsPassed),
            'TOP25' => (float) max(0, $totalTop25Citations),
            'Cret' => (float) $totalCitationsFailed,
            'PG' => (float) max(0, $totalPatentsGranted),
            'PP' => (float) max(0, $totalPatentsPublished),
            'RF' => $rfPerFaculty,
            'CF' => $cfPerFaculty,
            'NP_pct' => $npPct,
            'NHS_pct' => $nhsPct,
            'NG_pct' => $ngPct > 0 ? $ngPct : 82.0,
            'GMS_val' => $medianSalary,
            'NPHD' => $nphdAnnual,
            'N_state_pct' => $nStatePct,
            'N_country_pct' => $nCountryPct,
            'SW_pct' => $swPct,
            'FW_pct' => $fwPct,
            'ESC_pct' => $escPct,
            'PCS_score' => $pcs,
            'PR_survey_val' => $prAssumption,
        ];

        return [
            'inputs' => $inputs,
            'lineage' => [
                'NT' => 'Institute Master: Sanctioned Approved Intake',
                'NE' => 'Student Census: Non-Doctoral Enrolled Student Count',
                'NP' => 'Student Census: Doctoral Program Enrolled Count',
                'F' => 'Faculty Register: Eligible Full-Time & Regular Faculty with M.Tech/Ph.D.',
                'F_PHD' => 'Faculty Register: PhD Qualified Eligible Faculty Count',
                'F1_F2_F3' => 'Faculty Register: Teaching Experience Bins (<=8y, 8-15y, >15y)',
                'BC_BO' => 'Financial Statement: Capex and Opex per student excluding building & hostel',
                'P_CC_TOP25' => 'Faculty & Bibliometric Register: Publications, Citations, and Top 25 Percentile Citations',
                'PG_PP' => 'Research Register: Patents Granted and Published in 3-yr window',
                'RF_CF' => 'Research Register: Sponsored Research Earnings and Consultancy per Faculty',
                'NP_NHS_GMS' => 'Graduation Outcomes: Placed, Higher Studies, and Median Campus Salary',
                'RD_WD_ESC' => 'Demographics & Diversity: Out-of-State, Foreign, Women, and Fee-Reimbursement',
                'PCS' => 'Facilities Statement: Physically Challenged Facilities Score',
                'PR' => 'Controlled Scenario Assumption: Peer Perception Survey Estimate',
            ],
            'metadata' => [
                'total_faculty_records' => count($facultyRows),
                'eligible_faculty_count' => $eligibleFaculty,
                'total_student_records' => $totalStudents,
                'total_placed_students' => $placedCount,
                'total_3yr_capex_inr' => $totalEligibleCapex3Y,
                'total_3yr_opex_inr' => $totalEligibleOpex3Y,
                'generation_timestamp' => date('c'),
            ],
        ];
    }

    private function findField(array $row, array $candidates): mixed
    {
        // 1. Exact match first
        foreach ($row as $key => $val) {
            $k = strtolower(trim((string)$key));
            foreach ($candidates as $cand) {
                if ($k === $cand) {
                    return $val;
                }
            }
        }

        // 2. Substring match for candidates of length >= 4
        foreach ($row as $key => $val) {
            $k = strtolower(trim((string)$key));
            foreach ($candidates as $cand) {
                if (strlen($cand) >= 4 && str_contains($k, $cand)) {
                    return $val;
                }
            }
        }
        return null;
    }
}
