<?php

declare(strict_types=1);

namespace App\Ingestion;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

class ThreeWorkbookIngestionService
{
    private const MAX_ROWS = 25000;

    /**
     * Detects workbook category from sheet headers
     * @param array<string> $headers
     */
    public function detectWorkbookType(array $headers): ?string
    {
        $normalized = array_map(fn($h) => strtolower(trim((string)$h)), $headers);

        $facultyKeywords = ['faculty name', 'strt of teaching date', 'designation', 'publication passed', 'earnings from publications'];
        $studentKeywords = ['student name', 'strt year of cource', 'graduation year', 'placed or not', 'cgpa year 1'];
        $financeKeywords = ['lab spendings', 'building and construction cost', 'hostel and allied management cost', 'pcs facilities score', 'sanctioned approved intake'];

        $countFaculty = count(array_filter($facultyKeywords, fn($k) => in_array($k, $normalized, true) || $this->hasPartialMatch($k, $normalized)));
        $countStudent = count(array_filter($studentKeywords, fn($k) => in_array($k, $normalized, true) || $this->hasPartialMatch($k, $normalized)));
        $countFinance = count(array_filter($financeKeywords, fn($k) => in_array($k, $normalized, true) || $this->hasPartialMatch($k, $normalized)));

        if ($countFaculty >= 3) return 'FACULTY';
        if ($countStudent >= 3) return 'STUDENT';
        if ($countFinance >= 2) return 'FINANCE';

        return null;
    }

    private function hasPartialMatch(string $needle, array $haystack): bool
    {
        foreach ($haystack as $item) {
            if (str_contains($item, $needle)) return true;
        }
        return false;
    }

    /**
     * Parses and cleans an Excel (.xlsx, .xls) or CSV file.
     * Applies robust normalization: trimming, Indian numbering comma removal,
     * date standardisation, type casting, and lineage tracking.
     * 
     * @return array{
     *   file_type: string,
     *   headers: array<string>,
     *   rows: array<int, array<string, mixed>>,
     *   issues: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    public function parseAndCleanFile(string $filePath, ?string $explicitType = null): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rawData = [];
        $fileName = basename($filePath);
        $sheetName = 'Sheet1';

        if ($ext === 'csv') {
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new RuntimeException("Cannot open CSV file: {$filePath}");
            }
            // Strip potential UTF-8 BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($row = fgetcsv($handle)) !== false) {
                $rawData[] = $row;
            }
            fclose($handle);
        } else {
            // Spreadsheet loading with calculation cache flushing
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            Calculation::getInstance($spreadsheet)->clearCalculationCache();

            $sheet = $spreadsheet->getSheet(0);
            $sheetName = $sheet->getTitle();
            $rawData = $sheet->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        if (empty($rawData)) {
            throw new RuntimeException("Uploaded workbook is empty: {$fileName}");
        }

        // Detect header row (skip possible intro/title rows)
        $headerRowIdx = 0;
        for ($i = 0; $i < min(5, count($rawData)); $i++) {
            $nonEmpty = array_filter($rawData[$i], fn($cell) => $cell !== null && trim((string)$cell) !== '');
            if (count($nonEmpty) >= 5) {
                $headerRowIdx = $i;
                break;
            }
        }

        $rawHeaders = $rawData[$headerRowIdx];
        $headers = [];
        foreach ($rawHeaders as $idx => $val) {
            $h = trim((string)$val);
            if ($h !== '') {
                $headers[$idx] = $h;
            }
        }

        $fileType = $explicitType ?? $this->detectWorkbookType(array_values($headers));
        if ($fileType === null) {
            $fileType = 'UNKNOWN';
        }

        $cleanedRows = [];
        $issues = [];
        $issueCount = 0;

        for ($r = $headerRowIdx + 1; $r < count($rawData); $r++) {
            if ($r > self::MAX_ROWS) {
                break;
            }

            $rawRow = $rawData[$r];
            $nonEmptyCells = array_filter($rawRow, fn($c) => $c !== null && trim((string)$c) !== '');
            if (empty($nonEmptyCells)) {
                continue; // Skip blank rows
            }

            $cleanedRow = [
                '_source_file' => $fileName,
                '_source_sheet' => $sheetName,
                '_source_row' => $r + 1,
            ];

            foreach ($headers as $colIdx => $headerName) {
                $rawVal = $rawRow[$colIdx] ?? null;
                $cleanedVal = $this->cleanCell($headerName, $rawVal);
                $cleanedRow[$headerName] = $cleanedVal;

                // Validation rule: detect invalid formats or anomalies
                $anomaly = $this->checkCellAnomaly($headerName, $rawVal, $cleanedVal, $fileType);
                if ($anomaly !== null) {
                    $issueCount++;
                    $issues[] = [
                        'issue_id' => "ISSUE-{$issueCount}",
                        'file_type' => $fileType,
                        'file_name' => $fileName,
                        'sheet' => $sheetName,
                        'row' => $r + 1,
                        'field' => $headerName,
                        'observed_value' => (string) $rawVal,
                        'cleaned_value' => $cleanedVal,
                        'rule_id' => $anomaly['rule_id'],
                        'severity' => $anomaly['severity'],
                        'message' => $anomaly['message'],
                        'recommended_correction' => $anomaly['recommendation'],
                    ];
                }
            }

            $cleanedRows[] = $cleanedRow;
        }

        return [
            'file_type' => $fileType,
            'headers' => array_values($headers),
            'rows' => $cleanedRows,
            'issues' => $issues,
            'summary' => [
                'total_rows_read' => count($cleanedRows),
                'total_issues_found' => count($issues),
                'error_count' => count(array_filter($issues, fn($i) => $i['severity'] === 'ERROR')),
                'warning_count' => count(array_filter($issues, fn($i) => $i['severity'] === 'WARN')),
                'file_name' => $fileName,
                'detected_type' => $fileType,
            ],
        ];
    }

    /**
     * Cross-file consistency validation
     * @param array<int, array<string, mixed>> $facultyRows
     * @param array<int, array<string, mixed>> $studentRows
     * @param array<int, array<string, mixed>> $financeRows
     * @return array<int, array<string, mixed>> Cross-file issues
     */
    public function performCrossFileValidation(array $facultyRows, array $studentRows, array $financeRows): array
    {
        $crossIssues = [];
        $issueId = 1000;

        // 1. Sanctioned Intake NT check
        $financeRow = $financeRows[0] ?? [];
        $nt = (float) ($this->findValueByKeywords($financeRow, ['sanctioned approved intake', 'intake', 'nt']) ?? 0.0);
        $totalStudents = count($studentRows);

        if ($nt <= 0.0) {
            $crossIssues[] = [
                'issue_id' => 'CROSS-' . (++$issueId),
                'rule_id' => 'CROSS_NT_MISSING',
                'severity' => 'ERROR',
                'message' => 'Sanctioned Approved Intake (NT) in Financial/Institute master is missing or zero.',
                'recommended_correction' => 'Provide a valid positive approved intake (NT >= 1) in the Money and Institute Master.',
            ];
        }

        // 2. Student population consistency
        if ($nt > 0 && $totalStudents > 0) {
            // If total enrolled students in student master is less than 50% of annual intake or > 6 times intake (assuming 4 yr program)
            if ($totalStudents < ($nt * 0.5)) {
                $crossIssues[] = [
                    'issue_id' => 'CROSS-' . (++$issueId),
                    'rule_id' => 'CROSS_STUDENT_LOW',
                    'severity' => 'WARN',
                    'message' => "Total student records in Student Master ({$totalStudents}) is significantly lower than sanctioned intake NT ({$nt}).",
                    'recommended_correction' => 'Verify that all undergraduate and postgraduate student records have been uploaded.',
                ];
            }
        }

        // 3. Faculty count check
        $totalFaculty = count($facultyRows);
        if ($totalFaculty === 0) {
            $crossIssues[] = [
                'issue_id' => 'CROSS-' . (++$issueId),
                'rule_id' => 'CROSS_FACULTY_EMPTY',
                'severity' => 'ERROR',
                'message' => 'Faculty Master contains 0 faculty records. Cannot calculate FSR or FQE.',
                'recommended_correction' => 'Upload a valid Faculty Master with active faculty records.',
            ];
        } elseif ($nt > 0 && ($totalFaculty / max(1.0, $nt)) < 0.01) {
            $crossIssues[] = [
                'issue_id' => 'CROSS-' . (++$issueId),
                'rule_id' => 'CROSS_FSR_CRITICAL',
                'severity' => 'WARN',
                'message' => "Total faculty count ({$totalFaculty}) yields a critical student-to-faculty ratio below 1:100. FSR marks may be 0.",
                'recommended_correction' => 'Verify that all regular full-time faculty across departments are included.',
            ];
        }

        return $crossIssues;
    }

    /**
     * Ingests 3 master files by their local filesystem paths
     * 
     * @return array{
     *   parsed: array<string, array>,
     *   counts: array<string, int>,
     *   issues: array<int, array>,
     *   cross_file_validation: array<int, array>
     * }
     */
    public function ingestFiles(string $facultyPath, string $studentPath, string $financePath): array
    {
        $faculty = $this->parseAndCleanFile($facultyPath, 'FACULTY');
        $student = $this->parseAndCleanFile($studentPath, 'STUDENT');
        $finance = $this->parseAndCleanFile($financePath, 'FINANCE');

        $parsed = [
            'FACULTY' => $faculty,
            'STUDENT' => $student,
            'FINANCE' => $finance,
        ];

        $allIssues = array_merge($faculty['issues'], $student['issues'], $finance['issues']);

        // Run cross-file consistency checks
        $crossIssues = $this->performCrossFileValidation(
            $faculty['rows'],
            $student['rows'],
            $finance['rows']
        );

        $counts = [
            'faculty' => count($faculty['rows']),
            'students' => count($student['rows']),
            'finance_lines' => count($finance['rows']),
            'financial' => count($finance['rows']),
        ];

        return [
            'parsed' => $parsed,
            'counts' => $counts,
            'issues' => $allIssues,
            'cross_file_validation' => $crossIssues,
        ];
    }

    /**
     * Ingests uploaded PSR-7 files
     */
    public function ingestUploadedFiles(array $uploadedFiles): array
    {
        $parsed = [];
        $allIssues = [];

        foreach ($uploadedFiles as $key => $fileOrList) {
            $fileList = is_array($fileOrList) ? $fileOrList : [$fileOrList];
            foreach ($fileList as $file) {
                if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                    continue;
                }

                $clientFilename = $file->getClientFilename() ?? 'upload.xlsx';
                $ext = pathinfo($clientFilename, PATHINFO_EXTENSION);
                $tmpPath = tempnam(sys_get_temp_dir(), 'wb_') . '.' . $ext;
                $file->moveTo($tmpPath);

                try {
                    $explicitType = match ($key) {
                        'faculty_file' => 'FACULTY',
                        'student_file' => 'STUDENT',
                        'finance_file' => 'FINANCE',
                        default => null,
                    };
                    $cleaned = $this->parseAndCleanFile($tmpPath, $explicitType);
                    $type = $cleaned['file_type'];
                    $parsed[$type] = $cleaned;
                    $allIssues = array_merge($allIssues, $cleaned['issues']);
                } finally {
                    @unlink($tmpPath);
                }
            }
        }

        $crossIssues = $this->performCrossFileValidation(
            $parsed['FACULTY']['rows'] ?? [],
            $parsed['STUDENT']['rows'] ?? [],
            $parsed['FINANCE']['rows'] ?? []
        );

        return [
            'parsed' => $parsed,
            'counts' => [
                'faculty' => count($parsed['FACULTY']['rows'] ?? []),
                'students' => count($parsed['STUDENT']['rows'] ?? []),
                'finance_lines' => count($parsed['FINANCE']['rows'] ?? []),
                'financial' => count($parsed['FINANCE']['rows'] ?? []),
            ],
            'issues' => $allIssues,
            'cross_file_validation' => $crossIssues,
        ];
    }

    /**
     * Cleans cell value: strips currency, removes Indian format commas, trims whitespace, standardizes dates.
     */
    public function cleanCell(string $header, mixed $val): mixed
    {
        if ($val === null) {
            return null;
        }

        if (is_bool($val)) {
            return $val;
        }

        if (is_numeric($val)) {
            return $val;
        }

        $s = trim((string) $val);
        if ($s === '' || strtolower($s) === 'null' || strtolower($s) === 'na' || strtolower($s) === 'n/a' || strtolower($s) === '-') {
            return null;
        }

        // Clean currency & numeric with Indian / standard commas
        // e.g. "₹ 1,50,000", "1,00,000", "2.5 Lakhs", "INR 75000"
        $numericPattern = '/^[₹$\s]*(?:INR)?\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]+)?|[0-9]+(?:\.[0-9]+)?)\s*(?:Lakhs|Lakh)?$/i';
        if (preg_match($numericPattern, $s, $m)) {
            $rawNum = str_replace(',', '', $m[1]);
            $num = (float) $rawNum;
            if (stripos($s, 'lakh') !== false) {
                $num *= 100000.0;
            }
            return $num;
        }

        // Standardize Yes / No booleans
        $lower = strtolower($s);
        if (in_array($lower, ['yes', 'y', 'true', '1'], true)) {
            return true;
        }
        if (in_array($lower, ['no', 'n', 'false', '0'], true)) {
            return false;
        }

        // Date standardisation
        $date = $this->parseDateString($s);
        if ($date !== null) {
            return $date;
        }

        return $s;
    }

    /**
     * Parses dates in various Indian and international formats into YYYY-MM-DD
     */
    public function parseDateString(string $val): ?string
    {
        $clean = trim($val);
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y', 'd.m.Y'];
        foreach ($formats as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $clean);
            if ($d !== false && $d->format($fmt) === $clean) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    private function checkCellAnomaly(string $header, mixed $raw, mixed $cleaned, string $fileType): ?array
    {
        $hLower = strtolower($header);

        // Required IDs
        if (str_contains($hLower, 'faculty id') || str_contains($hLower, 'student name') || str_contains($hLower, 'enrollment')) {
            if ($cleaned === null || $cleaned === '') {
                return [
                    'rule_id' => 'REQ_IDENTIFIER_MISSING',
                    'severity' => 'ERROR',
                    'message' => "Missing required identifier in column '{$header}'.",
                    'recommendation' => 'Ensure every record has a unique ID or name.',
                ];
            }
        }

        // Numeric checks
        if (str_contains($hLower, 'salary') || str_contains($hLower, 'spending') || str_contains($hLower, 'expenditure') || str_contains($hLower, 'earnings')) {
            if ($cleaned !== null && !is_numeric($cleaned)) {
                return [
                    'rule_id' => 'NUMERIC_FORMAT_INVALID',
                    'severity' => 'ERROR',
                    'message' => "Expected numeric amount in '{$header}', found '{$raw}'.",
                    'recommendation' => 'Enter a valid numeric amount in INR.',
                ];
            }
            if (is_numeric($cleaned) && (float)$cleaned < 0.0) {
                return [
                    'rule_id' => 'NEGATIVE_FINANCE_VALUE',
                    'severity' => 'ERROR',
                    'message' => "Negative expenditure/salary observed in '{$header}': {$cleaned}.",
                    'recommendation' => 'Expenditures and salaries must be non-negative.',
                ];
            }
        }

        return null;
    }

    public function findValueByKeywords(array $row, array $keywords): mixed
    {
        // 1. Exact match first
        foreach ($row as $key => $val) {
            $kLower = strtolower(trim((string)$key));
            foreach ($keywords as $kw) {
                if ($kLower === $kw) {
                    return $val;
                }
            }
        }
        // 2. Substring match for keywords with length >= 4
        foreach ($row as $key => $val) {
            $kLower = strtolower(trim((string)$key));
            foreach ($keywords as $kw) {
                if (strlen($kw) >= 4 && str_contains($kLower, $kw)) {
                    return $val;
                }
            }
        }
        return null;
    }
}
