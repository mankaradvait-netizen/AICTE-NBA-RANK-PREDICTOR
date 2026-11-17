<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Historical Data and Competitive Position Estimation
 * 
 * Estimates competitive ranking bands and prediction intervals from published
 * NIRF Engineering score distributions. Complies with the non-negotiable principle:
 * "Never present a single rank as a promise. Always report a band with uncertainty."
 */
class RankPredictor
{
    /**
     * Historical score-to-rank reference intervals for NIRF Engineering
     */
    private const HISTORICAL_TIERS = [
        ['min_score' => 75.0, 'max_score' => 100.0, 'band' => 'Rank 1 - 10',     'min_rank' => 1,   'max_rank' => 10,  'confidence' => '92%', 'confidence_level' => 'High',     'sensitivity' => 1.8, 'mode' => 'Elite'],
        ['min_score' => 67.0, 'max_score' => 74.99, 'band' => 'Rank 11 - 25',    'min_rank' => 11,  'max_rank' => 25,  'confidence' => '88%', 'confidence_level' => 'High',     'sensitivity' => 2.4, 'mode' => 'Elite'],
        ['min_score' => 59.0, 'max_score' => 66.99, 'band' => 'Rank 26 - 50',    'min_rank' => 26,  'max_rank' => 50,  'confidence' => '85%', 'confidence_level' => 'High',     'sensitivity' => 3.8, 'mode' => 'Elite'],
        ['min_score' => 50.0, 'max_score' => 58.99, 'band' => 'Rank 51 - 100',   'min_rank' => 51,  'max_rank' => 100, 'confidence' => '80%', 'confidence_level' => 'High',     'sensitivity' => 6.2, 'mode' => 'Climber'],
        ['min_score' => 43.0, 'max_score' => 49.99, 'band' => 'Rank 101 - 150',  'min_rank' => 101, 'max_rank' => 150, 'confidence' => '75%', 'confidence_level' => 'Medium',   'sensitivity' => 8.5, 'mode' => 'Climber'],
        ['min_score' => 37.0, 'max_score' => 42.99, 'band' => 'Rank 151 - 200',  'min_rank' => 151, 'max_rank' => 200, 'confidence' => '70%', 'confidence_level' => 'Medium',   'sensitivity' => 11.2, 'mode' => 'Foundation'],
        ['min_score' => 31.0, 'max_score' => 36.99, 'band' => 'Rank 201 - 300',  'min_rank' => 201, 'max_rank' => 300, 'confidence' => '65%', 'confidence_level' => 'Moderate', 'sensitivity' => 16.5, 'mode' => 'Foundation'],
        ['min_score' => 0.0,  'max_score' => 30.99, 'band' => 'Band 300+ (Unranked)', 'min_rank' => 301, 'max_rank' => 500, 'confidence' => '60%', 'confidence_level' => 'Moderate', 'sensitivity' => 20.0, 'mode' => 'Foundation'],
    ];

    /**
     * Estimates competitive band, uncertainty, sensitivity, and strategy alignment
     * 
     * @param float $totalScore Official calculated composite score
     * @param string $category Framework category (e.g. ENGINEERING)
     * @param string $academicYear Academic cycle (e.g. 2026-2027)
     * @return array{
     *   total_score: float,
     *   predicted_band: string,
     *   estimated_rank_min: int,
     *   estimated_rank_max: int,
     *   confidence_percentage: string,
     *   confidence_label: string,
     *   marginal_rank_sensitivity: float,
     *   recommended_mode: string,
     *   target_score_next_tier: ?float,
     *   score_gap_to_next_tier: float,
     *   methodology_note: string
     * }
     */
    public function predict(float $totalScore, string $category = 'ENGINEERING', string $academicYear = '2026-2027'): array
    {
        $clampedScore = max(0.0, min(100.0, $totalScore));

        $matchedTier = null;
        $nextTier = null;

        for ($i = 0; $i < count(self::HISTORICAL_TIERS); $i++) {
            $tier = self::HISTORICAL_TIERS[$i];
            if ($clampedScore >= $tier['min_score'] && $clampedScore <= $tier['max_score']) {
                $matchedTier = $tier;
                $nextTier = ($i > 0) ? self::HISTORICAL_TIERS[$i - 1] : null;
                break;
            }
        }

        if ($matchedTier === null) {
            $matchedTier = self::HISTORICAL_TIERS[count(self::HISTORICAL_TIERS) - 1];
        }

        $nextMinScore = $nextTier !== null ? (float)$nextTier['min_score'] : null;
        $scoreGap = $nextMinScore !== null ? round(max(0.0, $nextMinScore - $clampedScore), 2) : 0.0;

        return [
            'total_score' => round($clampedScore, 2),
            'predicted_band' => $matchedTier['band'],
            'estimated_rank_min' => (int) $matchedTier['min_rank'],
            'estimated_rank_max' => (int) $matchedTier['max_rank'],
            'confidence_percentage' => $matchedTier['confidence'],
            'confidence_interval' => $matchedTier['confidence'],
            'confidence_label' => $matchedTier['confidence_level'],
            'marginal_rank_sensitivity' => (float) $matchedTier['sensitivity'],
            'recommended_mode' => $matchedTier['mode'],
            'target_score_next_tier' => $nextMinScore,
            'score_gap_to_next_tier' => $scoreGap,
            'methodology_note' => 'Estimated competitive position based on historical NIRF Engineering cohorts (2021-2025). Rank is relative to peer performance; this estimate carries uncertainty and does not constitute a guaranteed official ranking.',
        ];
    }
}
