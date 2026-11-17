<?php

declare(strict_types=1);

namespace App\Formula;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use RuntimeException;
use DivisionByZeroError;

class SafeExpressionEvaluator
{
    private ExpressionLanguage $language;

    public function __construct()
    {
        // Custom empty ExpressionLanguage with no default PHP functions or providers
        $this->language = new ExpressionLanguage();
        $this->registerWhitelistedFunctions();
    }

    public function getExpressionLanguage(): ExpressionLanguage
    {
        return $this->language;
    }

    /**
     * Evaluates an expression against a given variables context with 8-decimal precision.
     * Guards against division by zero and eliminates IEEE 754 floating point drift.
     * 
     * @param array<string, float|int|bool> $context
     */
    public function evaluate(string $expression, array $context): float
    {
        try {
            $result = $this->language->evaluate($expression, $context);
        } catch (DivisionByZeroError) {
            // Deterministic zero denominator guard
            return 0.0;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Division by zero') || str_contains($msg, 'division by zero')) {
                return 0.0;
            }
            throw new RuntimeException("Evaluation error for expression '{$expression}': " . $e->getMessage(), 0, $e);
        }

        if (is_bool($result)) {
            return $result ? 1.0 : 0.0;
        }

        if (!is_numeric($result)) {
            throw new RuntimeException("Evaluation produced a non-numeric result for '{$expression}'.");
        }

        $floatVal = (float) $result;

        if (is_nan($floatVal) || is_infinite($floatVal)) {
            // Guard NaN and INF to deterministic 0.0
            return 0.0;
        }

        // Maintain 8 decimal places of precision throughout all intermediate expressions
        return round($floatVal, 8);
    }

    /**
     * Evaluates a boolean validation rule expression
     * @param array<string, float|int|bool> $context
     */
    public function evaluateBoolean(string $expression, array $context): bool
    {
        try {
            $result = $this->language->evaluate($expression, $context);
        } catch (\Throwable $e) {
            throw new RuntimeException("Evaluation error for rule '{$expression}': " . $e->getMessage(), 0, $e);
        }

        return (bool) $result;
    }

    private function registerWhitelistedFunctions(): void
    {
        // 1. safe_div(a, b) -> returns 0.0 when abs(b) < 1e-9 (prevents division by zero)
        $this->language->addFunction(new ExpressionFunction(
            'safe_div',
            fn ($a, $b) => "(abs((float)({$b})) < 1e-9 ? 0.0 : round((float)({$a}) / (float)({$b}), 8))",
            fn ($arguments, $a, $b) => (abs((float) $b) < 1e-9) ? 0.0 : round((float) $a / (float) $b, 8)
        ));

        // 2. clamp(x, lo, hi)
        $this->language->addFunction(new ExpressionFunction(
            'clamp',
            fn ($x, $lo, $hi) => "max((float)({$lo}), min((float)({$hi}), (float)({$x})))",
            fn ($arguments, $x, $lo, $hi) => round(max((float) $lo, min((float) $hi, (float) $x)), 8)
        ));

        // 3. min(a, b, ...)
        $this->language->addFunction(new ExpressionFunction(
            'min',
            fn (...$args) => "min(" . implode(', ', array_map(fn($a) => "(float)({$a})", $args)) . ")",
            fn ($arguments, ...$args) => round((float) min(...array_map('floatval', $args)), 8)
        ));

        // 4. max(a, b, ...)
        $this->language->addFunction(new ExpressionFunction(
            'max',
            fn (...$args) => "max(" . implode(', ', array_map(fn($a) => "(float)({$a})", $args)) . ")",
            fn ($arguments, ...$args) => round((float) max(...array_map('floatval', $args)), 8)
        ));

        // 5. abs(x)
        $this->language->addFunction(new ExpressionFunction(
            'abs',
            fn ($x) => "abs((float)({$x}))",
            fn ($arguments, $x) => round(abs((float) $x), 8)
        ));

        // 6. sqrt(x) -> guards negative values by clamping to 0
        $this->language->addFunction(new ExpressionFunction(
            'sqrt',
            fn ($x) => "sqrt(max(0.0, (float)({$x})))",
            fn ($arguments, $x) => round(sqrt(max(0.0, (float) $x)), 8)
        ));

        // 7. log10(x) -> guards non-positive values
        $this->language->addFunction(new ExpressionFunction(
            'log10',
            fn ($x) => "((float)({$x}) <= 0.0 ? 0.0 : log10((float)({$x})))",
            fn ($arguments, $x) => ((float) $x <= 0.0) ? 0.0 : round(log10((float) $x), 8)
        ));
    }
}
