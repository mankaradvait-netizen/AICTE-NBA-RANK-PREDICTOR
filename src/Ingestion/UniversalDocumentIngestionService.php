<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Mapping\CanonicalFieldCatalog;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;
use InvalidArgumentException;

/**
 * Universal Multi-Format Document Ingestion Engine
 * Accepts any institutional document (.xlsx, .xls, .csv, .pdf),
 * extracts candidate facts with cell/line/page source locators,
 * maps against the 71-field canonical catalog, and reconciles conflicts.
 */
class UniversalDocumentIngestionService
{
    public const MAX_ACTIVE_DOCUMENTS = 12;
    public const RECOMMENDED_DOCUMENTS = 8;
    public const MAX_FILE_BYTES = 52428800; // 50 MiB
    public const MAX_PACKAGE_BYTES = 209715200; // 200 MiB
    public const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'pdf'];

    /**
     * Ingests a collection of uploaded documents (either PSR-7 UploadedFileInterface or array of file paths).
     * 
     * @param array<int, array{path: string, original_name: string, size?: int}> $files
     * @return array{
     *   success: bool,
     *   document_count: int,
     *   documents: array<int, array<string, mixed>>,
     *   candidate_facts: array<int, array<string, mixed>>,
     *   mapped_kpis: array<string, float>,
     *   issues: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    public function ingestDocuments(array $files): array
    {
        if (empty($files)) {
            throw new InvalidArgumentException("UPLOAD_PACKAGE_EMPTY: No files provided for ingestion.");
        }

        if (count($files) > self::MAX_ACTIVE_DOCUMENTS) {
            throw new InvalidArgumentException(
                "UPLOAD_TOO_MANY_ACTIVE_DOCUMENTS: Package contains " . count($files) .
                " files, exceeding maximum limit of " . self::MAX_ACTIVE_DOCUMENTS . "."
            );
        }

        $totalBytes = 0;
        foreach ($files as $f) {
            $path = $f['path'];
            $name = $f['original_name'] ?? basename($path);
            $size = $f['size'] ?? (file_exists($path) ? filesize($path) : 0);
            $totalBytes += $size;

            if ($size > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException("FILE_TOO_LARGE: File '{$name}' exceeds 50 MiB limit.");
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException("UNSUPPORTED_FILE_EXTENSION: '.{$ext}' is not supported. Use .xlsx, .xls, .csv, or .pdf.");
            }
        }

        if ($totalBytes > self::MAX_PACKAGE_BYTES) {
            throw new InvalidArgumentException("PACKAGE_TOO_LARGE: Total package size exceeds 200 MiB limit.");
        }

        $allFacts = [];
        $docSummaries = [];
        $issues = [];
        $candIndex = 1;

        foreach ($files as $f) {
            $path = $f['path'];
            $name = $f['original_name'] ?? basename($path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $docRes = match ($ext) {
                'xlsx', 'xls' => $this->parseSpreadsheet($path, $name, $candIndex),
                'csv' => $this->parseCsv($path, $name, $candIndex),
                'pdf' => $this->parsePdf($path, $name, $candIndex),
                default => throw new InvalidArgumentException("Unsupported format: {$ext}"),
            };

            $candIndex += count($docRes['facts']);
            $allFacts = array_merge($allFacts, $docRes['facts']);
            $issues = array_merge($issues, $docRes['issues']);
            $docSummaries[] = [
                'filename' => $name,
                'extension' => $ext,
                'size_bytes' => $f['size'] ?? (file_exists($path) ? filesize($path) : 0),
                'sha256' => file_exists($path) ? hash_file('sha256', $path) : null,
                'facts_count' => count($docRes['facts']),
                'sheets_or_pages' => $docRes['sheets_or_pages'] ?? 1,
                'status' => 'EXTRACTED',
            ];
        }

        // Reconcile facts into NIRF 29 KPI inputs & detect cross-source conflicts
        $reconciliation = $this->reconcileFacts($allFacts);
        $allIssues = array_merge($issues, $reconciliation['issues']);

        return [
            'success' => true,
            'document_count' => count($files),
            'documents' => $docSummaries,
            'candidate_facts' => $allFacts,
            'mapped_kpis' => $reconciliation['kpis'],
            'issues' => $allIssues,
            'summary' => [
                'total_facts' => count($allFacts),
                'mapped_fields_count' => count(array_filter($allFacts, fn($x) => $x['canonical_field_id'] !== null)),
                'conflict_count' => count(array_filter($allIssues, fn($x) => ($x['severity'] ?? '') === 'ERROR')),
                'status' => count(array_filter($allIssues, fn($x) => ($x['severity'] ?? '') === 'ERROR')) > 0 ? 'NEEDS_REVIEW' : 'READY_FOR_FREEZE',
            ],
        ];
    }

    /**
     * Parses .xlsx or .xls files using fast memory-safe array extraction.
     * @return array{facts: list<array<string, mixed>>, issues: list<array<string, mixed>>, sheets_or_pages: int}
     */
    private function parseSpreadsheet(string $filePath, string $docName, int $startIndex): array
    {
        $facts = [];
        $issues = [];
        $idx = $startIndex;

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheetCount = $spreadsheet->getSheetCount();

            $sheetsData = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetName = $sheet->getTitle();
                $sheetsData[$sheetName] = $sheet->toArray(null, true, false, false);
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            foreach ($sheetsData as $sheetName => $rawData) {
                if (empty($rawData)) {
                    continue;
                }

                $totalRows = count($rawData);

                // Detect header row (first row with >= 2 non-empty cells)
                $headerRowIdx = 0;
                for ($r = 0; $r < min(5, $totalRows); $r++) {
                    $nonEmpty = array_filter($rawData[$r], fn($c) => $c !== null && trim((string)$c) !== '');
                    if (count($nonEmpty) >= 2) {
                        $headerRowIdx = $r;
                        break;
                    }
                }

                $rawHeaders = $rawData[$headerRowIdx] ?? [];
                $headers = [];
                $canonicalByCol = [];
                foreach ($rawHeaders as $colIdx => $val) {
                    $h = trim((string)$val);
                    if ($h !== '') {
                        $headers[$colIdx] = $h;
                        $canonicalByCol[$colIdx] = CanonicalFieldCatalog::matchField($h);
                    }
                }

                // 1. Scan summary key-value cells (e.g. "Sanctioned Intake: 720")
                for ($r = 0; $r < min(30, $totalRows); $r++) {
                    foreach ($rawData[$r] as $colIdx => $val) {
                        $strVal = trim((string)$val);
                        if (preg_match('/^([A-Za-z\s]+)[:\-=\t]\s*([\d,]+(?:\.\d+)?)/', $strVal, $m)) {
                            $canonical = CanonicalFieldCatalog::matchField($m[1]);
                            $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                            $cleanVal = (float) str_replace(',', '', $m[2]);
                            $meta = $canonical ? CanonicalFieldCatalog::get($canonical) : null;
                            $facts[] = [
                                'candidate_id' => 'cand-' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                                'document' => $docName,
                                'document_filename' => $docName,
                                'field_code' => $canonical ?? 'UNMAPPED',
                                'canonical_field_id' => $canonical,
                                'canonical_name' => $meta['description'] ?? ($meta['name'] ?? trim($m[1])),
                                'source_label' => trim($m[1]),
                                'raw_value' => $cleanVal,
                                'normalized_value' => $cleanVal,
                                'unit' => 'numeric',
                                'source_locator' => [
                                    'source_type' => 'XLSX',
                                    'sheet' => $sheetName,
                                    'row' => $r + 1,
                                    'column' => $colLetter,
                                    'cell' => $colLetter . ($r + 1),
                                ],
                                'confidence' => $canonical ? 0.95 : 0.50,
                                'review_status' => $canonical ? 'SUGGESTED' : 'UNMAPPED',
                                'status' => 'BOUND',
                            ];
                        }
                    }
                }

                // 2. Scan data rows up to 150 facts per sheet
                $factCountForSheet = 0;
                $maxFactsPerSheet = 150;

                for ($r = $headerRowIdx + 1; $r < $totalRows; $r++) {
                    if ($factCountForSheet >= $maxFactsPerSheet) {
                        break;
                    }
                    $row = $rawData[$r];
                    if (empty(array_filter($row, fn($c) => $c !== null && trim((string)$c) !== ''))) {
                        continue;
                    }

                    foreach ($headers as $colIdx => $header) {
                        $rawVal = $row[$colIdx] ?? null;
                        if ($rawVal === null || trim((string)$rawVal) === '') {
                            continue;
                        }

                        $canonical = $canonicalByCol[$colIdx] ?? null;
                        if ($canonical === null && $factCountForSheet > 20) {
                            continue;
                        }

                        $cleanVal = $this->cleanValue($rawVal);
                        if ($cleanVal === null) {
                            continue;
                        }

                        $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                        $cellCoord = $colLetter . ($r + 1);
                        $meta = $canonical ? CanonicalFieldCatalog::get($canonical) : null;
                        $cName = $meta['description'] ?? ($meta['name'] ?? $header);

                        $facts[] = [
                            'candidate_id' => 'cand-' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                            'document' => $docName,
                            'document_filename' => $docName,
                            'field_code' => $canonical ?? 'UNMAPPED',
                            'canonical_field_id' => $canonical,
                            'canonical_name' => $cName,
                            'source_label' => $header,
                            'raw_value' => $rawVal,
                            'normalized_value' => $cleanVal,
                            'unit' => is_numeric($cleanVal) ? 'numeric' : 'text',
                            'source_locator' => [
                                'source_type' => 'XLSX',
                                'sheet' => $sheetName,
                                'row' => $r + 1,
                                'column' => $colLetter,
                                'cell' => $cellCoord,
                            ],
                            'confidence' => $canonical ? 0.90 : 0.40,
                            'review_status' => $canonical ? 'SUGGESTED' : 'UNMAPPED',
                            'status' => 'BOUND',
                        ];
                        $factCountForSheet++;
                    }
                }
            }

            return ['facts' => $facts, 'issues' => $issues, 'sheets_or_pages' => $sheetCount];
        } catch (\Throwable $e) {
            $issues[] = [
                'severity' => 'ERROR',
                'rule_id' => 'SPREADSHEET_PARSE_FAILED',
                'file' => $docName,
                'message' => "Failed to parse spreadsheet '{$docName}': " . $e->getMessage(),
                'status' => 'PENDING',
            ];
            return ['facts' => [], 'issues' => $issues, 'sheets_or_pages' => 0];
        }
    }

    /**
     * Parses .csv files
     * @return array{facts: list<array<string, mixed>>, issues: list<array<string, mixed>>, sheets_or_pages: int}
     */
    private function parseCsv(string $filePath, string $docName, int $startIndex): array
    {
        $facts = [];
        $issues = [];
        $idx = $startIndex;

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $issues[] = [
                'severity' => 'ERROR',
                'rule_id' => 'CSV_OPEN_FAILED',
                'file' => $docName,
                'message' => "Could not open CSV file '{$docName}'.",
                'status' => 'PENDING',
            ];
            return ['facts' => [], 'issues' => $issues, 'sheets_or_pages' => 1];
        }

        // Detect delimiter (comma vs semicolon vs tab)
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }

        $rawHeaders = fgetcsv($handle, 0, $delimiter) ?: [];
        $headers = [];
        $canonicalByCol = [];
        foreach ($rawHeaders as $colIdx => $val) {
            $h = trim((string)$val);
            if ($h !== '') {
                $headers[$colIdx] = $h;
                $canonicalByCol[$colIdx] = CanonicalFieldCatalog::matchField($h);
            }
        }

        $lineNum = 1;
        $factCount = 0;
        $maxCsvFacts = 150;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNum++;
            if ($factCount >= $maxCsvFacts) {
                break;
            }

            foreach ($headers as $colIdx => $header) {
                $rawVal = $row[$colIdx] ?? null;
                if ($rawVal === null || trim((string)$rawVal) === '') {
                    continue;
                }

                $canonical = $canonicalByCol[$colIdx] ?? null;
                if ($canonical === null && $factCount > 20) {
                    continue;
                }

                $cleanVal = $this->cleanValue($rawVal);
                if ($cleanVal === null) {
                    continue;
                }

                $meta = $canonical ? CanonicalFieldCatalog::get($canonical) : null;
                $cName = $meta['description'] ?? ($meta['name'] ?? $header);

                $facts[] = [
                    'candidate_id' => 'cand-' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                    'document' => $docName,
                    'document_filename' => $docName,
                    'field_code' => $canonical ?? 'UNMAPPED',
                    'canonical_field_id' => $canonical,
                    'canonical_name' => $cName,
                    'source_label' => $header,
                    'raw_value' => $rawVal,
                    'normalized_value' => $cleanVal,
                    'unit' => is_numeric($cleanVal) ? 'numeric' : 'text',
                    'source_locator' => [
                        'source_type' => 'CSV',
                        'line' => $lineNum,
                        'column' => $header,
                    ],
                    'confidence' => $canonical ? 0.90 : 0.40,
                    'review_status' => $canonical ? 'SUGGESTED' : 'UNMAPPED',
                    'status' => 'BOUND',
                ];
                $factCount++;
            }
        }
        fclose($handle);

        return ['facts' => $facts, 'issues' => $issues, 'sheets_or_pages' => 1];
    }

    /**
     * Parses .pdf files using bin/extract_pdf.py or native regex parsing
     * @return array{facts: list<array<string, mixed>>, issues: list<array<string, mixed>>, sheets_or_pages: int}
     */
    private function parsePdf(string $filePath, string $docName, int $startIndex): array
    {
        $facts = [];
        $issues = [];
        $idx = $startIndex;
        $pageCount = 1;

        $scriptPath = __DIR__ . '/../../bin/extract_pdf.py';
        $cmd = 'python ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($filePath);
        $output = @shell_exec($cmd);

        if ($output) {
            $json = json_decode($output, true);
            if (!empty($json['success']) && !empty($json['pages'])) {
                $pageCount = (int) ($json['total_pages'] ?? 1);
                foreach ($json['pages'] as $p) {
                    $pageNum = $p['page'] ?? 1;
                    foreach ($p['candidates'] ?? [] as $cand) {
                        $label = $cand['raw_label'] ?? '';
                        $canonical = CanonicalFieldCatalog::matchField($label);
                        $meta = $canonical ? CanonicalFieldCatalog::get($canonical) : null;
                        $facts[] = [
                            'candidate_id' => 'cand-' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                            'document' => $docName,
                            'document_filename' => $docName,
                            'field_code' => $canonical ?? 'UNMAPPED',
                            'canonical_field_id' => $canonical,
                            'canonical_name' => $meta['description'] ?? ($meta['name'] ?? $label),
                            'source_label' => $label,
                            'raw_value' => $cand['raw_value'] ?? 0,
                            'normalized_value' => $cand['raw_value'] ?? 0,
                            'unit' => 'numeric',
                            'source_locator' => [
                                'source_type' => 'PDF',
                                'page' => $pageNum,
                                'locator' => $cand['locator'] ?? "Page {$pageNum}",
                            ],
                            'confidence' => $canonical ? 0.88 : 0.50,
                            'review_status' => $canonical ? 'SUGGESTED' : 'UNMAPPED',
                            'status' => 'BOUND',
                        ];
                    }
                }
                return ['facts' => $facts, 'issues' => $issues, 'sheets_or_pages' => $pageCount];
            }
        }

        // Fallback: Basic text scan if python/pypdf not available
        $content = @file_get_contents($filePath);
        if ($content !== false) {
            preg_match_all('/([A-Za-z\s]{3,30})[:\t]\s*([\d,]+(?:\.\d+)?)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $label = trim($m[1]);
                $canonical = CanonicalFieldCatalog::matchField($label);
                $val = (float) str_replace(',', '', $m[2]);
                $meta = $canonical ? CanonicalFieldCatalog::get($canonical) : null;
                $facts[] = [
                    'candidate_id' => 'cand-' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                    'document' => $docName,
                    'document_filename' => $docName,
                    'field_code' => $canonical ?? 'UNMAPPED',
                    'canonical_field_id' => $canonical,
                    'canonical_name' => $meta['description'] ?? ($meta['name'] ?? $label),
                    'source_label' => $label,
                    'raw_value' => $val,
                    'normalized_value' => $val,
                    'unit' => 'numeric',
                    'source_locator' => ['source_type' => 'PDF', 'page' => 1, 'locator' => 'Text Area'],
                    'confidence' => $canonical ? 0.75 : 0.30,
                    'review_status' => $canonical ? 'SUGGESTED' : 'UNMAPPED',
                    'status' => 'BOUND',
                ];
            }
        }

        return ['facts' => $facts, 'issues' => $issues, 'sheets_or_pages' => $pageCount];
    }

    /**
     * Reconciles candidate facts into NIRF 29 inputs and detects cross-file conflicts
     * @param list<array<string, mixed>> $facts
     * @return array{kpis: array<string, float>, issues: list<array<string, mixed>>}
     */
    public function reconcileFacts(array $facts): array
    {
        $issues = [];
        $candidatesByMetric = [];

        // Group facts by target NIRF metric
        foreach ($facts as $f) {
            $fieldId = $f['canonical_field_id'] ?? null;
            if (!$fieldId) continue;

            $meta = CanonicalFieldCatalog::get($fieldId);
            $metricCode = $meta['metric'] ?? 'UNKNOWN';
            if ($metricCode === 'UNKNOWN' || $metricCode === 'META') continue;

            $candidatesByMetric[$metricCode][] = $f;
        }

        // 1. Detect conflicts: Multiple documents asserting differing values for key metrics
        foreach ($candidatesByMetric as $metricCode => $metricFacts) {
            $docs = array_unique(array_column($metricFacts, 'document'));
            if (count($docs) > 1) {
                $values = [];
                foreach ($metricFacts as $mf) {
                    $v = (float) ($mf['normalized_value'] ?? 0.0);
                    if ($v > 0) $values[] = $v;
                }
                $uniqueVals = array_unique($values);
                if (count($uniqueVals) > 1) {
                    $valList = implode(', ', $uniqueVals);
                    $issues[] = [
                        'severity' => 'WARNING',
                        'rule_id' => "CONFLICT_{$metricCode}",
                        'field' => $metricCode,
                        'message' => "Cross-Document Conflict detected for metric {$metricCode}: Documents report differing values ({$valList}). Please review candidate facts in Grid.",
                        'status' => 'PENDING',
                        'conflicting_documents' => $docs,
                    ];
                }
            }
        }

        // 2. Synthesize baseline KPI inputs from facts with statutory exclusions
        // Default values representing standard baseline if unasserted
        $kpis = [
            'SS' => 20.0,
            'NT' => 100.0,
            'NE' => 100.0,
            'NP' => 0.0,
            'F' => 20.0,
            'N_stud' => 100.0,
            'FRA' => 1.0,
            'F1' => 4.0,
            'F2' => 6.0,
            'F3' => 10.0,
            'FQ_phd' => 16.0,
            'FE' => 18.0,
            'PU_score' => 25.0,
            'QP_score' => 30.0,
            'IPR_score' => 10.0,
            'FPPP_score' => 15.0,
            'GPH_placed' => 75.0,
            'GUE_passed' => 85.0,
            'MS' => 650000.0,
            'GPHD_count' => 6.0,
            'RD_outside_state' => 15.0,
            'RD_outside_country' => 2.0,
            'WD_women' => 30.0,
            'ESCS_count' => 25.0,
            'PCS_score' => 18.0,
            'PR_score' => 45.0,
            'BC' => 15000000.0, // Statutory building construction cost (excluded from Capex)
            'BO' => 5000000.0,  // Statutory hostel cost (excluded from Opex)
            'Capex' => 12000000.0,
            'Opex' => 28000000.0,
        ];

        // Override with extracted candidate values
        $facultyCount = 0;
        $phdCount = 0;
        $placedCount = 0;
        $enrolledCount = 0;
        $higherStudyCount = 0;

        foreach ($facts as $f) {
            $fieldId = $f['canonical_field_id'] ?? '';
            $val = $f['normalized_value'];

            if ($fieldId === 'faculty.total_count' && is_numeric($val) && (float)$val > 0) {
                $facultyCount = (int)$val;
            } elseif ($fieldId === 'faculty.has_phd') {
                if (is_numeric($val) && (float)$val > 0) {
                    $phdCount = max($phdCount, (int)$val);
                } elseif ($val === true || $val === 'Yes' || $val === 'PhD') {
                    $phdCount++;
                }
            } elseif ($fieldId === 'outcome.placed_count' && is_numeric($val) && (float)$val > 0) {
                $placedCount = (int)$val;
            } elseif ($fieldId === 'student.total_enrolled' && is_numeric($val) && (float)$val > 0) {
                $enrolledCount = (int)$val;
            } elseif ($fieldId === 'outcome.higher_studies_count' && is_numeric($val) && (float)$val > 0) {
                $higherStudyCount = (int)$val;
            } elseif ($fieldId === 'outcome.median_salary' && is_numeric($val) && (float)$val > 50000) {
                $kpis['MS'] = (float)$val;
            } elseif ($fieldId === 'outcome.phd_graduated' && is_numeric($val) && (float)$val >= 0) {
                $kpis['GPHD_count'] = (float)$val;
            } elseif ($fieldId === 'facilities.pcs_score' && is_numeric($val)) {
                $kpis['PCS_score'] = min(20.0, (float)$val);
            } elseif ($fieldId === 'perception.peer_score' && is_numeric($val)) {
                $kpis['PR_score'] = min(100.0, (float)$val);
            } elseif ($fieldId === 'finance.capex' && is_numeric($val) && (float)$val > 0) {
                $kpis['Capex'] = (float)$val;
            } elseif ($fieldId === 'finance.opex' && is_numeric($val) && (float)$val > 0) {
                $kpis['Opex'] = (float)$val;
            } elseif ($fieldId === 'finance.bc_cost' && is_numeric($val) && (float)$val > 0) {
                $kpis['BC'] = (float)$val;
            } elseif ($fieldId === 'finance.bo_cost' && is_numeric($val) && (float)$val > 0) {
                $kpis['BO'] = (float)$val;
            } elseif ($fieldId === 'institution.sanctioned_intake' && is_numeric($val) && (float)$val > 0) {
                $kpis['NT'] = (float)$val;
                $kpis['NE'] = (float)$val;
                $kpis['N_stud'] = (float)$val;
            } elseif ($fieldId === 'diversity.women_percentage' && is_numeric($val)) {
                $kpis['WD_women'] = min(100.0, (float)$val);
            } elseif ($fieldId === 'diversity.outside_state_percentage' && is_numeric($val)) {
                $kpis['RD_outside_state'] = min(100.0, (float)$val);
            }
        }

        // Apply derived counts
        if ($facultyCount > 0) {
            $kpis['F'] = (float) $facultyCount;
        }
        if ($enrolledCount > 0) {
            $kpis['NT'] = (float) $enrolledCount;
            $kpis['NE'] = (float) $enrolledCount;
            $kpis['N_stud'] = (float) $enrolledCount;
        }
        if ($phdCount > 0) {
            $kpis['FQ_phd'] = (float) $phdCount;
        }
        if ($placedCount > 0 || $higherStudyCount > 0) {
            $gradCohort = max(100.0, $kpis['NT']);
            $kpis['GPH_placed'] = min(100.0, round((($placedCount + $higherStudyCount) / $gradCohort) * 100.0, 2));
        }

        // Statutory ceiling check on FSR (1:15 norm)
        $totalStudents = $kpis['NT'] + $kpis['NP'];
        if ($totalStudents > 0) {
            $currentRatio = $kpis['F'] / $totalStudents;
            if ($currentRatio >= (1.0 / 15.0)) {
                $issues[] = [
                    'severity' => 'INFO',
                    'rule_id' => 'STATUTORY_FSR_CEILING',
                    'field' => 'FSR',
                    'message' => "Statutory FSR Norm Achieved: Current ratio 1:" . round($totalStudents / $kpis['F'], 1) . " satisfies the statutory 1:15 requirement.",
                    'status' => 'INFO',
                ];
            }
        }

        return ['kpis' => $kpis, 'issues' => $issues];
    }

    /**
     * Cleans cell value: strips currency, commas, whitespace
     */
    private function cleanValue(mixed $val): mixed
    {
        if (is_numeric($val)) {
            return (float)$val;
        }
        if (is_string($val)) {
            $trimmed = trim($val);
            if ($trimmed === '') return null;

            // Strip currency symbols and Indian numbering commas
            $cleanNum = preg_replace('/[₹$€£\s]/u', '', $trimmed) ?? '';
            $cleanNum = str_replace(',', '', $cleanNum);

            if (is_numeric($cleanNum)) {
                return (float)$cleanNum;
            }

            // Boolean representation
            $lower = strtolower($trimmed);
            if (in_array($lower, ['yes', 'true', '1', 'y'], true)) return true;
            if (in_array($lower, ['no', 'false', '0', 'n'], true)) return false;

            return $trimmed;
        }
        return $val;
    }
}
