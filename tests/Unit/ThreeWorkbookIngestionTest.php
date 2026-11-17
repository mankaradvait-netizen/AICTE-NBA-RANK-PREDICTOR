<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\ThreeWorkbookIngestionService;
use App\Ingestion\KpiMapper;
use PHPUnit\Framework\TestCase;

class ThreeWorkbookIngestionTest extends TestCase
{
    private ThreeWorkbookIngestionService $service;
    private KpiMapper $mapper;

    protected function setUp(): void
    {
        $this->service = new ThreeWorkbookIngestionService();
        $this->mapper = new KpiMapper();
    }

    public function testDetectWorkbookTypes(): void
    {
        $facHeaders = ['Faculty Name', 'Faculty ID', 'Gender', 'Strt of teaching Date ', 'Designation ', 'Publication passed', 'Earnings from Publications'];
        $stuHeaders = ['Student Name ', 'Gender', 'STRT YEAR OF COURCE', 'GRADUATION YEAR', 'Program', 'PLACED OR NOT', 'CGPA year 1'];
        $finHeaders = ['Lab Spendings (Total 3 years)', 'Building and Construction Cost', 'Hostel and Allied management cost', 'Sanctioned Approved Intake UG+PG (NT)', 'PCS Facilities Score (Ramps, Lifts, Braille, Restrooms - Out of 20)'];

        $this->assertEquals('FACULTY', $this->service->detectWorkbookType($facHeaders));
        $this->assertEquals('STUDENT', $this->service->detectWorkbookType($stuHeaders));
        $this->assertEquals('FINANCE', $this->service->detectWorkbookType($finHeaders));
    }

    public function testCleanCellIndianCommasAndCurrencies(): void
    {
        $this->assertEquals(150000.0, $this->service->cleanCell('Salary', '₹ 1,50,000'));
        $this->assertEquals(2500000.0, $this->service->cleanCell('Capex', '25,00,000'));
        $this->assertEquals(200000.0, $this->service->cleanCell('Research', '2 Lakhs'));
        $this->assertTrue($this->service->cleanCell('Placed', 'Yes'));
        $this->assertFalse($this->service->cleanCell('Placed', 'No'));
        $this->assertEquals('2021-06-15', $this->service->cleanCell('Date', '15/06/2021'));
    }

    public function testKpiMapperProduces29ValidInputs(): void
    {
        $facultyRows = [
            [
                'Faculty Name' => 'Dr. Sharma',
                'Designation' => 'Professor',
                'Type Of Faculty' => 'Full time',
                'Education Level' => 'Ph.D',
                'Gender' => 'Male',
                'Strt of teaching Date' => '2010-07-01',
                'Publication passed' => 12,
                'Publications Failed' => 0,
                'Earnings from Publications' => 600000,
                'Earnings from counselling' => 150000,
                'Citations passed' => 180,
                'Citations Failed' => 0,
                'Patents Granted (3 Yrs)' => 2,
                'Patents Published (3 Yrs)' => 4,
                'Top 25 Percentile Citations' => 8,
                'Ph.D. Scholars Graduated Guided' => 3,
            ],
            [
                'Faculty Name' => 'Prof. Ananya',
                'Designation' => 'Assistant Professor',
                'Type Of Faculty' => 'Full time',
                'Education Level' => 'M.Tech',
                'Gender' => 'Female',
                'Strt of teaching Date' => '2020-08-01',
                'Publication passed' => 4,
                'Publications Failed' => 0,
                'Earnings from Publications' => 100000,
                'Earnings from counselling' => 50000,
                'Citations passed' => 25,
                'Citations Failed' => 0,
                'Patents Granted (3 Yrs)' => 0,
                'Patents Published (3 Yrs)' => 1,
                'Top 25 Percentile Citations' => 1,
                'Ph.D. Scholars Graduated Guided' => 0,
            ]
        ];

        $studentRows = [
            [
                'Student Name' => 'Student 1',
                'Program' => 'B.Tech',
                'Gender' => 'Male',
                'Country' => 'India',
                'State' => 'Gujarat',
                'Placed or Not' => 'Yes',
                'Salery placed on campus' => 700000,
                'Selected for Higher Studies' => 'No',
                'Graduated in Stipulated Time' => 'Yes',
                'Full Tuition Fee Reimbursement Status' => 'Yes',
            ],
            [
                'Student Name' => 'Student 2',
                'Program' => 'B.Tech',
                'Gender' => 'Female',
                'Country' => 'Nepal',
                'State' => 'Outside',
                'Placed or Not' => 'Yes',
                'Salery placed on campus' => 800000,
                'Selected for Higher Studies' => 'Yes',
                'Graduated in Stipulated Time' => 'Yes',
                'Full Tuition Fee Reimbursement Status' => 'No',
            ]
        ];

        $financeRows = [
            [
                'Sanctioned Approved Intake UG+PG (NT)' => 300,
                'PCS Facilities Score (Ramps, Lifts, Braille, Restrooms - Out of 20)' => 19,
                'Lab Spendings (Total 3 years)' => 15000000,
                'Building and Construction Cost' => 50000000, // Should be pruned from FRU
                'Hostel and Allied management cost' => 30000000, // Should be pruned from FRU
                'Sallery of teachers and lab assistents' => 90000000,
            ]
        ];

        $res = $this->mapper->map($facultyRows, $studentRows, $financeRows);
        $inputs = $res['inputs'];

        $this->assertEquals(300.0, $inputs['NT']);
        $this->assertEquals(2.0, $inputs['F']);
        $this->assertEquals(1.0, $inputs['F_PHD']);
        $this->assertEquals(19.0, $inputs['PCS_score']);
        $this->assertEquals(16.0, $inputs['P']); // 12 + 4
        $this->assertEquals(205.0, $inputs['CC']); // 180 + 25
        $this->assertEquals(750000.0, $inputs['GMS_val']); // Median of 700k and 800k
        $this->assertArrayHasKey('lineage', $res);
    }
}
