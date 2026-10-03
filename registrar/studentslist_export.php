<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Registrar Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';

$autoloadPath = '../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    exit(
        'Composer autoload file not found. Please run "composer install" '
        . 'from the BKHS project root.'
    );
}

require_once $autoloadPath;

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet Imports
|--------------------------------------------------------------------------
*/

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
|--------------------------------------------------------------------------
| Database Charset
|--------------------------------------------------------------------------
*/

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function getIntParam(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

    return $value !== false && $value !== null
        ? (int) $value
        : 0;
}

function cleanExcelFilename(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'All';
    }

    $value = preg_replace('/[\\\\\/:*?"<>|]+/', '', $value) ?? '';

    $value = preg_replace('/\s+/', '_', $value) ?? '';

    return trim($value, '_') !== ''
        ? trim($value, '_')
        : 'All';
}

function getAcademicYear(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $sql = "
        SELECT
            id,
            name,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day,
            status
        FROM academic_years
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row ?: null;
}

function getGrade(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $sql = "
        SELECT
            id,
            name,
            grade_number
        FROM grades
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row ?: null;
}

function getSection(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $sql = "
        SELECT
            id,
            name,
            code
        FROM sections
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row ?: null;
}

/*
|--------------------------------------------------------------------------
| Get Filters
|--------------------------------------------------------------------------
*/

$academicYearId = getIntParam('academic_year_id');
$gradeId = getIntParam('grade_id');
$sectionId = getIntParam('section_id');

/*
|--------------------------------------------------------------------------
| Validate Required Filters
|--------------------------------------------------------------------------
*/

if ($academicYearId <= 0 || $gradeId <= 0 || $sectionId <= 0) {
    http_response_code(400);
    exit(
        'Academic year, grade, and section are required '
        . 'to export the student list.'
    );
}

/*
|--------------------------------------------------------------------------
| Get Selected Filter Information
|--------------------------------------------------------------------------
*/

$academicYear = getAcademicYear($conn, $academicYearId);
$grade = getGrade($conn, $gradeId);
$section = getSection($conn, $sectionId);

if (!$academicYear) {
    http_response_code(404);
    exit('Academic year not found.');
}

if (!$grade) {
    http_response_code(404);
    exit('Grade not found.');
}

if (!$section) {
    http_response_code(404);
    exit('Section not found.');
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$statsSql = "
    SELECT
        COUNT(DISTINCT sr.student_id) AS total_students,

        COUNT(
            DISTINCT CASE
                WHEN LOWER(TRIM(s.gender)) = 'male'
                THEN sr.student_id
            END
        ) AS male_students,

        COUNT(
            DISTINCT CASE
                WHEN LOWER(TRIM(s.gender)) = 'female'
                THEN sr.student_id
            END
        ) AS female_students,

        COUNT(
            DISTINCT CASE
                WHEN LOWER(TRIM(sr.registration_type)) = 'new'
                THEN sr.student_id
            END
        ) AS new_students,

        COUNT(
            DISTINCT CASE
                WHEN LOWER(TRIM(sr.registration_type)) IN (
                    'returning',
                    're-registration',
                    'reregistration',
                    're registration'
                )
                THEN sr.student_id
            END
        ) AS returning_students

    FROM student_registrations sr

    INNER JOIN students s
        ON s.id = sr.student_id

    WHERE sr.academic_year_id = ?
      AND sr.grade_id = ?
      AND sr.section_id = ?
      AND s.is_deleted = 0
";

$statsStmt = $conn->prepare($statsSql);

if (!$statsStmt) {
    http_response_code(500);
    exit('Unable to prepare statistics query.');
}

$statsStmt->bind_param(
    'iii',
    $academicYearId,
    $gradeId,
    $sectionId
);

$statsStmt->execute();

$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

$statsStmt->close();

$totalStudents = (int) ($stats['total_students'] ?? 0);
$maleStudents = (int) ($stats['male_students'] ?? 0);
$femaleStudents = (int) ($stats['female_students'] ?? 0);
$newStudents = (int) ($stats['new_students'] ?? 0);
$returningStudents = (int) ($stats['returning_students'] ?? 0);

/*
|--------------------------------------------------------------------------
| Student List
|--------------------------------------------------------------------------
*/

$studentsSql = "
    SELECT
        s.student_code,
        s.full_name,
        s.gender,
        s.date_of_birth,
        sr.registration_type

    FROM student_registrations sr

    INNER JOIN students s
        ON s.id = sr.student_id

    WHERE sr.academic_year_id = ?
      AND sr.grade_id = ?
      AND sr.section_id = ?
      AND s.is_deleted = 0

    ORDER BY
        s.full_name ASC,
        s.student_code ASC
";

$studentsStmt = $conn->prepare($studentsSql);

if (!$studentsStmt) {
    http_response_code(500);
    exit('Unable to prepare student query.');
}

$studentsStmt->bind_param(
    'iii',
    $academicYearId,
    $gradeId,
    $sectionId
);

$studentsStmt->execute();

$studentsResult = $studentsStmt->get_result();

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator('Bole Kale Hiwot School')
    ->setLastModifiedBy('Bole Kale Hiwot School')
    ->setTitle('BKHS Student List')
    ->setSubject('Student List')
    ->setDescription(
        'Student list for Bole Kale Hiwot School'
    );

/*
|--------------------------------------------------------------------------
| Active Worksheet
|--------------------------------------------------------------------------
*/

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Student List');

/*
|--------------------------------------------------------------------------
| Colors
|--------------------------------------------------------------------------
*/

$darkBlue = '1E3A8A';
$lightBlue = 'DBEAFE';
$borderColor = 'D1D5DB';
$lightGray = 'F3F4F6';

/*
|--------------------------------------------------------------------------
| Report Header
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:F1');

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->getStyle('A1:F1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => $darkBlue,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(28);

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A2:F2');

$sheet->setCellValue(
    'A2',
    'STUDENT LIST'
);

$sheet->getStyle('A2:F2')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 13,
        'color' => [
            'rgb' => $darkBlue,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(2)->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Selected Class Information
|--------------------------------------------------------------------------
*/

$academicYearName = (string) ($academicYear['name'] ?? 'All');
$gradeName = (string) ($grade['name'] ?? 'All');
$sectionName = (string) ($section['name'] ?? 'All');

$sheet->mergeCells('A3:F3');

$sheet->setCellValue(
    'A3',
    'Academic Year: ' . $academicYearName
    . '    |    Grade: ' . $gradeName
    . '    |    Section: ' . $sectionName
);

$sheet->getStyle('A3:F3')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 11,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(3)->setRowHeight(22);

/*
|--------------------------------------------------------------------------
| Statistics Header
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A5:F5');

$sheet->setCellValue(
    'A5',
    'STUDENT SUMMARY'
);

$sheet->getStyle('A5:F5')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => $darkBlue,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(5)->setRowHeight(22);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$summary = [
    'Total Students' => $totalStudents,
    'Male' => $maleStudents,
    'Female' => $femaleStudents,
    'New' => $newStudents,
    'Returning' => $returningStudents,
];

$summaryColumns = ['A', 'B', 'C', 'D', 'E'];

$columnIndex = 0;

foreach ($summary as $label => $value) {
    $column = $summaryColumns[$columnIndex];

    $sheet->setCellValue(
        $column . '6',
        $label
    );

    $sheet->setCellValue(
        $column . '7',
        $value
    );

    $columnIndex++;
}

$sheet->getStyle('A6:E6')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => $darkBlue,
        ],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => $lightBlue,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getStyle('A7:E7')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getStyle('A6:E7')->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => [
                'rgb' => $borderColor,
            ],
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Student Table Header
|--------------------------------------------------------------------------
*/

$headerRow = 9;

$headers = [
    'No.',
    'Student ID',
    'Student Name',
    'Gender',
    'Date of Birth',
    'Registration',
];

foreach ($headers as $index => $header) {
    $column = Coordinate::stringFromColumnIndex($index + 1);

    $sheet->setCellValue(
        $column . $headerRow,
        $header
    );
}

$sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => $darkBlue,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => [
                'rgb' => $borderColor,
            ],
        ],
    ],
]);

$sheet->getRowDimension($headerRow)->setRowHeight(28);

/*
|--------------------------------------------------------------------------
| Student Data
|--------------------------------------------------------------------------
*/

$dataRow = 10;
$number = 1;

while ($student = $studentsResult->fetch_assoc()) {

    $sheet->setCellValue(
        'A' . $dataRow,
        $number
    );

    $sheet->setCellValueExplicit(
        'B' . $dataRow,
        (string) $student['student_code'],
        DataType::TYPE_STRING
    );

    $sheet->setCellValue(
        'C' . $dataRow,
        (string) $student['full_name']
    );

    $sheet->setCellValue(
        'D' . $dataRow,
        (string) $student['gender']
    );

    $sheet->setCellValue(
        'E' . $dataRow,
        (string) $student['date_of_birth']
    );

    $sheet->setCellValue(
        'F' . $dataRow,
        (string) $student['registration_type']
    );

    $dataRow++;
    $number++;
}

$studentsStmt->close();

/*
|--------------------------------------------------------------------------
| Data Table Formatting
|--------------------------------------------------------------------------
*/

$lastDataRow = max($dataRow - 1, $headerRow);

$sheet->getStyle(
    "A{$headerRow}:F{$lastDataRow}"
)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => [
                'rgb' => $borderColor,
            ],
        ],
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

if ($lastDataRow >= 10) {
    $sheet->getStyle(
        "A10:A{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

    $sheet->getStyle(
        "B10:B{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

    $sheet->getStyle(
        "D10:F{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );
}

/*
|--------------------------------------------------------------------------
| Alternating Row Fill
|--------------------------------------------------------------------------
*/

for ($row = 10; $row <= $lastDataRow; $row++) {
    if ($row % 2 === 0) {
        $sheet->getStyle(
            "A{$row}:F{$row}"
        )->getFill()->setFillType(
            Fill::FILL_SOLID
        );

        $sheet->getStyle(
            "A{$row}:F{$row}"
        )->getFill()->getStartColor()->setRGB(
            $lightGray
        );
    }
}

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')->setWidth(8);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(32);
$sheet->getColumnDimension('D')->setWidth(14);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(18);

/*
|--------------------------------------------------------------------------
| Freeze Pane
|--------------------------------------------------------------------------
*/

$sheet->freezePane('A10');

/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

if ($lastDataRow >= $headerRow) {
    $sheet->setAutoFilter(
        "A{$headerRow}:F{$lastDataRow}"
    );
}

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

$sheet->getPageMargins()
    ->setTop(0.5)
    ->setRight(0.35)
    ->setBottom(0.5)
    ->setLeft(0.35);

$sheet->getPageSetup()->setHorizontalCentered(true);

$sheet->getHeaderFooter()
    ->setOddFooter(
        '&LGenerated by BKHS&CPage &P of &N'
    );

/*
|--------------------------------------------------------------------------
| Print Area
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()->setPrintArea(
    "A1:F{$lastDataRow}"
);

/*
|--------------------------------------------------------------------------
| Output Filename
|--------------------------------------------------------------------------
*/

$academicYearFile = cleanExcelFilename(
    $academicYearName
);

$gradeFile = cleanExcelFilename(
    $gradeName
);

$sectionFile = cleanExcelFilename(
    $sectionName
);

$filename =
    'BKHS_'
    . $academicYearFile
    . '_'
    . $gradeFile
    . '_'
    . $sectionFile
    . '_Students_List.xlsx';

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
| Excel Download Headers
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' . $filename . '"'
);

header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');

header(
    'Expires: Mon, 26 Jul 1997 05:00:00 GMT'
);

header(
    'Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'
);

header('Pragma: public');

/*
|--------------------------------------------------------------------------
| Write Excel File
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