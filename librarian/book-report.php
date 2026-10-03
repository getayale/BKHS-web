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
| Database
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';

/*
|--------------------------------------------------------------------------
| Composer / PhpSpreadsheet
|--------------------------------------------------------------------------
*/

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
| Filters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string) (
            $_GET['search'] ?? ''
        )
    );

$categoryId =
    safeInt(
        $_GET['category_id'] ?? 0
    );

$page =
    max(
        1,
        safeInt(
            $_GET['page'] ?? 1
        )
    );

$perPage = 20;

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categories = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name
    FROM library_categories
    WHERE is_deleted = 0
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

        $categories[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Conditions
|--------------------------------------------------------------------------
*/

$where = [
    'lb.is_deleted = 0'
];

$params = [];

$types = '';

if ($search !== '') {

    $where[] = "
        (
            lb.title LIKE ?
            OR lc.name LIKE ?
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

    $types .= 'ss';
}

if ($categoryId > 0) {

    $where[] =
        'lb.category_id = ?';

    $params[] =
        $categoryId;

    $types .= 'i';
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Count Books
|--------------------------------------------------------------------------
*/

$totalBooks = 0;

$countSql = "
    SELECT COUNT(*)
    FROM library_books lb

    LEFT JOIN library_categories lc
        ON lc.id = lb.category_id

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
        $totalBooks
    );

    $stmt->fetch();

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
            $totalBooks /
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
| Export Excel Using PhpSpreadsheet
|--------------------------------------------------------------------------
|
| Export ALL books matching the current filters.
|
*/

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'excel'
) {

    $exportSql = "
        SELECT
            lb.title,
            lc.name AS category_name,
            lb.total_quantity

        FROM library_books lb

        LEFT JOIN library_categories lc
            ON lc.id = lb.category_id

        WHERE {$whereSql}

        ORDER BY
            lb.title ASC
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
    | Create Spreadsheet
    |--------------------------------------------------------------------------
    */

    $spreadsheet =
        new Spreadsheet();

    $sheet =
        $spreadsheet->getActiveSheet();

    $sheet->setTitle(
        'Book List'
    );

    /*
    |--------------------------------------------------------------------------
    | Report Title
    |--------------------------------------------------------------------------
    */

    $sheet->mergeCells(
        'A1:D1'
    );

    $sheet->setCellValue(
        'A1',
        'BKHS Library Book List'
    );

    $sheet->getStyle(
        'A1:D1'
    )->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 18,
        ],
        'alignment' => [
            'horizontal' =>
                Alignment::HORIZONTAL_LEFT,
            'vertical' =>
                Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)
        ->setRowHeight(30);

    /*
    |--------------------------------------------------------------------------
    | Report Information
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValue(
        'A2',
        'Report'
    );

    $sheet->setCellValue(
        'B2',
        'School Library Book List'
    );

    $sheet->setCellValue(
        'A3',
        'Total Books'
    );

    $sheet->setCellValue(
        'B3',
        count($exportRows)
    );

    $sheet->getStyle(
        'A2:A3'
    )->getFont()->setBold(true);

    /*
    |--------------------------------------------------------------------------
    | Table Header
    |--------------------------------------------------------------------------
    */

    $headerRow = 5;

    $headers = [
        'No',
        'Book Title',
        'Category',
        'Quantity'
    ];

    $sheet->fromArray(
        $headers,
        null,
        'A' . $headerRow
    );

    /*
    |--------------------------------------------------------------------------
    | Header Styling
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        'A5:D5'
    )->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => [
                'rgb' => 'FFFFFF',
            ],
        ],
        'fill' => [
            'fillType' =>
                Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => '2563EB',
            ],
        ],
        'alignment' => [
            'horizontal' =>
                Alignment::HORIZONTAL_CENTER,
            'vertical' =>
                Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' =>
                    Border::BORDER_THIN,
                'color' => [
                    'rgb' => 'D1D5DB',
                ],
            ],
        ],
    ]);

    $sheet->getRowDimension(
        $headerRow
    )->setRowHeight(24);

    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    $excelRow =
        $headerRow + 1;

    foreach (
        $exportRows
        as $index => $row
    ) {

        $sheet->setCellValue(
            'A' . $excelRow,
            $index + 1
        );

        $sheet->setCellValue(
            'B' . $excelRow,
            (string) (
                $row['title'] ?? ''
            )
        );

        $sheet->setCellValue(
            'C' . $excelRow,
            (string) (
                $row['category_name'] ??
                'Uncategorized'
            )
        );

        $sheet->setCellValue(
            'D' . $excelRow,
            (int) (
                $row['total_quantity'] ??
                0
            )
        );

        $excelRow++;
    }

    /*
    |--------------------------------------------------------------------------
    | Data Styling
    |--------------------------------------------------------------------------
    */

    if ($excelRow > $headerRow + 1) {

        $lastDataRow =
            $excelRow - 1;

        $sheet->getStyle(
            'A6:D' . $lastDataRow
        )->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' =>
                        Border::BORDER_THIN,
                    'color' => [
                        'rgb' => 'E5E7EB',
                    ],
                ],
            ],
            'alignment' => [
                'vertical' =>
                    Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        /*
        |----------------------------------------------------------------------
        | Center No and Quantity
        |----------------------------------------------------------------------
        */

        $sheet->getStyle(
            'A6:A' . $lastDataRow
        )->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );

        $sheet->getStyle(
            'D6:D' . $lastDataRow
        )->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Column Widths
    |--------------------------------------------------------------------------
    */

    $sheet->getColumnDimension('A')
        ->setWidth(10);

    $sheet->getColumnDimension('B')
        ->setWidth(45);

    $sheet->getColumnDimension('C')
        ->setWidth(28);

    $sheet->getColumnDimension('D')
        ->setWidth(15);

    /*
    |--------------------------------------------------------------------------
    | Freeze Header
    |--------------------------------------------------------------------------
    */

    $sheet->freezePane(
        'A6'
    );

    /*
    |--------------------------------------------------------------------------
    | Auto Filter
    |--------------------------------------------------------------------------
    */

    if ($excelRow > $headerRow) {

        $sheet->setAutoFilter(
            'A5:D' .
            ($excelRow - 1)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Page Setup
    |--------------------------------------------------------------------------
    */

    $sheet->getPageSetup()
        ->setOrientation(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
        );

    $sheet->getPageSetup()
        ->setPaperSize(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
        );

    $sheet->getPageSetup()
        ->setFitToWidth(1);

    $sheet->getPageSetup()
        ->setFitToHeight(0);

    /*
    |--------------------------------------------------------------------------
    | Output XLSX
    |--------------------------------------------------------------------------
    */

    $filename =
        'BKHS_Book_List_' .
        date('Ymd_His') .
        '.xlsx';

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
        'Cache-Control: max-age=1'
    );

    header(
        'Expires: Mon, 26 Jul 1997 05:00:00 GMT'
    );

    header(
        'Last-Modified: ' .
        gmdate('D, d M Y H:i:s') .
        ' GMT'
    );

    header(
        'Pragma: public'
    );

    /*
    |--------------------------------------------------------------------------
    | Write File
    |--------------------------------------------------------------------------
    */

    $writer =
        new Xlsx(
            $spreadsheet
        );

    $writer->save(
        'php://output'
    );

    $spreadsheet->disconnectWorksheets();

    unset($spreadsheet);

    exit;
}

/*
|--------------------------------------------------------------------------
| Book Records
|--------------------------------------------------------------------------
*/

$books = [];

$sql = "
    SELECT
        lb.id,
        lb.title,
        lb.total_quantity,
        lb.created_at,

        lc.name AS category_name

    FROM library_books lb

    LEFT JOIN library_categories lc
        ON lc.id = lb.category_id

    WHERE {$whereSql}

    ORDER BY
        lb.title ASC

    LIMIT ? OFFSET ?
";

$dataParams =
    $params;

$dataTypes =
    $types . 'ii';

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

        $books[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Category Name
|--------------------------------------------------------------------------
*/

$selectedCategoryName =
    'All Categories';

if ($categoryId > 0) {

    foreach (
        $categories
        as $category
    ) {

        if (
            (int) $category['id'] ===
            $categoryId
        ) {

            $selectedCategoryName =
                $category['name'];

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
        Book Report | BKHS Library
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
            border-bottom: 1px solid rgba(255,255,255,.08);
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
            background: rgba(239,68,68,.12);
            color: #fecaca;
        }

        /*
        |--------------------------------------------------------------------------
        | Reports Submenu
        |--------------------------------------------------------------------------
        */

        .reports-submenu {
            margin: 2px 0 8px 32px;
            border-left: 1px solid rgba(255,255,255,.10);
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
            background: rgba(255,255,255,.05);
            color: #fff;
        }

        .reports-submenu a.active {
            background: rgba(37,99,235,.18);
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
            background: rgba(255,255,255,.96);
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

        /*
        |--------------------------------------------------------------------------
        | Filters
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
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.10);
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
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            font-size: 18px;
            font-weight: 800;
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
            min-width: 700px;
            margin: 0;
            border-collapse: collapse;
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
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            color: #374151;
            vertical-align: middle;
        }

        .report-table tbody tr:hover {
            background: #fafcff;
        }

        .book-title {
            font-weight: 700;
            color: #111827;
        }

        .category-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 700;
        }

        .quantity-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            padding: 5px 9px;
            border-radius: 7px;
            background: #f3f4f6;
            color: #374151;
            font-size: 10px;
            font-weight: 800;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
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
        | Sidebar Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
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
                box-shadow: 0 -5px 20px rgba(15,23,42,.06);
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

            .filter-actions {
                width: 100%;
            }

            .filter-actions > * {
                flex: 1;
            }

            .btn-primary-custom,
            .btn-light-custom,
            .btn-excel {
                padding-left: 10px;
                padding-right: 10px;
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
            >
                <i class="bi bi-person-lines-fill"></i>
                <span>Student Study</span>
            </a>

            <a
                href="book-report.php"
                class="active"
            >
                <i class="bi bi-bookshelf"></i>
                <span>Book List</span>
            </a>

            <a
                href="borrowing-report.php"
            >
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
                    Book Report
                </h1>

                <p class="page-subtitle">
                    List of books available in the school library
                </p>

            </div>

        </div>

        <div class="topbar-right">

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

        <!-- Header -->

        <div class="page-header-card">

            <div
                class="d-flex flex-wrap align-items-center justify-content-between gap-3"
            >

                <div>

                    <h2 class="header-title">
                        School Library Book List
                    </h2>

                    <p class="header-description">
                        View the books registered in the school library.
                        Borrowed and available quantities are not included
                        in this report.
                    </p>

                </div>

                <div>

                    <span class="badge text-bg-light border px-3 py-2">
                        <i class="bi bi-bookshelf me-1"></i>
                        Library Inventory List
                    </span>

                </div>

            </div>

        </div>

        <!-- Filters -->

        <div class="filter-card">

            <form
                method="get"
                action="book-report.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-12 col-md-5 col-lg-5">

                        <label
                            for="search"
                            class="filter-label"
                        >
                            Search Book
                        </label>

                        <input
                            type="text"
                            id="search"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Search by book title or category..."
                        >

                    </div>

                    <div class="col-12 col-md-4 col-lg-3">

                        <label
                            for="category_id"
                            class="filter-label"
                        >
                            Category
                        </label>

                        <select
                            name="category_id"
                            id="category_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Categories
                            </option>

                            <?php foreach (
                                $categories
                                as $category
                            ): ?>

                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>
                                >
                                    <?= e(
                                        $category['name']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12 col-md-3 col-lg-4">

                        <div class="filter-actions">

                            <button
                                type="submit"
                                class="btn-primary-custom"
                            >
                                <i class="bi bi-search me-1"></i>
                                Apply Filter
                            </button>

                            <a
                                href="book-report.php"
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

            <div class="col-12 col-md-4">

                <div class="summary-card">

                    <div class="summary-label">
                        Total Books
                    </div>

                    <div class="summary-value">
                        <?= number_format($totalBooks) ?>
                    </div>

                </div>

            </div>

            <div class="col-12 col-md-4">

                <div class="summary-card">

                    <div class="summary-label">
                        Category
                    </div>

                    <div class="summary-value fs-6">
                        <?= e($selectedCategoryName) ?>
                    </div>

                </div>

            </div>

            <div class="col-12 col-md-4">

                <div class="summary-card">

                    <div class="summary-label">
                        Showing
                    </div>

                    <div class="summary-value fs-6">

                        <?php if ($totalBooks > 0): ?>

                            <?= number_format(
                                $offset + 1
                            ) ?>

                            -

                            <?= number_format(
                                min(
                                    $offset +
                                    $perPage,
                                    $totalBooks
                                )
                            ) ?>

                        <?php else: ?>

                            0

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

        <!-- Table -->

        <div class="table-card">

            <div class="table-header">

                <div>

                    <h3 class="table-title">
                        Books in School Library
                    </h3>

                    <p class="table-subtitle">
                        <?= number_format($totalBooks) ?>
                        book(s) found
                    </p>

                </div>

                <div class="small text-muted">
                    20 records per page
                </div>

            </div>

            <div class="table-responsive">

                <?php if (empty($books)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-book"></i>
                        </div>

                        <div class="empty-title">
                            No Books Found
                        </div>

                        <div class="empty-text">
                            No books match the selected filters.
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
                                    Book Title
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Quantity
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $books
                                as $index => $book
                            ): ?>

                                <tr>

                                    <td>
                                        <?= $offset + $index + 1 ?>
                                    </td>

                                    <td>

                                        <span class="book-title">

                                            <?= e(
                                                $book['title'] ?? ''
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="category-badge">

                                            <?= e(
                                                $book['category_name'] ??
                                                'Uncategorized'
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="quantity-badge">

                                            <?= number_format(
                                                (int) (
                                                    $book['total_quantity'] ??
                                                    0
                                                )
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
                $totalPages >
                1
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
                                    $offset +
                                    $perPage,
                                    $totalBooks
                                )
                            ) ?>
                        </strong>

                        of

                        <strong>
                            <?= number_format(
                                $totalBooks
                            ) ?>
                        </strong>

                        books

                    </div>

                    <nav>

                        <ul class="pagination">

                            <?php if (
                                $page >
                                1
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
                                $startPage >
                                1
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
                                    $startPage >
                                    2
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

    <a
        href="dashboard.php"
    >

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Dashboard
        </span>

    </a>

    <a
        href="books.php"
    >

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

    <a
        href="profile.php"
    >

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

        if (
            window.innerWidth >=
            992
        ) {

            closeSidebar();
        }

    }
);

</script>

</body>
</html>