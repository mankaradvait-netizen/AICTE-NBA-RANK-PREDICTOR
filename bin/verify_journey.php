<?php

declare(strict_types=1);

/**
 * End-to-End Verification of AICTE-NBA-NIRF Application on localhost:8000
 */

$port = getenv('PORT') ?: '8080';
$baseUrl = "http://localhost:{$port}";

$cookieJar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_verify_cookies_' . bin2hex(random_bytes(4)) . '.txt';

function httpReq(string $method, string $url, ?array $json = null, ?string $cookieJar = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    $headers = [];
    if ($json !== null) {
        $payload = json_encode($json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'status' => $status,
        'body' => $body,
        'content_type' => $contentType,
        'error' => $error,
    ];
}

echo "=== STARTING FULL 3-SCREEN USER JOURNEY VERIFICATION ON {$baseUrl} ===\n\n";

try {
    // 1. Screen 1: Sign-in Page
    $res1 = httpReq('GET', "{$baseUrl}/", null, $cookieJar);
    echo "1. GET / (Sign-in Page): Status {$res1['status']}\n";
    assert($res1['status'] === 200, "Sign-in page should return 200");
    assert(str_contains((string)$res1['body'], '#7B1113') || str_contains((string)$res1['body'], 'ADYPU') || str_contains((string)$res1['body'], 'Ajeenkya DY Patil'), "Sign-in page must have ADYPU branding");

    // 2. Sign In & Redirect
    $res2 = httpReq('POST', "{$baseUrl}/api/signin", [
        'email' => 'admin@adypu.edu.in',
        'password' => 'Password123!',
    ], $cookieJar);
    echo "2. POST /api/signin: Status {$res2['status']}\n";
    $authData = json_decode((string)$res2['body'], true);
    assert($res2['status'] === 200 && ($authData['success'] ?? false) === true, "Authentication must succeed");
    assert(($authData['redirect'] ?? '') === '/input', "Sign in must redirect directly to /input");
    echo "   Redirect: {$authData['redirect']} | CSRF Token: {$authData['csrf_token']}\n";

    // 3. Page 2 (Landing Page) Must Return 404 (Removed from site)
    $res3 = httpReq('GET', "{$baseUrl}/landing", null, $cookieJar);
    echo "3. GET /landing: Status {$res3['status']} (Confirmed removed from site)\n";
    assert($res3['status'] === 404, "Landing page must be removed (404)");

    // 4. Screen 2: Data Input Page
    $res4 = httpReq('GET', "{$baseUrl}/data-input", null, $cookieJar);
    echo "4. GET /data-input: Status {$res4['status']}\n";
    assert($res4['status'] === 200, "Data input page should return 200");
    assert(str_contains((string)$res4['body'], '1. Authentication'), "Must have Step 1 nav pill");
    assert(str_contains((string)$res4['body'], '2. Data Input & Ingestion'), "Must have Step 2 nav pill");
    assert(str_contains((string)$res4['body'], '3. Institutional Analysis & Strategy'), "Must have Step 3 nav pill");

    // 5. Ingest 3 Master Workbooks (Demo 1-Click / Live Master Files)
    $res5 = httpReq('POST', "{$baseUrl}/api/upload/workbooks?sample=1", null, $cookieJar);
    echo "5. POST /api/upload/workbooks?sample=1: Status {$res5['status']}\n";
    $ingestData = json_decode((string)$res5['body'], true);
    assert($res5['status'] === 200 && ($ingestData['success'] ?? false) === true, "Ingestion must succeed");
    echo "   Faculty count: {$ingestData['counts']['faculty']}\n";
    echo "   Student count: {$ingestData['counts']['students']}\n";
    echo "   Financial line items: {$ingestData['counts']['financial']}\n";
    assert($ingestData['counts']['students'] > 0, "Student count must be greater than 0");
    echo "   Data Quality Register Issues flagged: " . count($ingestData['issues'] ?? []) . "\n";

    // 6. Auto-Resolve All Pending Quality Issues
    $res6a = httpReq('POST', "{$baseUrl}/api/issues/resolve-all", [
        'note' => 'Auto-verified and reconciled against official institutional records by IQAC',
    ], $cookieJar);
    echo "6. POST /api/issues/resolve-all: Status {$res6a['status']}\n";
    $resolveData = json_decode((string)$res6a['body'], true);
    assert($res6a['status'] === 200 && ($resolveData['success'] ?? false) === true, "Auto-resolve all must succeed");

    // 7. Freeze Dataset & Calculate Baseline NIRF Score
    $res6 = httpReq('POST', "{$baseUrl}/api/dataset/freeze", [
        'institution_name' => 'Ajeenkya DY Patil University (School of Engineering)',
    ], $cookieJar);
    echo "7. POST /api/dataset/freeze: Status {$res6['status']}\n";
    $freezeData = json_decode((string)$res6['body'], true);
    assert($res6['status'] === 200 && ($freezeData['success'] ?? false) === true, "Dataset freeze must succeed");
    echo "   Deterministic Score: {$freezeData['total_score']} / 100\n";
    echo "   Predicted Band: {$freezeData['predicted_band']}\n";
    echo "   Confidence: {$freezeData['confidence']}\n";
    echo "   Cryptographic Hash (SHA-256): {$freezeData['frozen_hash']}\n";

    // 8. Screen 3: Results & Calculation Traces
    $res7 = httpReq('GET', "{$baseUrl}/results", null, $cookieJar);
    echo "8. GET /results: Status {$res7['status']}\n";
    assert($res7['status'] === 200, "Results page should return 200");

    // 9. What-If Marginal ROI Simulation (CLIMBER Mode)
    $res8 = httpReq('POST', "{$baseUrl}/api/simulate", [
        'mode' => 'CLIMBER',
    ], $cookieJar);
    echo "9. POST /api/simulate (Mode: CLIMBER): Status {$res8['status']}\n";
    $simData = json_decode((string)$res8['body'], true);
    assert($res8['status'] === 200 && ($simData['success'] ?? false) === true, "Simulation must succeed");
    echo "   Baseline: {$simData['simulation']['baseline_score']} -> Optimized: {$simData['simulation']['optimized_score']}\n";
    echo "   Score Delta: +{$simData['simulation']['score_delta']}\n";
    echo "   Target Band: {$simData['simulation']['target_band']}\n";
    echo "   Marginal ROI Levers: " . count($simData['simulation']['roadmap'] ?? []) . " strategic actions ranked\n";

    // 10. Number-Guard Audited AI Strategy
    $res9 = httpReq('POST', "{$baseUrl}/api/ai/strategy", null, $cookieJar);
    echo "10. POST /api/ai/strategy: Status {$res9['status']}\n";
    $aiData = json_decode((string)$res9['body'], true);
    assert($res9['status'] === 200 && ($aiData['success'] ?? false) === true, "AI Strategy must succeed");
    $ngStatus = $aiData['ai_strategy']['number_guard']['status'] ?? 'UNKNOWN';
    echo "    Number-Guard Audit: {$ngStatus}\n";
    assert($ngStatus === 'NUMBER_GUARD_CERTIFIED', "AI Strategy must be Number-Guard Certified");

    // 11. Authentic Binary PDF Report
    $res10 = httpReq('GET', "{$baseUrl}/report/pdf", null, $cookieJar);
    echo "11. GET /report/pdf: Status {$res10['status']}\n";
    assert($res10['status'] === 200, "PDF generation must return 200");
    $pdfBody = (string)$res10['body'];
    $pdfHeader = substr($pdfBody, 0, 5);
    echo "    Header: {$pdfHeader}\n";
    echo "    Size: " . strlen($pdfBody) . " bytes\n";
    echo "    Content-Type: {$res10['content_type']}\n";
    assert($pdfHeader === '%PDF-', "Downloaded document must be an authentic %PDF-1.4 binary file");

    echo "\n>>> ALL 11 3-SCREEN USER JOURNEY STAGES VERIFIED ACCURATELY! <<<\n";
} finally {
    if (file_exists($cookieJar)) {
        @unlink($cookieJar);
    }
}
