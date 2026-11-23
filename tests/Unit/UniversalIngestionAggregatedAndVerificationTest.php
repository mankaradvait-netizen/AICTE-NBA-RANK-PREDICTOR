<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\DatabaseManager;
use App\Config\Environment;
use App\Engine\ScoreEngine;
use App\Formula\SafeExpressionEvaluator;
use App\Ingestion\UniversalDocumentIngestionService;
use App\Mapping\CanonicalFieldCatalog;
use PHPUnit\Framework\TestCase;

class UniversalIngestionAggregatedAndVerificationTest extends TestCase
{
    private UniversalDocumentIngestionService $service;

    protected function setUp(): void
    {
        $this->service = new UniversalDocumentIngestionService();
    }

    public function testCanonicalFieldCatalogMatchesNewAliases(): void
    {
        $this->assertSame('student.ug_enrolled', CanonicalFieldCatalog::matchField('number of ug students'));
        $this->assertSame('student.ug_enrolled', CanonicalFieldCatalog::matchField('students_enrolled_beginning'));
        $this->assertSame('student.ug_enrolled', CanonicalFieldCatalog::matchField('UG Student'));
        $this->assertSame('institution.sanctioned_intake', CanonicalFieldCatalog::matchField('Sanctioned_Seats'));
        $this->assertSame('outcome.placed_count', CanonicalFieldCatalog::matchField('Students_Placed'));
        $this->assertSame('outcome.median_salary', CanonicalFieldCatalog::matchField('UG_Median_Salary'));
        $this->assertSame('outcome.median_salary', CanonicalFieldCatalog::matchField('Avg Package (LPA)'));
        $this->assertSame('outcome.median_salary', CanonicalFieldCatalog::matchField('Placed Package LPA'));
        $this->assertSame('finance.academic_opex', CanonicalFieldCatalog::matchField('A. Salary of Teaching Staff  B.Salary of Non Teaching Staff'));
        $this->assertSame('finance.academic_capex', CanonicalFieldCatalog::matchField('Library (Books Journals and e-Resources only)'));
        $this->assertSame('research.sponsored_research_inr', CanonicalFieldCatalog::matchField('Total amount received -R&D'));
        $this->assertSame('faculty.total_count', CanonicalFieldCatalog::matchField('No. of faculty'));
        $this->assertSame('faculty.has_phd', CanonicalFieldCatalog::matchField('No. of PhD'));
    }

    public function testMetadataAndIgnoreLabelsDoNotProduceFacts(): void
    {
        $this->assertNull(CanonicalFieldCatalog::matchField('Institute_ID'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Academic_Year'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Amount in Words'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Result Not Yet Declare by SPPU'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Total_UG_Programs'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Total_Buildings'));
        $this->assertNull(CanonicalFieldCatalog::matchField('Sr. No.'));
    }

    public function testReconciliationProducesAll29NirfInputs(): void
    {
        $facts = [
            [
                'canonical_field_id' => 'institution.sanctioned_intake',
                'source_label' => 'Intake',
                'normalized_value' => 960.0,
            ],
            [
                'canonical_field_id' => 'student.total_enrolled',
                'source_label' => 'Total Enrolled',
                'normalized_value' => 3122.0,
            ],
            [
                'canonical_field_id' => 'faculty.total_count',
                'source_label' => 'No. of faculty',
                'normalized_value' => 118.0,
            ],
            [
                'canonical_field_id' => 'faculty.has_phd',
                'source_label' => 'No. of PhD',
                'normalized_value' => 34.0,
            ],
            [
                'canonical_field_id' => 'outcome.placed_count',
                'source_label' => 'Placed',
                'normalized_value' => 296.0,
            ],
            [
                'canonical_field_id' => 'outcome.median_salary',
                'source_label' => 'Median Salary',
                'normalized_value' => 580000.0,
            ],
            [
                'canonical_field_id' => 'finance.academic_capex',
                'source_label' => 'Total Capex',
                'normalized_value' => 44787801.0,
            ],
            [
                'canonical_field_id' => 'finance.academic_opex',
                'source_label' => 'Total Opex',
                'normalized_value' => 231183708.0,
            ],
            [
                'canonical_field_id' => 'research.sponsored_research_inr',
                'source_label' => 'Research Grant Total',
                'normalized_value' => 18051220.0,
            ],
            [
                'canonical_field_id' => 'research.consultancy_earnings_inr',
                'source_label' => 'Consultancy Revenue',
                'normalized_value' => 20107890.0,
            ],
            [
                'canonical_field_id' => 'student.women_count',
                'source_label' => 'Female Students',
                'normalized_value' => 983.0,
            ],
            [
                'canonical_field_id' => 'student.total_enrolled',
                'source_label' => 'Census Roster: 2025-26',
                'normalized_value' => 3122.0,
            ],
        ];

        $result = $this->service->reconcileFacts($facts);
        $kpis = $result['kpis'];
        $issues = $result['issues'];

        $this->assertArrayHasKey('NT', $kpis);
        $this->assertArrayHasKey('NE', $kpis);
        $this->assertArrayHasKey('F', $kpis);
        $this->assertArrayHasKey('F_PHD', $kpis);
        $this->assertArrayHasKey('BC', $kpis);
        $this->assertArrayHasKey('BO', $kpis);
        $this->assertArrayHasKey('RF', $kpis);
        $this->assertArrayHasKey('CF', $kpis);
        $this->assertArrayHasKey('NP_pct', $kpis);
        $this->assertArrayHasKey('MS', $kpis);
        $this->assertArrayHasKey('SW_pct', $kpis);

        $this->assertSame(960.0, $kpis['NT']);
        $this->assertSame(3122.0, $kpis['NE']);
        $this->assertSame(118.0, $kpis['F']);
        $this->assertSame(34.0, $kpis['F_PHD']);
        $this->assertSame(580000.0, $kpis['MS']);

        // Verify that census cross-verification issue was generated
        $verified = array_filter($issues, fn($i) => ($i['rule_id'] ?? '') === 'ROSTER_CROSS_VERIFIED');
        $this->assertNotEmpty($verified);
    }

    public function testPlacementSummaryTableImage1(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', 'STUDENT LIST WITH PLACEMENT DATA — ALL 3 ACADEMIC YEARS');
        $sheet->fromArray([
            ['AY', 'Total Students', 'Placement Records', 'Matched (Placed)', 'Match %', 'Avg Package (LPA)'],
            ['2023-24', 126, 52, 52, 41, '3.5 LPA'],
            ['2024-25', 123, 96, 96, 78, '4.5 LPA'],
            ['2025-26', 325, 145, 145, 45, '2.5 LPA'],
        ], null, 'A2');

        $tmpFile = sys_get_temp_dir() . '/test_img1_' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpFile);

        $res = $this->service->ingestDocuments([
            ['path' => $tmpFile, 'original_name' => 'Placement_Summary_Image1.xlsx', 'size' => filesize($tmpFile)]
        ]);
        @unlink($tmpFile);

        $this->assertTrue($res['success']);
        $this->assertCount(14, $res['candidate_facts']);
        // Primary KPI reflects latest academic year (2025-26: 45% and 2.5 LPA)
        $this->assertEquals(45.0, $res['mapped_kpis']['NP_pct']);
        $this->assertEquals(250000.0, $res['mapped_kpis']['MS']);

        // Multi-year aggregate is retained in candidate facts for audit
        $aggFacts = array_filter($res['candidate_facts'], fn($f) => $f['canonical_field_id'] === 'outcome.placement_percentage' && !($f['is_latest'] ?? false) && str_contains((string)($f['source_label'] ?? ''), 'Average'));
        $this->assertNotEmpty($aggFacts);
        $firstAgg = reset($aggFacts);
        $this->assertEquals(54.67, $firstAgg['normalized_value']);

        $verified = array_filter($res['issues'], fn($i) => ($i['rule_id'] ?? '') === 'PLACEMENT_SUMMARY_VERIFIED');
        $this->assertNotEmpty($verified);
    }

    public function testPlacementSummaryTableImage2WithNaRows(): void
    {
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

        $tmpFile = sys_get_temp_dir() . '/test_img2_' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpFile);

        $res = $this->service->ingestDocuments([
            ['path' => $tmpFile, 'original_name' => 'Placement_Summary_Image2.xlsx', 'size' => filesize($tmpFile)]
        ]);
        @unlink($tmpFile);

        $this->assertTrue($res['success']);
        $this->assertCount(6, $res['candidate_facts']);
        $this->assertEquals(69.0, $res['mapped_kpis']['NP_pct']);
        $this->assertEquals(260000.0, $res['mapped_kpis']['MS']);

        $verified = array_filter($res['issues'], fn($i) => ($i['rule_id'] ?? '') === 'PLACEMENT_SUMMARY_VERIFIED');
        $this->assertNotEmpty($verified);
    }
}
