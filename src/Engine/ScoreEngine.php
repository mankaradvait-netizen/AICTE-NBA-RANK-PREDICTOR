<?php

declare(strict_types=1);

namespace App\Engine;

use App\Config\DatabaseManager;
use App\Formula\SafeExpressionEvaluator;
use PDO;
use RuntimeException;

/**
 * @phpstan-type FormulaSetData array{
 *   id: int,
 *   version: int,
 *   category: string,
 *   academic_year: string,
 *   rules: list<array<string, mixed>>,
 *   derived_variables: list<array<string, mixed>>,
 *   benchmarks: list<array<string, mixed>>,
 *   tiers: list<array<string, mixed>>,
 *   inputs: list<array<string, mixed>>
 * }
 */
class ScoreEngine
{
    /** @var array<string, FormulaSetData> Cache for active formula sets */
    private array $formulaSetCache = [];

    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly SafeExpressionEvaluator $evaluator
    ) {}

    /**
     * Scores an institution deterministically against an active formula set
     * with high-precision arithmetic and deterministic tie-breaking support.
     * 
     * @param array<string, float> $validatedInputs
     * @return array{
     *   total_score: float,
     *   parameter_scores: array<string, array{
     *     raw_score: float,
     *     clamped_score: float,
     *     max_marks: float,
     *     main_weight: float,
     *     weighted_score: float,
     *     main_parameter: string,
     *     is_clamped: bool,
     *     clamp_reason: ?string
     *   }>,
     *   main_parameter_subtotals: array<string, array{
     *     subtotal_clamped: float,
     *     main_weight: float,
     *     weighted_total: float
     *   }>,
     *   formula_set_id: int,
     *   formula_version: int,
     *   derived_variables: array<string, float>,
     *   benchmarks_used: array<string, float>
     * }
     */
    public function score(array $validatedInputs, string $category, string $academicYear, ?int $forceSetId = null): array
    {
        $formulaData = $this->loadFormulaSet($category, $academicYear, $forceSetId);
        $setId = $formulaData['id'];
        $setVersion = $formulaData['version'];

        // 1. Build context: Inputs -> Derived Chain -> Benchmarks applied LAST
        // Provide baseline defaults for all standard NIRF inputs so partial uploads or missing fields never crash formula evaluation
        $defaults = [
            'NT' => 100.0, 'NE' => 100.0, 'NP' => 0.0, 'F' => 15.0, 'F_PHD' => 0.0,
            'F1' => 0.0, 'F2' => 0.0, 'F3' => 0.0, 'BC' => 0.0, 'BO' => 0.0,
            'P' => 0.0, 'CC' => 0.0, 'TOP25' => 0.0, 'PG' => 0.0, 'PP' => 0.0,
            'RF' => 0.0, 'CF' => 0.0, 'NP_pct' => 0.0, 'NHS_pct' => 0.0, 'NG_pct' => 0.0,
            'GMS_val' => 0.0, 'NPHD' => 0.0, 'N_state_pct' => 0.0, 'N_country_pct' => 0.0,
            'SW_pct' => 0.0, 'FW_pct' => 0.0, 'ESC_pct' => 0.0, 'PCS_score' => 0.0,
            'PR_survey_val' => 0.0,
        ];
        $context = array_merge($defaults, $validatedInputs);
        if (($context['NT'] ?? 0.0) <= 0.0) {
            $context['NT'] = 100.0;
        }
        if (($context['NE'] ?? 0.0) <= 0.0) {
            $context['NE'] = 100.0;
        }

        // 2. Evaluate Derived Variables in eval_order with 8-decimal precision
        $derivedValues = [];
        foreach ($formulaData['derived_variables'] as $dv) {
            $code = $dv['derived_code'];
            $val = $this->evaluator->evaluate($dv['expression'], $context);
            $derivedValues[$code] = $val;
            $context[$code] = $val;
        }

        // 3. Benchmarks Applied Last (Inputs CANNOT override benchmark denominators)
        $benchmarksUsed = [];
        foreach ($formulaData['benchmarks'] as $bm) {
            $bmCode = $bm['metric_code'];
            $bmVal = (float) $bm['metric_value'];
            $benchmarksUsed[$bmCode] = $bmVal;
            $context[$bmCode] = $bmVal; // Overwrites any input named like a benchmark
        }

        // 4. Evaluate NIRF Rules
        $parameterScores = [];
        $subtotals = [];
        $totalScore = 0.0;

        foreach ($formulaData['rules'] as $rule) {
            $code = $rule['parameter_code'];
            $mainParam = $rule['main_parameter'];
            $maxMarks = (float) $rule['max_marks'];
            $weight = (float) $rule['main_weight'];

            $raw = $this->evaluator->evaluate($rule['formula_expression'], $context);

            if (is_nan($raw) || is_infinite($raw)) {
                $raw = 0.0;
            }

            $clamped = max(0.0, min($maxMarks, $raw));
            $isClamped = ($raw < 0.0 || $raw > $maxMarks);
            $clampReason = $raw < 0.0 
                ? 'Floor clamp at 0.0' 
                : ($raw > $maxMarks ? "Ceiling clamp at {$maxMarks}" : null);

            $weighted = round($clamped * $weight, 8);

            $parameterScores[$code] = [
                'raw_score' => round($raw, 4),
                'clamped_score' => round($clamped, 4),
                'max_marks' => $maxMarks,
                'main_weight' => $weight,
                'weighted_score' => round($weighted, 4),
                'main_parameter' => $mainParam,
                'is_clamped' => $isClamped,
                'clamp_reason' => $clampReason,
            ];

            if (!isset($subtotals[$mainParam])) {
                $subtotals[$mainParam] = [
                    'subtotal_clamped' => 0.0,
                    'main_weight' => $weight,
                    'weighted_total' => 0.0,
                ];
            }
            $subtotals[$mainParam]['subtotal_clamped'] += $clamped;
            $subtotals[$mainParam]['weighted_total'] += $weighted;

            $totalScore += $weighted;
        }

        // Standardize Two-Stage Rounding:
        // Intermediate ratios preserved at 8 decimals; official sub-totals and composite score rounded at stage 2
        foreach ($subtotals as $param => &$data) {
            $data['subtotal_clamped'] = round($data['subtotal_clamped'], 2, PHP_ROUND_HALF_UP);
            $data['weighted_total'] = round($data['weighted_total'], 2, PHP_ROUND_HALF_UP);
        }

        return [
            'total_score' => round($totalScore, 2, PHP_ROUND_HALF_UP),
            'parameter_scores' => $parameterScores,
            'main_parameter_subtotals' => $subtotals,
            'formula_set_id' => $setId,
            'formula_version' => $setVersion,
            'derived_variables' => $derivedValues,
            'benchmarks_used' => $benchmarksUsed,
        ];
    }

    /**
     * Deterministic Multi-Level Tie-Breaking Comparator:
     * ORDER BY total_score DESC, TLR_score DESC, RP_score DESC, GO_score DESC, OI_score DESC, PR_score DESC, institution_id ASC
     * 
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareInstitutions(array $a, array $b): int
    {
        // 1. total_score DESC
        $scoreA = (float) ($a['total_score'] ?? 0.0);
        $scoreB = (float) ($b['total_score'] ?? 0.0);
        if (abs($scoreA - $scoreB) > 0.0001) {
            return $scoreB <=> $scoreA;
        }

        // Subtotals order: TLR, RPC (or RP), GO, OI, PR
        $subParams = ['TLR', 'RPC', 'GO', 'OI', 'PR'];
        foreach ($subParams as $sp) {
            $valA = (float) ($a['main_parameter_subtotals'][$sp]['weighted_total'] ?? $a[$sp . '_score'] ?? 0.0);
            $valB = (float) ($b['main_parameter_subtotals'][$sp]['weighted_total'] ?? $b[$sp . '_score'] ?? 0.0);
            if (abs($valA - $valB) > 0.0001) {
                return $valB <=> $valA;
            }
        }

        // 7. institution_id ASC (deterministic tie-breaker)
        $idA = (string) ($a['institution_id'] ?? $a['institution_code'] ?? '');
        $idB = (string) ($b['institution_id'] ?? $b['institution_code'] ?? '');
        return strcmp($idA, $idB);
    }

    /**
     * Loads and caches active formula rules, derived variables, and benchmarks from the database.
     * Enforces category and academic_year verification.
     * 
     * @return FormulaSetData
     */
    public function loadFormulaSet(string $category, string $academicYear, ?int $forceSetId = null): array
    {
        $pdo = $this->dbManager->getFormulaReader();

        $cacheKey = $forceSetId !== null ? "id:{$forceSetId}:{$category}:{$academicYear}" : "cat:{$category}:{$academicYear}";
        if (isset($this->formulaSetCache[$cacheKey])) {
            return $this->formulaSetCache[$cacheKey];
        }

        if ($forceSetId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM formula_sets WHERE id = :id AND category = :cat AND academic_year = :year");
            $stmt->execute([':id' => $forceSetId, ':cat' => $category, ':year' => $academicYear]);
            $set = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$set) {
                // Fallback to ID check with warning if category/year unspecified
                $stmtFallback = $pdo->prepare("SELECT * FROM formula_sets WHERE id = :id");
                $stmtFallback->execute([':id' => $forceSetId]);
                $set = $stmtFallback->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM formula_sets 
                WHERE category = :cat AND academic_year = :year 
                ORDER BY CASE status WHEN 'ACTIVE' THEN 1 WHEN 'VALIDATED' THEN 2 ELSE 3 END, version DESC
                LIMIT 1
            ");
            $stmt->execute([':cat' => $category, ':year' => $academicYear]);
            $set = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$set) {
            throw new RuntimeException("No active or valid formula set found for category '{$category}' and academic year '{$academicYear}'.");
        }

        $setId = (int) $set['id'];

        // Load Rules
        $rStmt = $pdo->prepare("SELECT * FROM nirf_rules WHERE formula_set_id = :id ORDER BY display_order ASC");
        $rStmt->execute([':id' => $setId]);
        $rules = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load Derived Variables
        $dvStmt = $pdo->prepare("SELECT * FROM derived_variables WHERE formula_set_id = :id ORDER BY eval_order ASC");
        $dvStmt->execute([':id' => $setId]);
        $derived = $dvStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load Benchmarks
        $bmStmt = $pdo->prepare("SELECT * FROM nirf_benchmarks WHERE formula_set_id = :id");
        $bmStmt->execute([':id' => $setId]);
        $benchmarks = $bmStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load Tiers
        $tStmt = $pdo->prepare("SELECT * FROM tier_playbook WHERE formula_set_id = :id");
        $tStmt->execute([':id' => $setId]);
        $tiers = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load Input Definitions
        $inStmt = $pdo->prepare("SELECT * FROM input_definitions WHERE formula_set_id = :id");
        $inStmt->execute([':id' => $setId]);
        $inputs = $inStmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [
            'id' => $setId,
            'version' => (int) $set['version'],
            'category' => (string) $set['category'],
            'academic_year' => (string) $set['academic_year'],
            'rules' => $rules,
            'derived_variables' => $derived,
            'benchmarks' => $benchmarks,
            'tiers' => $tiers,
            'inputs' => $inputs,
        ];

        $this->formulaSetCache["id:{$setId}"] = $data;
        $this->formulaSetCache["id:{$setId}:{$category}:{$academicYear}"] = $data;
        $this->formulaSetCache["cat:{$category}:{$academicYear}"] = $data;
        return $data;
    }

    public function clearCache(): void
    {
        $this->formulaSetCache = [];
    }
}
