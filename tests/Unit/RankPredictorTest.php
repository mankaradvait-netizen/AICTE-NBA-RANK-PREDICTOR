<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engine\RankPredictor;
use PHPUnit\Framework\TestCase;

class RankPredictorTest extends TestCase
{
    private RankPredictor $predictor;

    protected function setUp(): void
    {
        $this->predictor = new RankPredictor();
    }

    public function testPredictTopTier(): void
    {
        $res = $this->predictor->predict(78.5, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 1 - 10', $res['predicted_band']);
        $this->assertEquals(1, $res['estimated_rank_min']);
        $this->assertEquals(10, $res['estimated_rank_max']);
        $this->assertEquals('Elite', $res['recommended_mode']);
        $this->assertEquals(0.0, $res['score_gap_to_next_tier']);
    }

    public function testPredictClimberTier(): void
    {
        $res = $this->predictor->predict(54.2, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 51 - 100', $res['predicted_band']);
        $this->assertEquals('Climber', $res['recommended_mode']);
        $this->assertGreaterThan(0.0, $res['marginal_rank_sensitivity']);
        $this->assertEquals(59.0, $res['target_score_next_tier']);
        $this->assertEquals(4.8, $res['score_gap_to_next_tier']);

        // 10-Year Empirical Calibrated Metrics
        $this->assertGreaterThanOrEqual(51, $res['expected_rank']);
        $this->assertLessThanOrEqual(100, $res['expected_rank']);
        $this->assertStringStartsWith('Rank ', $res['calibrated_interval']);
        $this->assertGreaterThan(0.0, $res['national_percentile']);
        $this->assertLessThan(100.0, $res['national_percentile']);
        $this->assertStringContainsString('Nationally', $res['national_percentile_label']);
        $this->assertEquals(59.42, $res['inflation_adjusted_target']);
        $this->assertArrayHasKey('TLR_median', $res['national_benchmarks']);
        $this->assertArrayHasKey('fru_per_student_inr', $res['national_benchmarks']);
    }

    public function testPredictFoundationTier(): void
    {
        $res = $this->predictor->predict(34.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 201 - 300', $res['predicted_band']);
        $this->assertEquals('Foundation', $res['recommended_mode']);
        $this->assertEquals(37.0, $res['target_score_next_tier']);
        $this->assertEquals(3.0, $res['score_gap_to_next_tier']);
    }

    public function testPredictUnranked(): void
    {
        $res = $this->predictor->predict(25.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Band 300+ (Unranked)', $res['predicted_band']);
        $this->assertEquals('Foundation', $res['recommended_mode']);
    }
}
