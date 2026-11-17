<?php

declare(strict_types=1);

namespace App\Engine;

use App\Formula\SafeExpressionEvaluator;
use RuntimeException;
use InvalidArgumentException;

class GapOptimizer
{
    private const MAX_BISECTION_ITERATIONS = 40;

    public function __construct(
        private readonly ScoreEngine $scoreEngine,
        private readonly SafeExpressionEvaluator $evaluator
    ) {}

    /**
     * Executes deterministic marginal-gain gap analysis, statutory ceiling pruning,
     * and full-scorecard recalculation after each intervention.
     * 
     * @param array<string, float> $currentInputs
     * @return array{
     *   tier_code: string,
     *   current_band: string,
     *   target_band: string,
     *   current_score: float,
     *   target_score_min: float,
     *   projected_score: float,
     *   target_reached: bool,
     *   blocking_constraint: ?string,
     *   top_levers: array<int, array{
     *     parameter_code: string,
     *     primary_variable: string,
     *     current_value: float,
     *     required_delta: float,
     *     projected_value: float,
     *     current_parameter_score: float,
     *     projected_parameter_score: float,
     *     projected_weighted_gain: float,
     *     benchmark_metric: ?string,
     *     benchmark_anchor: ?string,
     *     anchor_value: ?float,
     *     gap_to_anchor: ?float,
     *     effort_cost_factor: float
     *   }>,
     *   action_plan_text: string
     * }
     */
    public function optimize(
        array $currentInputs,
        string $category,
        string $academicYear,
        string $currentBand,
        string $targetBand,
        ?int $forceSetId = null
    ): array {
        // 1. Validate Tier Transition
        $this->validateBands($currentBand, $targetBand);

        $formulaData = $this->scoreEngine->loadFormulaSet($category, $academicYear, $forceSetId);
        $currentScorecard = $this->scoreEngine->score($currentInputs, $category, $academicYear, $formulaData['id']);
        $currentScore = (float) $currentScorecard['total_score'];

        // 2. Resolve Active Tier Configuration
        $tierConfig = $this->resolveTierConfig($formulaData['tiers'], $currentBand, $targetBand);
        $targetScoreMin = (float) $tierConfig['target_score_min'];
        $benchmarkAnchor = (string) $tierConfig['benchmark_anchor']; // p_min, p_med, p_90
        $maxEffortAllowed = (float) $tierConfig['max_effort_cost_factor'];

        // 3. Prepare Input Bounds Lookup
        $inputBounds = [];
        foreach ($formulaData['inputs'] as $in) {
            $inputBounds[$in['input_code']] = [
                'min' => (float) $in['min_value'],
                'max' => (float) $in['max_value'],
            ];
        }

        // Prepare Benchmark Denominators Lookup
        $benchmarks = [];
        foreach ($formulaData['benchmarks'] as $bm) {
            $benchmarks[$bm['metric_code']] = $bm;
        }

        // 4. Marginal-Gain Sensitivity and Statutory Ceiling Pruning
        $candidateLevers = [];
        $rulesByCode = [];
        foreach ($formulaData['rules'] as $rule) {
            $rulesByCode[$rule['parameter_code']] = $rule;
            $code = $rule['parameter_code'];
            $pVar = $rule['primary_variable'];
            $maxMarks = (float) $rule['max_marks'];
            $weight = (float) $rule['main_weight'];
            $effort = (float) $rule['effort_cost_factor'];
            $step = (float) $rule['variable_step_size'];

            $currentParamScore = (float) ($currentScorecard['parameter_scores'][$code]['clamped_score'] ?? 0.0);

            // Statutory Ceiling Pruning: Immediately prune rules that have hit statutory maximum
            if ($currentParamScore >= ($maxMarks - 0.001)) {
                continue;
            }

            // Parameter-specific statutory ceilings:
            if ($code === 'FSR') {
                $curF = (float) ($currentInputs['F'] ?? 0.0);
                $curNT = (float) ($currentInputs['NT'] ?? 1.0);
                $curNP = (float) ($currentInputs['NP'] ?? 0.0);
                if (($curNT + $curNP) > 0 && ($curF / ($curNT + $curNP)) >= (1.0 / 15.0)) {
                    continue; // 1:15 norm already achieved
                }
            } elseif ($code === 'PCS' && (float)($currentInputs['PCS_score'] ?? 0.0) >= 20.0) {
                continue;
            } elseif ($code === 'GUE' && (float)($currentInputs['NG_pct'] ?? 0.0) >= 80.0) {
                continue;
            } elseif ($code === 'GPH' && ((float)($currentInputs['NP_pct'] ?? 0.0) + (float)($currentInputs['NHS_pct'] ?? 0.0)) >= 100.0) {
                continue;
            } elseif ($code === 'WD' && (float)($currentInputs['SW_pct'] ?? 0.0) >= 50.0 && (float)($currentInputs['FW_pct'] ?? 0.0) >= 20.0) {
                continue;
            }

            // In Tier 1, strictly filter out levers exceeding effort threshold
            if ($tierConfig['tier_code'] === 'TIER_1' && $effort > $maxEffortAllowed) {
                continue;
            }

            // Check if primary variable has room to expand
            $curVal = (float) ($currentInputs[$pVar] ?? 0.0);
            $maxVal = (float) ($inputBounds[$pVar]['max'] ?? 100000000.0);
            if ($curVal >= $maxVal) {
                continue;
            }

            // Perturb primary variable by 1 step
            $perturbedInputs = $currentInputs;
            $perturbedInputs[$pVar] = min($maxVal, $curVal + $step);

            // Recompute derived chain
            $perturbedContext = $this->buildContext($perturbedInputs, $formulaData['derived_variables'], $benchmarks);
            $newRaw = $this->evaluator->evaluate($rule['formula_expression'], $perturbedContext);
            $newClamped = max(0.0, min($maxMarks, $newRaw));

            $marginalGain = ($newClamped - $currentParamScore) * $weight;
            if ($marginalGain <= 0.00001) {
                continue;
            }

            // Priority index: w_i * (dS_i / dx_i) * (1 / Cost_i)
            $roiPriorityIndex = $marginalGain / max(0.1, $effort);

            $candidateLevers[] = [
                'parameter_code' => $code,
                'primary_variable' => $pVar,
                'current_value' => $curVal,
                'current_clamped' => $currentParamScore,
                'max_marks' => $maxMarks,
                'main_weight' => $weight,
                'effort_cost_factor' => $effort,
                'variable_step_size' => $step,
                'max_bound' => $maxVal,
                'roi_priority_index' => $roiPriorityIndex,
                'marginal_gain' => $marginalGain,
                'benchmark_metric' => $rule['benchmark_metric'] ?? null,
            ];
        }

        // Sort levers descending by marginal ROI priority index
        usort($candidateLevers, fn($a, $b) => $b['roi_priority_index'] <=> $a['roi_priority_index']);

        // 5. Allocation via Bisection Solver with FULL Recalculation
        $allocatedLevers = [];
        $simulatedInputs = $currentInputs;
        $projectedTotal = $currentScore;
        $blockingConstraint = null;

        foreach ($candidateLevers as $lever) {
            if ($projectedTotal >= $targetScoreMin) {
                break;
            }

            $code = $lever['parameter_code'];
            $pVar = $lever['primary_variable'];
            $ruleDef = $rulesByCode[$code];
            $currentVal = (float) $simulatedInputs[$pVar];
            $maxBound = (float) $lever['max_bound'];
            $maxMarks = (float) $lever['max_marks'];
            $weight = (float) $lever['main_weight'];
            $remainingNeededScore = $targetScoreMin - $projectedTotal;

            $initialScore = $this->evaluateParamScore($simulatedInputs, $ruleDef, $formulaData['derived_variables'], $benchmarks);

            // Bisection search on delta in [0, maxBound - currentVal]
            $deltaLow = 0.0;
            $deltaHigh = $maxBound - $currentVal;

            if ($deltaHigh <= 0.0) {
                $blockingConstraint = "Input '{$pVar}' is already at its upper bound of {$maxBound}.";
                continue;
            }

            $bestDelta = 0.0;
            $bestGain = 0.0;
            $bestClamped = $initialScore;

            for ($iter = 0; $iter < self::MAX_BISECTION_ITERATIONS; $iter++) {
                $mid = ($deltaLow + $deltaHigh) / 2.0;

                $testInputs = $simulatedInputs;
                $testInputs[$pVar] = $currentVal + $mid;
                $testClamped = $this->evaluateParamScore($testInputs, $ruleDef, $formulaData['derived_variables'], $benchmarks);
                $testGain = ($testClamped - $initialScore) * $weight;

                if ($testClamped >= $maxMarks || $testGain >= $remainingNeededScore) {
                    $bestDelta = $mid;
                    $bestGain = $testGain;
                    $bestClamped = $testClamped;
                    $deltaHigh = $mid; // Try finding an even smaller delta
                } else {
                    $deltaLow = $mid;
                }
            }

            // Fallback if target score not reachable in interval: take full deltaHigh
            if ($bestDelta === 0.0 && $deltaHigh > 0.0) {
                $testInputs = $simulatedInputs;
                $testInputs[$pVar] = $currentVal + $deltaHigh;
                $testClamped = $this->evaluateParamScore($testInputs, $ruleDef, $formulaData['derived_variables'], $benchmarks);
                $testGain = ($testClamped - $initialScore) * $weight;
                if ($testGain > 0.0001) {
                    $bestDelta = $deltaHigh;
                    $bestGain = $testGain;
                    $bestClamped = $testClamped;
                }
            }

            if ($bestDelta > 0.0) {
                $simulatedInputs[$pVar] = $currentVal + $bestDelta;

                // FULL RECALCULATION of the complete scorecard with ScoreEngine
                // This accounts for cross-parameter effects and non-linear interactions.
                $recalcScorecard = $this->scoreEngine->score(
                    $simulatedInputs,
                    $category,
                    $academicYear,
                    $formulaData['id']
                );
                $actualGain = $recalcScorecard['total_score'] - $projectedTotal;
                $projectedTotal = $recalcScorecard['total_score'];

                // Benchmark comparison
                $anchorVal = null;
                $gapToAnchor = null;
                if (!empty($lever['benchmark_metric']) && isset($benchmarks[$lever['benchmark_metric']])) {
                    $bmRow = $benchmarks[$lever['benchmark_metric']];
                    $anchorKey = in_array($benchmarkAnchor, ['p_min', 'p_med', 'p_90', 'p_max'], true) ? $benchmarkAnchor : 'p_med';
                    $anchorVal = (float) $bmRow[$anchorKey];
                    $gapToAnchor = max(0.0, $anchorVal - ($currentVal + $bestDelta));
                }

                $allocatedLevers[] = [
                    'parameter_code' => $code,
                    'primary_variable' => $pVar,
                    'current_value' => round($currentVal, 4),
                    'required_delta' => round($bestDelta, 4),
                    'projected_value' => round($currentVal + $bestDelta, 4),
                    'current_parameter_score' => round($initialScore, 4),
                    'projected_parameter_score' => round($bestClamped, 4),
                    'projected_weighted_gain' => round(max($bestGain, $actualGain), 4),
                    'benchmark_metric' => $lever['benchmark_metric'],
                    'benchmark_anchor' => $benchmarkAnchor,
                    'anchor_value' => $anchorVal !== null ? round($anchorVal, 4) : null,
                    'gap_to_anchor' => $gapToAnchor !== null ? round($gapToAnchor, 4) : null,
                    'effort_cost_factor' => $lever['effort_cost_factor'],
                ];
            }
        }

        $targetReached = ($projectedTotal >= $targetScoreMin);
        if (!$targetReached && $blockingConstraint === null) {
            $blockingConstraint = "Target score of {$targetScoreMin} cannot be fully satisfied within current input bounds and effort thresholds.";
        }

        // Return up to 5 top priority levers
        $topLevers = array_slice($allocatedLevers, 0, 5);

        return [
            'tier_code' => (string) $tierConfig['tier_code'],
            'current_band' => $currentBand,
            'target_band' => $targetBand,
            'current_score' => round($currentScore, 2),
            'target_score_min' => round($targetScoreMin, 2),
            'projected_score' => round($projectedTotal, 2),
            'target_reached' => $targetReached,
            'blocking_constraint' => $blockingConstraint,
            'top_levers' => $topLevers,
            'action_plan_text' => (string) $tierConfig['action_plan_text'],
        ];
    }

    private function validateBands(string $current, string $target): void
    {
        $validBands = ['UNRANKED', 'TOP_300', 'TOP_100', 'TOP_50', 'TOP_20'];
        if (!in_array($current, $validBands, true) || !in_array($target, $validBands, true)) {
            throw new InvalidArgumentException("Invalid current or target rank band.");
        }
        $cIdx = array_search($current, $validBands, true);
        $tIdx = array_search($target, $validBands, true);
        if ($tIdx <= $cIdx) {
            throw new InvalidArgumentException("Invalid rank progression: target band must represent an improvement over current band.");
        }
    }

    /**
     * @param list<array<string, mixed>> $tiers
     * @return array<string, mixed>
     */
    private function resolveTierConfig(array $tiers, string $current, string $target): array
    {
        foreach ($tiers as $t) {
            if ($t['current_band'] === $current && $t['target_band'] === $target) {
                return $t;
            }
        }
        foreach ($tiers as $t) {
            if ($t['current_band'] === $current) {
                return $t;
            }
        }
        return [
            'tier_code' => 'CUSTOM',
            'target_score_min' => 45.0,
            'target_score_max' => 55.0,
            'max_effort_cost_factor' => 2.0,
            'benchmark_anchor' => 'p_med',
            'action_plan_text' => 'Custom strategic improvement plan.',
        ];
    }

    /**
     * @param array<string, float> $inputs
     * @param array<string, mixed> $ruleDef
     * @param list<array<string, mixed>> $derived
     * @param array<string, array<string, mixed>> $benchmarks
     */
    private function evaluateParamScore(array $inputs, array $ruleDef, array $derived, array $benchmarks): float
    {
        $context = $this->buildContext($inputs, $derived, $benchmarks);
        $raw = $this->evaluator->evaluate($ruleDef['formula_expression'], $context);
        $maxMarks = (float) $ruleDef['max_marks'];
        return max(0.0, min($maxMarks, $raw));
    }

    /**
     * @param array<string, float> $inputs
     * @param list<array<string, mixed>> $derived
     * @param array<string, array<string, mixed>> $benchmarks
     * @return array<string, float>
     */
    private function buildContext(array $inputs, array $derived, array $benchmarks): array
    {
        $context = $inputs;
        foreach ($derived as $dv) {
            $code = $dv['derived_code'];
            $val = $this->evaluator->evaluate($dv['expression'], $context);
            $context[$code] = $val;
        }
        foreach ($benchmarks as $bm) {
            $bmCode = $bm['metric_code'];
            $context[$bmCode] = (float) $bm['metric_value'];
        }
        return $context;
    }
}
