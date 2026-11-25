<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\DatabaseManager;
use App\Config\Environment;
use App\Engine\ScoreEngine;
use App\Formula\SafeExpressionEvaluator;
use App\Ingestion\UniversalDocumentIngestionService;
use PHPUnit\Framework\TestCase;

class InstitutionalFolderIngestionTest extends TestCase
{
    private UniversalDocumentIngestionService $service;
    private string $workspaceDir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->service = new UniversalDocumentIngestionService();
        $this->workspaceDir = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testIngestsAllElevenInstitutionalFilesWithoutHallucination(): void
    {
        $targetFiles = [
            '1. SITRC Master_Institute_Info.xls',
            '2. SITRC TLR_Faculty_Data_ Update 14.07.2026.xlsx',
            '3. SITRC B.Tech Students Data.xls',
            '4.MBA_templates_PG_ Category Update.xlsx',
            '5.MCA_templates_PG_ Category Update.xlsx',
            '6. SITRC Financial Resourses-1.xlsx',
            '7. SITRC GO_Graduation_Outcomes.xls',
            '8. SITRC OI_Outreach_Inclusivity.xls',
            'NIRF data analysis Summery .xls',
            'Perception_Scores.xlsx',
            'Publication Scopus_SITRC.xlsx',
        ];

        $filesToIngest = [];
        foreach ($targetFiles as $tf) {
            $fullPath = $this->workspaceDir . DIRECTORY_SEPARATOR . $tf;
            if (file_exists($fullPath)) {
                $filesToIngest[] = [
                    'path' => $fullPath,
                    'original_name' => $tf,
                    'size' => filesize($fullPath),
                ];
            }
        }

        $this->assertCount(11, $filesToIngest, "All 11 institutional files must exist in workspace.");

        $res = $this->service->ingestDocuments($filesToIngest);

        $this->assertTrue($res['success'], "Universal document ingestion must succeed.");
        $this->assertCount(11, $res['documents'], "All 11 documents must be registered.");
        $this->assertGreaterThan(50, count($res['candidate_facts']), "Candidate facts must be extracted.");

        $kpis = $res['mapped_kpis'];

        // 1. Intake & Students (Non-hallucinated: Intake NT=2220, Enrolled NE=2372, PhD NP=0)
        $this->assertEquals(2220.0, $kpis['NT']);
        $this->assertEquals(2372.0, $kpis['NE']);
        $this->assertEquals(0.0, $kpis['NP']);

        // 2. Faculty & PhD (118 regular faculty, 34 with PhD)
        $this->assertEquals(118.0, $kpis['F']);
        $this->assertEquals(34.0, $kpis['F_PHD']);

        // 3. Faculty Experience Bands (F1=37, F2=44, F3=37; sum = 118)
        $this->assertEquals(37.0, $kpis['F1']);
        $this->assertEquals(44.0, $kpis['F2']);
        $this->assertEquals(37.0, $kpis['F3']);
        $this->assertEquals(118.0, $kpis['F1'] + $kpis['F2'] + $kpis['F3']);

        // 4. Bibliometrics from Scopus Export (3-Year window 2024-2026: 220 pubs, 399 citations, 55 top25)
        $this->assertEquals(220.0, $kpis['P']);
        $this->assertEquals(399.0, $kpis['CC']);
        $this->assertEquals(55.0, $kpis['TOP25']);
        $this->assertEquals(0.0, $kpis['Pret']);
        $this->assertEquals(0.0, $kpis['Cret']);

        // 5. IPR / Patents
        $this->assertEquals(40.0, $kpis['PP']);
        $this->assertEquals(13.0, $kpis['PG']);

        // 6. Multi-Year Financial Resources (3-Year Averages: Capex=65.82M, Opex=314.79M, Res=11.57M, Cons=14.60M)
        // BC = 65,821,349.67 / 2220 = 29649.26 INR/student
        // BO = 314,797,305.67 / 2220 = 141800.59 INR/student
        // RF = 11,573,966.67 / 118 = 98084.46 INR/faculty
        // CF = 14,601,330.00 / 118 = 123740.08 INR/faculty
        $this->assertEqualsWithDelta(29649.26, $kpis['BC'], 1.0);
        $this->assertEqualsWithDelta(141800.59, $kpis['BO'], 1.0);
        $this->assertEqualsWithDelta(98084.46, $kpis['RF'], 1.0);
        $this->assertEqualsWithDelta(123740.08, $kpis['CF'], 1.0);

        // 7. Graduation Outcomes (323 passed, 296 placed = 91.64%, 27 higher studies = 8.36%, median salary = 580000)
        $this->assertEquals(91.64, $kpis['NP_pct']);
        $this->assertEquals(8.36, $kpis['NHS_pct']);
        $this->assertEquals(100.0, $kpis['NP_pct'] + $kpis['NHS_pct']);
        $this->assertEquals(82.39, $kpis['NG_pct']);
        $this->assertEquals(580000.0, $kpis['MS']);
        $this->assertEquals(580000.0, $kpis['GMS_val']);
        $this->assertEquals(0.0, $kpis['NPHD']);

        // 8. Diversity & Perception
        $this->assertEquals(29.93, $kpis['SW_pct']);
        $this->assertEquals(36.44, $kpis['FW_pct']);
        $this->assertEquals(2.15, $kpis['N_state_pct']);
        $this->assertEquals(0.0, $kpis['N_country_pct']);
        $this->assertEquals(80.52, $kpis['ESC_pct']);
        $this->assertEquals(20.0, $kpis['PCS_score']);
        $this->assertEquals(7.4, $kpis['PR_survey_val']);
    }

    public function testExecutionThroughScoreEngineWithInstitutionalKpis(): void
    {
        Environment::load($this->workspaceDir);
        $dbManager = new DatabaseManager($_ENV);
        $evaluator = new SafeExpressionEvaluator();
        $engine = new ScoreEngine($dbManager, $evaluator);

        $inputs = [
            'NT' => 2220.0,
            'NE' => 2220.0,
            'NP' => 0.0,
            'F' => 118.0,
            'F_PHD' => 34.0,
            'F1' => 37.0,
            'F2' => 44.0,
            'F3' => 37.0,
            'BC' => 29649.26,
            'BO' => 141800.59,
            'P' => 220.0,
            'Pret' => 0.0,
            'CC' => 399.0,
            'TOP25' => 55.0,
            'Cret' => 0.0,
            'PG' => 13.0,
            'PP' => 40.0,
            'RF' => 98084.46,
            'CF' => 123740.08,
            'NP_pct' => 91.64,
            'NHS_pct' => 8.36,
            'NG_pct' => 82.39,
            'GMS_val' => 580000.0,
            'MS' => 580000.0,
            'NPHD' => 0.0,
            'N_state_pct' => 2.15,
            'N_country_pct' => 0.0,
            'SW_pct' => 29.93,
            'FW_pct' => 36.44,
            'ESC_pct' => 80.52,
            'PCS_score' => 20.0,
            'PR_survey_val' => 7.4,
        ];

        $result = $engine->score($inputs, 'ENGINEERING', '2026-2027');

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('total_score', $result);
        $this->assertArrayHasKey('parameter_scores', $result);

        // Calibrated non-inflated score evaluates to ~43.10
        $this->assertEqualsWithDelta(43.10, $result['total_score'], 0.1);

        $rankPredictor = new \App\Engine\RankPredictor();
        $rankResult = $rankPredictor->predict($result['total_score']);
        $this->assertSame('Rank 101 - 150', $rankResult['predicted_band']);

        // Compare against official 300-institution cohort
        $cohortResult = $rankPredictor->compareWithCohort($result['total_score'], 'Sandip Institute of Technology & Research Centre (SITRC)');
        $this->assertSame(300, $cohortResult['total_cohort_count']);
        $this->assertEquals(108, $cohortResult['simulated_cohort_rank']);
        $this->assertSame('Rank 101 - 150', $cohortResult['simulated_tier']);
        $this->assertNotEmpty($cohortResult['peers_above']);
        $this->assertNotEmpty($cohortResult['peers_below']);
        $this->assertEqualsWithDelta(0.85, $cohortResult['tier_cutoffs']['tier_top_100']['gap'], 0.05);

        // Check key sub-parameters
        $pScores = $result['parameter_scores'];
        $this->assertGreaterThan(0.0, $pScores['SS']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['FSR']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['FQE']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['FRU']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['PU']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['QP']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['IPR']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['FPPP']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['GPH']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['GMS']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['RD']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['WD']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['ESCS']['raw_score']);
        $this->assertGreaterThan(0.0, $pScores['PCS']['raw_score']);
        $this->assertEquals(7.4, $pScores['PR']['clamped_score']);
        $this->assertEquals(0.74, $result['main_parameter_subtotals']['PR']['weighted_total']);
    }

    public function testUploadWorkbooksFolderApiEndpoint(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = '1';
        $_SESSION['session_token'] = 'test-token';

        $app = \App\App::create();

        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/api/upload/workbooks?folder=1')
            ->withHeader('Accept', 'application/json');

        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        $this->assertIsArray($data);
        $this->assertTrue($data['success']);
        $this->assertGreaterThanOrEqual(11, count($data['documents']));
        $this->assertGreaterThan(50, count($data['candidate_facts']));

        $mapped = $data['kpis'];
        $this->assertEquals(2220.0, $mapped['NT']);
        $this->assertEquals(2372.0, $mapped['NE']);
        $this->assertEquals(118.0, $mapped['F']);
        $this->assertEquals(34.0, $mapped['F_PHD']);
        $this->assertEquals(220.0, $mapped['P']);
        $this->assertEquals(399.0, $mapped['CC']);
        $this->assertEquals(55.0, $mapped['TOP25']);
        $this->assertEquals(82.39, $mapped['NG_pct']);
        $this->assertEquals(91.64, $mapped['NP_pct']);
        $this->assertEquals(8.36, $mapped['NHS_pct']);
        $this->assertEquals(7.4, $mapped['PR_survey_val']);
    }
}
