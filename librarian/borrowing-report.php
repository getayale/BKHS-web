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

/*
|--------------------------------------------------------------------------
| Database + Composer
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

require_once '../includes/EthiopianCalendar.php';

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

function safeInt($value, int $default = 0): int
{
    return filter_var(
        $value,
        FILTER_VALIDATE_INT
    ) !== false
        ? (int) $value
        : $default;
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

        return $date['formatted'];
    } catch (Throwable $e) {
        return '—';
    }
}

function formatTime(?string $datetime): string
{
    if (empty($datetime)) {
        return '—';
    }

    try {
        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return '—';
        }

        return date('h:i A', $timestamp);
    } catch (Throwable $e) {
        return '—';
    }
}

function redirectWithMessage(
    string $type,
    string $message
): never {
    $_SESSION['report_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: borrowing-report.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Librarian Information
|--------------------------------------------------------------------------
*/

$librarianId = (int) $_SESSION['user_id'];

$librarianName = 'Librarian';
$librarianEmail = '';
$librarianPhoto = null;

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

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $librarianName =
            $row['full_name'] ?? 'Librarian';

        $librarianEmail =
            $row['email'] ?? '';

        $librarianPhoto =
            $row['photo_path'] ?? null;
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

    $photoPath = str_replace(
        '\\',
        '/',
        trim((string) $librarianPhoto)
    );

    $photoPath =
        ltrim($photoPath, '/');

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
            '../' . $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

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
        $activeAcademicYear = $row;
    }

    $stmt->close();
}

if (!$activeAcademicYear) {

    redirectWithMessage(
        'danger',
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

$todayEthiopianFormatted =
    $todayEthiopian['formatted'];

$todayGregorian =
    EthiopianCalendar::toGregorian(
        (int) $todayEthiopian['year'],
        (int) $todayEthiopian['month'],
        (int) $todayEthiopian['day']
    );

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$period =
    $_GET['period'] ?? 'daily';

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
    $period = 'daily';
}

$gradeId =
    safeInt(
        $_GET['grade_id'] ?? 0
    );

$sectionId =
    safeInt(
        $_GET['section_id'] ?? 0
    );

$status =
    trim(
        (string) (
            $_GET['status'] ?? ''
        )
    );

$search =
    trim(
        (string) (
            $_GET['search'] ?? ''
        )
    );

$page =
    max(
        1,
        safeInt(
            $_GET['page'] ?? 1,
            1
        )
    );

$perPage = 20;

/*
|--------------------------------------------------------------------------
| Ethiopian Reporting Date Range
|--------------------------------------------------------------------------
|
| Database stores Gregorian dates.
| UI displays Ethiopian dates.
|--------------------------------------------------------------------------
*/

$startGregorian =
    $todayGregorian;

$endGregorian =
    $todayGregorian;

if ($period === 'weekly') {

    $startEthiopian =
        EthiopianCalendar::subDays(
            (int) $todayEthiopian['year'],
            (int) $todayEthiopian['month'],
            (int) $todayEthiopian['day'],
            6
        );

    $startGregorian =
        EthiopianCalendar::toGregorian(
            (int) $startEthiopian['year'],
            (int) $startEthiopian['month'],
            (int) $startEthiopian['day']
        );
}

if ($period === 'monthly') {

    $startGregorian =
        EthiopianCalendar::toGregorian(
            (int) $todayEthiopian['year'],
            (int) $todayEthiopian['month'],
            1
        );
}

/*
|--------------------------------------------------------------------------
| Period Label
|--------------------------------------------------------------------------
*/

$periodLabel = match ($period) {

    'weekly' =>
        'Weekly',

    'monthly' =>
        'Monthly',

    default =>
        'Daily'
};

/*
|--------------------------------------------------------------------------
| Grades
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

    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Sections
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

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Build WHERE Conditions
|--------------------------------------------------------------------------
*/

$where = [
    "lbr.academic_year_id = ?",
    "lbr.borrow_date BETWEEN ? AND ?"
];

$params = [
    $academicYearId,
    $startGregorian,
    $endGregorian
];

$types = 'iss';

if ($gradeId > 0) {

    $where[] =
        "sr.grade_id = ?";

    $params[] =
        $gradeId;

    $types .= 'i';
}

if ($sectionId > 0) {

    $where[] =
        "sr.section_id = ?";

    $params[] =
        $sectionId;

    $types .= 'i';
}

if ($status !== '') {

    $where[] =
        "lbr.status = ?";

    $params[] =
        $status;

    $types .= 's';
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
        '%' . $search . '%';

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
| Excel Export
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'excel'
) {

    $exportRows = [];

    $exportSql = "
        SELECT
            s.student_code,
            s.full_name AS student_name,

            g.grade_number,
            g.name AS grade_name,

            sec.name AS section_name,
            sec.code AS section_code,

            lb.title AS book_title,

            lbr.borrow_date,
            lbr.borrow_time,

            lbr.due_date,

            lbr.return_date,
            lbr.return_time,

            lbr.status

        FROM library_borrowings lbr

        INNER JOIN students s
            ON s.id = lbr.student_id

        INNER JOIN student_registrations sr
            ON sr.id =
                lbr.student_registration_id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN library_books lb
            ON lb.id = lbr.book_id

        WHERE {$whereSql}

        ORDER BY
            lbr.borrow_date DESC,
            lbr.borrow_time DESC,
            lbr.id DESC
    ";

    $stmt =
        $conn->prepare(
            $exportSql
        );

    if (!$stmt) {

        redirectWithMessage(
            'danger',
            'Unable to prepare the Excel export.'
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
        $row =
            $result->fetch_assoc()
    ) {

        $exportRows[] =
            $row;
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Create XLSX with PhpSpreadsheet
    |--------------------------------------------------------------------------
    */

    $spreadsheet =
        new Spreadsheet();

    $sheet =
        $spreadsheet->getActiveSheet();

    $sheet->setTitle(
        'Borrowing Report'
    );

    /*
    |--------------------------------------------------------------------------
    | Report Header
    |--------------------------------------------------------------------------
    */

    $sheet->mergeCells(
        'A1:L1'
    );

    $sheet->setCellValue(
        'A1',
        'BKHS Library Borrowing Report'
    );

    $sheet
        ->getStyle('A1:L1')
        ->applyFromArray([

            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => [
                    'argb' => 'FFFFFFFF'
                ]
            ],

            'fill' => [
                'fillType' =>
                    Fill::FILL_SOLID,

                'startColor' => [
                    'argb' => 'FF2563EB'
                ]
            ],

            'alignment' => [
                'horizontal' =>
                    Alignment::HORIZONTAL_CENTER,

                'vertical' =>
                    Alignment::VERTICAL_CENTER
            ]
        ]);

    $sheet
        ->getRowDimension(1)
        ->setRowHeight(28);

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
        'Period'
    );

    $sheet->setCellValue(
        'B3',
        $periodLabel
    );

    $sheet->setCellValue(
        'D2',
        'From'
    );

    $sheet->setCellValue(
        'E2',
        formatEthiopianDate(
            $startGregorian
        )
    );

    $sheet->setCellValue(
        'D3',
        'To'
    );

    $sheet->setCellValue(
        'E3',
        formatEthiopianDate(
            $endGregorian
        )
    );

    $sheet->setCellValue(
        'G2',
        'Total Records'
    );

    $sheet->setCellValue(
        'H2',
        count($exportRows)
    );

    $sheet
        ->getStyle('A2:A3')
        ->getFont()
        ->setBold(true);

    $sheet
        ->getStyle('D2:D3')
        ->getFont()
        ->setBold(true);

    $sheet
        ->getStyle('G2')
        ->getFont()
        ->setBold(true);

    /*
    |--------------------------------------------------------------------------
    | Headers
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | Do NOT use setCellValueByColumnAndRow().
    | Newer PhpSpreadsheet versions do not provide it.
    |--------------------------------------------------------------------------
    */

    $headers = [
        'No',
        'Student Code',
        'Student Name',
        'Grade',
        'Section',
        'Book Title',
        'Borrow Date',
        'Take Time',
        'Due Date',
        'Return Date',
        'Return Time',
        'Status'
    ];

    $headerRow = 5;

    $headerColumns = [
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L'
    ];

    foreach (
        $headers as $index => $header
    ) {

        $column =
            $headerColumns[$index];

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

    $sheet
        ->getStyle(
            "A{$headerRow}:L{$headerRow}"
        )
        ->applyFromArray([

            'font' => [
                'bold' => true,
                'color' => [
                    'argb' => 'FFFFFFFF'
                ]
            ],

            'fill' => [
                'fillType' =>
                    Fill::FILL_SOLID,

                'startColor' => [
                    'argb' => 'FF1E40AF'
                ]
            ],

            'alignment' => [
                'horizontal' =>
                    Alignment::HORIZONTAL_CENTER,

                'vertical' =>
                    Alignment::VERTICAL_CENTER,

                'wrapText' => true
            ],

            'borders' => [
                'allBorders' => [
                    'borderStyle' =>
                        Border::BORDER_THIN,

                    'color' => [
                        'argb' => 'FFD1D5DB'
                    ]
                ]
            ]
        ]);

    /*
    |--------------------------------------------------------------------------
    | Export Rows
    |--------------------------------------------------------------------------
    */

    $rowNumber = 6;

    foreach (
        $exportRows as $index => $row
    ) {

        $sheet->setCellValue(
            "A{$rowNumber}",
            $index + 1
        );

        $sheet->setCellValue(
            "B{$rowNumber}",
            $row['student_code'] ?? ''
        );

        $sheet->setCellValue(
            "C{$rowNumber}",
            $row['student_name'] ?? ''
        );

        $gradeNumber =
            (int) (
                $row['grade_number'] ?? 0
            );

        $gradeText =
            'Grade ' . $gradeNumber;

        $sheet->setCellValue(
            "D{$rowNumber}",
            $gradeText
        );

        $sectionText =
            (string) (
                $row['section_name'] ?? ''
            );

        if (
            !empty(
                $row['section_code']
            ) &&
            $row['section_code'] !==
                $sectionText
        ) {

            $sectionText .=
                ' (' .
                $row['section_code'] .
                ')';
        }

        $sheet->setCellValue(
            "E{$rowNumber}",
            $sectionText
        );

        $sheet->setCellValue(
            "F{$rowNumber}",
            $row['book_title'] ?? ''
        );

        $sheet->setCellValue(
            "G{$rowNumber}",
            formatEthiopianDate(
                $row['borrow_date'] ?? null
            )
        );

        $sheet->setCellValue(
            "H{$rowNumber}",
            formatTime(
                $row['borrow_time'] ?? null
            )
        );

        $sheet->setCellValue(
            "I{$rowNumber}",
            formatEthiopianDate(
                $row['due_date'] ?? null
            )
        );

        $sheet->setCellValue(
            "J{$rowNumber}",
            formatEthiopianDate(
                $row['return_date'] ?? null
            )
        );

        $sheet->setCellValue(
            "K{$rowNumber}",
            formatTime(
                $row['return_time'] ?? null
            )
        );

        $sheet->setCellValue(
            "L{$rowNumber}",
            $row['status'] ?? ''
        );

        $rowNumber++;
    }

    /*
    |--------------------------------------------------------------------------
    | Last Row
    |--------------------------------------------------------------------------
    */

    $lastRow =
        max(
            $headerRow,
            $rowNumber - 1
        );

    /*
    |--------------------------------------------------------------------------
    | Table Styling
    |--------------------------------------------------------------------------
    */

    $sheet
        ->getStyle(
            "A{$headerRow}:L{$lastRow}"
        )
        ->applyFromArray([

            'borders' => [
                'allBorders' => [
                    'borderStyle' =>
                        Border::BORDER_THIN,

                    'color' => [
                        'argb' => 'FFD1D5DB'
                    ]
                ]
            ]
        ]);

    $sheet
        ->getStyle(
            "A{$headerRow}:L{$lastRow}"
        )
        ->getAlignment()
        ->setVertical(
            Alignment::VERTICAL_CENTER
        );

    $sheet
        ->getStyle(
            "A{$headerRow}:L{$lastRow}"
        )
        ->getAlignment()
        ->setWrapText(true);

    if ($lastRow >= 6) {

        $sheet
            ->getStyle(
                "A6:A{$lastRow}"
            )
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );

        $sheet
            ->getStyle(
                "D6:E{$lastRow}"
            )
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );

        $sheet
            ->getStyle(
                "G6:L{$lastRow}"
            )
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Column Widths
    |--------------------------------------------------------------------------
    */

    $sheet
        ->getColumnDimension('A')
        ->setWidth(8);

    $sheet
        ->getColumnDimension('B')
        ->setWidth(20);

    $sheet
        ->getColumnDimension('C')
        ->setWidth(30);

    $sheet
        ->getColumnDimension('D')
        ->setWidth(14);

    $sheet
        ->getColumnDimension('E')
        ->setWidth(18);

    $sheet
        ->getColumnDimension('F')
        ->setWidth(38);

    $sheet
        ->getColumnDimension('G')
        ->setWidth(20);

    $sheet
        ->getColumnDimension('H')
        ->setWidth(14);

    $sheet
        ->getColumnDimension('I')
        ->setWidth(20);

    $sheet
        ->getColumnDimension('J')
        ->setWidth(20);

    $sheet
        ->getColumnDimension('K')
        ->setWidth(14);

    $sheet
        ->getColumnDimension('L')
        ->setWidth(16);

    /*
    |--------------------------------------------------------------------------
    | Freeze Header + Filter
    |--------------------------------------------------------------------------
    */

    $sheet->freezePane('A6');

    $sheet->setAutoFilter(
        "A{$headerRow}:L{$lastRow}"
    );

    /*
    |--------------------------------------------------------------------------
    | Print Settings
    |--------------------------------------------------------------------------
    */

    $sheet
        ->getPageSetup()
        ->setOrientation(
            PageSetup::ORIENTATION_LANDSCAPE
        );

    $sheet
        ->getPageSetup()
        ->setPaperSize(
            PageSetup::PAPERSIZE_A4
        );

    $sheet
        ->getPageSetup()
        ->setFitToWidth(1);

    $sheet
        ->getPageSetup()
        ->setFitToHeight(0);

    $sheet
        ->getPageMargins()
        ->setTop(0.4);

    $sheet
        ->getPageMargins()
        ->setBottom(0.4);

    $sheet
        ->getPageMargins()
        ->setLeft(0.3);

    $sheet
        ->getPageMargins()
        ->setRight(0.3);

    /*
    |--------------------------------------------------------------------------
    | Download XLSX
    |--------------------------------------------------------------------------
    */

    $filename =
        'BKHS_Borrowing_Report_' .
        date('Ymd_His') .
        '.xlsx';

    /*
    |--------------------------------------------------------------------------
    | Clear Output Buffer
    |--------------------------------------------------------------------------
    */

    while (
        ob_get_level() > 0
    ) {
        ob_end_clean();
    }

    /*
    |--------------------------------------------------------------------------
    | Download Headers
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

    header(
        'Cache-Control: max-age=0'
    );

    header(
        'Expires: 0'
    );

    /*
    |--------------------------------------------------------------------------
    | Write XLSX
    |--------------------------------------------------------------------------
    */

    $writer =
        new Xlsx($spreadsheet);

    $writer->save(
        'php://output'
    );

    $spreadsheet
        ->disconnectWorksheets();

    unset($spreadsheet);

    exit;
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalRecords = 0;
$borrowedCount = 0;
$returnedCount = 0;
$overdueCount = 0;

$statsSql = "
    SELECT
        COUNT(*) AS total_records,

        SUM(
            CASE
                WHEN lbr.status = 'Borrowed'
                THEN 1
                ELSE 0
            END
        ) AS borrowed_count,

        SUM(
            CASE
                WHEN lbr.status = 'Returned'
                THEN 1
                ELSE 0
            END
        ) AS returned_count,

        SUM(
            CASE
                WHEN lbr.status = 'Overdue'
                THEN 1
                ELSE 0
            END
        ) AS overdue_count

    FROM library_borrowings lbr

    INNER JOIN students s
        ON s.id = lbr.student_id

    INNER JOIN student_registrations sr
        ON sr.id =
            lbr.student_registration_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_books lb
        ON lb.id = lbr.book_id

    WHERE {$whereSql}
";

$stmt =
    $conn->prepare(
        $statsSql
    );

if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if (
        $row =
            $result->fetch_assoc()
    ) {

        $totalRecords =
            (int) (
                $row['total_records'] ?? 0
            );

        $borrowedCount =
            (int) (
                $row['borrowed_count'] ?? 0
            );

        $returnedCount =
            (int) (
                $row['returned_count'] ?? 0
            );

        $overdueCount =
            (int) (
                $row['overdue_count'] ?? 0
            );
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages =
    max(
        1,
        (int) ceil(
            $totalRecords /
            $perPage
        )
    );

if (
    $page > $totalPages
) {
    $page =
        $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Main Borrowing Records
|--------------------------------------------------------------------------
*/

$records = [];

$listSql = "
    SELECT
        lbr.id,

        s.student_code,
        s.full_name AS student_name,

        g.grade_number,
        g.name AS grade_name,

        sec.name AS section_name,
        sec.code AS section_code,

        lb.title AS book_title,

        lbr.borrow_date,
        lbr.borrow_time,

        lbr.due_date,

        lbr.return_date,
        lbr.return_time,

        lbr.status

    FROM library_borrowings lbr

    INNER JOIN students s
        ON s.id = lbr.student_id

    INNER JOIN student_registrations sr
        ON sr.id =
            lbr.student_registration_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_books lb
        ON lb.id = lbr.book_id

    WHERE {$whereSql}

    ORDER BY
        lbr.borrow_date DESC,
        lbr.borrow_time DESC,
        lbr.id DESC

    LIMIT ? OFFSET ?
";

$listParams =
    $params;

$listParams[] =
    $perPage;

$listParams[] =
    $offset;

$listTypes =
    $types . 'ii';

$stmt =
    $conn->prepare(
        $listSql
    );

if ($stmt) {

    $stmt->bind_param(
        $listTypes,
        ...$listParams
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
| Flash Message
|--------------------------------------------------------------------------
*/

$message =
    $_SESSION['report_message']
    ?? null;

unset(
    $_SESSION['report_message']
);

/*
|--------------------------------------------------------------------------
| Pagination URL Helper
|--------------------------------------------------------------------------
*/

function pageUrl(
    int $pageNumber
): string {

    $query =
        $_GET;

    $query['page'] =
        $pageNumber;

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
        Borrowing Report | BKHS Library
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
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #cbd5e1;
            --sidebar-muted: #94a3b8;
            --border: #e5e7eb;
            --background: #f8fafc;
            --text: #111827;
            --muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
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
            bottom: 0;
            width: 260px;
            background: var(--sidebar);
            z-index: 1100;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
        }

        .brand-text {
            color: #fff;
            font-weight: 800;
            font-size: 16px;
            line-height: 1.1;
        }

        .brand-subtitle {
            display: block;
            color: var(--sidebar-muted);
            font-size: 10px;
            font-weight: 500;
            margin-top: 4px;
        }

        .sidebar-section {
            padding: 18px 14px 8px;
        }

        .sidebar-label {
            padding: 0 10px 8px;
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 43px;
            padding: 10px 12px;
            margin-bottom: 3px;
            border-radius: 9px;
            color: var(--sidebar-text);
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Reports Submenu
        |--------------------------------------------------------------------------
        */

        .reports-submenu {
            margin: 2px 0 6px 32px;
            padding-left: 10px;
            border-left: 1px solid rgba(255,255,255,.1);
        }

        .reports-submenu a {
            display: block;
            padding: 8px 10px;
            color: var(--sidebar-muted);
            font-size: 12px;
            border-radius: 7px;
            margin-bottom: 2px;
        }

        .reports-submenu a:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .reports-submenu a.active {
            color: #fff;
            background: rgba(37,99,235,.25);
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .topbar-date {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-mini img {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
        }

        .profile-mini-name {
            font-weight: 600;
            font-size: 13px;
        }

        .profile-mini-role {
            color: var(--muted);
            font-size: 11px;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .page-heading {
            margin-bottom: 22px;
        }

        .page-heading h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 5px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
        }

        /*
        |--------------------------------------------------------------------------
        | Cards
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow:
                0 8px 24px
                rgba(15,23,42,.06);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: #eff6ff;
            color: var(--primary);
            font-size: 20px;
        }

        .stat-value {
            margin-top: 14px;
            font-size: 24px;
            font-weight: 800;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-top: 2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Filter Card
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-top: 24px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #dbe1e8;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow:
                0 0 0 .2rem
                rgba(37,99,235,.1);
        }

        .btn {
            border-radius: 9px;
            font-weight: 600;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Period Buttons
        |--------------------------------------------------------------------------
        */

        .period-buttons {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .period-btn {
            border: 1px solid var(--border);
            background: #fff;
            color: #475569;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .period-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .period-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Report Card
        |--------------------------------------------------------------------------
        */

        .report-card {
            margin-top: 24px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .report-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .report-card-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .report-card-subtitle {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            margin: 0;
            min-width: 1050px;
        }

        .table thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .02em;
            white-space: nowrap;
            padding: 13px 12px;
            border-bottom: 1px solid var(--border);
        }

        .table tbody td {
            padding: 13px 12px;
            vertical-align: middle;
            border-color: #eef2f7;
            font-size: 12px;
        }

        .student-name {
            font-weight: 600;
            color: #1e293b;
        }

        .student-code {
            color: var(--muted);
            font-size: 11px;
            margin-top: 2px;
        }

        .book-title {
            font-weight: 600;
            color: #334155;
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 78px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-borrowed {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-returned {
            background: #ecfdf5;
            color: #047857;
        }

        .status-overdue {
            background: #fef2f2;
            color: #dc2626;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination .page-link {
            border-radius: 7px !important;
            margin: 0 2px;
            border-color: var(--border);
            color: #475569;
            font-size: 12px;
        }

        .pagination .active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state h5 {
            color: #475569;
            font-size: 15px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
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
        | Mobile Menu Button
        |--------------------------------------------------------------------------
        */

        .mobile-menu-btn {
            display: none;
            width: 38px;
            height: 38px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 9px;
            color: #475569;
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

            .main {
                margin-left: 0;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding:
                    22px 18px 90px;
            }

            .mobile-menu-btn {
                display: inline-flex !important;
            }
        }

        @media (max-width: 767.98px) {

            .topbar {
                height: 68px;
            }

            .topbar-title {
                font-size: 16px;
            }

            .topbar-date {
                display: none;
            }

            .profile-mini-name,
            .profile-mini-role {
                display: none;
            }

            .profile-mini img {
                width: 38px;
                height: 38px;
            }

            .content {
                padding:
                    18px 14px 90px;
            }

            .page-heading h1 {
                font-size: 20px;
            }

            .report-card-header {
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
                height: 64px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1200;
                display: flex;
                align-items: center;
                justify-content: space-around;
            }

            .mobile-bottom-nav a {
                flex: 1;
                text-align: center;
                color: #64748b;
                font-size: 10px;
                font-weight: 600;
            }

            .mobile-bottom-nav a i {
                display: block;
                font-size: 18px;
                margin-bottom: 2px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }
        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
-->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS"
        >

        <div class="brand-text">

            BKHS

            <span class="brand-subtitle">
                Library Management
            </span>

        </div>

    </div>

    <div class="sidebar-section">

        <div class="sidebar-label">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="books.php"
            class="sidebar-link"
        >
            <i class="bi bi-book"></i>
            <span>Books</span>
        </a>

        <a
            href="categories.php"
            class="sidebar-link"
        >
            <i class="bi bi-tags"></i>
            <span>Categories</span>
        </a>

        <a
            href="study-attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-check"></i>
            <span>Study Attendance</span>
        </a>

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
            <i class="bi bi-exclamation-triangle"></i>
            <span>Overdue Books</span>
        </a>

        <a
            href="reservations.php"
            class="sidebar-link"
        >
            <i class="bi bi-bookmark"></i>
            <span>Reservations</span>
        </a>

        <a
            href="fines.php"
            class="sidebar-link"
        >
            <i class="bi bi-cash-stack"></i>
            <span>Fines</span>
        </a>

    </div>

    <div class="sidebar-section">

        <div class="sidebar-label">
            Reports
        </div>

        <a
            href="student-study-report.php"
            class="sidebar-link"
        >
            <i class="bi bi-clipboard-data"></i>
            <span>Reports</span>
        </a>

        <div class="reports-submenu">

            <a
                href="student-study-report.php"
            >
                <i class="bi bi-person-lines-fill me-2"></i>
                Student Study
            </a>

            <a
                href="book-report.php"
            >
                <i class="bi bi-book me-2"></i>
                Book List
            </a>

            <a
                href="borrowing-report.php"
                class="active"
            >
                <i class="bi bi-arrow-left-right me-2"></i>
                Borrowing
            </a>

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
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!--
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
-->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h2 class="topbar-title">
                    Borrowing Report
                </h2>

                <div class="topbar-date">
                    <?= e($todayEthiopianFormatted) ?>
                </div>

            </div>

        </div>

        <div class="profile-mini">

            <div class="text-end">

                <div class="profile-mini-name">
                    <?= e($librarianName) ?>
                </div>

                <div class="profile-mini-role">
                    Librarian
                </div>

            </div>

            <img
                src="<?= e($photoUrl) ?>"
                alt="Librarian"
            >

        </div>

    </header>

    <!-- Content -->

    <section class="content">

        <div class="page-heading">

            <h1>
                Borrowing Report
            </h1>

            <p>
                View and export library borrowing
                records for the active academic year.
            </p>

        </div>

        <?php if ($message): ?>

            <div
                class="alert alert-<?= e($message['type'] ?? 'info') ?> alert-dismissible fade show"
                role="alert"
            >

                <?= e(
                    $message['message'] ?? ''
                ) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="row g-3">

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-journal-arrow-up"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalRecords) ?>
                    </div>

                    <div class="stat-label">
                        Total Borrowings
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-book"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($borrowedCount) ?>
                    </div>

                    <div class="stat-label">
                        Currently Borrowed
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($returnedCount) ?>
                    </div>

                    <div class="stat-label">
                        Returned
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-exclamation-circle"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($overdueCount) ?>
                    </div>

                    <div class="stat-label">
                        Overdue
                    </div>

                </div>

            </div>

        </div>

        <!-- Filters -->

        <div class="filter-card">

            <form
                method="get"
                action="borrowing-report.php"
            >

                <div class="mb-3">

                    <label class="form-label">
                        Report Period
                    </label>

                    <div class="period-buttons">

                        <a
                            href="?<?= http_build_query([
                                'period' => 'daily',
                                'grade_id' => $gradeId,
                                'section_id' => $sectionId,
                                'status' => $status,
                                'search' => $search
                            ]) ?>"
                            class="period-btn <?= $period === 'daily' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-calendar-day me-1"></i>
                            Daily
                        </a>

                        <a
                            href="?<?= http_build_query([
                                'period' => 'weekly',
                                'grade_id' => $gradeId,
                                'section_id' => $sectionId,
                                'status' => $status,
                                'search' => $search
                            ]) ?>"
                            class="period-btn <?= $period === 'weekly' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-calendar-week me-1"></i>
                            Weekly
                        </a>

                        <a
                            href="?<?= http_build_query([
                                'period' => 'monthly',
                                'grade_id' => $gradeId,
                                'section_id' => $sectionId,
                                'status' => $status,
                                'search' => $search
                            ]) ?>"
                            class="period-btn <?= $period === 'monthly' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-calendar-month me-1"></i>
                            Monthly
                        </a>

                    </div>

                </div>

                <div class="row g-3">

                    <!-- Search -->

                    <div class="col-12 col-lg-4">

                        <label class="form-label">
                            Search Student or Book
                        </label>

                        <div class="input-group">

                            <span
                                class="input-group-text bg-white"
                            >
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                value="<?= e($search) ?>"
                                placeholder="Student name, code or book title..."
                            >

                        </div>

                    </div>

                    <!-- Grade -->

                    <div class="col-6 col-lg-2">

                        <label class="form-label">
                            Grade
                        </label>

                        <select
                            name="grade_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Grades
                            </option>

                            <?php foreach (
                                $grades as $grade
                            ): ?>

                                <option
                                    value="<?= (int) $grade['id'] ?>"
                                    <?= $gradeId === (int) $grade['id'] ? 'selected' : '' ?>
                                >
                                    Grade
                                    <?= (int) $grade['grade_number'] ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Section -->

                    <div class="col-6 col-lg-2">

                        <label class="form-label">
                            Section
                        </label>

                        <select
                            name="section_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Sections
                            </option>

                            <?php foreach (
                                $sections as $section
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

                    <!-- Status -->

                    <div class="col-6 col-lg-2">

                        <label class="form-label">
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-select"
                        >

                            <option value="">
                                All Status
                            </option>

                            <option
                                value="Borrowed"
                                <?= $status === 'Borrowed' ? 'selected' : '' ?>
                            >
                                Borrowed
                            </option>

                            <option
                                value="Returned"
                                <?= $status === 'Returned' ? 'selected' : '' ?>
                            >
                                Returned
                            </option>

                            <option
                                value="Overdue"
                                <?= $status === 'Overdue' ? 'selected' : '' ?>
                            >
                                Overdue
                            </option>

                        </select>

                    </div>

                    <!-- Buttons -->

                    <div
                        class="col-6 col-lg-2 d-flex align-items-end"
                    >

                        <div class="d-flex gap-2 w-100">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >
                                <i class="bi bi-filter me-1"></i>
                                Filter
                            </button>

                            <a
                                href="borrowing-report.php"
                                class="btn btn-light border"
                                title="Reset"
                            >
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                        </div>

                    </div>

                </div>

                <input
                    type="hidden"
                    name="period"
                    value="<?= e($period) ?>"
                >

            </form>

        </div>

        <!-- Report -->

        <div class="report-card">

            <div class="report-card-header">

                <div>

                    <h3 class="report-card-title">
                        Borrowing Records
                    </h3>

                    <div class="report-card-subtitle">

                        <?= e($academicYearName) ?>

                        ·

                        <?= e($periodLabel) ?>

                        ·

                        <?= e(
                            formatEthiopianDate(
                                $startGregorian
                            )
                        ) ?>

                        <?php if (
                            $startGregorian !==
                            $endGregorian
                        ): ?>

                            -
                            <?= e(
                                formatEthiopianDate(
                                    $endGregorian
                                )
                            ) ?>

                        <?php endif; ?>

                    </div>

                </div>

                <div>

                    <a
                        href="?<?= http_build_query(
                            array_merge(
                                $_GET,
                                [
                                    'export' => 'excel',
                                    'page' => 1
                                ]
                            )
                        ) ?>"
                        class="btn btn-success"
                    >
                        <i class="bi bi-file-earmark-excel me-1"></i>
                        Export Excel
                    </a>

                </div>

            </div>

            <?php if (
                empty($records)
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-journal-x"></i>

                    <h5>
                        No borrowing records found
                    </h5>

                    <p class="mb-0">
                        No borrowing records match
                        the selected filters.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table">

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
                                    Borrow Date
                                </th>

                                <th>
                                    Take Time
                                </th>

                                <th>
                                    Due Date
                                </th>

                                <th>
                                    Return Date
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
                                $records as $index => $record
                            ): ?>

                                <?php

                                $number =
                                    $offset +
                                    $index +
                                    1;

                                $recordStatus =
                                    (string) (
                                        $record['status']
                                        ?? ''
                                    );

                                $statusClass =
                                    match (
                                        $recordStatus
                                    ) {

                                        'Returned' =>
                                            'status-returned',

                                        'Overdue' =>
                                            'status-overdue',

                                        default =>
                                            'status-borrowed'
                                    };

                                ?>

                                <tr>

                                    <td class="text-muted">
                                        <?= $number ?>
                                    </td>

                                    <td>

                                        <div class="student-name">
                                            <?= e(
                                                $record[
                                                    'student_name'
                                                ] ?? ''
                                            ) ?>
                                        </div>

                                        <div class="student-code">
                                            <?= e(
                                                $record[
                                                    'student_code'
                                                ] ?? ''
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>
                                        Grade
                                        <?= (int) (
                                            $record[
                                                'grade_number'
                                            ] ?? 0
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $record[
                                                'section_name'
                                            ] ?? '—'
                                        ) ?>
                                    </td>

                                    <td>

                                        <div class="book-title">
                                            <?= e(
                                                $record[
                                                    'book_title'
                                                ] ?? ''
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>
                                        <?= e(
                                            formatEthiopianDate(
                                                $record[
                                                    'borrow_date'
                                                ] ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            formatTime(
                                                $record[
                                                    'borrow_time'
                                                ] ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            formatEthiopianDate(
                                                $record[
                                                    'due_date'
                                                ] ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            formatEthiopianDate(
                                                $record[
                                                    'return_date'
                                                ] ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            formatTime(
                                                $record[
                                                    'return_time'
                                                ] ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="status-badge <?= e($statusClass) ?>"
                                        >
                                            <?= e(
                                                $recordStatus
                                            ) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- Pagination -->

                <?php

                $startRecord =
                    $totalRecords > 0
                        ? $offset + 1
                        : 0;

                $endRecord =
                    min(
                        $offset + $perPage,
                        $totalRecords
                    );

                ?>

                <div class="pagination-wrapper">

                    <div class="pagination-info">

                        Showing

                        <strong>
                            <?= $startRecord ?>
                        </strong>

                        to

                        <strong>
                            <?= $endRecord ?>
                        </strong>

                        of

                        <strong>
                            <?= $totalRecords ?>
                        </strong>

                        records

                    </div>

                    <?php if (
                        $totalPages > 1
                    ): ?>

                        <nav>

                            <ul class="pagination mb-0">

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page > 1 ? e(pageUrl($page - 1)) : '#' ?>"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

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
                                            href="<?= e(pageUrl(1)) ?>"
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
                                    $pageNumber =
                                        $startPage;
                                    $pageNumber <=
                                        $endPage;
                                    $pageNumber++
                                ): ?>

                                    <li
                                        class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                pageUrl(
                                                    $pageNumber
                                                )
                                            ) ?>"
                                        >
                                            <?= $pageNumber ?>
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
                                                pageUrl(
                                                    $totalPages
                                                )
                                            ) ?>"
                                        >
                                            <?= $totalPages ?>
                                        </a>

                                    </li>

                                <?php endif; ?>

                                <li
                                    class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page < $totalPages ? e(pageUrl($page + 1)) : '#' ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2"></i>

        Dashboard

    </a>

    <a href="books.php">

        <i class="bi bi-book"></i>

        Books

    </a>

    <a
        href="borrowing-report.php"
        class="active"
    >

        <i class="bi bi-bar-chart"></i>

        Reports

    </a>

    <a href="profile.php">

        <i class="bi bi-person"></i>

        Profile

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
        document.getElementById(
            'sidebar'
        );

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    const mobileMenuBtn =
        document.getElementById(
            'mobileMenuBtn'
        );

    function openSidebar() {

        sidebar.classList.add(
            'show'
        );

        overlay.classList.add(
            'show'
        );

        document.body.style.overflow =
            'hidden';
    }

    function closeSidebar() {

        sidebar.classList.remove(
            'show'
        );

        overlay.classList.remove(
            'show'
        );

        document.body.style.overflow =
            '';
    }

    if (mobileMenuBtn) {

        mobileMenuBtn.addEventListener(
            'click',
            openSidebar
        );
    }

    if (overlay) {

        overlay.addEventListener(
            'click',
            closeSidebar
        );
    }

    document
        .querySelectorAll(
            '.sidebar-link'
        )
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <=
                            991
                        ) {

                            closeSidebar();
                        }
                    }
                );
            }
        );

    window.addEventListener(
        'resize',
        function () {

            if (
                window.innerWidth > 991
            ) {

                closeSidebar();
            }
        }
    );

</script>

</body>

</html>