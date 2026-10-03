<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Start Output Buffer
|--------------------------------------------------------------------------
|
| Prevent accidental PHP whitespace/warnings from corrupting the XLSX file.
|
*/

ob_start();

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
    ob_end_clean();

    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    ob_end_clean();

    http_response_code(500);
    exit('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet
|--------------------------------------------------------------------------
*/

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    ob_end_clean();

    http_response_code(500);

    exit(
        'PhpSpreadsheet is not installed. ' .
        'Please run: composer require phpoffice/phpspreadsheet'
    );
}

require_once $autoload;

/*
|--------------------------------------------------------------------------
| Imports
|--------------------------------------------------------------------------
*/

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/*
|--------------------------------------------------------------------------
| Get Filters
|--------------------------------------------------------------------------
*/

$academicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$semesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

$gradeId = isset($_GET['grade_id'])
    ? (int) $_GET['grade_id']
    : 0;

$sectionId = isset($_GET['section_id'])
    ? (int) $_GET['section_id']
    : 0;

/*
|--------------------------------------------------------------------------
| Validate Filters
|--------------------------------------------------------------------------
*/

if (
    $academicYearId <= 0 ||
    $semesterId <= 0 ||
    $gradeId <= 0 ||
    $sectionId <= 0
) {
    ob_end_clean();

    http_response_code(400);

    exit(
        'Invalid statistics filters. ' .
        'Academic Year, Semester, Grade and Section are required.'
    );
}

/*
|--------------------------------------------------------------------------
| Helper: Fetch One Row
|--------------------------------------------------------------------------
*/

function fetchOne(
    mysqli $conn,
    string $sql,
    string $types,
    array $params
): ?array {

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Database prepare error: ' . $conn->error
        );
    }

    if (!$stmt->bind_param($types, ...$params)) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Database bind error: ' . $error
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Database execute error: ' . $error
        );
    }

    $result = $stmt->get_result();

    $row = $result
        ? $result->fetch_assoc()
        : null;

    $stmt->close();

    return $row ?: null;
}

/*
|--------------------------------------------------------------------------
| Validate Academic Year
|--------------------------------------------------------------------------
*/

$academicYear = fetchOne(
    $conn,
    "
        SELECT
            id,
            name
        FROM academic_years
        WHERE id = ?
        LIMIT 1
    ",
    'i',
    [$academicYearId]
);

if (!$academicYear) {
    ob_end_clean();

    http_response_code(404);
    exit('Academic year not found.');
}

/*
|--------------------------------------------------------------------------
| Validate Semester
|--------------------------------------------------------------------------
*/

$semester = fetchOne(
    $conn,
    "
        SELECT
            id,
            academic_year_id,
            name,
            max_mark
        FROM semesters
        WHERE id = ?
          AND academic_year_id = ?
          AND LOWER(TRIM(name)) IN (
              'first semester',
              'second semester'
          )
        LIMIT 1
    ",
    'ii',
    [
        $semesterId,
        $academicYearId
    ]
);

if (!$semester) {
    ob_end_clean();

    http_response_code(404);
    exit('Semester not found for the selected academic year.');
}

/*
|--------------------------------------------------------------------------
| Validate Grade
|--------------------------------------------------------------------------
*/

$grade = fetchOne(
    $conn,
    "
        SELECT
            id,
            name,
            grade_number
        FROM grades
        WHERE id = ?
        LIMIT 1
    ",
    'i',
    [$gradeId]
);

if (!$grade) {
    ob_end_clean();

    http_response_code(404);
    exit('Grade not found.');
}

/*
|--------------------------------------------------------------------------
| Validate Section
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We use the sections table directly.
|
| academic_year_grade_sections is currently empty in your database.
|
*/

$section = fetchOne(
    $conn,
    "
        SELECT
            id,
            name,
            code
        FROM sections
        WHERE id = ?
        LIMIT 1
    ",
    'i',
    [$sectionId]
);

if (!$section) {
    ob_end_clean();

    http_response_code(404);
    exit('Section not found.');
}

/*
|--------------------------------------------------------------------------
| Get Subject Statistics
|--------------------------------------------------------------------------
|
| Each subject is calculated separately.
|
| < 50
| 50 - 74
| >= 75
|
*/

$sql = "
    SELECT
        gs.id AS subject_id,
        gs.subject_name,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'male'
             AND r.mark < 50
            THEN s.id
        END) AS below_50_male,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'female'
             AND r.mark < 50
            THEN s.id
        END) AS below_50_female,

        COUNT(DISTINCT CASE
            WHEN r.mark < 50
            THEN s.id
        END) AS below_50_total,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'male'
             AND r.mark >= 50
             AND r.mark < 75
            THEN s.id
        END) AS range_50_74_male,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'female'
             AND r.mark >= 50
             AND r.mark < 75
            THEN s.id
        END) AS range_50_74_female,

        COUNT(DISTINCT CASE
            WHEN r.mark >= 50
             AND r.mark < 75
            THEN s.id
        END) AS range_50_74_total,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'male'
             AND r.mark >= 75
            THEN s.id
        END) AS above_75_male,

        COUNT(DISTINCT CASE
            WHEN LOWER(TRIM(s.gender)) = 'female'
             AND r.mark >= 75
            THEN s.id
        END) AS above_75_female,

        COUNT(DISTINCT CASE
            WHEN r.mark >= 75
            THEN s.id
        END) AS above_75_total

    FROM grade_subjects gs

    LEFT JOIN results r
        ON r.grade_subject_id = gs.id
       AND r.semester_id = ?

    LEFT JOIN student_registrations sr
        ON sr.id = r.student_registration_id
       AND sr.academic_year_id = ?
       AND sr.grade_id = ?
       AND sr.section_id = ?

    LEFT JOIN students s
        ON s.id = sr.student_id
       AND s.is_deleted = 0

    WHERE gs.grade = ?
      AND gs.is_active = 1

    GROUP BY
        gs.id,
        gs.subject_name

    ORDER BY
        gs.subject_name ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    ob_end_clean();

    throw new RuntimeException(
        'Statistics query prepare error: ' . $conn->error
    );
}

$gradeNumber = (int) $grade['grade_number'];

$stmt->bind_param(
    'iiiii',
    $semesterId,
    $academicYearId,
    $gradeId,
    $sectionId,
    $gradeNumber
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    ob_end_clean();

    throw new RuntimeException(
        'Statistics query execute error: ' . $error
    );
}

$result = $stmt->get_result();

$statistics = [];

while ($row = $result->fetch_assoc()) {
    $statistics[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Statistics');

/*
|--------------------------------------------------------------------------
| School Name
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:J1');

$sheet->setCellValue(
    'A1',
    'Bole Kale Hiwot School'
);

$sheet->getStyle('A1:J1')->applyFromArray([
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

$sheet->mergeCells('A2:J2');

$sheet->setCellValue(
    'A2',
    'Subject Performance Statistics'
);

$sheet->getStyle('A2:J2')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 14,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(2)->setRowHeight(25);

/*
|--------------------------------------------------------------------------
| Report Information
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A4:B4');
$sheet->mergeCells('C4:J4');

$sheet->setCellValue('A4', 'Academic Year');
$sheet->setCellValue('C4', (string) $academicYear['name']);

$sheet->mergeCells('A5:B5');
$sheet->mergeCells('C5:J5');

$sheet->setCellValue('A5', 'Semester');
$sheet->setCellValue('C5', (string) $semester['name']);

$sheet->mergeCells('A6:B6');
$sheet->mergeCells('C6:J6');

$sheet->setCellValue('A6', 'Grade');
$sheet->setCellValue('C6', (string) $grade['name']);

$sheet->mergeCells('A7:B7');
$sheet->mergeCells('C7:J7');

$sheet->setCellValue('A7', 'Section');
$sheet->setCellValue('C7', (string) $section['name']);

$sheet->getStyle('A4:B7')->applyFromArray([
    'font' => [
        'bold' => true,
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getStyle('C4:J7')->applyFromArray([
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

/*
|--------------------------------------------------------------------------
| Table Header
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A9:A10');

$sheet->setCellValue(
    'A9',
    'Subject'
);

$sheet->mergeCells('B9:D9');

$sheet->setCellValue(
    'B9',
    '< 50'
);

$sheet->mergeCells('E9:G9');

$sheet->setCellValue(
    'E9',
    '50–74'
);

$sheet->mergeCells('H9:J9');

$sheet->setCellValue(
    'H9',
    '≥ 75'
);

/*
|--------------------------------------------------------------------------
| Second Header Row
|--------------------------------------------------------------------------
*/

$sheet->setCellValue('B10', 'Male');
$sheet->setCellValue('C10', 'Female');
$sheet->setCellValue('D10', 'Total');

$sheet->setCellValue('E10', 'Male');
$sheet->setCellValue('F10', 'Female');
$sheet->setCellValue('G10', 'Total');

$sheet->setCellValue('H10', 'Male');
$sheet->setCellValue('I10', 'Female');
$sheet->setCellValue('J10', 'Total');

/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle('A9:J10')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
        'size' => 11,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '4F46E5',
        ],
    ],
]);

$sheet->getRowDimension(9)->setRowHeight(24);
$sheet->getRowDimension(10)->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Data Rows
|--------------------------------------------------------------------------
*/

$dataStartRow = 11;

$currentRow = $dataStartRow;

$totalBelow50Male = 0;
$totalBelow50Female = 0;
$totalBelow50 = 0;

$total5074Male = 0;
$total5074Female = 0;
$total5074 = 0;

$total75Male = 0;
$total75Female = 0;
$total75 = 0;

foreach ($statistics as $row) {

    $below50Male = (int) $row['below_50_male'];
    $below50Female = (int) $row['below_50_female'];
    $below50Total = (int) $row['below_50_total'];

    $range5074Male = (int) $row['range_50_74_male'];
    $range5074Female = (int) $row['range_50_74_female'];
    $range5074Total = (int) $row['range_50_74_total'];

    $above75Male = (int) $row['above_75_male'];
    $above75Female = (int) $row['above_75_female'];
    $above75Total = (int) $row['above_75_total'];

    /*
    |--------------------------------------------------------------------------
    | Subject
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "A{$currentRow}",
        (string) $row['subject_name']
    );

    /*
    |--------------------------------------------------------------------------
    | < 50
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "B{$currentRow}",
        $below50Male
    );

    $sheet->setCellValue(
        "C{$currentRow}",
        $below50Female
    );

    $sheet->setCellValue(
        "D{$currentRow}",
        $below50Total
    );

    /*
    |--------------------------------------------------------------------------
    | 50–74
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "E{$currentRow}",
        $range5074Male
    );

    $sheet->setCellValue(
        "F{$currentRow}",
        $range5074Female
    );

    $sheet->setCellValue(
        "G{$currentRow}",
        $range5074Total
    );

    /*
    |--------------------------------------------------------------------------
    | >= 75
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        "H{$currentRow}",
        $above75Male
    );

    $sheet->setCellValue(
        "I{$currentRow}",
        $above75Female
    );

    $sheet->setCellValue(
        "J{$currentRow}",
        $above75Total
    );

    /*
    |--------------------------------------------------------------------------
    | Totals
    |--------------------------------------------------------------------------
    */

    $totalBelow50Male += $below50Male;
    $totalBelow50Female += $below50Female;
    $totalBelow50 += $below50Total;

    $total5074Male += $range5074Male;
    $total5074Female += $range5074Female;
    $total5074 += $range5074Total;

    $total75Male += $above75Male;
    $total75Female += $above75Female;
    $total75 += $above75Total;

    $currentRow++;
}

/*
|--------------------------------------------------------------------------
| Total Row
|--------------------------------------------------------------------------
*/

$totalRow = $currentRow;

$sheet->setCellValue(
    "A{$totalRow}",
    'Total'
);

$sheet->setCellValue(
    "B{$totalRow}",
    $totalBelow50Male
);

$sheet->setCellValue(
    "C{$totalRow}",
    $totalBelow50Female
);

$sheet->setCellValue(
    "D{$totalRow}",
    $totalBelow50
);

$sheet->setCellValue(
    "E{$totalRow}",
    $total5074Male
);

$sheet->setCellValue(
    "F{$totalRow}",
    $total5074Female
);

$sheet->setCellValue(
    "G{$totalRow}",
    $total5074
);

$sheet->setCellValue(
    "H{$totalRow}",
    $total75Male
);

$sheet->setCellValue(
    "I{$totalRow}",
    $total75Female
);

$sheet->setCellValue(
    "J{$totalRow}",
    $total75
);

/*
|--------------------------------------------------------------------------
| Data Alignment
|--------------------------------------------------------------------------
*/

if ($currentRow > $dataStartRow) {

    $sheet->getStyle(
        "B{$dataStartRow}:J" . ($totalRow - 1)
    )
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

    $sheet->getStyle(
        "A{$dataStartRow}:A" . ($totalRow - 1)
    )
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_LEFT
        );
}

/*
|--------------------------------------------------------------------------
| Total Row Styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A{$totalRow}:J{$totalRow}"
)->applyFromArray([
    'font' => [
        'bold' => true,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'EDE9FE',
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

/*
|--------------------------------------------------------------------------
| Borders
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A9:J{$totalRow}"
)
    ->getBorders()
    ->getAllBorders()
    ->setBorderStyle(
        Border::BORDER_THIN
    );

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')->setWidth(30);

foreach (
    ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J']
    as $column
) {
    $sheet
        ->getColumnDimension($column)
        ->setWidth(12);
}

/*
|--------------------------------------------------------------------------
| General Alignment
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A4:J{$totalRow}"
)
    ->getAlignment()
    ->setVertical(
        Alignment::VERTICAL_CENTER
    );

/*
|--------------------------------------------------------------------------
| Freeze Panes
|--------------------------------------------------------------------------
*/

$sheet->freezePane('B11');

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()->setOrientation(
    \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
);

$sheet->getPageSetup()->setPaperSize(
    \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
);

$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

$sheet->getPageMargins()->setTop(0.4);
$sheet->getPageMargins()->setBottom(0.4);
$sheet->getPageMargins()->setLeft(0.3);
$sheet->getPageMargins()->setRight(0.3);

/*
|--------------------------------------------------------------------------
| Print Area
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()->setPrintArea(
    "A1:J{$totalRow}"
);

/*
|--------------------------------------------------------------------------
| Excel Properties
|--------------------------------------------------------------------------
*/

$spreadsheet->getProperties()
    ->setCreator('Bole Kale Hiwot School')
    ->setTitle('Subject Performance Statistics')
    ->setSubject('Subject Performance Statistics')
    ->setDescription(
        'Subject performance statistics by grade and section'
    );

/*
|--------------------------------------------------------------------------
| Download Filename
|--------------------------------------------------------------------------
*/

$academicYearName = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    (string) $academicYear['name']
);

$gradeName = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    (string) $grade['name']
);

$sectionName = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    (string) $section['name']
);

$filename =
    'BKHS_Subject_Statistics_' .
    $academicYearName .
    '_' .
    $gradeName .
    '_' .
    $sectionName .
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
| XLSX Headers
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
header('Cache-Control: max-age=1');
header('Expires: 0');
header('Pragma: public');

/*
|--------------------------------------------------------------------------
| Generate XLSX
|--------------------------------------------------------------------------
*/

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

/*
|--------------------------------------------------------------------------
| Cleanup
|--------------------------------------------------------------------------
*/

$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;