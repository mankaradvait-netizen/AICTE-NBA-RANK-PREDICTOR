<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Number-Guard: Verification Gate for AI-Generated Accreditation Strategy Reports
 * 
 * Extracts every numerical figure, ratio, and percentage from AI-drafted narrative
 * and validates it against the authoritative, code-calculated scorecard and inputs.
 * Blocks hallucinations, unverified ranking promises, and mathematical discrepancies.
 */
class NumberGuard
{
    /**
     * Set of standard numbers permitted without strict engine match (e.g. years, ordinals, timeframes)
     */
    private const WHITELISTED_STANDARD_NUMBERS = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '12', '15', '20', '24', '25', '30', '35', '36', '40', '50', '80',
        '2021', '2022', '2023', '2024', '2025', '2026', '2027', '2028', '2030',
        '10%', '20%', '30%', '40%', '50%', '80%', '100', '100%'
    ];

    /**
     * Verifies that all numbers in the text exist in the authorized numerical pool
     * 
     * @param string $text AI generated narrative
     * @param array<string, float|int|string> $authorizedPool Dictionary of calculated values
     * @return array{
     *   is_valid: bool,
     *   total_numbers_found: int,
     *   unverified_numbers: array<string>,
     *   verified_count: int,
     *   sanitized_text: string
     * }
     */
    public function verify(string $text, array $authorizedPool): array
    {
        // Extract all allowed float/int values from authorized pool
        $allowedValues = [];
        foreach ($authorizedPool as $val) {
            if (is_numeric($val)) {
                $f = (float) $val;
                $allowedValues[] = (string) round($f, 2);
                $allowedValues[] = (string) round($f, 1);
                $allowedValues[] = (string) (int) round($f);
                $allowedValues[] = (string) round($f, 4);
                $allowedValues[] = number_format($f, 2, '.', '');
                $allowedValues[] = number_format($f, 0, '.', ',');
            }
        }

        // Find all numbers in text (e.g. 68.13, 26.99, 15%, 1,50,000)
        preg_match_all('/\b\d{1,3}(?:,\d{2,3})*(?:\.\d+)?%?\b/', $text, $matches);
        $found = $matches[0];

        $unverified = [];
        $verifiedCount = 0;

        foreach ($found as $numStr) {
            $cleanNum = rtrim(str_replace(',', '', $numStr), '%');

            if (in_array($numStr, self::WHITELISTED_STANDARD_NUMBERS, true) || in_array($cleanNum, self::WHITELISTED_STANDARD_NUMBERS, true)) {
                $verifiedCount++;
                continue;
            }

            // Check if cleanNum matches any authorized value
            $matched = false;
            foreach ($allowedValues as $av) {
                if ($av === $cleanNum || abs((float)$av - (float)$cleanNum) < 0.05) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $verifiedCount++;
            } else {
                $unverified[] = $numStr;
            }
        }

        $isValid = count($unverified) === 0;

        return [
            'is_valid' => $isValid,
            'total_numbers_found' => count($found),
            'unverified_numbers' => array_values(array_unique($unverified)),
            'verified_count' => $verifiedCount,
            'sanitized_text' => $text,
        ];
    }
}
