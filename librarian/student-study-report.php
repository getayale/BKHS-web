<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Librarian Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'librarian'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function safeInt(mixed $value): int
{
    return filter_var(
        $value,
        FILTER_VALIDATE_INT
    ) !== false
        ? (int) $value
        : 0;
}

function formatEthiopianDate(?string $gregorianDate): string
{
    if (empty($gregorianDate)) {
        return '—';
    }

    try {
        $date = EthiopianCalendar::fromGregorian(
            substr($gregorianDate, 0, 10)
        );

        return $date['formatted'] ?? '—';
    } catch (Throwable $e) {
        return '—';
    }
}

function formatTime(?string $dateTime): string
{
    if (empty($dateTime)) {
        return '—';
    }

    $timestamp = strtotime($dateTime);

    if ($timestamp === false) {
        return '—';
    }

    return date('h:i A', $timestamp);
}

/*
|--------------------------------------------------------------------------
| Create Student Study XLSX Using PhpSpreadsheet
|--------------------------------------------------------------------------
*/

function createStudentStudyXlsx(
    array $rows,
    string $academicYearName,
    string $period,
    string $startGregorian,
    string $endGregorian,
    string $gradeName,
    string $sectionName
): void {

    $spreadsheet = new Spreadsheet();

    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setTitle('Student Study Report');

    /*
    |--------------------------------------------------------------------------
    | Report Title
    |--------------------------------------------------------------------------
    */

    $sheet->mergeCells('A1:J1');

    $sheet->setCellValue(
        'A1',
        'BKHS Student Study Report'
    );

    $sheet->getStyle('A1:J1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 18,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(28);

    /*
    |--------------------------------------------------------------------------
    | Report Information
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'A2',
        'Academic Year'
    );

    $sheet->setCellValue(
        'B2',
        $academicYearName
    );

    $sheet->setCellValue(
        'A3',
        'Report Period'
    );

    $sheet->setCellValue(
        'B3',
        ucfirst($period)
    );

    $sheet->setCellValue(
        'A4',
        'Date Range'
    );

    $sheet->setCellValue(
        'B4',
        formatEthiopianDate($startGregorian) .
        ' - ' .
        formatEthiopianDate($endGregorian)
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
        'A6',
        'Section'
    );

    $sheet->setCellValue(
        'B6',
        $sectionName
    );

    $sheet->getStyle('A2:A6')->getFont()->setBold(true);

    $sheet->getStyle('A2:B6')->getAlignment()->setVertical(
        Alignment::VERTICAL_CENTER
    );

    /*
    |--------------------------------------------------------------------------
    | Table Header
    |--------------------------------------------------------------------------
    */

    $headerRow = 8;

    $headers = [
        'No',
        'Student Code',
        'Student Name',
        'Grade',
        'Section',
        'Book Title',
        'Study Date',
        'Take Time',
        'Return Time',
        'Status'
    ];

    foreach ($headers as $columnIndex => $header) {

        $columnLetter =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $columnIndex + 1
            );

        $sheet->setCellValue(
            $columnLetter . $headerRow,
            $header
        );
    }

    $sheet->getStyle(
        "A{$headerRow}:J{$headerRow}"
    )->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => [
                'rgb' => 'FFFFFF',
            ],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => '2563EB',
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
                    'rgb' => 'D1D5DB',
                ],
            ],
        ],
    ]);

    $sheet->getRowDimension($headerRow)->setRowHeight(24);

    /*
    |--------------------------------------------------------------------------
    | Data Rows
    |--------------------------------------------------------------------------
    */

    $currentRow = $headerRow + 1;

    foreach ($rows as $index => $row) {

        $grade = trim(
            (string) (
                $row['grade_name'] ?? ''
            )
        );

        if ($grade === '') {

            $gradeNumber =
                $row['grade_number'] ?? '';

            $grade =
                $gradeNumber !== ''
                    ? 'Grade ' . $gradeNumber
                    : '';
        }

        $section = trim(
            (string) (
                $row['section_name'] ?? ''
            )
        );

        if ($section === '') {

            $section =
                trim(
                    (string) (
                        $row['section_code'] ?? ''
                    )
                );
        }

        $sheet->setCellValue(
            "A{$currentRow}",
            $index + 1
        );

        $sheet->setCellValue(
            "B{$currentRow}",
            $row['student_code'] ?? ''
        );

        $sheet->setCellValue(
            "C{$currentRow}",
            $row['student_name'] ?? ''
        );

        $sheet->setCellValue(
            "D{$currentRow}",
            $grade
        );

        $sheet->setCellValue(
            "E{$currentRow}",
            $section
        );

        $sheet->setCellValue(
            "F{$currentRow}",
            $row['book_title'] ?? ''
        );

        $sheet->setCellValue(
            "G{$currentRow}",
            formatEthiopianDate(
                $row['attendance_date'] ?? null
            )
        );

        $sheet->setCellValue(
            "H{$currentRow}",
            formatTime(
                $row['take_time'] ?? null
            )
        );

        $sheet->setCellValue(
            "I{$currentRow}",
            formatTime(
                $row['return_time'] ?? null
            )
        );

        $sheet->setCellValue(
            "J{$currentRow}",
            $row['status'] ?? ''
        );

        $currentRow++;
    }

    /*
    |--------------------------------------------------------------------------
    | Data Styling
    |--------------------------------------------------------------------------
    */

    $lastRow = max(
        $headerRow,
        $currentRow - 1
    );

    $sheet->getStyle(
        "A{$headerRow}:J{$lastRow}"
    )->applyFromArray([
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => [
                    'rgb' => 'D1D5DB',
                ],
            ],
        ],
    ]);

    if ($lastRow >= $headerRow + 1) {

        $sheet->getStyle(
            "A" . ($headerRow + 1) . ":A{$lastRow}"
        )->getAlignment()->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

        $sheet->getStyle(
            "D" . ($headerRow + 1) . ":E{$lastRow}"
        )->getAlignment()->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

        $sheet->getStyle(
            "G" . ($headerRow + 1) . ":J{$lastRow}"
        )->getAlignment()->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

        $sheet->getStyle(
            "A" . ($headerRow + 1) . ":J{$lastRow}"
        )->getAlignment()->setWrapText(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Column Widths
    |--------------------------------------------------------------------------
    */

    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(28);
    $sheet->getColumnDimension('D')->setWidth(16);
    $sheet->getColumnDimension('E')->setWidth(14);
    $sheet->getColumnDimension('F')->setWidth(35);
    $sheet->getColumnDimension('G')->setWidth(24);
    $sheet->getColumnDimension('H')->setWidth(16);
    $sheet->getColumnDimension('I')->setWidth(16);
    $sheet->getColumnDimension('J')->setWidth(14);

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

    $sheet->setAutoFilter(
        "A{$headerRow}:J{$lastRow}"
    );

    /*
    |--------------------------------------------------------------------------
    | Print Settings
    |--------------------------------------------------------------------------
    */

    $sheet->getPageSetup()->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

    $sheet->getPageSetup()->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);

    $sheet->getPageMargins()->setTop(0.4);
    $sheet->getPageMargins()->setBottom(0.4);
    $sheet->getPageMargins()->setLeft(0.3);
    $sheet->getPageMargins()->setRight(0.3);

    /*
    |--------------------------------------------------------------------------
    | Footer
    |--------------------------------------------------------------------------
    */

    $sheet->getHeaderFooter()->setOddFooter(
        '&L BKHS Library&R Page &P of &N'
    );

    /*
    |--------------------------------------------------------------------------
    | Output XLSX
    |--------------------------------------------------------------------------
    */

    $filename =
        'BKHS_Student_Study_Report_' .
        date('Ymd_His') .
        '.xlsx';

    /*
    |--------------------------------------------------------------------------
    | Clear Existing Output Buffers
    |--------------------------------------------------------------------------
    */

    while (ob_get_level() > 0) {
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

    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);

    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();

    unset($spreadsheet);

    exit;
}

/*
|--------------------------------------------------------------------------
| Librarian Information
|--------------------------------------------------------------------------
*/

$librarianId =
    (int) $_SESSION['user_id'];

$librarianName =
    'Librarian';

$librarianEmail =
    '';

$librarianPhoto =
    null;

$stmt = $conn->prepare("
    SELECT
        full_name,
        email,
        photo_path
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'librarian'
      AND is_deleted = 0
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $librarianId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $librarianName =
            $row['full_name'] ??
            'Librarian';

        $librarianEmail =
            $row['email'] ??
            '';

        $librarianPhoto =
            $row['photo_path'] ??
            null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Librarian Photo
|--------------------------------------------------------------------------
*/

$photoUrl =
    '../public/images/default-avatar.png';

if (!empty($librarianPhoto)) {

    $photoPath =
        str_replace(
            '\\',
            '/',
            trim(
                (string) $librarianPhoto
            )
        );

    $photoPath =
        ltrim(
            $photoPath,
            '/'
        );

    if (
        str_starts_with(
            $photoPath,
            'public/'
        ) &&
        !str_contains(
            $photoPath,
            '..'
        )
    ) {

        $photoUrl =
            '../' .
            $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear =
    null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE LOWER(status) = 'active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $activeAcademicYear =
            $row;
    }

    $stmt->close();
}

if (!$activeAcademicYear) {
    die(
        'No active academic year is available.'
    );
}

$academicYearId =
    (int) $activeAcademicYear['id'];

$academicYearName =
    (string) (
        $activeAcademicYear['name'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| Ethiopian Today
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$todayEtYear =
    (int) (
        $todayEthiopian['year'] ?? 0
    );

$todayEtMonth =
    (int) (
        $todayEthiopian['month'] ?? 0
    );

$todayEtDay =
    (int) (
        $todayEthiopian['day'] ?? 0
    );

$todayEthiopianFormatted =
    $todayEthiopian['formatted'] ?? '';

$todayGregorian =
    EthiopianCalendar::toGregorian(
        $todayEtYear,
        $todayEtMonth,
        $todayEtDay
    );

/*
|--------------------------------------------------------------------------
| Request Filters
|--------------------------------------------------------------------------
*/

$period =
    strtolower(
        trim(
            (string) (
                $_GET['period'] ??
                'daily'
            )
        )
    );

$allowedPeriods = [
    'daily',
    'weekly',
    'monthly'
];

if (
    !in_array(
        $period,
        $allowedPeriods,
        true
    )
) {

    $period =
        'daily';
}

$gradeId =
    safeInt(
        $_GET['grade_id'] ??
        0
    );

$sectionId =
    safeInt(
        $_GET['section_id'] ??
        0
    );

$search =
    trim(
        (string) (
            $_GET['search'] ??
            ''
        )
    );

$page =
    max(
        1,
        safeInt(
            $_GET['page'] ??
            1
        )
    );

$perPage =
    20;

/*
|--------------------------------------------------------------------------
| Report Date Range
|--------------------------------------------------------------------------
|
| Database:
|     attendance_date = Gregorian DATE
|
| UI:
|     Ethiopian date
|
|--------------------------------------------------------------------------
*/

$startGregorian =
    $todayGregorian;

$endGregorian =
    $todayGregorian;

if ($period === 'weekly') {

    $startEt =
        EthiopianCalendar::subDays(
            $todayEtYear,
            $todayEtMonth,
            $todayEtDay,
            6
        );

    $startGregorian =
        EthiopianCalendar::toGregorian(
            (int) $startEt['year'],
            (int) $startEt['month'],
            (int) $startEt['day']
        );

} elseif ($period === 'monthly') {

    $startGregorian =
        EthiopianCalendar::toGregorian(
            $todayEtYear,
            $todayEtMonth,
            1
        );
}

/*
|--------------------------------------------------------------------------
| Load Grades
|--------------------------------------------------------------------------
*/

$grades = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
");

if ($stmt) {

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $grades[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load Sections
|--------------------------------------------------------------------------
*/

$sections = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        code
    FROM sections
    ORDER BY name ASC
");

if ($stmt) {

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $sections[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Build Conditions
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

$where[] =
    'lsa.academic_year_id = ?';

$params[] =
    $academicYearId;

$types .= 'i';

$where[] =
    'lsa.attendance_date BETWEEN ? AND ?';

$params[] =
    $startGregorian;

$params[] =
    $endGregorian;

$types .= 'ss';

$where[] =
    's.is_deleted = 0';

if ($gradeId > 0) {

    $where[] =
        'sr.grade_id = ?';

    $params[] =
        $gradeId;

    $types .= 'i';
}

if ($sectionId > 0) {

    $where[] =
        'sr.section_id = ?';

    $params[] =
        $sectionId;

    $types .= 'i';
}

if ($search !== '') {

    $where[] = "
        (
            s.full_name LIKE ?
            OR s.student_code LIKE ?
            OR lb.title LIKE ?
        )
    ";

    $searchLike =
        '%' .
        $search .
        '%';

    $params[] =
        $searchLike;

    $params[] =
        $searchLike;

    $params[] =
        $searchLike;

    $types .= 'sss';
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
|
| Export happens before HTML output.
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'excel'
) {

    $exportSql = "
        SELECT
            s.student_code,
            s.full_name AS student_name,

            g.grade_number,
            g.name AS grade_name,

            sec.name AS section_name,
            sec.code AS section_code,

            lb.title AS book_title,

            lsa.attendance_date,
            lsa.take_time,
            lsa.return_time,
            lsa.status

        FROM library_study_attendance lsa

        INNER JOIN students s
            ON s.id = lsa.student_id

        INNER JOIN student_registrations sr
            ON sr.id = lsa.student_registration_id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN library_books lb
            ON lb.id = lsa.book_id

        WHERE {$whereSql}

        ORDER BY
            lsa.attendance_date DESC,
            lsa.take_time DESC,
            s.full_name ASC
    ";

    $stmt =
        $conn->prepare(
            $exportSql
        );

    $exportRows = [];

    if ($stmt) {

        if (!empty($params)) {

            $stmt->bind_param(
                $types,
                ...$params
            );
        }

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $exportRows[] =
                $row;
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Selected Grade Name
    |--------------------------------------------------------------------------
    */

    $exportGradeName =
        'All Grades';

    if ($gradeId > 0) {

        foreach (
            $grades
            as $grade
        ) {

            if (
                (int) $grade['id'] ===
                $gradeId
            ) {

                $exportGradeName =
                    $grade['name'] ??
                    (
                        'Grade ' .
                        $grade['grade_number']
                    );

                break;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Selected Section Name
    |--------------------------------------------------------------------------
    */

    $exportSectionName =
        'All Sections';

    if ($sectionId > 0) {

        foreach (
            $sections
            as $section
        ) {

            if (
                (int) $section['id'] ===
                $sectionId
            ) {

                $exportSectionName =
                    $section['name'];

                break;
            }
        }
    }

    createStudentStudyXlsx(
        $exportRows,
        $academicYearName,
        $period,
        $startGregorian,
        $endGregorian,
        $exportGradeName,
        $exportSectionName
    );
}

/*
|--------------------------------------------------------------------------
| Count Records
|--------------------------------------------------------------------------
*/

$totalRecords =
    0;

$countSql = "
    SELECT COUNT(*)

    FROM library_study_attendance lsa

    INNER JOIN students s
        ON s.id = lsa.student_id

    INNER JOIN student_registrations sr
        ON sr.id = lsa.student_registration_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_books lb
        ON lb.id = lsa.book_id

    WHERE {$whereSql}
";

$stmt =
    $conn->prepare(
        $countSql
    );

if ($stmt) {

    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $stmt->bind_result(
        $totalRecords
    );

    $stmt->fetch();

    $stmt->close();
}

$totalPages =
    max(
        1,
        (int) ceil(
            $totalRecords /
            $perPage
        )
    );

if (
    $page >
    $totalPages
) {

    $page =
        $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Study Records
|--------------------------------------------------------------------------
*/

$records = [];

$sql = "
    SELECT
        lsa.id,
        lsa.attendance_date,
        lsa.take_time,
        lsa.return_time,
        lsa.status,

        s.student_code,
        s.full_name AS student_name,

        g.grade_number,
        g.name AS grade_name,

        sec.name AS section_name,
        sec.code AS section_code,

        lb.title AS book_title

    FROM library_study_attendance lsa

    INNER JOIN students s
        ON s.id = lsa.student_id

    INNER JOIN student_registrations sr
        ON sr.id = lsa.student_registration_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_books lb
        ON lb.id = lsa.book_id

    WHERE {$whereSql}

    ORDER BY
        lsa.attendance_date DESC,
        lsa.take_time DESC,
        s.full_name ASC

    LIMIT ? OFFSET ?
";

$dataParams =
    $params;

$dataTypes =
    $types .
    'ii';

$dataParams[] =
    $perPage;

$dataParams[] =
    $offset;

$stmt =
    $conn->prepare(
        $sql
    );

if ($stmt) {

    $stmt->bind_param(
        $dataTypes,
        ...$dataParams
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $records[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Filter Labels
|--------------------------------------------------------------------------
*/

$selectedGradeName =
    'All Grades';

if ($gradeId > 0) {

    foreach (
        $grades
        as $grade
    ) {

        if (
            (int) $grade['id'] ===
            $gradeId
        ) {

            $selectedGradeName =
                $grade['name'] ??
                (
                    'Grade ' .
                    $grade['grade_number']
                );

            break;
        }
    }
}

$selectedSectionName =
    'All Sections';

if ($sectionId > 0) {

    foreach (
        $sections
        as $section
    ) {

        if (
            (int) $section['id'] ===
            $sectionId
        ) {

            $selectedSectionName =
                $section['name'];

            break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function buildPageUrl(
    int $pageNumber
): string {

    $query =
        $_GET;

    $query['page'] =
        $pageNumber;

    unset(
        $query['export']
    );

    return '?' .
        http_build_query(
            $query
        );
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Student Study Report | BKHS Library
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --topbar-height: 76px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar-bg: #111827;
            --sidebar-hover: #1f2937;
            --body-bg: #f8fafc;
            --border: #e5e7eb;
            --text-dark: #111827;
            --text-muted: #6b7280;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--body-bg);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            color: #fff;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            flex-shrink: 0;
        }

        .sidebar-brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 3px;
        }

        .sidebar-brand-text {
            margin-left: 11px;
            min-width: 0;
        }

        .sidebar-brand-title {
            font-size: 15px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
        }

        .sidebar-brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 16px 12px 20px;
            overflow-y: auto;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 0 12px;
            margin: 8px 0 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 44px;
            padding: 10px 12px;
            margin-bottom: 4px;
            color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        .sidebar-link.logout {
            color: #fca5a5;
        }

        .sidebar-link.logout:hover {
            background: rgba(239, 68, 68, .12);
            color: #fecaca;
        }

        /*
        |--------------------------------------------------------------------------
        | Reports Submenu
        |--------------------------------------------------------------------------
        */

        .reports-submenu {
            margin: 2px 0 8px 32px;
            border-left: 1px solid rgba(255, 255, 255, .10);
            padding-left: 8px;
        }

        .reports-submenu a {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #9ca3af;
            font-size: 12px;
            font-weight: 500;
            padding: 8px 10px;
            border-radius: 7px;
            transition: background .2s ease, color .2s ease;
        }

        .reports-submenu a:hover {
            background: rgba(255, 255, 255, .05);
            color: #fff;
        }

        .reports-submenu a.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .reports-submenu a i {
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            height: var(--topbar-height);
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, .96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            min-width: 0;
        }

        .mobile-menu-btn {
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: var(--text-dark);
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }

        .page-title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: var(--text-dark);
        }

        .page-subtitle {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ethiopian-date {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--text-muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .ethiopian-date i {
            color: var(--primary);
            font-size: 15px;
        }

        .profile-menu {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-dark);
        }

        .profile-photo {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
            background: #f3f4f6;
        }

        .profile-info {
            line-height: 1.2;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .profile-role {
            margin-top: 4px;
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .page-header-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 800;
            margin: 0;
        }

        .header-description {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .academic-year-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            background: #eff6ff;
            color: var(--primary);
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Filter
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .filter-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #d1d5db;
            border-radius: 8px;
            font-size: 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10);
        }

        .period-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .period-button {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #4b5563;
            border-radius: 8px;
            padding: 9px 15px;
            font-size: 11px;
            font-weight: 700;
            transition: background .2s ease, color .2s ease;
        }

        .period-button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .period-button.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            height: 100%;
        }

        .btn-primary-custom,
        .btn-light-custom,
        .btn-excel {
            min-height: 42px;
            border-radius: 8px;
            padding: 9px 16px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-primary-custom {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
        }

        .btn-light-custom {
            background: #fff;
            border: 1px solid #d1d5db;
            color: #4b5563;
        }

        .btn-light-custom:hover {
            background: #f9fafb;
            color: #111827;
        }

        .btn-excel {
            background: #15803d;
            border: 1px solid #15803d;
            color: #fff;
        }

        .btn-excel:hover {
            background: #166534;
            border-color: #166534;
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        .summary-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 20px;
            height: calc(100% - 20px);
        }

        .summary-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .05em;
        }

        .summary-value {
            margin-top: 5px;
            font-size: 15px;
            font-weight: 800;
            color: var(--text-dark);
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .table-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }

        .table-subtitle {
            margin: 4px 0 0;
            font-size: 11px;
            color: var(--text-muted);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            min-width: 1050px;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .report-table thead th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 13px 15px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .report-table tbody td {
            padding: 14px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            color: #374151;
            vertical-align: middle;
        }

        .report-table tbody tr:hover {
            background: #fafcff;
        }

        .student-name {
            font-weight: 700;
            color: #111827;
        }

        .student-code {
            font-size: 10px;
            color: #6b7280;
            margin-top: 3px;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .section-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-reading {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-returned {
            background: #ecfdf5;
            color: #047857;
        }

        .book-title {
            font-weight: 600;
            color: #374151;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #f3f4f6;
            color: #9ca3af;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 14px;
        }

        .empty-title {
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .empty-text {
            color: var(--text-muted);
            font-size: 11px;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            border-top: 1px solid var(--border);
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 11px;
        }

        .pagination {
            margin: 0;
            gap: 4px;
        }

        .pagination .page-link {
            border: 1px solid #e5e7eb;
            border-radius: 7px !important;
            color: #4b5563;
            font-size: 11px;
            min-width: 34px;
            text-align: center;
        }

        .pagination .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            z-index: 1050;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-wrapper {
                margin-left: 0;
            }

            .topbar {
                padding: 0 18px;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .content {
                padding: 22px 18px 90px;
            }

            .ethiopian-date {
                display: none;
            }

            .filter-actions {
                height: auto;
                flex-wrap: wrap;
            }
        }

        @media (min-width: 992px) {

            .mobile-menu-btn {
                display: none;
            }
        }

        @media (max-width: 767.98px) {

            .topbar {
                height: 68px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                font-size: 11px;
            }

            .profile-info {
                display: none;
            }

            .profile-photo {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 18px 14px 90px;
            }

            .page-header-card {
                padding: 18px;
            }

            .filter-card {
                padding: 16px;
            }

            .table-header {
                padding: 16px;
                align-items: flex-start;
                flex-direction: column;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: flex-start;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 66px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1000;
                display: flex;
                align-items: center;
                justify-content: space-around;
                box-shadow: 0 -5px 20px rgba(15, 23, 42, .06);
            }

            .mobile-bottom-nav a {
                flex: 1;
                height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                color: #6b7280;
                font-size: 9px;
                font-weight: 600;
            }

            .mobile-bottom-nav a i {
                font-size: 18px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }
        }

        @media (max-width: 420px) {

            .topbar {
                padding: 0 12px;
            }

            .content {
                padding-left: 12px;
                padding-right: 12px;
            }

            .page-title {
                font-size: 16px;
            }

            .period-button {
                flex: 1;
                min-width: 80px;
            }

            .filter-actions {
                width: 100%;
            }

            .filter-actions > * {
                flex: 1;
            }
        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS Logo"
        >

        <div class="sidebar-brand-text">

            <div class="sidebar-brand-title">
                BKHS Library
            </div>

            <div class="sidebar-brand-subtitle">
                Librarian Portal
            </div>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="books.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-fill"></i>
            <span>Books</span>
        </a>

        <a
            href="categories.php"
            class="sidebar-link"
        >
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a
            href="study-attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Study Attendance</span>
        </a>

        <div class="nav-section-title mt-4">
            Library Operations
        </div>

        <a
            href="borrow-book.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Borrow Book</span>
        </a>

        <a
            href="return-book.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-in-down"></i>
            <span>Return Book</span>
        </a>

        <a
            href="borrowing-control.php"
            class="sidebar-link"
        >
            <i class="bi bi-arrow-left-right"></i>
            <span>Borrowing Control</span>
        </a>

        <a
            href="overdue-books.php"
            class="sidebar-link"
        >
            <i class="bi bi-clock-history"></i>
            <span>Overdue Books</span>
        </a>

        <div class="nav-section-title mt-4">
            Reports
        </div>

        <a
            href="reports.php"
            class="sidebar-link active"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

        <div class="reports-submenu">

            <a
                href="student-study-report.php"
                class="active"
            >
                <i class="bi bi-person-lines-fill"></i>
                <span>Student Study</span>
            </a>

            <a href="book-report.php">
                <i class="bi bi-bookshelf"></i>
                <span>Book List</span>
            </a>

            <a href="borrowing-report.php">
                <i class="bi bi-journal-arrow-up"></i>
                <span>Borrowing</span>
            </a>

        </div>

        <div class="nav-section-title mt-4">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<!-- Main -->

<div class="main-wrapper">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open navigation"
            >
                <i class="bi bi-list fs-5"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Student Study Report
                </h1>

                <p class="page-subtitle">
                    Students studying and reading books inside the library
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">

                <i class="bi bi-calendar3"></i>

                <span>
                    <?= e($todayEthiopianFormatted) ?>
                </span>

            </div>

            <a
                href="profile.php"
                class="profile-menu"
            >

                <img
                    src="<?= e($photoUrl) ?>"
                    alt="Librarian"
                    class="profile-photo"
                    onerror="this.onerror=null;this.src='../public/images/default-avatar.png';"
                >

                <div class="profile-info">

                    <div class="profile-name">
                        <?= e($librarianName) ?>
                    </div>

                    <div class="profile-role">
                        Librarian
                    </div>

                </div>

            </a>

        </div>

    </header>

    <!-- Content -->

    <main class="content">

        <!-- Page Header -->

        <div class="page-header-card">

            <div
                class="d-flex flex-wrap align-items-center justify-content-between gap-3"
            >

                <div>

                    <h2 class="header-title">
                        Student Study Report
                    </h2>

                    <p class="header-description">
                        View student library study activity by daily,
                        weekly or monthly period, grade, section and
                        student. Export the filtered report to Excel.
                    </p>

                </div>

                <div class="academic-year-badge">

                    <i class="bi bi-calendar-check"></i>

                    Academic Year:
                    <?= e($academicYearName) ?>

                </div>

            </div>

        </div>

        <!-- Filters -->

        <div class="filter-card">

            <form
                method="get"
                action="student-study-report.php"
            >

                <div class="row g-3">

                    <!-- Period -->

                    <div class="col-12">

                        <label class="filter-label">
                            Report Period
                        </label>

                        <div class="period-buttons">

                            <button
                                type="submit"
                                name="period"
                                value="daily"
                                class="period-button <?= $period === 'daily' ? 'active' : '' ?>"
                            >
                                <i class="bi bi-calendar-day me-1"></i>
                                Daily
                            </button>

                            <button
                                type="submit"
                                name="period"
                                value="weekly"
                                class="period-button <?= $period === 'weekly' ? 'active' : '' ?>"
                            >
                                <i class="bi bi-calendar-week me-1"></i>
                                Weekly
                            </button>

                            <button
                                type="submit"
                                name="period"
                                value="monthly"
                                class="period-button <?= $period === 'monthly' ? 'active' : '' ?>"
                            >
                                <i class="bi bi-calendar-month me-1"></i>
                                Monthly
                            </button>

                        </div>

                    </div>

                    <!-- Search -->

                    <div class="col-12 col-md-6 col-xl-3">

                        <label
                            for="search"
                            class="filter-label"
                        >
                            Search Student / Book
                        </label>

                        <input
                            type="text"
                            id="search"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Name, code or book title..."
                        >

                    </div>

                    <!-- Grade -->

                    <div class="col-12 col-md-6 col-xl-2">

                        <label
                            for="grade_id"
                            class="filter-label"
                        >
                            Grade
                        </label>

                        <select
                            id="grade_id"
                            name="grade_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Grades
                            </option>

                            <?php foreach (
                                $grades
                                as $grade
                            ): ?>

                                <option
                                    value="<?= (int) $grade['id'] ?>"
                                    <?= $gradeId === (int) $grade['id'] ? 'selected' : '' ?>
                                >
                                    <?= e(
                                        $grade['name'] ??
                                        (
                                            'Grade ' .
                                            $grade['grade_number']
                                        )
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Section -->

                    <div class="col-12 col-md-6 col-xl-2">

                        <label
                            for="section_id"
                            class="filter-label"
                        >
                            Section
                        </label>

                        <select
                            id="section_id"
                            name="section_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Sections
                            </option>

                            <?php foreach (
                                $sections
                                as $section
                            ): ?>

                                <option
                                    value="<?= (int) $section['id'] ?>"
                                    <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>
                                >
                                    <?= e(
                                        $section['name']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Actions -->

                    <div class="col-12 col-md-6 col-xl-5">

                        <label class="filter-label">
                            Actions
                        </label>

                        <div class="filter-actions">

                            <button
                                type="submit"
                                class="btn-primary-custom"
                            >
                                <i class="bi bi-search me-1"></i>
                                Apply Filter
                            </button>

                            <a
                                href="student-study-report.php"
                                class="btn-light-custom"
                            >
                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Reset
                            </a>

                            <button
                                type="submit"
                                name="export"
                                value="excel"
                                class="btn-excel"
                            >
                                <i class="bi bi-file-earmark-excel me-1"></i>
                                Export Excel
                            </button>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <!-- Summary -->

        <div class="row g-3">

            <div class="col-12 col-sm-6 col-lg-3">

                <div class="summary-card">

                    <div class="summary-label">
                        Period
                    </div>

                    <div class="summary-value">
                        <?= e(
                            ucfirst($period)
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-12 col-sm-6 col-lg-3">

                <div class="summary-card">

                    <div class="summary-label">
                        Date Range
                    </div>

                    <div class="summary-value">

                        <?= e(
                            formatEthiopianDate(
                                $startGregorian
                            )
                        ) ?>

                        <?php if (
                            $startGregorian !==
                            $endGregorian
                        ): ?>

                            <span class="text-muted">
                                —
                            </span>

                            <?= e(
                                formatEthiopianDate(
                                    $endGregorian
                                )
                            ) ?>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <div class="col-12 col-sm-6 col-lg-3">

                <div class="summary-card">

                    <div class="summary-label">
                        Grade
                    </div>

                    <div class="summary-value">
                        <?= e(
                            $selectedGradeName
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-12 col-sm-6 col-lg-3">

                <div class="summary-card">

                    <div class="summary-label">
                        Total Records
                    </div>

                    <div class="summary-value">
                        <?= number_format(
                            $totalRecords
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Study Table -->

        <div class="table-card">

            <div class="table-header">

                <div>

                    <h3 class="table-title">
                        Student Study Records
                    </h3>

                    <p class="table-subtitle">
                        <?= number_format(
                            $totalRecords
                        ) ?>
                        study record(s) found
                    </p>

                </div>

                <div class="small text-muted">
                    <?= e(
                        $selectedSectionName
                    ) ?>
                </div>

            </div>

            <div class="table-responsive">

                <?php if (
                    empty($records)
                ): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-journal-x"></i>
                        </div>

                        <div class="empty-title">
                            No Study Records Found
                        </div>

                        <div class="empty-text">
                            No student study records match the selected filters.
                        </div>

                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>

                            <tr>

                                <th>
                                    No
                                </th>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Grade
                                </th>

                                <th>
                                    Section
                                </th>

                                <th>
                                    Book Title
                                </th>

                                <th>
                                    Study Date
                                </th>

                                <th>
                                    Take Time
                                </th>

                                <th>
                                    Return Time
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $records
                                as $index => $record
                            ): ?>

                                <tr>

                                    <td>
                                        <?= $offset + $index + 1 ?>
                                    </td>

                                    <td>

                                        <div class="student-name">

                                            <?= e(
                                                $record['student_name'] ??
                                                ''
                                            ) ?>

                                        </div>

                                        <div class="student-code">

                                            <?= e(
                                                $record['student_code'] ??
                                                ''
                                            ) ?>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="grade-badge">

                                            <?= e(
                                                $record['grade_name'] ??
                                                (
                                                    'Grade ' .
                                                    (
                                                        $record['grade_number'] ??
                                                        ''
                                                    )
                                                )
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="section-badge">

                                            <?= e(
                                                $record['section_name'] ??
                                                (
                                                    $record['section_code'] ??
                                                    ''
                                                )
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="book-title">

                                            <?= e(
                                                $record['book_title'] ??
                                                ''
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?= e(
                                            formatEthiopianDate(
                                                $record['attendance_date'] ??
                                                null
                                            )
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= e(
                                            formatTime(
                                                $record['take_time'] ??
                                                null
                                            )
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= e(
                                            formatTime(
                                                $record['return_time'] ??
                                                null
                                            )
                                        ) ?>

                                    </td>

                                    <td>

                                        <?php

                                        $status =
                                            (string) (
                                                $record['status'] ??
                                                ''
                                            );

                                        $statusClass =
                                            strtolower(
                                                $status
                                            ) === 'returned'
                                                ? 'status-returned'
                                                : 'status-reading';

                                        ?>

                                        <span
                                            class="status-badge <?= e($statusClass) ?>"
                                        >
                                            <?= e(
                                                $status ?: '—'
                                            ) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

            <!-- Pagination -->

            <?php if (
                $totalPages > 1
            ): ?>

                <div class="pagination-wrapper">

                    <div class="pagination-info">

                        Showing

                        <strong>
                            <?= number_format(
                                $offset + 1
                            ) ?>
                        </strong>

                        to

                        <strong>
                            <?= number_format(
                                min(
                                    $offset + $perPage,
                                    $totalRecords
                                )
                            ) ?>
                        </strong>

                        of

                        <strong>
                            <?= number_format(
                                $totalRecords
                            ) ?>
                        </strong>

                        records

                    </div>

                    <nav>

                        <ul class="pagination">

                            <!-- Previous -->

                            <?php if (
                                $page > 1
                            ): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            buildPageUrl(
                                                $page - 1
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

                            <?php endif; ?>

                            <?php

                            $startPage =
                                max(
                                    1,
                                    $page - 2
                                );

                            $endPage =
                                min(
                                    $totalPages,
                                    $page + 2
                                );

                            ?>

                            <?php if (
                                $startPage > 1
                            ): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            buildPageUrl(1)
                                        ) ?>"
                                    >
                                        1
                                    </a>

                                </li>

                                <?php if (
                                    $startPage > 2
                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                            <?php endif; ?>

                            <?php for (
                                $i = $startPage;
                                $i <= $endPage;
                                $i++
                            ): ?>

                                <li
                                    class="page-item <?= $i === $page ? 'active' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            buildPageUrl($i)
                                        ) ?>"
                                    >
                                        <?= $i ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <?php if (
                                $endPage <
                                $totalPages
                            ): ?>

                                <?php if (
                                    $endPage <
                                    $totalPages - 1
                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            buildPageUrl(
                                                $totalPages
                                            )
                                        ) ?>"
                                    >
                                        <?= $totalPages ?>
                                    </a>

                                </li>

                            <?php endif; ?>

                            <!-- Next -->

                            <?php if (
                                $page <
                                $totalPages
                            ): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            buildPageUrl(
                                                $page + 1
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            <?php endif; ?>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    </main>

</div>

<!-- Mobile Bottom Navigation -->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Dashboard
        </span>

    </a>

    <a href="books.php">

        <i class="bi bi-book-fill"></i>

        <span>
            Books
        </span>

    </a>

    <a
        href="reports.php"
        class="active"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Reports
        </span>

    </a>

    <a href="profile.php">

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>

</nav>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

function openSidebar() {

    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('show');
    }

    document.body.style.overflow =
        'hidden';
}

function closeSidebar() {

    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('show');
    }

    document.body.style.overflow =
        '';
}

if (mobileMenuBtn) {

    mobileMenuBtn.addEventListener(
        'click',
        openSidebar
    );
}

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );
}

window.addEventListener(
    'resize',
    function () {

        if (window.innerWidth >= 992) {
            closeSidebar();
        }

    }
);

</script>

</body>
</html>