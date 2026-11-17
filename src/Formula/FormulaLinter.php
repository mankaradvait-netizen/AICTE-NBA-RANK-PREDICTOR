<?php

declare(strict_types=1);

namespace App\Formula;

use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\ArrayNode;
use RuntimeException;

class FormulaLinter
{
    private const MAX_LENGTH = 500;
    private const MAX_DEPTH = 12;

    private const ALLOWED_FUNCTIONS = [
        'min', 'max', 'abs', 'sqrt', 'log10', 'clamp', 'safe_div'
    ];

    private const ALLOWED_OPERATORS = [
        '+', '-', '*', '/', '%', '==', '===', '!=', '!==', '<', '<=', '>', '>=', '&&', '||', 'and', 'or', '!', 'not'
    ];

    public function __construct(
        private readonly SafeExpressionEvaluator $evaluator
    ) {}

    /**
     * Validates an expression against strict AST, syntax, character, and variable rules
     * @param array<string> $allowedVariables
     * @return array{valid: bool, error: ?string}
     */
    public function lint(string $expression, array $allowedVariables): array
    {
        $trimmed = trim($expression);

        // 1. Length check
        if (strlen($trimmed) === 0) {
            return ['valid' => false, 'error' => 'Formula expression cannot be empty.'];
        }
        if (strlen($trimmed) > self::MAX_LENGTH) {
            return ['valid' => false, 'error' => "Expression exceeds maximum allowed length of " . self::MAX_LENGTH . " characters."];
        }

        // 2. Character whitelist filter: only alphanumeric, basic math/logic operators, and delimiters
        if (!preg_match('/^[0-9a-zA-Z_.+\\-*\/()\s,<>=!&|?:]+$/', $trimmed)) {
            return ['valid' => false, 'error' => "Expression contains forbidden characters. Only numbers, variable names, and arithmetic/logical operators are permitted."];
        }

        // 3. Forbid PHP constructs, strings, keywords
        $forbiddenKeywords = ['constant', 'enum', 'php', 'eval', 'system', 'exec', 'include', 'require', '__proto__', 'class'];
        foreach ($forbiddenKeywords as $badWord) {
            if (preg_match('/\b' . preg_quote($badWord, '/') . '\b/i', $trimmed)) {
                return ['valid' => false, 'error' => "Expression contains forbidden identifier '{$badWord}'."];
            }
        }

        // 4. Parse into AST
        try {
            $parsed = $this->evaluator->getExpressionLanguage()->parse($trimmed, $allowedVariables);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (preg_match('/Variable "([^"]+)" is not valid/i', $msg, $m)) {
                return ['valid' => false, 'error' => "Undeclared or unrecognized variable '{$m[1]}' in formula."];
            }
            return ['valid' => false, 'error' => "Syntax error in expression: " . $msg];
        }

        // 5. AST Traversal: depth and node safety check
        $rootNode = $parsed->getNodes();
        $astCheck = $this->walkNode($rootNode, 1, $allowedVariables);
        if (!$astCheck['valid']) {
            return $astCheck;
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Recursive AST node walker
     * @param array<string> $allowedVariables
     * @return array{valid: bool, error: ?string}
     */
    private function walkNode(Node $node, int $depth, array $allowedVariables): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['valid' => false, 'error' => "Expression AST depth exceeds limit of " . self::MAX_DEPTH . "."];
        }

        // Strictly reject attribute/method access, array constructs, etc.
        if ($node instanceof GetAttrNode) {
            return ['valid' => false, 'error' => "Object/method access is strictly forbidden in NIRF formulas."];
        }
        if ($node instanceof ArrayNode) {
            return ['valid' => false, 'error' => "Array literals are strictly forbidden in NIRF formulas."];
        }

        // Check node types
        if ($node instanceof ConstantNode) {
            $val = $node->attributes['value'] ?? null;
            // Only numeric or boolean constants are allowed (no string literals)
            if (!is_numeric($val) && !is_bool($val)) {
                return ['valid' => false, 'error' => "String or non-numeric constants are forbidden in formulas."];
            }
        } elseif ($node instanceof NameNode) {
            $varName = (string) ($node->attributes['name'] ?? '');
            if (!in_array($varName, $allowedVariables, true)) {
                return ['valid' => false, 'error' => "Undeclared or unrecognized variable '{$varName}' in formula."];
            }
        } elseif ($node instanceof FunctionNode) {
            $funcName = (string) ($node->attributes['name'] ?? '');
            if (!in_array($funcName, self::ALLOWED_FUNCTIONS, true)) {
                return ['valid' => false, 'error' => "Disallowed function call '{$funcName}'. Only whitelisted functions are permitted."];
            }
        } elseif ($node instanceof BinaryNode) {
            $operator = (string) ($node->attributes['operator'] ?? '');
            if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                return ['valid' => false, 'error' => "Disallowed binary operator '{$operator}'."];
            }
        } elseif ($node instanceof UnaryNode) {
            $operator = (string) ($node->attributes['operator'] ?? '');
            if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                return ['valid' => false, 'error' => "Disallowed unary operator '{$operator}'."];
            }
        } elseif ($node instanceof ConditionalNode) {
            // Ternary allowed
        } elseif (get_class($node) === Node::class) {
            // Base Node class is used by Symfony ExpressionLanguage as an arguments container
        } else {
            $class = get_class($node);
            return ['valid' => false, 'error' => "Disallowed AST node type: {$class}."];
        }

        // Recursively inspect child nodes
        $children = $node->nodes;
        foreach ($children as $child) {
            if ($child instanceof Node) {
                $res = $this->walkNode($child, $depth + 1, $allowedVariables);
                if (!$res['valid']) {
                    return $res;
                }
            }
        }

        return ['valid' => true, 'error' => null];
    }
}
