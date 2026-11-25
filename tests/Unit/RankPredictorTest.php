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

    public function testPredictRank26To50(): void
    {
        $res = $this->predictor->predict(55.5, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 26 - 50', $res['predicted_band']);
        $this->assertEquals('Elite', $res['recommended_mode']);
        $this->assertEquals(26, $res['estimated_rank_min']);
        $this->assertEquals(50, $res['estimated_rank_max']);
    }

    public function testPredictClimberTier(): void
    {
        $res = $this->predictor->predict(48.5, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 51 - 100', $res['predicted_band']);
        $this->assertEquals('Climber', $res['recommended_mode']);
        $this->assertGreaterThan(0.0, $res['marginal_rank_sensitivity']);
        $this->assertEquals(54.16, $res['target_score_next_tier']);
        $this->assertEquals(5.66, $res['score_gap_to_next_tier']);

        // 10-Year Empirical Calibrated Metrics
        $this->assertGreaterThanOrEqual(51, $res['expected_rank']);
        $this->assertLessThanOrEqual(100, $res['expected_rank']);
        $this->assertStringStartsWith('Rank ', $res['calibrated_interval']);
        $this->assertGreaterThan(0.0, $res['national_percentile']);
        $this->assertLessThan(100.0, $res['national_percentile']);
        $this->assertStringContainsString('Nationally', $res['national_percentile_label']);
        $this->assertEquals(54.58, $res['inflation_adjusted_target']);
        $this->assertArrayHasKey('TLR_median', $res['national_benchmarks']);
        $this->assertArrayHasKey('fru_per_student_inr', $res['national_benchmarks']);
    }

    public function testPredictRank101To150(): void
    {
        $res = $this->predictor->predict(41.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 101 - 150', $res['predicted_band']);
        $this->assertEquals('Climber', $res['recommended_mode']);
        $this->assertEquals(43.95, $res['target_score_next_tier']);
        $this->assertEquals(2.95, $res['score_gap_to_next_tier']);
    }

    public function testPredictFoundationTier(): void
    {
        $res = $this->predictor->predict(34.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 151 - 200', $res['predicted_band']);
        $this->assertEquals('Foundation', $res['recommended_mode']);
        $this->assertEquals(38.00, $res['target_score_next_tier']);
        $this->assertEquals(4.0, $res['score_gap_to_next_tier']);
    }

    public function testPredictRank201To300(): void
    {
        $res = $this->predictor->predict(28.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Rank 201 - 300', $res['predicted_band']);
        $this->assertEquals('Foundation', $res['recommended_mode']);
        $this->assertEquals(33.00, $res['target_score_next_tier']);
        $this->assertEquals(5.0, $res['score_gap_to_next_tier']);
    }

    public function testPredictUnranked(): void
    {
        $res = $this->predictor->predict(22.0, 'ENGINEERING', '2026-2027');
        $this->assertEquals('Band 300+ (Unranked)', $res['predicted_band']);
        $this->assertEquals('Foundation', $res['recommended_mode']);
        $this->assertEquals(25.00, $res['target_score_next_tier']);
        $this->assertEquals(3.0, $res['score_gap_to_next_tier']);
    }

    public function testCompareWithCohortAll300(): void
    {
        // 1. Institution in Top 100
        $c1 = $this->predictor->compareWithCohort(47.89, 'COEP Technological University');
        $this->assertSame(300, $c1['total_cohort_count']);
        $this->assertEquals(77, $c1['simulated_cohort_rank']);
        $this->assertSame('Top 100', $c1['simulated_tier']);
        $this->assertNotEmpty($c1['peers_above']);
        $this->assertNotEmpty($c1['peers_below']);
        $this->assertEquals(0.0, $c1['tier_cutoffs']['tier_top_100']['gap']);

        // 2. Institution in 101 - 150 Band
        $c2 = $this->predictor->compareWithCohort(43.10, 'Sandip Institute of Technology & Research Centre (SITRC)');
        $this->assertSame(300, $c2['total_cohort_count']);
        $this->assertEquals(108, $c2['simulated_cohort_rank']);
        $this->assertSame('Rank 101 - 150', $c2['simulated_tier']);
        $this->assertEqualsWithDelta(0.85, $c2['tier_cutoffs']['tier_top_100']['gap'], 0.05);

        // 3. Institution in 201 - 300 Band
        $c3 = $this->predictor->compareWithCohort(29.80, 'Sample Tier-3 College');
        $this->assertSame(300, $c3['total_cohort_count']);
        $this->assertEquals(241, $c3['simulated_cohort_rank']);
        $this->assertSame('Rank 201 - 300', $c3['simulated_tier']);
        $this->assertEqualsWithDelta(14.15, $c3['tier_cutoffs']['tier_top_100']['gap'], 0.05);
        $this->assertEqualsWithDelta(3.20, $c3['tier_cutoffs']['tier_151_200']['gap'], 0.05);

        // 4. Verify Full Cohort Integrity
        $this->assertCount(300, $c3['full_cohort']);
        $this->assertEquals(1, $c3['full_cohort'][0]['rank']);
        $this->assertEquals(300, $c3['full_cohort'][299]['rank']);
        $this->assertGreaterThan($c3['full_cohort'][299]['score'], $c3['full_cohort'][0]['score']);
    }
}
