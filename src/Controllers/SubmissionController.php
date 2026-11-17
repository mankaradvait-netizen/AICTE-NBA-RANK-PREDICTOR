<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\DatabaseManager;
use App\Engine\GapOptimizer;
use App\Engine\ScoreEngine;
use App\Excel\FormulaWorkbookManager;
use App\Security\AuditLogger;
use App\Security\CryptoService;
use App\Validation\NirfInputValidator;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use InvalidArgumentException;

class SubmissionController
{
    public function __construct(
        private readonly DatabaseManager $dbManager,
        private readonly CryptoService $cryptoService,
        private readonly NirfInputValidator $inputValidator,
        private readonly ScoreEngine $scoreEngine,
        private readonly GapOptimizer $gapOptimizer,
        private readonly FormulaWorkbookManager $workbookManager
    ) {}

    /**
     * POST /submissions - Create a new DRAFT submission
     */
    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $userId = (string) ($user['id'] ?? '');

        if (empty($instCode)) {
            return $this->error($response, "User is not associated with an institution.", 403, 'FORBIDDEN');
        }

        $body = (array) $request->getParsedBody();
        $category = (string) ($body['category'] ?? 'ENGINEERING');
        $academicYear = (string) ($body['academic_year'] ?? '2026-2027');
        $currentBand = (string) ($body['current_rank_band'] ?? 'UNRANKED');
        $targetBand = (string) ($body['target_rank_band'] ?? 'TOP_300');
        $rawMetrics = isset($body['raw_metrics']) && is_array($body['raw_metrics']) ? $body['raw_metrics'] : [];

        // Fetch active formula set for this category and academic year
        $corePdo = $this->dbManager->getInstitutionCore();
        $formulaPdo = $this->dbManager->getFormulaReader();

        $stmtSet = $formulaPdo->prepare("
            SELECT id, version FROM formula_sets 
            WHERE category = :cat AND academic_year = :year AND status = 'ACTIVE'
            LIMIT 1
        ");
        $stmtSet->execute([':cat' => $category, ':year' => $academicYear]);
        $activeSet = $stmtSet->fetch(PDO::FETCH_ASSOC);

        if (!$activeSet) {
            return $this->error($response, "No active NIRF formula set configured for category {$category} and year {$academicYear}.", 422, 'NO_ACTIVE_FORMULA_SET');
        }

        $setId = (int) $activeSet['id'];
        $setVersion = (int) $activeSet['version'];

        // Encrypt raw metrics with CryptoService
        $plaintext = json_encode($rawMetrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($plaintext === false) {
            $plaintext = '{}';
        }
        $encContainer = $this->cryptoService->encrypt($plaintext);

        $submissionId = $this->generateUuid();

        $stmt = $corePdo->prepare("
            INSERT INTO institution_submissions (
                id, institution_code, submitted_by, category, academic_year, status,
                current_rank_band, target_rank_band, formula_set_id, formula_version,
                encrypted_raw_metrics, encryption_iv, auth_tag, payload_hmac, key_id,
                payload_sha256, row_version
            ) VALUES (
                :id, :inst, :sub_by, :cat, :year, 'DRAFT',
                :c_band, :t_band, :set_id, :set_v,
                :enc_payload, :iv, :auth_tag, :payload_hmac, :key_id,
                :sha256, 1
            )
        ");

        $stmt->execute([
            ':id' => $submissionId,
            ':inst' => $instCode,
            ':sub_by' => $userId,
            ':cat' => $category,
            ':year' => $academicYear,
            ':c_band' => $currentBand,
            ':t_band' => $targetBand,
            ':set_id' => $setId,
            ':set_v' => $setVersion,
            ':enc_payload' => $encContainer['encrypted_payload'],
            ':iv' => $encContainer['iv'],
            ':auth_tag' => $encContainer['auth_tag'],
            ':payload_hmac' => $encContainer['payload_hmac'],
            ':key_id' => $encContainer['key_id'],
            ':sha256' => $encContainer['payload_sha256'],
        ]);

        AuditLogger::log($corePdo, $userId, $this->getIp($request), 'SUBMISSION_CREATE', $submissionId, [
            'institution_code' => $instCode,
            'category' => $category,
            'academic_year' => $academicYear,
            'formula_set_id' => $setId,
        ]);

        return $this->json($response, [
            'submission_id' => $submissionId,
            'status' => 'DRAFT',
            'row_version' => 1,
            'category' => $category,
            'academic_year' => $academicYear,
            'current_rank_band' => $currentBand,
            'target_rank_band' => $targetBand,
        ], 201);
    }

    /**
     * PUT /submissions/{id} - Update draft metrics with row_version optimistic locking
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $userId = (string) ($user['id'] ?? '');

        $corePdo = $this->dbManager->getInstitutionCore();

        // Fetch submission with tenancy check
        $stmt = $corePdo->prepare("SELECT * FROM institution_submissions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub || ($sub['institution_code'] !== $instCode && $user['role'] !== 'SYSTEM_ADMIN')) {
            return $this->error($response, "Submission not found or access denied.", 404, 'NOT_FOUND');
        }

        if ($sub['status'] !== 'DRAFT') {
            return $this->error($response, "Cannot modify submission in {$sub['status']} status.", 409, 'SUBMISSION_LOCKED');
        }

        $body = (array) $request->getParsedBody();
        $expectedVersion = (int) ($body['row_version'] ?? 0);

        // Optimistic locking check
        if ($expectedVersion !== (int) $sub['row_version']) {
            return $this->error($response, "Optimistic Lock Conflict: submission was updated by another request. Refresh data.", 409, 'CONCURRENCY_CONFLICT');
        }

        $rawMetrics = isset($body['raw_metrics']) && is_array($body['raw_metrics']) ? $body['raw_metrics'] : [];
        $plaintext = json_encode($rawMetrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($plaintext === false) {
            $plaintext = '{}';
        }

        $encContainer = $this->cryptoService->encrypt($plaintext);
        $newVersion = (int) $sub['row_version'] + 1;

        $updateStmt = $corePdo->prepare("
            UPDATE institution_submissions 
            SET encrypted_raw_metrics = :enc, encryption_iv = :iv, auth_tag = :tag, 
                payload_hmac = :hmac, key_id = :key_id, payload_sha256 = :sha, 
                row_version = :new_v
            WHERE id = :id AND row_version = :old_v
        ");

        $updateStmt->execute([
            ':enc' => $encContainer['encrypted_payload'],
            ':iv' => $encContainer['iv'],
            ':tag' => $encContainer['auth_tag'],
            ':hmac' => $encContainer['payload_hmac'],
            ':key_id' => $encContainer['key_id'],
            ':sha' => $encContainer['payload_sha256'],
            ':new_v' => $newVersion,
            ':id' => $id,
            ':old_v' => $expectedVersion,
        ]);

        if ($updateStmt->rowCount() === 0) {
            return $this->error($response, "Optimistic Lock Conflict: submission was updated by another request concurrently. Refresh and retry.", 409, 'CONCURRENCY_CONFLICT');
        }

        AuditLogger::log($corePdo, $userId, $this->getIp($request), 'SUBMISSION_UPDATE', $id, [
            'row_version' => $newVersion,
        ]);

        return $this->json($response, [
            'submission_id' => $id,
            'status' => 'DRAFT',
            'row_version' => $newVersion,
        ]);
    }

    /**
     * POST /submissions/{id}/submit - Validate, Score, Optimize, Encrypt & Lock (Idempotent)
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $userId = (string) ($user['id'] ?? '');

        $corePdo = $this->dbManager->getInstitutionCore();

        $stmt = $corePdo->prepare("SELECT * FROM institution_submissions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub || ($sub['institution_code'] !== $instCode && $user['role'] !== 'SYSTEM_ADMIN')) {
            return $this->error($response, "Submission not found or access denied.", 404, 'NOT_FOUND');
        }

        // Idempotency: If already LOCKED, return existing computed scores directly
        if ($sub['status'] === 'LOCKED') {
            return $this->json($response, [
                'submission_id' => $id,
                'status' => 'LOCKED',
                'computed_scores' => json_decode((string)$sub['computed_scores'], true),
                'gap_analysis' => json_decode((string)$sub['gap_analysis'], true),
                'submitted_at' => $sub['submitted_at'],
            ]);
        }

        // Decrypt raw metrics (extracts wrapped DEK directly from bundled container prefix)
        try {
            $plaintext = $this->cryptoService->decrypt(
                (string) $sub['encrypted_raw_metrics'],
                (string) $sub['encryption_iv'],
                $sub['auth_tag'] !== null ? (string)$sub['auth_tag'] : null,
                $sub['payload_hmac'] !== null ? (string)$sub['payload_hmac'] : null,
                '', // Unbundle wrapped DEK directly from the encrypted payload prefix
                (string) $sub['payload_sha256']
            );
        } catch (\Throwable $e) {
            return $this->error($response, "Decryption or cryptographic payload integrity verification failed: " . $e->getMessage(), 500, 'CRYPTO_ERROR');
        }

        $metrics = json_decode($plaintext, true);
        if (!is_array($metrics)) {
            $metrics = [];
        }

        // Validate metrics using NirfInputValidator
        $validation = $this->inputValidator->validate($metrics, $sub['category'], $sub['academic_year']);
        if (!$validation['valid']) {
            return $this->json($response, [
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Metric validation errors prevented final scoring.',
                    'validation_errors' => $validation['errors'],
                    'validation_warnings' => $validation['warnings'],
                ]
            ], 422);
        }

        // Deterministic ScoreEngine calculation
        $scorecard = $this->scoreEngine->score(
            $validation['validated_inputs'],
            $sub['category'],
            $sub['academic_year'],
            (int) $sub['formula_set_id']
        );

        // Gap Optimizer calculation
        $gapPlan = $this->gapOptimizer->optimize(
            $validation['validated_inputs'],
            $sub['category'],
            $sub['academic_year'],
            $sub['current_rank_band'],
            $sub['target_rank_band'],
            (int) $sub['formula_set_id']
        );

        $now = date('Y-m-d H:i:s');
        $scoresJson = json_encode($scorecard, JSON_UNESCAPED_SLASHES);
        $gapJson = json_encode($gapPlan, JSON_UNESCAPED_SLASHES);

        $lockStmt = $corePdo->prepare("
            UPDATE institution_submissions 
            SET status = 'LOCKED', computed_scores = :scores, gap_analysis = :gap, 
                submitted_at = :now, row_version = row_version + 1
            WHERE id = :id AND status = 'DRAFT'
        ");
        $lockStmt->execute([
            ':scores' => $scoresJson,
            ':gap' => $gapJson,
            ':now' => $now,
            ':id' => $id,
        ]);

        if ($lockStmt->rowCount() === 0) {
            // Concurrent submit won the race - fetch and return the locked record idempotently
            $stmt = $corePdo->prepare("SELECT * FROM institution_submissions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $lockedSub = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lockedSub && $lockedSub['status'] === 'LOCKED') {
                return $this->json($response, [
                    'submission_id' => $id,
                    'status' => 'LOCKED',
                    'computed_scores' => json_decode((string)$lockedSub['computed_scores'], true),
                    'gap_analysis' => json_decode((string)$lockedSub['gap_analysis'], true),
                    'validation_warnings' => $validation['warnings'],
                    'submitted_at' => $lockedSub['submitted_at'],
                ]);
            }
            return $this->error($response, "Submission could not be locked.", 409, 'SUBMISSION_LOCKED');
        }

        AuditLogger::log($corePdo, $userId, $this->getIp($request), 'SUBMISSION_SUBMIT', $id, [
            'total_score' => $scorecard['total_score'],
            'target_reached' => $gapPlan['target_reached'],
        ]);

        return $this->json($response, [
            'submission_id' => $id,
            'status' => 'LOCKED',
            'computed_scores' => $scorecard,
            'gap_analysis' => $gapPlan,
            'validation_warnings' => $validation['warnings'],
            'submitted_at' => $now,
        ]);
    }

    /**
     * GET /submissions - List submissions for caller's institution
     */
    public function list(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $corePdo = $this->dbManager->getInstitutionCore();

        if ($user['role'] === 'SYSTEM_ADMIN' || $user['role'] === 'AUDITOR') {
            $stmt = $corePdo->query("SELECT id, institution_code, category, academic_year, status, current_rank_band, target_rank_band, created_at, submitted_at FROM institution_submissions ORDER BY created_at DESC");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $stmt = $corePdo->prepare("SELECT id, institution_code, category, academic_year, status, current_rank_band, target_rank_band, created_at, submitted_at FROM institution_submissions WHERE institution_code = :inst ORDER BY created_at DESC");
            $stmt->execute([':inst' => $instCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->json($response, ['submissions' => $rows]);
    }

    /**
     * GET /submissions/{id}/results - Return scorecard and gap analysis
     */
    public function getResults(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $corePdo = $this->dbManager->getInstitutionCore();

        $stmt = $corePdo->prepare("SELECT * FROM institution_submissions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub || ($sub['institution_code'] !== $instCode && $user['role'] !== 'SYSTEM_ADMIN' && $user['role'] !== 'AUDITOR')) {
            return $this->error($response, "Submission not found or access denied.", 404, 'NOT_FOUND');
        }

        $scores = json_decode((string)$sub['computed_scores'], true);
        $gap = json_decode((string)$sub['gap_analysis'], true);

        return $this->json($response, [
            'submission_id' => $id,
            'institution_code' => $sub['institution_code'],
            'category' => $sub['category'],
            'academic_year' => $sub['academic_year'],
            'status' => $sub['status'],
            'formula_version' => $sub['formula_version'],
            'computed_scores' => $scores,
            'gap_analysis' => $gap,
            'submitted_at' => $sub['submitted_at'],
        ]);
    }

    /**
     * GET /submissions/{id}/export.xlsx - Download Excel scorecard with formula injection defense
     */
    public function exportExcel(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $user = $request->getAttribute('user');
        $instCode = (string) ($user['institution_code'] ?? '');
        $corePdo = $this->dbManager->getInstitutionCore();

        $stmt = $corePdo->prepare("SELECT * FROM institution_submissions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub || ($sub['institution_code'] !== $instCode && $user['role'] !== 'SYSTEM_ADMIN')) {
            return $this->error($response, "Submission not found or access denied.", 404, 'NOT_FOUND');
        }

        $scores = json_decode((string)$sub['computed_scores'], true);
        $gap = json_decode((string)$sub['gap_analysis'], true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('NIRF_Scorecard');

        // Headers
        $sheet->fromArray([
            ['Parameter Code', 'Parameter Name', 'Main Parameter', 'Raw Score', 'Clamped Score', 'Max Marks', 'Weight', 'Weighted Score']
        ]);
        $sheet->freezePane('A2');

        $rows = [];
        if (isset($scores['parameter_scores']) && is_array($scores['parameter_scores'])) {
            foreach ($scores['parameter_scores'] as $pCode => $pData) {
                $rows[] = [
                    $this->workbookManager->escapeFormulaInjection((string)$pCode),
                    $this->workbookManager->escapeFormulaInjection((string)$pCode),
                    $pData['main_parameter'],
                    $pData['raw_score'],
                    $pData['clamped_score'],
                    $pData['max_marks'],
                    $pData['main_weight'],
                    $pData['weighted_score'],
                ];
            }
        }
        $sheet->fromArray($rows, null, 'A2');

        // Total Row
        $lastRow = count($rows) + 3;
        $sheet->setCellValue("A{$lastRow}", 'TOTAL SCORE');
        $sheet->setCellValue("H{$lastRow}", $scores['total_score'] ?? 0.0);

        $tempFile = tempnam(sys_get_temp_dir(), 'nirf_export_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary scorecard export file.');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $contents = file_get_contents($tempFile);
            if ($contents === false) {
                throw new RuntimeException('Unable to read generated scorecard export file.');
            }
            $response->getBody()->write($contents);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="nirf_scorecard_' . $sub['institution_code'] . '.xlsx"');
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
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
