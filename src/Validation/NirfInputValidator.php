<?php

declare(strict_types=1);

namespace App\Validation;

use App\Config\DatabaseManager;
use App\Formula\SafeExpressionEvaluator;
use PDO;
use RuntimeException;
use InvalidArgumentException;

class NirfInputValidator
{
    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly SafeExpressionEvaluator $evaluator
    ) {}

    /**
     * Data-driven validation against active input definitions and cross-field validation rules
     * @return array{
     *   valid: bool,
     *   errors: array<string>,
     *   warnings: array<string>,
     *   validated_inputs: array<string, float>,
     *   derived_chain: array<string, float>,
     *   formula_set_id: int,
     *   formula_version: int
     * }
     */
    public function validate(array $submittedInputs, string $category, string $academicYear): array
    {
        $pdo = $this->dbManager->getFormulaReader();

        // 1. Fetch active formula set
        $stmt = $pdo->prepare("
            SELECT id, version FROM formula_sets 
            WHERE category = :cat AND academic_year = :year AND status = 'ACTIVE'
            LIMIT 1
        ");
        $stmt->execute([':cat' => $category, ':year' => $academicYear]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$set) {
            return [
                'valid' => false,
                'errors' => ["No ACTIVE formula set found for category '{$category}' and academic year '{$academicYear}'."],
                'warnings' => [],
                'validated_inputs' => [],
                'derived_chain' => [],
                'formula_set_id' => 0,
                'formula_version' => 0,
            ];
        }

        $setId = (int) $set['id'];
        $setVersion = (int) $set['version'];

        // 2. Fetch input definitions
        $inStmt = $pdo->prepare("SELECT * FROM input_definitions WHERE formula_set_id = :id");
        $inStmt->execute([':id' => $setId]);
        $definitions = [];
        while ($row = $inStmt->fetch(PDO::FETCH_ASSOC)) {
            $definitions[(string)$row['input_code']] = $row;
        }

        $errors = [];
        $warnings = [];
        $validated = [];

        // Bidirectional alias mapping between official NIRF PDF symbols and schema codes
        $aliases = [
            'Np' => 'NP_pct',
            'NP_pct' => 'Np',
            'Nhs' => 'NHS_pct',
            'NHS_pct' => 'Nhs',
            'Ng' => 'NG_pct',
            'NG_pct' => 'Ng',
            'MS' => 'GMS_val',
            'GMS_val' => 'MS',
            'Nphd' => 'NPHD',
            'NPHD' => 'Nphd',
            'Sos' => 'N_state_pct',
            'N_state_pct' => 'Sos',
            'Soc' => 'N_country_pct',
            'N_country_pct' => 'Soc',
            'Nws' => 'SW_pct',
            'SW_pct' => 'Nws',
            'Nwf' => 'FW_pct',
            'FW_pct' => 'Nwf',
            'Nesc' => 'ESC_pct',
            'ESC_pct' => 'Nesc',
            'PCS' => 'PCS_score',
            'PCS_score' => 'PCS',
            'PR' => 'PR_survey_val',
            'PR_SCORE' => 'PR_survey_val',
            'PR_survey_val' => 'PR',
            'TOP25P' => 'TOP25',
            'TOP25' => 'TOP25P',
            'F_phd' => 'F_PHD',
            'Fphd' => 'F_PHD',
        ];

        // Normalize input keys using declared definitions and aliases
        $normalizedInputs = [];
        foreach ($submittedInputs as $key => $val) {
            $mappedKey = $key;
            if (!isset($definitions[$key]) && isset($aliases[$key]) && isset($definitions[$aliases[$key]])) {
                $mappedKey = $aliases[$key];
            }
            $normalizedInputs[$mappedKey] = $val;
        }

        // 3. Reject unknown submitted keys (Mass Assignment Protection)
        foreach ($normalizedInputs as $key => $val) {
            if (!isset($definitions[$key])) {
                $errors[] = "Unknown metric parameter '{$key}' submitted. Only declared input codes are permitted.";
            }
        }

        // 4. Validate types, required status, and ranges for each declared input
        foreach ($definitions as $code => $def) {
            $hasKey = array_key_exists($code, $normalizedInputs);
            $rawVal = $hasKey ? $normalizedInputs[$code] : null;

            if ($def['required'] && ($rawVal === null || $rawVal === '')) {
                $errors[] = "Missing required input metric '{$code}' ({$def['label']}).";
                continue;
            }

            if ($rawVal === null && !$def['required']) {
                $validated[$code] = 0.0;
                continue;
            }

            if (!is_numeric($rawVal)) {
                $errors[] = "Metric '{$code}' must be a numeric value.";
                continue;
            }

            $numVal = (float) $rawVal;

            if (is_nan($numVal) || is_infinite($numVal)) {
                $errors[] = "Metric '{$code}' contains an invalid NaN or INF value.";
                continue;
            }

            $dataType = (string) $def['data_type'];
            if ($dataType === 'int' && floor($numVal) != $numVal) {
                $errors[] = "Metric '{$code}' ({$def['label']}) must be an integer, received float ({$numVal}).";
            }

            $min = (float) $def['min_value'];
            $max = (float) $def['max_value'];

            if ($numVal < $min || $numVal > $max) {
                $errors[] = "Metric '{$code}' ({$numVal}) is out of allowed range [{$min}, {$max}].";
            }

            $validated[$code] = $numVal;
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'validated_inputs' => [],
                'derived_chain' => [],
                'formula_set_id' => $setId,
                'formula_version' => $setVersion,
            ];
        }

        // 5. Evaluate cross-field validation rules
        $vrStmt = $pdo->prepare("SELECT * FROM validation_rules WHERE formula_set_id = :id ORDER BY id ASC");
        $vrStmt->execute([':id' => $setId]);
        while ($vr = $vrStmt->fetch(PDO::FETCH_ASSOC)) {
            try {
                $passed = $this->evaluator->evaluateBoolean((string)$vr['expression'], $validated);
                if (!$passed) {
                    $sev = (string) $vr['severity'];
                    if ($sev === 'ERROR') {
                        $errors[] = "Validation Failure ({$vr['rule_id']}): {$vr['error_message']}";
                    } else {
                        $warnings[] = "Validation Warning ({$vr['rule_id']}): {$vr['error_message']}";
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Rule execution error in '{$vr['rule_id']}': {$e->getMessage()}";
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'validated_inputs' => [],
                'derived_chain' => [],
                'formula_set_id' => $setId,
                'formula_version' => $setVersion,
            ];
        }

        // 6. Compute derived variables in eval_order
        $dvStmt = $pdo->prepare("SELECT * FROM derived_variables WHERE formula_set_id = :id ORDER BY eval_order ASC");
        $dvStmt->execute([':id' => $setId]);
        $derivedChain = [];
        $context = $validated;

        while ($dv = $dvStmt->fetch(PDO::FETCH_ASSOC)) {
            $dCode = (string) $dv['derived_code'];
            try {
                $val = $this->evaluator->evaluate((string)$dv['expression'], $context);
                $derivedChain[$dCode] = $val;
                $context[$dCode] = $val;
            } catch (\Throwable $e) {
                $errors[] = "Error computing derived variable '{$dCode}': {$e->getMessage()}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'validated_inputs' => $validated,
            'derived_chain' => $derivedChain,
            'formula_set_id' => $setId,
            'formula_version' => $setVersion,
        ];
    }
}
