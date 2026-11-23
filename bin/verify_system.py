import urllib.request
import urllib.parse
import http.cookiejar
import json
import sys

base_url = 'http://127.0.0.1:8001'
cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

print("=== 1. Checking GET / (Sign In Screen) ===")
resp = opener.open(base_url + '/')
assert resp.status == 200
html = resp.read().decode('utf-8')
assert 'Sign In' in html
print("   -> OK (HTTP 200, Sign In rendered)")

print("=== 2. Authenticating via POST /api/signin (Web Sign In) ===")
login_payload = json.dumps({'email': 'admin@adypu.edu.in', 'password': 'Password123!'}).encode('utf-8')
req = urllib.request.Request(base_url + '/api/signin', data=login_payload, headers={'Content-Type': 'application/json'})
resp = opener.open(req)
data = json.loads(resp.read().decode('utf-8'))
assert data.get('success') is True
csrf = data.get('csrf_token')
print(f"   -> OK (HTTP 200, Web Session established, CSRF: {csrf[:10]}...)")

print("=== 3. Checking GET /landing (Screen 2: Workspace Setup) ===")
resp = opener.open(base_url + '/landing')
assert resp.status == 200
assert 'Institutional Intelligence' in resp.read().decode('utf-8')
print("   -> OK (HTTP 200, Workspace & Methodology rendered)")

print("=== 4. Checking GET /input (Screen 3: Data Input) ===")
resp = opener.open(base_url + '/input')
assert resp.status == 200
assert 'Universal Document Ingestion' in resp.read().decode('utf-8')
print("   -> OK (HTTP 200, Universal Ingestion Hub rendered)")

print("=== 4b. Testing POST /api/upload/workbooks?sample=1 (Ingestion & Fact Extraction) ===")
req = urllib.request.Request(base_url + '/api/upload/workbooks?sample=1', data=b'', headers={'X-CSRF-Token': csrf})
resp = opener.open(req)
assert resp.status == 200
up_data = json.loads(resp.read().decode('utf-8'))
assert up_data.get('success') is True
facts_cnt = len(up_data.get('candidate_facts', []))
docs_cnt = len(up_data.get('documents', []))
print(f"   -> OK (HTTP 200, Ingestion Pipeline Extracted {facts_cnt} candidate facts from {docs_cnt} workbooks)")

print("=== 4c. Testing POST /api/dataset/freeze (Dataset Freeze with Audit Seal) ===")
freeze_payload = json.dumps({'institution_name': 'Sandip Institute of Technology & Research Centre (SITRC)'}).encode('utf-8')
req = urllib.request.Request(base_url + '/api/dataset/freeze', data=freeze_payload, headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
resp = opener.open(req)
assert resp.status == 200
frz_data = json.loads(resp.read().decode('utf-8'))
assert frz_data.get('success') is True
seal = frz_data.get('frozen_hash', '')[:16]
print(f"   -> OK (HTTP 200, Dataset Frozen with Audit Seal: {seal}...)")

print("=== 5. Performing POST /api/analyse (Deterministic Scoring & Calibrated Rank) ===")
inputs_payload = {
    'institution_name': 'Sandip Institute of Technology & Research Centre (SITRC)',
    'NT': 480, 'NE': 2220, 'NP': 0, 'F': 118, 'FQ_phd': 34,
    'F1': 38, 'F2': 45, 'F3': 35, 'BC': 31102.64, 'BO': 85775.09,
    'P': 120, 'CC': 250, 'TOP25': 60, 'PG': 13, 'PP': 40,
    'RF': 50992.15, 'CF': 56801.95, 'NP_pct': 72.93, 'NHS_pct': 9.375, 'NG_pct': 82.0,
    'MS': 580000, 'GMS_val': 580000, 'NPHD': 6, 'N_state_pct': 2.15, 'N_country_pct': 0,
    'SW_pct': 29.93, 'FW_pct': 25, 'ESC_pct': 50, 'PCS_score': 18, 'PR_survey_val': 65
}
payload_bytes = json.dumps(inputs_payload).encode('utf-8')
req = urllib.request.Request(base_url + '/api/analyse', data=payload_bytes, headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
resp = opener.open(req)
assert resp.status == 200
a_data = json.loads(resp.read().decode('utf-8'))
assert a_data.get('success') is True
assert a_data.get('redirect') == '/analysis'
print("   -> OK (HTTP 200, Deterministic Calculation complete, Redirect: /analysis)")

print("=== 6. Checking GET /analysis (Screen 4: Calibrated Rank & Analysis) ===")
resp = opener.open(base_url + '/analysis')
assert resp.status == 200
analysis_html = resp.read().decode('utf-8')
assert '10-Year Empirical NIRF Calibration' in analysis_html
assert 'Calibrated Expected Rank' in analysis_html
assert '10-Year National Benchmarks Comparison' in analysis_html
print("   -> OK (HTTP 200, 10-Year Empirical Calibrated Rank & Benchmarks rendered)")

print("=== 7. Performing POST /api/simulate (What-If Optimization) ===")
sim_payload = json.dumps({'mode': 'CLIMBER'}).encode('utf-8')
req = urllib.request.Request(base_url + '/api/simulate', data=sim_payload, headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
resp = opener.open(req)
assert resp.status == 200
s_data = json.loads(resp.read().decode('utf-8'))
assert s_data.get('success') is True
opt_score = s_data['simulation']['optimized_score']
steps_count = len(s_data['simulation']['roadmap'])
print(f"   -> OK (Optimized Score: {opt_score}, Steps: {steps_count})")

print("=== 8. Performing POST /api/ai/strategy (Number-Guard Certified AI Strategy) ===")
req = urllib.request.Request(base_url + '/api/ai/strategy', data=b'{}', headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
resp = opener.open(req)
assert resp.status == 200
ai_data = json.loads(resp.read().decode('utf-8'))
assert ai_data.get('success') is True
strategy = ai_data.get('ai_strategy', {})
guard_status = strategy.get('number_guard', {}).get('status')
provider = strategy.get('api_provider')
print(f"   -> OK (Status: {guard_status}, Provider: {provider})")

print("=== 9. Checking GET /report/pdf (PDF Generation) ===")
resp = opener.open(base_url + '/report/pdf')
assert resp.status == 200
pdf_bytes = resp.read()
assert pdf_bytes.startswith(b'%PDF')
print(f"   -> OK (Generated valid binary PDF: {len(pdf_bytes)} bytes)")

print("\n>>> ALL 9 STAGES PASSED SUCCESSFULLY - SYSTEM FULLY CONNECTED & RESPONDING!")
