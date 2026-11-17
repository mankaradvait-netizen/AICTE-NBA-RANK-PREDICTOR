<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Formula\SafeExpressionEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SafeExpressionEvaluatorTest extends TestCase
{
    private SafeExpressionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new SafeExpressionEvaluator();
    }

    public function testSafeDivReturnsZeroOnDivisionByZero(): void
    {
        $result = $this->evaluator->evaluate('safe_div(100.0, 0.0)', []);
        $this->assertEquals(0.0, $result);
    }

    public function testSafeDivStandardDivision(): void
    {
        $result = $this->evaluator->evaluate('safe_div(50.0, 2.0)', []);
        $this->assertEquals(25.0, $result);
    }

    public function testClampFunction(): void
    {
        $this->assertEquals(10.0, $this->evaluator->evaluate('clamp(15.0, 0.0, 10.0)', []));
        $this->assertEquals(0.0, $this->evaluator->evaluate('clamp(-5.0, 0.0, 10.0)', []));
        $this->assertEquals(7.5, $this->evaluator->evaluate('clamp(7.5, 0.0, 10.0)', []));
    }

    public function testMinMaxAbsSqrtLog10(): void
    {
        $this->assertEquals(15.0, $this->evaluator->evaluate('min(15.0, 20.0, 35.0)', []));
        $this->assertEquals(35.0, $this->evaluator->evaluate('max(15.0, 20.0, 35.0)', []));
        $this->assertEquals(42.0, $this->evaluator->evaluate('abs(-42.0)', []));
        $this->assertEquals(5.0, $this->evaluator->evaluate('sqrt(25.0)', []));
        $this->assertEquals(0.0, $this->evaluator->evaluate('sqrt(-9.0)', [])); // Clamped to 0
        $this->assertEquals(2.0, $this->evaluator->evaluate('log10(100.0)', []));
    }

    public function testComplexFormulaEvaluation(): void
    {
        // SS formula evaluation
        $expr = '15.0 * min(1.0, safe_div(NE, NT)) + 5.0 * min(1.0, safe_div(NP, NP_MAX))';
        $context = [
            'NE' => 1000.0,
            'NT' => 1200.0,
            'NP' => 50.0,
            'NP_MAX' => 1000.0
        ];
        // 15 * (1000/1200) + 5 * (50/1000) = 15 * 0.833333 + 5 * 0.05 = 12.5 + 0.25 = 12.75
        $result = $this->evaluator->evaluate($expr, $context);
        $this->assertEqualsWithDelta(12.75, $result, 0.01);
    }

    public function testEvaluateBoolean(): void
    {
        $this->assertTrue($this->evaluator->evaluateBoolean('F_PHD <= F', ['F_PHD' => 20, 'F' => 60]));
        $this->assertFalse($this->evaluator->evaluateBoolean('F_PHD <= F', ['F_PHD' => 70, 'F' => 60]));
    }

    public function testDivisionByZeroGuardedDeterministically(): void
    {
        $result = $this->evaluator->evaluate('100.0 / 0.0', []);
        $this->assertEquals(0.0, $result);
    }

    public function testPrecisionMaintainedEightDecimals(): void
    {
        $result = $this->evaluator->evaluate('0.1 + 0.2', []);
        $this->assertEquals(0.3, $result);
    }
}
