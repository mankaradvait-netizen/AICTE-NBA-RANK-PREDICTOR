<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Report\PdfReportService;
use PHPUnit\Framework\TestCase;

class PdfReportServiceTest extends TestCase
{
    private PdfReportService $pdfService;

    protected function setUp(): void
    {
        $this->pdfService = new PdfReportService();
    }

    public function testGeneratesValidPdfBinaryStream(): void
    {
        $data = [
            'institution_name' => 'Ajeenkya DY Patil University (ADYPU)',
            'academic_year' => '2026-2027',
            'category' => 'ENGINEERING',
            'total_score' => 68.13,
            'predicted_band' => 'Rank 51 - 100',
            'confidence' => '80%',
            'main_parameter_subtotals' => [
                'TLR' => ['main_weight' => 0.30, 'subtotal_clamped' => 88.0, 'weighted_total' => 26.4],
                'RPC' => ['main_weight' => 0.30, 'subtotal_clamped' => 49.3, 'weighted_total' => 14.8],
                'GO'  => ['main_weight' => 0.20, 'subtotal_clamped' => 65.4, 'weighted_total' => 13.1],
                'OI'  => ['main_weight' => 0.10, 'subtotal_clamped' => 74.3, 'weighted_total' => 7.4],
                'PR'  => ['main_weight' => 0.10, 'subtotal_clamped' => 64.0, 'weighted_total' => 6.4],
            ],
            'parameter_scores' => [
                'SS' => ['main_parameter' => 'TLR', 'raw_score' => 14.75, 'clamped_score' => 14.75, 'max_marks' => 20.0, 'main_weight' => 0.30, 'weighted_score' => 4.43],
                'FSR' => ['main_parameter' => 'TLR', 'raw_score' => 26.99, 'clamped_score' => 26.99, 'max_marks' => 30.0, 'main_weight' => 0.30, 'weighted_score' => 8.10],
            ],
        ];

        $pdfBytes = $this->pdfService->generatePdf($data);

        $this->assertStringStartsWith('%PDF-1.4', $pdfBytes);
        $this->assertStringContainsString('%%EOF', $pdfBytes);
        $this->assertStringContainsString('/Catalog', $pdfBytes);
        $this->assertStringContainsString('/Pages', $pdfBytes);
        $this->assertGreaterThan(500, strlen($pdfBytes));
    }
}
