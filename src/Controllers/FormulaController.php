<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\DatabaseManager;
use App\Excel\FormulaWorkbookManager;
use App\Security\AuditLogger;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FormulaController
{
    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly FormulaWorkbookManager $workbookManager
    ) {}

    public function getTemplate(Request $request, Response $response): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'nirf_template_');
        if ($path === false) {
            return $this->error($response, 'Unable to create temporary formula template.', 500, 'TEMPLATE_TEMP_FILE_FAILED');
        }

        try {
            \App\Excel\TemplateGenerator::generate($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                return $this->error($response, 'Unable to read generated formula workbook template.', 500, 'TEMPLATE_READ_FAILED');
            }
            $response->getBody()->write($contents);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 500, 'TEMPLATE_GENERATION_FAILED');
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="formula_master_template.xlsx"');
    }

    public function upload(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uploadedFiles = $request->getUploadedFiles();

        $file = $uploadedFiles['file'] ?? $uploadedFiles['workbook'] ?? null;
        if (!$file) {
            return $this->error($response, "No workbook file uploaded. Multipart key 'file' or 'workbook' required.", 400, 'NO_FILE_UPLOADED');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($response, "Upload error code: " . $file->getError(), 400, 'UPLOAD_FAILED');
        }

        if ($file->getSize() !== null && $file->getSize() > 5242880) {
            return $this->error($response, "File size exceeds 5 MB limit.", 413, 'FILE_TOO_LARGE');
        }

        $clientFilename = (string) $file->getClientFilename();
        if ($clientFilename !== '' && !preg_match('/\.xlsx$/i', $clientFilename)) {
            return $this->error($response, "Invalid file extension. Only .xlsx files are permitted.", 400, 'INVALID_FILE_EXTENSION');
        }

        // Store outside webroot with random filename
        $storageDir = sys_get_temp_dir() . '/nirf_uploads';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0770, true);
        }

        $tempPath = $storageDir . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $file->moveTo($tempPath);

        try {
            $result = $this->workbookManager->importWorkbook($tempPath, (string)$user['id']);
            unlink($tempPath);

            AuditLogger::log(
                $this->dbManager->getInstitutionCore(),
                (string)$user['id'],
                $this->getIp($request),
                'FORMULA_WORKBOOK_UPLOAD',
                (string)$result['formula_set_id'],
                ['status' => $result['status']]
            );

            $httpStatus = ($result['status'] === 'VALIDATED') ? 201 : 422;
            return $this->json($response, $result, $httpStatus);
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return $this->error($response, $e->getMessage(), 422, 'IMPORT_ERROR');
        }
    }

    public function list(Request $request, Response $response): Response
    {
        $pdo = $this->dbManager->getFormulaReader();
        $stmt = $pdo->query("SELECT id, category, academic_year, version, version_label, status, uploaded_by, approved_by, created_at, activated_at, notes FROM formula_sets ORDER BY category, academic_year, version DESC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return $this->json($response, ['formula_sets' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $pdo = $this->dbManager->getFormulaReader();

        $stmt = $pdo->prepare("SELECT * FROM formula_sets WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$set) {
            return $this->error($response, "Formula set {$id} not found.", 404, 'NOT_FOUND');
        }

        // Load rules
        $rStmt = $pdo->prepare("SELECT * FROM nirf_rules WHERE formula_set_id = :id ORDER BY display_order ASC");
        $rStmt->execute([':id' => $id]);
        $rules = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load benchmarks
        $bStmt = $pdo->prepare("SELECT * FROM nirf_benchmarks WHERE formula_set_id = :id");
        $bStmt->execute([':id' => $id]);
        $benchmarks = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load import log
        $lStmt = $pdo->prepare("SELECT * FROM formula_import_log WHERE formula_set_id = :id ORDER BY id DESC LIMIT 1");
        $lStmt->execute([':id' => $id]);
        $log = $lStmt->fetch(PDO::FETCH_ASSOC);

        return $this->json($response, [
            'formula_set' => $set,
            'rules' => $rules,
            'benchmarks' => $benchmarks,
            'validation_report' => $log ? json_decode((string)$log['validation_report'], true) : null,
        ]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $ackUnverified = (bool) ($body['acknowledge_unverified'] ?? false);

        try {
            $result = $this->workbookManager->approveAndActivate($id, (string)$user['id'], $ackUnverified);

            AuditLogger::log(
                $this->dbManager->getInstitutionCore(),
                (string)$user['id'],
                $this->getIp($request),
                'FORMULA_SET_ACTIVATE',
                (string)$id,
                ['acknowledge_unverified' => $ackUnverified]
            );

            return $this->json($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 422, 'ACTIVATION_ERROR');
        }
    }

    public function rollback(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $user = $request->getAttribute('user');

        try {
            $result = $this->workbookManager->rollback($id, (string)$user['id']);

            AuditLogger::log(
                $this->dbManager->getInstitutionCore(),
                (string)$user['id'],
                $this->getIp($request),
                'FORMULA_SET_ROLLBACK',
                (string)$id
            );

            return $this->json($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 422, 'ROLLBACK_ERROR');
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $tempPath = tempnam(sys_get_temp_dir(), 'export_');
        if ($tempPath === false) {
            return $this->error($response, 'Unable to create temporary export file.', 500, 'EXPORT_TEMP_FILE_FAILED');
        }

        try {
            $this->workbookManager->exportSetToExcel($id, $tempPath);
            $contents = file_get_contents($tempPath);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read generated formula workbook.');
            }

            $response->getBody()->write($contents);
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="formula_set_' . $id . '.xlsx"');
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 500, 'EXPORT_ERROR');
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function ingestBenchmarks(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $setId = (int) ($body['formula_set_id'] ?? 0);
        $rawData = isset($body['cohort_data']) && is_array($body['cohort_data']) ? $body['cohort_data'] : [];
        $sourceReference = isset($body['source_reference']) && is_string($body['source_reference'])
            ? trim($body['source_reference'])
            : '';

        if ($setId <= 0 || count($rawData) !== 100 || $sourceReference === '') {
            return $this->error($response, "A valid formula_set_id, exactly 100 cohort rows, and source_reference are required.", 400, 'INVALID_INPUT');
        }

        try {
            $this->workbookManager->ingestTop100($setId, $rawData, $sourceReference);
            return $this->json($response, ['message' => 'The 100-row benchmark cohort was ingested with its source reference; verify the source before activation.']);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 422, 'INGESTION_ERROR');
        }
    }

    private function getIp(Request $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, string $message, int $status, string $code): Response
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => bin2hex(random_bytes(8)),
            ]
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
