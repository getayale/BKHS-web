<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Principal Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet
|--------------------------------------------------------------------------
|
| Install once from the BKHS project root:
|
| composer require phpoffice/phpspreadsheet
|
*/

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    http_response_code(500);

    exit(
        'PhpSpreadsheet is not installed. ' .
        'Run "composer require phpoffice/phpspreadsheet" ' .
        'from the BKHS project root.'
    );
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
|--------------------------------------------------------------------------
| Read Filters
|--------------------------------------------------------------------------
*/

$selectedAcademicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$selectedSemesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

$selectedGrade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$selectedSection = trim(
    (string) ($_GET['section'] ?? '')
);

$selectedSubjectId = isset($_GET['subject_id'])
    ? (int) $_GET['subject_id']
    : 0;

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$searchPattern = '%' . $search . '%';

/*
|--------------------------------------------------------------------------
| Validate Required Filters
|--------------------------------------------------------------------------
*/

if (
    $selectedAcademicYearId <= 0 ||
    $selectedSemesterId <= 0 ||
    $selectedGrade <= 0 ||
    $selectedSection === '' ||
    $selectedSubjectId <= 0
) {
    http_response_code(400);

    exit('Invalid result export filters.');
}

/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/

$academicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare academic year query.');
}

$stmt->bind_param(
    'i',
    $selectedAcademicYearId
);

$stmt->execute();

$result = $stmt->get_result();

$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    http_response_code(404);
    exit('Academic year not found.');
}

/*
|--------------------------------------------------------------------------
| Semester
|--------------------------------------------------------------------------
|
| Supports:
| - Mid Semester
| - First Semester
| - Quarter Semester
| - Second Semester
|
| No semester start/end dates are exported.
|--------------------------------------------------------------------------
*/

$semester = null;

$stmt = $conn->prepare("
    SELECT
        id,
        academic_year_id,
        name,
        order_number,
        status
    FROM semesters
    WHERE id = ?
      AND academic_year_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare semester query.');
}

$stmt->bind_param(
    'ii',
    $selectedSemesterId,
    $selectedAcademicYearId
);

$stmt->execute();

$result = $stmt->get_result();

$semester = $result->fetch_assoc();

$stmt->close();

if (!$semester) {
    http_response_code(404);
    exit('Semester not found.');
}

/*
|--------------------------------------------------------------------------
| Grade
|--------------------------------------------------------------------------
*/

$gradeName = 'Grade ' . $selectedGrade;

$stmt = $conn->prepare("
    SELECT
        id,
        grade_number,
        name
    FROM grades
    WHERE grade_number = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $selectedGrade
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($grade = $result->fetch_assoc()) {
        $gradeName = (string) $grade['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Subject
|--------------------------------------------------------------------------
*/

$subjectName = '';

$stmt = $conn->prepare("
    SELECT
        id,
        subject_name
    FROM grade_subjects
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare subject query.');
}

$stmt->bind_param(
    'i',
    $selectedSubjectId
);

$stmt->execute();

$result = $stmt->get_result();

if ($subject = $result->fetch_assoc()) {
    $subjectName = (string) $subject['subject_name'];
}

$stmt->close();

if ($subjectName === '') {
    http_response_code(404);
    exit('Subject not found.');
}

/*
|--------------------------------------------------------------------------
| Get Students + Results
|--------------------------------------------------------------------------
|
| IMPORTANT:
| No LIMIT/OFFSET here.
| Export contains ALL students matching the filters.
|--------------------------------------------------------------------------
*/

$students = [];

$stmt = $conn->prepare("
    SELECT
        sr.id AS registration_id,
        s.id AS student_id,
        s.student_code,
        s.full_name,

        r.id AS result_id,
        r.mark

    FROM student_registrations sr

    INNER JOIN students s
        ON s.id = sr.student_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    LEFT JOIN results r
        ON r.student_registration_id = sr.id
       AND r.grade_subject_id = ?
       AND r.semester_id = ?

    WHERE sr.academic_year_id = ?
      AND g.grade_number = ?
      AND sec.code = ?
      AND s.full_name LIKE ?

    ORDER BY
        s.full_name ASC,
        sr.id ASC
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare student results query.');
}

$stmt->bind_param(
    'iiiiss',
    $selectedSubjectId,
    $selectedSemesterId,
    $selectedAcademicYearId,
    $selectedGrade,
    $selectedSection,
    $searchPattern
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator('Bole Kale Hiwot School')
    ->setLastModifiedBy('Bole Kale Hiwot School')
    ->setTitle('Student Results')
    ->setSubject('Student Academic Results')
    ->setDescription(
        'Student academic results exported from BKHS School Management System.'
    );

/*
|--------------------------------------------------------------------------
| Active Worksheet
|--------------------------------------------------------------------------
*/

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Results');

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')->setWidth(8);
$sheet->getColumnDimension('B')->setWidth(32);
$sheet->getColumnDimension('C')->setWidth(24);
$sheet->getColumnDimension('D')->setWidth(18);

/*
|--------------------------------------------------------------------------
| School Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:D1');

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 18,
    ],

    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(30);

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A2:D2');

$sheet->setCellValue(
    'A2',
    'STUDENT RESULTS'
);

$sheet->getStyle('A2')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 14,
    ],

    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(2)->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Information Section
|--------------------------------------------------------------------------
*/

$sheet->setCellValue(
    'A4',
    'Academic Year'
);

$sheet->setCellValue(
    'B4',
    (string) $academicYear['name']
);

$sheet->setCellValue(
    'C4',
    'Semester'
);

$sheet->setCellValue(
    'D4',
    (string) $semester['name']
);

$sheet->setCellValue(
    'A5',
    'Grade'
);

$sheet->setCellValue(
    'B5',
    $gradeName
);

$sheet->setCellValue(
    'C5',
    'Section'
);

$sheet->setCellValue(
    'D5',
    $selectedSection
);

$sheet->setCellValue(
    'A6',
    'Subject'
);

$sheet->setCellValue(
    'B6',
    $subjectName
);

$sheet->mergeCells('B6:D6');

/*
|--------------------------------------------------------------------------
| Information Styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle('A4:D6')->applyFromArray([

    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],

    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getStyle('A4:A6')->applyFromArray([
    'font' => [
        'bold' => true,
    ],
]);

$sheet->getStyle('C4:C5')->applyFromArray([
    'font' => [
        'bold' => true,
    ],
]);

/*
|--------------------------------------------------------------------------
| Table Header
|--------------------------------------------------------------------------
*/

$tableHeaderRow = 8;

$sheet->setCellValue(
    "A{$tableHeaderRow}",
    'No.'
);

$sheet->setCellValue(
    "B{$tableHeaderRow}",
    'Student'
);

$sheet->setCellValue(
    "C{$tableHeaderRow}",
    'Student Code'
);

$sheet->setCellValue(
    "D{$tableHeaderRow}",
    'Mark'
);

/*
|--------------------------------------------------------------------------
| Table Header Styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A{$tableHeaderRow}:D{$tableHeaderRow}"
)->applyFromArray([

    'font' => [
        'bold' => true,
    ],

    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],

    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],

    'fill' => [
        'fillType' => Fill::FILL_SOLID,

        'color' => [
            'rgb' => 'E5E7EB',
        ],
    ],
]);

$sheet->getRowDimension(
    $tableHeaderRow
)->setRowHeight(23);

/*
|--------------------------------------------------------------------------
| Student Rows
|--------------------------------------------------------------------------
*/

$currentRow = $tableHeaderRow + 1;

foreach ($students as $index => $student) {

    $studentNumber = $index + 1;

    /*
    |--------------------------------------------------------------------------
    | No.
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "A{$currentRow}",
        $studentNumber
    );

    /*
    |--------------------------------------------------------------------------
    | Student Name
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "B{$currentRow}",
        (string) $student['full_name']
    );

    /*
    |--------------------------------------------------------------------------
    | Student Code
    |--------------------------------------------------------------------------
    |
    | Explicit string prevents Excel from changing the code format.
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValueExplicit(
        "C{$currentRow}",
        (string) $student['student_code'],
        DataType::TYPE_STRING
    );

    /*
    |--------------------------------------------------------------------------
    | Mark
    |--------------------------------------------------------------------------
    |
    | Recorded marks remain numeric.
    | General format prevents unnecessary decimal zeros.
    |
    | Examples:
    | 80     -> 80
    | 80.5   -> 80.5
    | 80.25  -> 80.25
    | 100    -> 100
    |
    | Missing marks become "Not Recorded".
    |--------------------------------------------------------------------------
    */

    if (
        $student['mark'] !== null &&
        $student['mark'] !== '' &&
        is_numeric($student['mark'])
    ) {

        $mark = (float) $student['mark'];

        $sheet->setCellValue(
            "D{$currentRow}",
            $mark
        );

        /*
        |--------------------------------------------------------------------------
        | Important:
        | Use General instead of 0.## so Excel does not force
        | unnecessary decimal formatting.
        |--------------------------------------------------------------------------
        */

        $sheet->getStyle(
            "D{$currentRow}"
        )->getNumberFormat()
          ->setFormatCode('General');

    } else {

        $sheet->setCellValue(
            "D{$currentRow}",
            'Not Recorded'
        );
    }

    $currentRow++;
}

/*
|--------------------------------------------------------------------------
| Table Borders & Alignment
|--------------------------------------------------------------------------
*/

if ($currentRow > $tableHeaderRow + 1) {

    $lastDataRow = $currentRow - 1;

    $sheet->getStyle(
        "A{$tableHeaderRow}:D{$lastDataRow}"
    )->applyFromArray([

        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],

        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    /*
    |--------------------------------------------------------------------------
    | No. Alignment
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        "A" . ($tableHeaderRow + 1) . ":A{$lastDataRow}"
    )->getAlignment()
      ->setHorizontal(
          Alignment::HORIZONTAL_CENTER
      );

    /*
    |--------------------------------------------------------------------------
    | Student Code + Mark Alignment
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        "C" . ($tableHeaderRow + 1) . ":D{$lastDataRow}"
    )->getAlignment()
      ->setHorizontal(
          Alignment::HORIZONTAL_CENTER
      );
}

/*
|--------------------------------------------------------------------------
| No Students
|--------------------------------------------------------------------------
*/

if (empty($students)) {

    $emptyRow = $tableHeaderRow + 1;

    $sheet->mergeCells(
        "A{$emptyRow}:D{$emptyRow}"
    );

    $sheet->setCellValue(
        "A{$emptyRow}",
        'No students found for the selected filters.'
    );

    $sheet->getStyle(
        "A{$emptyRow}:D{$emptyRow}"
    )->applyFromArray([

        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],

        'font' => [
            'italic' => true,
        ],

        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
    ]);

    $currentRow++;
}

/*
|--------------------------------------------------------------------------
| Freeze Header
|--------------------------------------------------------------------------
*/

$sheet->freezePane('A9');

/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

$lastTableRow = max(
    $tableHeaderRow,
    $currentRow - 1
);

$sheet->setAutoFilter(
    "A{$tableHeaderRow}:D{$lastTableRow}"
);

/*
|--------------------------------------------------------------------------
| Print Settings
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    )
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    )
    ->setFitToWidth(1)
    ->setFitToHeight(0);

$sheet->getPageMargins()
    ->setTop(0.5)
    ->setRight(0.4)
    ->setLeft(0.4)
    ->setBottom(0.5);

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$sheet->getHeaderFooter()
    ->setOddFooter(
        '&LGenerated by BKHS School Management System&RPage &P of &N'
    );

/*
|--------------------------------------------------------------------------
| Generate Safe Filename
|--------------------------------------------------------------------------
*/

$academicYearFile = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    (string) $academicYear['name']
);

$semesterFile = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    (string) $semester['name']
);

$sectionFile = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    $selectedSection
);

$subjectFile = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    $subjectName
);

$filename =
    'BKHS_Results_' .
    $academicYearFile . '_' .
    $semesterFile . '_' .
    'Grade' . $selectedGrade . '_' .
    $sectionFile . '_' .
    $subjectFile .
    '.xlsx';

/*
|--------------------------------------------------------------------------
| Clean Output Buffer
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {
    ob_end_clean();
}

/*
|--------------------------------------------------------------------------
| Download XLSX
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

header('Cache-Control: max-age=0');

header('Expires: 0');

header('Pragma: public');

/*
|--------------------------------------------------------------------------
| Write Excel File
|--------------------------------------------------------------------------
*/

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;

