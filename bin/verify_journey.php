<?php

declare(strict_types=1);

/**
 * End-to-End Verification of AICTE-NBA-NIRF Application on localhost:8000
 */

$cookieJar = __DIR__ . '/../var/test_cookies.txt';
if (file_exists($cookieJar)) {
    unlink($cookieJar);
}

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

echo "=== STARTING FULL END-TO-END VERIFICATION ON http://localhost:8000 ===\n\n";

// 1. Screen 1: Sign-in Page
$res1 = httpReq('GET', 'http://localhost:8000/', null, $cookieJar);
echo "1. GET / (Sign-in Page): Status {$res1['status']}\n";
assert($res1['status'] === 200, "Sign-in page should return 200");
assert(str_contains((string)$res1['body'], '#7B1113') || str_contains((string)$res1['body'], 'ADYPU') || str_contains((string)$res1['body'], 'Ajeenkya DY Patil'), "Sign-in page must have ADYPU branding");

// 2. Sign In
$res2 = httpReq('POST', 'http://localhost:8000/api/signin', [
    'email' => 'admin@adypu.edu.in',
    'password' => 'Password123!',
], $cookieJar);
echo "2. POST /api/signin: Status {$res2['status']}\n";
$authData = json_decode((string)$res2['body'], true);
assert($res2['status'] === 200 && ($authData['success'] ?? false) === true, "Authentication must succeed");
echo "   CSRF Token: {$authData['csrf_token']}\n";

// 3. Screen 2: Landing Page
$res3 = httpReq('GET', 'http://localhost:8000/landing', null, $cookieJar);
echo "3. GET /landing: Status {$res3['status']}\n";
assert($res3['status'] === 200, "Landing page should return 200");

// 4. Screen 3: Data Input Page
$res4 = httpReq('GET', 'http://localhost:8000/data-input', null, $cookieJar);
echo "4. GET /data-input: Status {$res4['status']}\n";
assert($res4['status'] === 200, "Data input page should return 200");

// 5. Ingest 3 Master Workbooks (Demo 1-Click / Live Master Files)
$res5 = httpReq('POST', 'http://localhost:8000/api/upload/workbooks?sample=1', null, $cookieJar);
echo "5. POST /api/upload/workbooks?sample=1: Status {$res5['status']}\n";
$ingestData = json_decode((string)$res5['body'], true);
assert($res5['status'] === 200 && ($ingestData['success'] ?? false) === true, "Ingestion must succeed");
echo "   Faculty count: {$ingestData['counts']['faculty']}\n";
echo "   Student count: {$ingestData['counts']['students']}\n";
echo "   Financial line items: {$ingestData['counts']['financial']}\n";
echo "   Data Quality Register Issues flagged: " . count($ingestData['issues'] ?? []) . "\n";

// 6. Freeze Dataset & Calculate Baseline NIRF Score
$res6 = httpReq('POST', 'http://localhost:8000/api/dataset/freeze', [
    'institution_name' => 'Ajeenkya DY Patil University (School of Engineering)',
], $cookieJar);
echo "6. POST /api/dataset/freeze: Status {$res6['status']}\n";
$freezeData = json_decode((string)$res6['body'], true);
assert($res6['status'] === 200 && ($freezeData['success'] ?? false) === true, "Dataset freeze must succeed");
echo "   Deterministic Score: {$freezeData['total_score']} / 100\n";
echo "   Predicted Band: {$freezeData['predicted_band']}\n";
echo "   Confidence: {$freezeData['confidence']}\n";
echo "   Cryptographic Hash (SHA-256): {$freezeData['frozen_hash']}\n";

// 7. Screen 4: Results & Calculation Traces
$res7 = httpReq('GET', 'http://localhost:8000/results', null, $cookieJar);
echo "7. GET /results: Status {$res7['status']}\n";
assert($res7['status'] === 200, "Results page should return 200");

// 8. What-If Marginal ROI Simulation (CLIMBER Mode)
$res8 = httpReq('POST', 'http://localhost:8000/api/simulate', [
    'mode' => 'CLIMBER',
], $cookieJar);
echo "8. POST /api/simulate (Mode: CLIMBER): Status {$res8['status']}\n";
$simData = json_decode((string)$res8['body'], true);
assert($res8['status'] === 200 && ($simData['success'] ?? false) === true, "Simulation must succeed");
echo "   Baseline: {$simData['simulation']['baseline_score']} -> Optimized: {$simData['simulation']['optimized_score']}\n";
echo "   Score Delta: +{$simData['simulation']['score_delta']}\n";
echo "   Target Band: {$simData['simulation']['target_band']}\n";
echo "   Marginal ROI Levers: " . count($simData['simulation']['roadmap'] ?? []) . " strategic actions ranked\n";

// 9. Number-Guard Audited AI Strategy
$res9 = httpReq('POST', 'http://localhost:8000/api/ai/strategy', null, $cookieJar);
echo "9. POST /api/ai/strategy: Status {$res9['status']}\n";
$aiData = json_decode((string)$res9['body'], true);
assert($res9['status'] === 200 && ($aiData['success'] ?? false) === true, "AI Strategy must succeed");
$ngStatus = $aiData['ai_strategy']['number_guard']['status'] ?? 'UNKNOWN';
echo "   Number-Guard Audit: {$ngStatus}\n";
assert($ngStatus === 'NUMBER_GUARD_CERTIFIED', "AI Strategy must be Number-Guard Certified");

// 10. Authentic Binary PDF Report
$res10 = httpReq('GET', 'http://localhost:8000/report/pdf', null, $cookieJar);
echo "10. GET /report/pdf: Status {$res10['status']}\n";
assert($res10['status'] === 200, "PDF generation must return 200");
$pdfBody = (string)$res10['body'];
$pdfHeader = substr($pdfBody, 0, 5);
echo "    Header: {$pdfHeader}\n";
echo "    Size: " . strlen($pdfBody) . " bytes\n";
echo "    Content-Type: {$res10['content_type']}\n";
assert($pdfHeader === '%PDF-', "Downloaded document must be an authentic %PDF-1.4 binary file");

echo "\n>>> ALL 10 USER JOURNEY STAGES VERIFIED PERFECTLY! <<<\n";
