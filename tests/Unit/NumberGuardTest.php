<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\NumberGuard;
use App\AI\AiStrategyService;
use PHPUnit\Framework\TestCase;

class NumberGuardTest extends TestCase
{
    private NumberGuard $guard;
    private AiStrategyService $aiService;

    protected function setUp(): void
    {
        $this->guard = new NumberGuard();
        $this->aiService = new AiStrategyService($this->guard);
    }

    public function testVerifiesAuthorizedNumbers(): void
    {
        $authorized = [
            'score' => 68.13,
            'tlr' => 26.99,
            'target' => 75.0,
        ];

        $text = "The score was 68.13, TLR was 26.99, and target is 75.0.";
        $res = $this->guard->verify($text, $authorized);

        $this->assertTrue($res['is_valid']);
        $this->assertEmpty($res['unverified_numbers']);
    }

    public function testFlagsHallucinatedNumbers(): void
    {
        $authorized = [
            'score' => 68.13,
        ];

        $text = "The score was 68.13 and the institution will reach rank 999 with 888.88 marks.";
        $res = $this->guard->verify($text, $authorized);

        $this->assertFalse($res['is_valid']);
        $this->assertContains('999', $res['unverified_numbers']);
        $this->assertContains('888.88', $res['unverified_numbers']);
    }

    public function testGenerateStrategyPlanWithNumberGuardCertification(): void
    {
        $scorecard = [
            'total_score' => 68.13,
            'parameter_scores' => [
                'SS' => ['clamped_score' => 14.75, 'raw_score' => 14.75, 'max_marks' => 20.0],
                'FSR' => ['clamped_score' => 26.99, 'raw_score' => 26.99, 'max_marks' => 30.0],
            ],
            'main_parameter_subtotals' => [
                'TLR' => ['weighted_total' => 26.4],
                'RPC' => ['weighted_total' => 14.8],
                'GO' => ['weighted_total' => 13.1],
                'OI' => ['weighted_total' => 7.4],
                'PR' => ['weighted_total' => 6.4],
            ]
        ];

        $inputs = ['NT' => 1200, 'NE' => 1000, 'F' => 60];
        $gap = [
            'top_levers' => [
                [
                    'parameter_code' => 'PU',
                    'primary_variable' => 'P',
                    'current_value' => 60.0,
                    'required_delta' => 15.0,
                    'projected_value' => 75.0,
                    'projected_weighted_gain' => 1.5,
                ]
            ]
        ];
        $rank = [
            'predicted_band' => 'Rank 51 - 100',
            'confidence_percentage' => '80%',
            'estimated_rank_min' => 51,
            'estimated_rank_max' => 100,
            'marginal_rank_sensitivity' => 6.2,
            'score_gap_to_next_tier' => 4.8,
        ];

        $plan = $this->aiService->generateStrategyPlan($scorecard, $inputs, $gap, $rank, 'Climber');

        $this->assertEquals([], $plan['number_guard_verification']['unverified_figures']);
        $this->assertEquals('NIRF Engineering', $plan['framework']);
        $this->assertEquals('Climber', $plan['strategy_mode']);
        $this->assertArrayHasKey('number_guard_verification', $plan);
        $this->assertEquals('NUMBER_GUARD_CERTIFIED', $plan['number_guard_verification']['status']);
    }
}
