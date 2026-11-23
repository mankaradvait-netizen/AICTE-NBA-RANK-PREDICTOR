<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\UniversalDocumentIngestionService;
use PHPUnit\Framework\TestCase;

class PdfMultiYearAndCalculatedDataIngestionTest extends TestCase
{
    private UniversalDocumentIngestionService $service;

    protected function setUp(): void
    {
        $this->service = new UniversalDocumentIngestionService();
    }

    public function testCalculatedDataKeyValueParsing(): void
    {
        // Test key-value expressions in spreadsheet/PDF summary cells
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        
        $sheet->setCellValue('A1', 'Institutional Calculated Summary');
        $sheet->setCellValue('A2', 'number of ug students = 65');
        $sheet->setCellValue('A3', 'Total Expense = 455608138');
        $sheet->setCellValue('A4', 'Total Sanctioned Intake = 720');
        $sheet->setCellValue('A5', 'Total Faculty = 118');
        $sheet->setCellValue('A6', 'Average Package: 4.5 LPA');

        $tmpFile = sys_get_temp_dir() . '/test_calc_' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpFile);

        $res = $this->service->ingestDocuments([
            ['path' => $tmpFile, 'original_name' => 'Institutional_Summary.xlsx', 'size' => filesize($tmpFile)]
        ]);
        @unlink($tmpFile);

        $this->assertTrue($res['success']);
        
        // Check student count is exactly 65 (not overridden by defaults or garbage)
        $ugFact = array_filter($res['candidate_facts'], fn($f) => $f['canonical_field_id'] === 'student.ug_enrolled');
        $this->assertNotEmpty($ugFact);
        $firstUg = reset($ugFact);
        $this->assertEquals(65.0, $firstUg['normalized_value']);
        $this->assertTrue($firstUg['is_latest']);
        $this->assertEquals(65.0, $res['mapped_kpis']['NE']);

        // Check total expenditure
        $expFact = array_filter($res['candidate_facts'], fn($f) => $f['canonical_field_id'] === 'finance.total_expenditure');
        $this->assertNotEmpty($expFact);
        $firstExp = reset($expFact);
        $this->assertEquals(455608138.0, $firstExp['normalized_value']);

        // Check package: 4.5 LPA -> 450,000 INR
        $salFact = array_filter($res['candidate_facts'], fn($f) => $f['canonical_field_id'] === 'outcome.median_salary');
        $this->assertNotEmpty($salFact);
        $firstSal = reset($salFact);
        $this->assertEquals(450000.0, $firstSal['normalized_value']);
        $this->assertEquals(450000.0, $res['mapped_kpis']['MS']);
    }

    public function testMultiYearReverseChronologicalColumnExtraction(): void
    {
        // Simulates NIRF DCS format where latest year is column D (left) and oldest is column F (right)
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Financial_DCS');

        $sheet->setCellValue('A1', 'Financial Resources: Academic Capex and Opex');
        $sheet->fromArray([
            ['Financial Year', '', '2025-26', '2024-25', '2023-24', '2022-23'],
            ['Total Annual Operational Expenditure', 'Amount in Rupees', '', 369354888, 283057636, 292029393],
            ['Total Annual Capital Expenditure', 'Amount in Rupees', '', 86253250, 70057719, 41153080],
            ['Total Amount Received Sponsored Research', 'Amount in Rupees', '', 18051220, 13541280, 3129400],
            ['Total Amount Received Consultancy', 'Amount in Rupees', '', 20107890, 19450500, 4245600],
        ], null, 'A2');

        $tmpFile = sys_get_temp_dir() . '/test_dcs_' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpFile);

        $res = $this->service->ingestDocuments([
            ['path' => $tmpFile, 'original_name' => 'Financial_Resources_DCS.xlsx', 'size' => filesize($tmpFile)]
        ]);
        @unlink($tmpFile);

        $this->assertTrue($res['success']);

        // Must pick 2024-25 (the latest year with valid non-empty data), NOT 2022-23!
        $this->assertEquals(369354888.0, $res['mapped_kpis']['Opex']);
        $this->assertEquals(86253250.0, $res['mapped_kpis']['Capex']);

        $opexFact = array_filter($res['candidate_facts'], fn($f) => $f['canonical_field_id'] === 'finance.academic_opex');
        $this->assertNotEmpty($opexFact);
        $firstOpex = reset($opexFact);
        $this->assertEquals(369354888.0, $firstOpex['normalized_value']);
        $this->assertEquals('2024-25', $firstOpex['academic_year']);
        $this->assertTrue($firstOpex['is_latest']);
    }

    public function testPlacementSummaryImage2WithNarowsTakesLatestYear(): void
    {
        // Verifies Image 2 format where 2023-24 and 2024-25 are NA and only 2025-26 has ₹2.6L
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', 'STUDENT LIST WITH PLACEMENT DATA — ALL 3 ACADEMIC YEARS');
        $sheet->fromArray([
            ['AY', 'Total Students', 'Placement Records', 'Matched (Placed)', 'Match %', 'Avg Package (LPA)'],
            ['2023-24', 'NA', 'NA', 'NA', 'NA', 'NA'],
            ['2024-25', 'NA', 'NA', 'NA', 'NA', 'NA'],
            ['2025-26', 55, 38, 38, 69, '₹2.6L'],
        ], null, 'A2');

        $tmpFile = sys_get_temp_dir() . '/test_p2_' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpFile);

        $res = $this->service->ingestDocuments([
            ['path' => $tmpFile, 'original_name' => 'Placement_Summary_Image2.xlsx', 'size' => filesize($tmpFile)]
        ]);
        @unlink($tmpFile);

        $this->assertTrue($res['success']);
        
        // Validates latest year metrics
        $this->assertEquals(69.0, $res['mapped_kpis']['NP_pct']);
        $this->assertEquals(260000.0, $res['mapped_kpis']['MS']);

        // Zero NA garbage entries
        foreach ($res['candidate_facts'] as $f) {
            $this->assertNotEquals('NA', $f['raw_value']);
            $this->assertNotEquals('N/A', $f['raw_value']);
            $this->assertIsNumeric($f['normalized_value']);
        }
    }
}
