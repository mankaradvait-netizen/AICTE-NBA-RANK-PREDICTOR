<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\DatabaseManager;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InputSchemaController
{
    public function __construct(
        private readonly DatabaseManager $dbManager
    ) {}

    public function getSchema(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $category = (string) ($params['category'] ?? 'ENGINEERING');
        $academicYear = (string) ($params['year'] ?? '2026-2027');

        $pdo = $this->dbManager->getFormulaReader();

        $stmt = $pdo->prepare("
            SELECT id, version FROM formula_sets 
            WHERE category = :cat AND academic_year = :year AND status = 'ACTIVE' 
            LIMIT 1
        ");
        $stmt->execute([':cat' => $category, ':year' => $academicYear]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$set) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "No active formula set found for category {$category} and academic year {$academicYear}.",
                    'request_id' => bin2hex(random_bytes(8)),
                ]
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $setId = (int) $set['id'];

        $inStmt = $pdo->prepare("SELECT input_code, label, unit, data_type, min_value, max_value, required, main_parameter, help_text FROM input_definitions WHERE formula_set_id = :id ORDER BY id ASC");
        $inStmt->execute([':id' => $setId]);
        $inputs = $inStmt->fetchAll(PDO::FETCH_ASSOC);

        $vrStmt = $pdo->prepare("SELECT rule_id, expression, error_message, severity FROM validation_rules WHERE formula_set_id = :id ORDER BY id ASC");
        $vrStmt->execute([':id' => $setId]);
        $rules = $vrStmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write((string) json_encode([
            'category' => $category,
            'academic_year' => $academicYear,
            'formula_set_id' => $setId,
            'formula_version' => (int) $set['version'],
            'inputs' => $inputs,
            'validation_rules' => $rules,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
