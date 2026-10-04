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
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet
|--------------------------------------------------------------------------
*/

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    die('PhpSpreadsheet autoloader was not found.');
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Clean Excel Filename
|--------------------------------------------------------------------------
*/

function cleanExcelFilename(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '/[\\\\\/:\*?"<>|]+/',
        '',
        $value
    ) ?? '';

    $value = preg_replace(
        '/\s+/',
        '_',
        $value
    ) ?? '';

    return trim(
        $value,
        '._ '
    );
}

/*
|--------------------------------------------------------------------------
| Get Filters
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) (
        $_GET['search'] ?? ''
    )
);

$grade = (int) (
    $_GET['grade'] ?? 0
);

$section = strtoupper(
    trim(
        (string) (
            $_GET['section'] ?? ''
        )
    )
);

$selectedAcademicYearId = (int) (
    $_GET['academic_year_id'] ?? 0
);

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/

$academicYears = [];

$activeAcademicYear = null;

$selectedAcademicYear = null;

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            status
        FROM academic_years
        ORDER BY id DESC
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare academic years query.'
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $academicYears[] = $row;

        if (
            strtolower(
                trim(
                    (string) $row['status']
                )
            ) === 'active'
        ) {

            $activeAcademicYear = $row;
        }
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS subject teacher export academic years error: ' .
        $e->getMessage()
    );

    die(
        'Unable to load academic years.'
    );
}

/*
|--------------------------------------------------------------------------
| Select Academic Year
|--------------------------------------------------------------------------
*/

if (
    $selectedAcademicYearId <= 0 &&
    $activeAcademicYear
) {

    $selectedAcademicYearId =
        (int) $activeAcademicYear['id'];
}

/*
|--------------------------------------------------------------------------
| Find Selected Academic Year
|--------------------------------------------------------------------------
*/

foreach (
    $academicYears as $academicYear
) {

    if (
        (int) $academicYear['id'] ===
        $selectedAcademicYearId
    ) {

        $selectedAcademicYear =
            $academicYear;

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Fallback To Active Academic Year
|--------------------------------------------------------------------------
*/

if (
    !$selectedAcademicYear &&
    $activeAcademicYear
) {

    $selectedAcademicYear =
        $activeAcademicYear;

    $selectedAcademicYearId =
        (int) $activeAcademicYear['id'];
}

if (!$selectedAcademicYear) {

    die(
        'The selected academic year could not be found.'
    );
}

/*
|--------------------------------------------------------------------------
| Academic Year Name
|--------------------------------------------------------------------------
*/

$academicYearName =
    (string) $selectedAcademicYear['name'];

/*
|--------------------------------------------------------------------------
| Build WHERE Conditions
|--------------------------------------------------------------------------
|
| Only active subject-teacher assignments belonging to
| currently active teachers are exported.
|
*/

$where = [
    "sta.academic_year = ?",
    "sta.is_active = 1",
    "t.employment_status = 'Active'"
];

$types = 's';

$params = [
    $academicYearName
];

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "(
        u.full_name LIKE ?
        OR u.email LIKE ?
        OR u.phone LIKE ?
        OR gs.subject_name LIKE ?
        OR CAST(sta.grade AS CHAR) LIKE ?
        OR sta.section LIKE ?
    )";

    $searchValue =
        '%' . $search . '%';

    $types .= 'ssssss';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($grade > 0) {

    $where[] =
        "sta.grade = ?";

    $types .= 'i';

    $params[] =
        $grade;
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($section !== '') {

    $where[] =
        "sta.section = ?";

    $types .= 's';

    $params[] =
        $section;
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Get Subject Teacher Assignments
|--------------------------------------------------------------------------
|
| Correct relationships:
|
| subject_teacher_assignments.teacher_user_id
|                 ↓
| users.id
|
| subject_teacher_assignments.teacher_user_id
|                 ↓
| teachers.user_id
|
| subject_teacher_assignments.grade_subject_id
|                 ↓
| grade_subjects.id
|
| Only teachers with employment_status = Active are included.
|
*/

$assignments = [];

try {

    $sql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            gs.subject_name,
            u.full_name AS teacher_name

        FROM subject_teacher_assignments sta

        INNER JOIN users u
            ON u.id = sta.teacher_user_id

        INNER JOIN teachers t
            ON t.user_id = sta.teacher_user_id

        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id

        WHERE {$whereSql}

        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC,
            u.full_name ASC
    ";

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {

        throw new RuntimeException(
            'Unable to prepare subject teacher export query.'
        );
    }

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row = $result->fetch_assoc()
    ) {

        $assignments[] =
            $row;
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS subject teacher export query error: ' .
        $e->getMessage()
    );

    die(
        'Unable to load subject teacher assignments.'
    );
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$ethiopianDate =
    (string) (
        $todayEthiopian['formatted']
        ?? ''
    );

$ethiopianDateAm =
    (string) (
        $todayEthiopian['formatted_am']
        ?? ''
    );

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet =
    new Spreadsheet();

$sheet =
    $spreadsheet->getActiveSheet();

$sheet->setTitle(
    'Subject Teachers'
);

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    'A1:E1'
);

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->getStyle(
    'A1:E1'
)->getFont()
    ->setBold(true)
    ->setSize(16);

$sheet->getStyle(
    'A1:E1'
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

$sheet->getStyle(
    'A1:E1'
)->getAlignment()
    ->setVertical(
        Alignment::VERTICAL_CENTER
    );

$sheet->getRowDimension(1)
    ->setRowHeight(28);

/*
|--------------------------------------------------------------------------
| Report Subtitle
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    'A2:E2'
);

$sheet->setCellValue(
    'A2',
    'ACTIVE SUBJECT TEACHER ASSIGNMENTS'
);

$sheet->getStyle(
    'A2:E2'
)->getFont()
    ->setBold(true)
    ->setSize(13);

$sheet->getStyle(
    'A2:E2'
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    'A3:E3'
);

$sheet->setCellValue(
    'A3',
    'Academic Year: ' .
    $academicYearName .
    ' | Employment Status: Active'
);

$sheet->getStyle(
    'A3:E3'
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

$sheet->getStyle(
    'A3:E3'
)->getFont()
    ->setBold(true);

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    'A4:E4'
);

$sheet->setCellValue(
    'A4',
    'Generated: ' .
    $ethiopianDate
);

$sheet->getStyle(
    'A4:E4'
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

/*
|--------------------------------------------------------------------------
| Amharic Ethiopian Date
|--------------------------------------------------------------------------
*/

if ($ethiopianDateAm !== '') {

    $sheet->mergeCells(
        'A5:E5'
    );

    $sheet->setCellValue(
        'A5',
        'ቀን፦ ' .
        $ethiopianDateAm
    );

    $sheet->getStyle(
        'A5:E5'
    )->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );
}

/*
|--------------------------------------------------------------------------
| Table Header
|--------------------------------------------------------------------------
*/

$headerRow = 7;

$headers = [
    'No.',
    'Grade',
    'Section',
    'Subject',
    'Teacher Name'
];

foreach (
    $headers as $columnIndex => $header
) {

    $column =
        chr(
            ord('A') +
            $columnIndex
        );

    $sheet->setCellValue(
        $column . $headerRow,
        $header
    );
}

/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$headerStyle =
    $sheet->getStyle(
        'A7:E7'
    );

$headerStyle
    ->getFont()
    ->setBold(true);

$headerStyle
    ->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

$headerStyle
    ->getAlignment()
    ->setVertical(
        Alignment::VERTICAL_CENTER
    );

$headerStyle
    ->getFill()
    ->setFillType(
        Fill::FILL_SOLID
    )
    ->getStartColor()
    ->setARGB('E5E7EB');

$headerStyle
    ->getBorders()
    ->getAllBorders()
    ->setBorderStyle(
        Border::BORDER_THIN
    );

/*
|--------------------------------------------------------------------------
| Write Data
|--------------------------------------------------------------------------
*/

$rowNumber = 8;

foreach (
    $assignments as $index => $row
) {

    $classGrade =
        (int) (
            $row['grade'] ?? 0
        );

    $classSection =
        strtoupper(
            (string) (
                $row['section'] ?? ''
            )
        );

    $subjectName =
        (string) (
            $row['subject_name']
            ?? 'Unknown Subject'
        );

    $teacherName =
        (string) (
            $row['teacher_name']
            ?? 'Unknown Teacher'
        );

    /*
    |--------------------------------------------------------------------------
    | No.
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'A' . $rowNumber,
        $index + 1
    );

    /*
    |--------------------------------------------------------------------------
    | Grade
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'B' . $rowNumber,
        $classGrade
    );

    /*
    |--------------------------------------------------------------------------
    | Section
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'C' . $rowNumber,
        $classSection
    );

    /*
    |--------------------------------------------------------------------------
    | Subject
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'D' . $rowNumber,
        $subjectName
    );

    /*
    |--------------------------------------------------------------------------
    | Teacher Name
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'E' . $rowNumber,
        $teacherName
    );

    $rowNumber++;
}

/*
|--------------------------------------------------------------------------
| Table Styling
|--------------------------------------------------------------------------
*/

$lastDataRow =
    max(
        7,
        $rowNumber - 1
    );

$sheet->getStyle(
    'A7:E' . $lastDataRow
)->getBorders()
    ->getAllBorders()
    ->setBorderStyle(
        Border::BORDER_THIN
    );

$sheet->getStyle(
    'A8:A' . $lastDataRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

$sheet->getStyle(
    'B8:C' . $lastDataRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

$sheet->getStyle(
    'A7:E' . $lastDataRow
)->getAlignment()
    ->setVertical(
        Alignment::VERTICAL_CENTER
    );

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')
    ->setWidth(8);

$sheet->getColumnDimension('B')
    ->setWidth(12);

$sheet->getColumnDimension('C')
    ->setWidth(12);

$sheet->getColumnDimension('D')
    ->setWidth(32);

$sheet->getColumnDimension('E')
    ->setWidth(35);

/*
|--------------------------------------------------------------------------
| Freeze Header
|--------------------------------------------------------------------------
*/

$sheet->freezePane(
    'A8'
);

/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

$sheet->setAutoFilter(
    'A7:E' . $lastDataRow
);

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

$sheet->getPageSetup()
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

$sheet->getPageSetup()
    ->setFitToWidth(1);

$sheet->getPageSetup()
    ->setFitToHeight(0);

$sheet->getPageMargins()
    ->setTop(0.5);

$sheet->getPageMargins()
    ->setRight(0.5);

$sheet->getPageMargins()
    ->setBottom(0.5);

$sheet->getPageMargins()
    ->setLeft(0.5);

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$footerRow =
    $lastDataRow + 2;

$sheet->mergeCells(
    'A' . $footerRow . ':E' . $footerRow
);

$sheet->setCellValue(
    'A' . $footerRow,
    'Total Active Subject Teacher Assignments: ' .
    number_format(
        count($assignments)
    )
);

$sheet->getStyle(
    'A' . $footerRow . ':E' . $footerRow
)->getFont()
    ->setBold(true);

$sheet->getStyle(
    'A' . $footerRow . ':E' . $footerRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_RIGHT
    );

/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$filenameParts = [
    'BKHS',
    cleanExcelFilename($academicYearName)
];

if ($grade > 0) {

    $filenameParts[] =
        'Grade_' . $grade;
}

if ($section !== '') {

    $filenameParts[] =
        'Section_' . $section;
}

$filenameParts[] =
    'Active_Subject_Teachers';

$filename =
    implode(
        '_',
        array_filter(
            $filenameParts
        )
    ) . '.xlsx';

/*
|--------------------------------------------------------------------------
| HTTP Headers
|--------------------------------------------------------------------------
*/

while (
    ob_get_level() > 0
) {

    ob_end_clean();
}

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

header(
    'Cache-Control: max-age=0'
);

header(
    'Expires: 0'
);

header(
    'Pragma: public'
);

/*
|--------------------------------------------------------------------------
| Output Excel
|--------------------------------------------------------------------------
*/

$writer =
    new Xlsx($spreadsheet);

$writer->save(
    'php://output'
);

exit;
