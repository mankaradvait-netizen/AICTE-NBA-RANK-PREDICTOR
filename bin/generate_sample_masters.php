<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// -----------------------------------------------------------------------------
// 1. FACULTY DATA MASTER
// -----------------------------------------------------------------------------
$facultyHeaders = [
    'Faculty Name', 'Faculty ID', 'Gender', 'Strt of teaching Date ', 'DOB', 'Sallery (yearly)', 'Designation ', 'PWD',
    'Publication passed', 'Publications Failed', 'Earnings from Publications', 'Earnings from counselling',
    'Citations passed', 'Citations Failed', 'Education Level',
    'Type Of Faculty (Full time, Contract FOR 2 SEM, Visiting and etc)', 'Country ', 'State',
    'Patents Granted (3 Yrs)', 'Patents Published (3 Yrs)', 'Top 25 Percentile Citations (TOP25P)',
    'Ph.D. Scholars Graduated Guided (3 Yrs)', 'Department / Program', 'AICTE Unique Faculty ID',
    'PAN / Aadhaar Verified', 'NET / SET / GATE Qualified', 'Date of Leaving (If Left)',
    'Active in Last 3 Consecutive Yrs (CAY, CAYm1, CAYm2)', 'FDPs / STTPs Attended (Days in Yr)',
    'MOOCs / SWAYAM Certifications Completed', 'Books / Chapters Published (ISBN)', 'Sponsored Research Projects Count'
];

$facultyRows = [
    ['Dr. Rajesh Sharma', 'FAC101', 'Male', '2008-07-15', '1975-04-12', '24,00,000', 'Professor', 'No', 18, 0, '7,50,000', '3,50,000', 240, 0, 'Ph.D', 'Full time', 'India', 'Maharashtra', 3, 5, 12, 4, 'Computer Engineering', 'AICTE-1-101', 'Yes', 'Yes', '', 'Yes', 12, 3, 2, 2],
    ['Dr. Priya Nair', 'FAC102', 'Female', '2012-08-01', '1982-11-20', '18,50,000', 'Associate Professor', 'No', 12, 0, '4,50,000', '2,00,000', 165, 0, 'Ph.D', 'Full time', 'India', 'Kerala', 2, 3, 8, 2, 'Information Technology', 'AICTE-1-102', 'Yes', 'Yes', '', 'Yes', 10, 2, 1, 1],
    ['Dr. Amit Patel', 'FAC103', 'Male', '2015-01-10', '1985-06-18', '14,00,000', 'Associate Professor', 'No', 9, 0, '2,50,000', '1,20,000', 98, 0, 'Ph.D', 'Full time', 'India', 'Gujarat', 1, 2, 5, 2, 'Mechanical Engineering', 'AICTE-1-103', 'Yes', 'Yes', '', 'Yes', 8, 2, 1, 1],
    ['Prof. Sunita Deshmukh', 'FAC104', 'Female', '2019-07-22', '1990-09-05', '10,50,000', 'Assistant Professor', 'No', 5, 0, '1,00,000', '50,000', 42, 0, 'Ph.D', 'Full time', 'India', 'Maharashtra', 0, 1, 3, 0, 'Computer Engineering', 'AICTE-1-104', 'Yes', 'Yes', '', 'Yes', 14, 4, 0, 0],
    ['Dr. Vikram Singh', 'FAC105', 'Male', '2005-09-10', '1971-02-14', '26,00,000', 'Professor', 'No', 22, 0, '9,00,000', '4,50,000', 310, 0, 'Ph.D', 'Full time', 'India', 'Rajasthan', 4, 6, 16, 5, 'Electronics & Telecomm', 'AICTE-1-105', 'Yes', 'Yes', '', 'Yes', 15, 2, 3, 3],
    ['Prof. Ananya Roy', 'FAC106', 'Female', '2021-06-15', '1993-12-08', '8,50,000', 'Assistant Professor', 'No', 3, 0, '50,000', '25,000', 18, 0, 'M.Tech', 'Full time', 'India', 'West Bengal', 0, 1, 1, 0, 'Information Technology', 'AICTE-1-106', 'Yes', 'Yes', '', 'Yes', 7, 2, 0, 0],
    ['Dr. Mohammed Al-Mansoor', 'FAC107', 'Male', '2014-03-01', '1980-08-25', '19,50,000', 'Associate Professor', 'No', 11, 0, '4,00,000', '1,80,000', 145, 0, 'Ph.D', 'Full time', 'UAE', 'Dubai', 1, 2, 7, 1, 'Computer Engineering', 'AICTE-1-107', 'Yes', 'Yes', '', 'Yes', 10, 1, 1, 1],
    ['Prof. Rohan Kulkarni', 'FAC108', 'Male', '2022-08-01', '1995-03-30', '7,50,000', 'Assistant Professor', 'No', 2, 0, '0', '0', 12, 0, 'M.Tech', 'Full time', 'India', 'Maharashtra', 0, 0, 0, 0, 'Civil Engineering', 'AICTE-1-108', 'Yes', 'No', '', 'Yes', 6, 1, 0, 0],
    ['Dr. Sneha Reddy', 'FAC109', 'Female', '2016-11-15', '1986-07-19', '13,50,000', 'Assistant Professor', 'No', 8, 0, '3,00,000', '1,50,000', 85, 0, 'Ph.D', 'Full time', 'India', 'Telangana', 1, 2, 4, 1, 'Biotechnology', 'AICTE-1-109', 'Yes', 'Yes', '', 'Yes', 12, 3, 1, 1],
    ['Prof. Karthik Venkatesh', 'FAC110', 'Male', '2020-01-05', '1992-05-14', '9,20,000', 'Assistant Professor', 'Yes', 4, 0, '1,20,000', '40,000', 34, 0, 'M.E.', 'Full time', 'India', 'Karnataka', 0, 1, 2, 0, 'Mechanical Engineering', 'AICTE-1-110', 'Yes', 'Yes', '', 'Yes', 8, 2, 0, 0],
    ['Dr. David Johnson', 'FAC111', 'Male', '2011-04-18', '1978-10-02', '22,00,000', 'Professor', 'No', 15, 0, '6,00,000', '2,80,000', 210, 0, 'Ph.D', 'Full time', 'USA', 'California', 2, 4, 10, 3, 'Computer Engineering', 'AICTE-1-111', 'Yes', 'Yes', '', 'Yes', 14, 2, 2, 2],
    ['Dr. Kavita Joshi', 'FAC112', 'Female', '2018-07-01', '1988-01-22', '11,50,000', 'Assistant Professor', 'No', 6, 0, '1,80,000', '80,000', 55, 0, 'Ph.D', 'Full time', 'India', 'Maharashtra', 0, 1, 3, 0, 'Information Technology', 'AICTE-1-112', 'Yes', 'Yes', '', 'Yes', 10, 2, 0, 0],
    ['Prof. Harish Kumar', 'FAC113', 'Male', '2023-01-16', '1996-09-11', '7,00,000', 'Assistant Professor', 'No', 1, 0, '0', '0', 6, 0, 'M.Tech', 'Full time', 'India', 'Haryana', 0, 0, 0, 0, 'Electronics & Telecomm', 'AICTE-1-113', 'Yes', 'No', '', 'Yes', 5, 1, 0, 0],
    ['Dr. Meera Iyer', 'FAC114', 'Female', '2007-06-20', '1973-12-15', '25,00,000', 'Professor', 'No', 20, 0, '8,50,000', '4,00,000', 280, 0, 'Ph.D', 'Full time', 'India', 'Tamil Nadu', 3, 5, 14, 4, 'Biotechnology', 'AICTE-1-114', 'Yes', 'Yes', '', 'Yes', 15, 3, 2, 2],
    ['Prof. Suresh Babu', 'FAC115', 'Male', '2024-07-01', '1997-04-05', '6,50,000', 'Assistant Professor', 'No', 1, 0, '0', '0', 4, 0, 'M.Tech', 'Full time', 'India', 'Andhra Pradesh', 0, 0, 0, 0, 'Civil Engineering', 'AICTE-1-115', 'Yes', 'No', '', 'Yes', 5, 0, 0, 0],
    ['Prof. Jean Dupont', 'FAC116', 'Male', '2022-09-01', '1984-06-12', '12,00,000', 'Visiting Faculty', 'No', 4, 0, '1,50,000', '50,000', 35, 0, 'Ph.D', 'Visiting', 'France', 'Paris', 1, 1, 2, 0, 'Design Engineering', 'AICTE-1-116', 'Yes', 'No', '', 'No', 4, 1, 0, 0],
    ['Prof. Neha Gupta', 'FAC117', 'Female', '2023-08-15', '1994-11-28', '6,80,000', 'Lecturer', 'No', 1, 0, '0', '0', 5, 0, 'M.Tech', 'Contract FOR 2 SEM', 'India', 'Uttar Pradesh', 0, 0, 0, 0, 'Computer Engineering', 'AICTE-1-117', 'Yes', 'Yes', '', 'Yes', 6, 1, 0, 0],
    ['Dr. Balaji Sundaram', 'FAC118', 'Male', '2013-10-10', '1981-03-17', '16,50,000', 'Associate Professor', 'No', 10, 0, '3,50,000', '1,60,000', 125, 0, 'Ph.D', 'Full time', 'India', 'Tamil Nadu', 1, 3, 6, 1, 'Mechanical Engineering', 'AICTE-1-118', 'Yes', 'Yes', '', 'Yes', 12, 2, 1, 1],
    ['Dr. Shweta Choudhury', 'FAC119', 'Female', '2017-02-01', '1987-08-09', '12,80,000', 'Assistant Professor', 'No', 7, 0, '2,20,000', '90,000', 68, 0, 'Ph.D', 'Full time', 'India', 'Assam', 0, 2, 3, 0, 'Computer Engineering', 'AICTE-1-119', 'Yes', 'Yes', '', 'Yes', 8, 2, 1, 0],
    ['Dr. Arvind Verma', 'FAC120', 'Male', '2006-03-25', '1974-05-30', '24,50,000', 'Professor', 'No', 19, 0, '8,00,000', '3,80,000', 265, 0, 'Ph.D', 'Full time', 'India', 'Delhi', 3, 5, 13, 3, 'Information Technology', 'AICTE-1-120', 'Yes', 'Yes', '', 'Yes', 14, 3, 2, 2],
];

exportWorkbook($dataDir . '/Faculty_Data_Master_v2', $facultyHeaders, $facultyRows, 'Faculty Multi-Accreditation Master (NIRF, NBA, NAAC, AICTE)');

// -----------------------------------------------------------------------------
// 2. STUDENT DATA MASTER
// -----------------------------------------------------------------------------
$studentHeaders = [
    'Student Name ', 'Gender', 'DOB', 'STRT YEAR OF COURCE', 'GRADUATION YEAR', 'Program', 'Branch and school of',
    'Publications with faculty', 'Self Publications', 'Country', 'STATE', 'PWD', 'CAST', 'Tution Fee Waiver ',
    'CGPA year 1', 'CGPA year 2', 'CGPA year 3', 'CGPA year 4', 'BACKLOCGS ', 'YEARS COMPLETED ',
    'YEARS TOOK TO COMPLETE', 'PLACED OR NOT', 'INTERNSHIPS DONE ', 'SALERY PLACED ON CAMPUS',
    'FEES TOTAL', 'SCHOLARSHIP IN TOTAL ', 'Number of projects done with help of campus and faculties',
    'Number of Book or chapters published specify book or chapters', 'Number Of Research Paper Done',
    'Citations Or Publications In total ', 'Revenu Generated from all publications with help of college',
    'Selected for Higher Studies (Nhs)', 'Graduated in Stipulated Time (Ng)', 'Full Tuition Fee Reimbursement Status (ESCS)',
    'Doctoral Graduation Status (GPHD)', 'University Enrollment / PRN No', 'Admission Entry Type (Regular / Lateral Entry / TFWS / EWS / J&K)',
    'Reserved Category Seat Type (GOI / State Quota)', 'Competitive Exam Qualified (GATE / GRE / CAT / TOEFL / NET)',
    'PO & PSO Attainment Score (NBA Criterion 3 - Out of 3.0)', 'MOOCs / SWAYAM / Value-Added Courses Completed',
    'Extension / NSS / Social Outreach Hours (NAAC 3.4)', 'Sports / Cultural / Hackathon Awards (National/State)',
    'Placement Offer Letter Reference / Employer Name', 'APAAR / ABC ID (AICTE Digilocker Compliance)'
];

$studentRows = [];
$firstNames = ['Aarav', 'Ananya', 'Rohan', 'Sneha', 'Aditya', 'Pooja', 'Vikram', 'Meera', 'Karthik', 'Divya', 'Siddharth', 'Neha', 'Gaurav', 'Tanvi', 'Rahul', 'Isha', 'Varun', 'Kriti', 'Arjun', 'Riya', 'Sameer', 'Shreya', 'Manish', 'Anjali', 'Kunal'];
$lastNames = ['Sharma', 'Patel', 'Kulkarni', 'Iyer', 'Reddy', 'Deshmukh', 'Verma', 'Singh', 'Nair', 'Joshi', 'Gupta', 'Roy', 'Choudhury', 'Babu', 'Kumar'];
$programs = ['UG', 'UG', 'UG', 'UG', 'UG', 'UG', 'PG', 'Ph.D'];
$states = ['Maharashtra', 'Maharashtra', 'Maharashtra', 'Gujarat', 'Karnataka', 'Rajasthan', 'Delhi', 'Telangana', 'Tamil Nadu', 'Madhya Pradesh', 'Kerala'];

for ($i = 1; $i <= 100; $i++) {
    $fn = $firstNames[($i * 3 + 1) % count($firstNames)];
    $ln = $lastNames[($i * 5 + 2) % count($lastNames)];
    $gender = ($i % 3 === 0 || $i % 7 === 0) ? 'Female' : 'Male';
    $prog = $programs[$i % count($programs)];
    $isPlaced = ($prog === 'UG' && $i % 4 !== 0) ? 'Yes' : 'No';
    $sal = ($isPlaced === 'Yes') ? (450000 + ($i % 12) * 50000) : 0;
    $isHs = ($isPlaced === 'No' && $prog === 'UG' && $i % 5 === 0) ? 'Yes' : 'No';
    $isDoctoralGrad = ($prog === 'Ph.D' && $i % 2 === 0) ? 'Yes' : 'No';
    $country = ($i === 15 || $i === 42 || $i === 78) ? 'Nepal' : (($i === 92) ? 'Bangladesh' : 'India');
    $st = ($country === 'India') ? $states[$i % count($states)] : 'International';
    $feeReimb = ($i % 6 === 0) ? 'Yes' : 'No';

    $studentRows[] = [
        "{$fn} {$ln}", $gender, '2002-05-14', 2022, 2026, $prog, 'Computer Science & Engineering',
        ($i % 5 === 0 ? 1 : 0), ($i % 8 === 0 ? 1 : 0), $country, $st, 'No', ($i % 4 === 0 ? 'OBC' : 'Open'),
        $feeReimb, '8.2', '8.4', '8.6', '8.5', 0, 4, 4, $isPlaced, ($i % 2 === 0 ? 2 : 1), $sal,
        '1,80,000', ($feeReimb === 'Yes' ? '1,80,000' : '0'), 3, 0, ($i % 5 === 0 ? 1 : 0), ($i % 5 === 0 ? 5 : 0), 0,
        $isHs, 'Yes', $feeReimb, $isDoctoralGrad, "PRN2022" . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
        'Regular', 'State Quota', ($i % 7 === 0 ? 'GATE' : 'None'), '2.65', 3, 40, ($i % 10 === 0 ? 1 : 0),
        ($isPlaced === 'Yes' ? 'TCS / Infosys / Capgemini' : ''), "ABC-" . (10000000 + $i)
    ];
}

exportWorkbook($dataDir . '/Student_Data_Master_v2', $studentHeaders, $studentRows, 'Student Multi-Accreditation Master (NIRF, NBA, NAAC, AICTE)');

// -----------------------------------------------------------------------------
// 3. FINANCIAL & INFRASTRUCTURE DATA MASTER
// -----------------------------------------------------------------------------
$financeHeaders = [
    'Lab Spendings (Total 3 years)', 'Classroom Spends (3years)', 'Publication and Research Spends \ Funds Raised',
    'Sanitization and Providing Spends', 'Placement and PR Spend', 'Light Bills',
    'Sallery of teachers and lab assistents and everyone in total',
    'Project Building and club funds with total number or projects and clubs',
    'Building and Construction Cost', 'Hostel and Allied management cost', 'Maintainence of campus spend',
    'Exper Sessions', 'Visiting payments', 'Event Spends with count of events', 'Financial Year Label',
    'Lab Equipment & Capital Spend (Annual INR)', 'Library Books, E-Journals & Subscriptions (Capital INR)',
    'Software Licenses & Computing Capital Spend (INR)', 'Sanctioned Approved Intake UG+PG (NT)',
    'PCS Facilities Score (Ramps, Lifts, Braille, Restrooms - Out of 20)', 'Total Sanctioned Faculty Posts (NAAC 2.4.1 / AICTE)',
    'Total Annual Institutional Budget Allocation (NBA 6.1 / NAAC 4.1.4)', 'Seed Money Provided to Faculty for Research (NAAC 3.1.2)',
    'Financial Support to Faculty for Conferences/FDPs (NAAC 6.3.2)', 'ICT-Enabled Classrooms & Smart Labs Count (NAAC 4.1.3)',
    'Student Computers Available for Academic Use (NAAC 4.3.2 / AICTE)', 'Internet Bandwidth in Mbps / Gbps (NAAC 4.3.3 / AICTE)',
    'Active Functional Industry MoUs Count (NAAC 3.5.2 / AICTE-CII)'
];

$financeRows = [
    [
        '3,50,00,000', '1,20,00,000', '85,00,000', '45,00,000', '35,00,000', '1,10,00,000',
        '24,50,00,000', '65,00,000', '12,00,00,000', '4,50,00,000', '1,80,00,000', '25,00,000',
        '30,00,000', '75,00,000', '2023-24 to 2025-26 (3 Years Consolidated)',
        '1,25,00,000', '35,00,000', '60,00,000', 720, 19, 120, '35,00,00,000', '25,00,000',
        '15,00,000', 48, 850, '1 Gbps', 34
    ]
];

exportWorkbook($dataDir . '/Money_and_Institute_Master_v2', $financeHeaders, $financeRows, 'Financial & Infrastructure Multi-Accreditation Master');

echo "Successfully generated all 3 Master Workbooks in .xlsx and .csv formats inside: {$dataDir}\n";

function exportWorkbook(string $basePath, array $headers, array $rows, string $title): void
{
    // Write XLSX
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Sheet1');

    $sheet->fromArray([$headers], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    // Style Header Row (ADYPU Maroon)
    $highestCol = $sheet->getHighestColumn();
    $headerRange = "A1:{$highestCol}1";
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('7B1113');
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Auto-fit columns up to max 35
    $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
    for ($colIndex = 1; $colIndex <= $highestColIndex; $colIndex++) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
        $sheet->getColumnDimension($col)->setWidth(20);
    }
    $sheet->freezePane('A2');

    $writer = new Xlsx($ss);
    $writer->save($basePath . '.xlsx');

    // Write CSV
    $csvWriter = new Csv($ss);
    $csvWriter->setUseBOM(true);
    $csvWriter->save($basePath . '.csv');
}
