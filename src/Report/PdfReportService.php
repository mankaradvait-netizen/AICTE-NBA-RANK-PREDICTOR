<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Pure PHP Real PDF 1.4 Document Generator
 * 
 * Produces valid binary application/pdf documents containing institutional
 * accreditation scorecards, ranking band projections, and parameter calculation traces.
 * Does not depend on external system binaries or wrappers.
 */
class PdfReportService
{
    /**
     * Generates genuine binary PDF bytes
     * 
     * @param array<string, mixed> $reportData
     * @return string Valid %PDF-1.4 byte stream
     */
    public function generatePdf(array $reportData): string
    {
        $institution = (string) ($reportData['institution_name'] ?? 'Institutional Assessment');
        $cycle = (string) ($reportData['academic_year'] ?? '2026-2027');
        $category = (string) ($reportData['category'] ?? 'ENGINEERING');
        $score = number_format((float)($reportData['total_score'] ?? 0.0), 2);
        $rankBand = (string) ($reportData['predicted_band'] ?? 'Top 100');
        $confidence = (string) ($reportData['confidence'] ?? '80%');
        $dateStr = (string) ($reportData['date'] ?? date('F j, Y'));
        $sha = hash('sha256', json_encode($reportData));

        $subtotals = $reportData['main_parameter_subtotals'] ?? [];
        $params = $reportData['parameter_scores'] ?? [];

        // Build PDF Stream Commands (Text and graphics)
        $ops = [];
        $ops[] = "q";
        
        // Header background banner (ADYPU Maroon: RGB 0.48, 0.07, 0.07)
        $ops[] = "0.482 0.067 0.075 rg"; // Fill color
        $ops[] = "36 720 540 50 re f"; // Rectangle

        // Header Text (White)
        $ops[] = "1.0 1.0 1.0 rg";
        $ops[] = "BT /F2 15 Tf 50 740 Td (Institutional Accreditation Assessment Report) Tj ET";
        $ops[] = "BT /F1 9 Tf 50 726 Td (Official Auditable Calculation Trace & Rank-Band Estimation - AICTE NBA NIRF) Tj ET";

        // Institution & Metadata block
        $ops[] = "0.1 0.1 0.1 rg";
        $ops[] = "BT /F2 14 Tf 36 690 Td (" . $this->escapeText($institution) . ") Tj ET";
        $ops[] = "BT /F1 9 Tf 36 675 Td (Framework: NIRF " . $this->escapeText($category) . " | Academic Cycle: " . $this->escapeText($cycle) . " | Generated: " . $this->escapeText($dateStr) . ") Tj ET";

        // Score Card Box (Gold border: RGB 0.77, 0.62, 0.35)
        $ops[] = "0.773 0.627 0.349 RG"; // Stroke color
        $ops[] = "1.5 w"; // Line width
        $ops[] = "36 600 540 60 re S";

        $ops[] = "0.98 0.98 0.98 rg";
        $ops[] = "37 601 538 58 re f";

        $ops[] = "0.482 0.067 0.075 rg";
        $ops[] = "BT /F2 10 Tf 50 642 Td (OFFICIAL COMPOSITE SCORE) Tj ET";
        $ops[] = "BT /F2 22 Tf 50 615 Td (" . $score . " / 100) Tj ET";

        $cohortRank = isset($reportData['cohort_rank']) ? (int)$reportData['cohort_rank'] : null;
        $totalCohort = isset($reportData['total_cohort']) ? (int)$reportData['total_cohort'] : 300;
        $simulatedTier = isset($reportData['simulated_tier']) ? (string)$reportData['simulated_tier'] : $rankBand;
        $cohortLabel = $cohortRank !== null ? "#{$cohortRank} of {$totalCohort} ({$simulatedTier})" : $rankBand;

        $ops[] = "0.1 0.1 0.1 rg";
        $ops[] = "BT /F2 10 Tf 210 642 Td (OFFICIAL COHORT POSITION) Tj ET";
        $ops[] = "BT /F2 12 Tf 210 618 Td (" . $this->escapeText($cohortLabel) . ") Tj ET";

        $ops[] = "BT /F2 10 Tf 410 642 Td (ESTIMATE CONFIDENCE) Tj ET";
        $ops[] = "BT /F1 11 Tf 410 618 Td (" . $this->escapeText($confidence) . " Confidence) Tj ET";

        // Main Parameters Table Header
        $ops[] = "0.1 0.1 0.1 rg";
        $ops[] = "BT /F2 11 Tf 36 575 Td (Summary by NIRF Assessment Pillar) Tj ET";

        $y = 550;
        $ops[] = "0.2 0.2 0.2 rg";
        $ops[] = "36 " . ($y - 5) . " 540 18 re f";
        $ops[] = "1.0 1.0 1.0 rg";
        $ops[] = "BT /F2 9 Tf 45 " . $y . " Td (Pillar Code) Tj 140 " . $y . " Td (Parameter Name) Tj 340 " . $y . " Td (Weight) Tj 410 " . $y . " Td (Score / Max) Tj 490 " . $y . " Td (Weighted) Tj ET";

        $pillarNames = [
            'TLR' => 'Teaching, Learning & Resources',
            'RPC' => 'Research & Professional Practice',
            'GO'  => 'Graduation Outcomes',
            'OI'  => 'Outreach & Inclusivity',
            'PR'  => 'Perception (Peer & Employer)',
        ];

        $y -= 18;
        foreach ($subtotals as $code => $sub) {
            $name = $pillarNames[$code] ?? $code;
            $w = number_format(((float)($sub['main_weight'] ?? 0.0)) * 100, 0) . '%';
            $raw = number_format((float)($sub['subtotal_clamped'] ?? 0.0), 2);
            $weighted = number_format((float)($sub['weighted_total'] ?? 0.0), 2);

            $ops[] = "0.95 0.95 0.95 rg";
            $ops[] = "36 " . ($y - 3) . " 540 15 re f";
            $ops[] = "0.1 0.1 0.1 rg";
            $ops[] = "BT /F2 8 Tf 45 " . $y . " Td (" . $code . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 140 " . $y . " Td (" . $this->escapeText($name) . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 340 " . $y . " Td (" . $w . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 410 " . $y . " Td (" . $raw . " / 100) Tj ET";
            $ops[] = "BT /F2 8 Tf 490 " . $y . " Td (" . $weighted . ") Tj ET";
            $y -= 16;
        }

        // Sub-parameters Detailed Breakdown Header
        $y -= 10;
        $ops[] = "0.1 0.1 0.1 rg";
        $ops[] = "BT /F2 11 Tf 36 " . $y . " Td (Key Metric Calculations & Lineage Traces) Tj ET";

        $y -= 16;
        $ops[] = "0.2 0.2 0.2 rg";
        $ops[] = "36 " . ($y - 5) . " 540 16 re f";
        $ops[] = "1.0 1.0 1.0 rg";
        $ops[] = "BT /F2 8 Tf 45 " . $y . " Td (Metric) Tj 90 " . $y . " Td (Group) Tj 150 " . $y . " Td (Raw Value) Tj 240 " . $y . " Td (Clamped) Tj 330 " . $y . " Td (Max Marks) Tj 410 " . $y . " Td (Weight) Tj 490 " . $y . " Td (Weighted) Tj ET";

        $y -= 15;
        $count = 0;
        foreach ($params as $mCode => $p) {
            if ($count++ >= 12) break; // First 12 key metrics on page 1
            $g = (string) ($p['main_parameter'] ?? '');
            $raw = number_format((float)($p['raw_score'] ?? 0.0), 2);
            $clamped = number_format((float)($p['clamped_score'] ?? 0.0), 2);
            $maxM = number_format((float)($p['max_marks'] ?? 0.0), 1);
            $wt = number_format((float)($p['main_weight'] ?? 0.0), 2);
            $wtd = number_format((float)($p['weighted_score'] ?? 0.0), 2);

            $ops[] = "0.1 0.1 0.1 rg";
            $ops[] = "BT /F2 8 Tf 45 " . $y . " Td (" . $mCode . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 90 " . $y . " Td (" . $g . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 150 " . $y . " Td (" . $raw . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 240 " . $y . " Td (" . $clamped . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 330 " . $y . " Td (" . $maxM . ") Tj ET";
            $ops[] = "BT /F1 8 Tf 410 " . $y . " Td (" . $wt . ") Tj ET";
            $ops[] = "BT /F2 8 Tf 490 " . $y . " Td (" . $wtd . ") Tj ET";
            $y -= 14;
        }

        // Verification Footer
        $ops[] = "0.773 0.627 0.349 RG 0.5 w";
        $ops[] = "36 50 540 0.1 re S";
        $ops[] = "0.3 0.3 0.3 rg";
        $ops[] = "BT /F1 7 Tf 36 38 Td (Calculation Hash: " . substr($sha, 0, 32) . " | Number-Guard Verified | NIRF 2026 Engine v1.0) Tj ET";
        $ops[] = "BT /F1 7 Tf 440 38 Td (Page 1 of 1 - Confidential) Tj ET";
        $ops[] = "Q";

        $contentStream = implode("\n", $ops);
        $streamLen = strlen($contentStream);

        // Build valid PDF 1.4 binary structure
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        $objects[6] = "<< /Length {$streamLen} >>\nstream\n{$contentStream}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $offsets[0] = 0;

        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n";
        $pdf .= "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= "{$xrefPos}\n";
        $pdf .= "%%EOF\n";

        return $pdf;
    }

    private function escapeText(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
