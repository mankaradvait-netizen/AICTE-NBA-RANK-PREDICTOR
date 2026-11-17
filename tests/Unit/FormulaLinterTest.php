<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Formula\FormulaLinter;
use App\Formula\SafeExpressionEvaluator;
use PHPUnit\Framework\TestCase;

class FormulaLinterTest extends TestCase
{
    private FormulaLinter $linter;
    private array $allowedVars;

    protected function setUp(): void
    {
        $evaluator = new SafeExpressionEvaluator();
        $this->linter = new FormulaLinter($evaluator);
        $this->allowedVars = ['NT', 'NE', 'NP', 'NP_MAX', 'F', 'F_PHD', 'BC', 'BO', 'BO_MAX'];
    }

    public function testAcceptsValidFormula(): void
    {
        $expr = '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))';
        $result = $this->linter->lint($expr, $this->allowedVars);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testRejectsPhpConstant(): void
    {
        $expr = "constant('PHP_VERSION')";
        $result = $this->linter->lint($expr, $this->allowedVars);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('forbidden', strtolower($result['error'] ?? ''));
    }

    public function testRejectsStringLiterals(): void
    {
        $expr = "NE + 'malicious_string'";
        $result = $this->linter->lint($expr, $this->allowedVars);

        $this->assertFalse($result['valid']);
    }

    public function testRejectsUndeclaredVariables(): void
    {
        $expr = 'NE + UNKNOWN_VAR';
        $result = $this->linter->lint($expr, $this->allowedVars);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("Undeclared or unrecognized variable 'UNKNOWN_VAR'", $result['error'] ?? '');
    }

    public function testRejectsOverLengthExpression(): void
    {
        $longExpr = str_repeat('NE + ', 150) . 'NE'; // > 500 characters
        $result = $this->linter->lint($longExpr, $this->allowedVars);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum allowed length', $result['error'] ?? '');
    }

    public function testRejectsOverDeepAst(): void
    {
        // Construct deeply nested expression > 12 depth
        $deepExpr = '((((((((((((((NE + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1) + 1)';
        $result = $this->linter->lint($deepExpr, $this->allowedVars);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('AST depth exceeds limit', $result['error'] ?? '');
    }

    public function testRejectsDisallowedCharacters(): void
    {
        $expr = 'NE; system("ls")';
        $result = $this->linter->lint($expr, $this->allowedVars);

        $this->assertFalse($result['valid']);
    }
}
