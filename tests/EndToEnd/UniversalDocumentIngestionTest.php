<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Ingestion\UniversalDocumentIngestionService;
use App\Mapping\CanonicalFieldCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UniversalDocumentIngestionTest extends TestCase
{
    private UniversalDocumentIngestionService $service;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->service = new UniversalDocumentIngestionService();
        $this->fixtureDir = 'C:/Users/HP/.gemini/antigravity/brain/8822dbd6-24f9-472b-97a0-4a5a7b463b98/extracted_handoff_v3/NIRF_Engineering_Final_Developer_Handoff_v3/15_TEST_FIXTURES/synthetic_random_uploads';
    }

    public function testIngestsMultiFormatFilesSuccessfully(): void
    {
        $files = [
            [
                'path' => $this->fixtureDir . '/Annual_Report_Extract_2024_25.pdf',
                'original_name' => 'Annual_Report_Extract_2024_25.pdf',
            ],
            [
                'path' => $this->fixtureDir . '/HR_Export_Oct_2025.xlsx',
                'original_name' => 'HR_Export_Oct_2025.xlsx',
            ],
            [
                'path' => $this->fixtureDir . '/placements_sis_export.csv',
                'original_name' => 'placements_sis_export.csv',
            ],
            [
                'path' => $this->fixtureDir . '/Finance_GL_Export_2022_25.xlsx',
                'original_name' => 'Finance_GL_Export_2022_25.xlsx',
            ],
        ];

        $res = $this->service->ingestDocuments($files);

        $this->assertTrue($res['success']);
        $this->assertCount(4, $res['documents']);
        $this->assertGreaterThan(50, count($res['candidate_facts']));

        // Verify locators for each format
        $hasPdfLocator = false;
        $hasXlsxLocator = false;
        $hasCsvLocator = false;

        foreach ($res['candidate_facts'] as $fact) {
            $this->assertNotEmpty($fact['candidate_id']);
            $this->assertNotEmpty($fact['document']);
            $loc = $fact['source_locator'];

            if ($loc['source_type'] === 'PDF') {
                $hasPdfLocator = true;
                $this->assertArrayHasKey('page', $loc);
            } elseif ($loc['source_type'] === 'XLSX') {
                $hasXlsxLocator = true;
                $this->assertArrayHasKey('sheet', $loc);
                $this->assertArrayHasKey('cell', $loc);
            } elseif ($loc['source_type'] === 'CSV') {
                $hasCsvLocator = true;
                $this->assertArrayHasKey('line', $loc);
            }
        }

        $this->assertTrue($hasPdfLocator, "Should extract candidate facts with PDF locators");
        $this->assertTrue($hasXlsxLocator, "Should extract candidate facts with XLSX locators");
        $this->assertTrue($hasCsvLocator, "Should extract candidate facts with CSV locators");
    }

    public function testRejectsUnsupportedFileExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UNSUPPORTED_FILE_EXTENSION');

        $this->service->ingestDocuments([
            [
                'path' => __DIR__ . '/test.docx',
                'original_name' => 'annual_report.docx',
            ]
        ]);
    }

    public function testRejectsPackageExceeding12ActiveDocuments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UPLOAD_TOO_MANY_ACTIVE_DOCUMENTS');

        $files = [];
        for ($i = 1; $i <= 13; $i++) {
            $files[] = [
                'path' => $this->fixtureDir . '/placements_sis_export.csv',
                'original_name' => "export_{$i}.csv",
            ];
        }

        $this->service->ingestDocuments($files);
    }

    public function testReconcilesConflictsInsteadOfSilentSelection(): void
    {
        $conflictingFacts = [
            [
                'candidate_id' => 'cand-001',
                'document' => 'HR_Export_Oct_2025.xlsx',
                'canonical_field_id' => 'faculty.total_count',
                'source_label' => 'Total Faculty',
                'raw_value' => 20,
                'normalized_value' => 20.0,
                'source_locator' => ['source_type' => 'XLSX', 'sheet' => 'Summary', 'cell' => 'B2'],
                'confidence' => 0.95,
                'review_status' => 'SUGGESTED',
            ],
            [
                'candidate_id' => 'cand-002',
                'document' => 'Annual_Report_Extract_2024_25.pdf',
                'canonical_field_id' => 'faculty.total_count',
                'source_label' => 'Faculty count',
                'raw_value' => 24,
                'normalized_value' => 24.0,
                'source_locator' => ['source_type' => 'PDF', 'page' => 1, 'locator' => 'Page 1, Line 4'],
                'confidence' => 0.90,
                'review_status' => 'SUGGESTED',
            ],
        ];

        $res = $this->service->reconcileFacts($conflictingFacts);

        $hasConflictWarning = false;
        foreach ($res['issues'] as $iss) {
            if (str_starts_with($iss['rule_id'], 'CONFLICT_')) {
                $hasConflictWarning = true;
                $this->assertStringContainsString('Cross-Document Conflict detected', $iss['message']);
            }
        }

        $this->assertTrue($hasConflictWarning, "Must flag conflict when multiple documents assert differing values");
    }
}
