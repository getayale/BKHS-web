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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function safeString(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function redirectWithMessage(
    string $type,
    string $message
): never {
    $_SESSION['return_book_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: return-book.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

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
            safeString(
                $row['full_name'] ?? 'Librarian'
            );

        $librarianEmail =
            safeString(
                $row['email'] ?? ''
            );

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

    if ($row =
        $result->fetch_assoc()
    ) {
        $activeAcademicYear =
            $row;
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
    safeString(
        $activeAcademicYear['name'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$todayEthiopianFormatted =
    $todayEthiopian['formatted'];

/*
|--------------------------------------------------------------------------
| Format Ethiopian Date
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(
    ?string $gregorianDate
): string {

    if (
        empty($gregorianDate)
    ) {
        return '—';
    }

    try {

        $date =
            EthiopianCalendar::fromGregorian(
                substr(
                    $gregorianDate,
                    0,
                    10
                )
            );

        return $date['formatted'];

    } catch (Throwable $exception) {

        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Format Time
|--------------------------------------------------------------------------
*/

function formatTime(
    ?string $dateTime
): string {

    if (
        empty($dateTime)
    ) {
        return '—';
    }

    try {

        $date =
            new DateTimeImmutable(
                $dateTime,
                new DateTimeZone(
                    'Africa/Addis_Ababa'
                )
            );

        return $date->format(
            'h:i A'
        );

    } catch (Throwable $exception) {

        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Handle Return Book
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'return_book'
) {

    $borrowingId =
        (int) (
            $_POST['borrowing_id']
            ?? 0
        );

    if ($borrowingId <= 0) {

        redirectWithMessage(
            'danger',
            'Invalid borrowing record.'
        );
    }

    $conn->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Lock Borrowing Record
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                b.id,
                b.student_id,
                b.student_registration_id,
                b.academic_year_id,
                b.book_id,
                b.status,
                b.return_date,
                b.return_time,

                s.full_name AS student_name,
                s.student_code,

                lb.title AS book_title,
                lb.total_quantity,
                lb.available_quantity

            FROM library_borrowings b

            INNER JOIN students s
                ON s.id = b.student_id

            INNER JOIN library_books lb
                ON lb.id = b.book_id

            WHERE b.id = ?
              AND b.academic_year_id = ?
            LIMIT 1

            FOR UPDATE
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to find the borrowing record.'
            );
        }

        $stmt->bind_param(
            'ii',
            $borrowingId,
            $academicYearId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $borrowing =
            $result->fetch_assoc();

        $stmt->close();

        if (!$borrowing) {

            throw new RuntimeException(
                'The borrowing record was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Returning Already Returned Book
        |--------------------------------------------------------------------------
        */

        $currentStatus =
            safeString(
                $borrowing['status']
                ?? ''
            );

        if (
            $currentStatus === 'Returned'
        ) {

            throw new RuntimeException(
                'This book has already been returned.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Lock Book Row
        |--------------------------------------------------------------------------
        */

        $bookId =
            (int) $borrowing['book_id'];

        $stmt = $conn->prepare("
            SELECT
                id,
                title,
                total_quantity,
                available_quantity
            FROM library_books
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to check the book.'
            );
        }

        $stmt->bind_param(
            'i',
            $bookId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $book =
            $result->fetch_assoc();

        $stmt->close();

        if (!$book) {

            throw new RuntimeException(
                'The book was not found.'
            );
        }

        $availableQuantity =
            (int) $book['available_quantity'];

        $totalQuantity =
            (int) $book['total_quantity'];

        /*
        |--------------------------------------------------------------------------
        | Increase Available Quantity
        |--------------------------------------------------------------------------
        */

        $newAvailableQuantity =
            min(
                $totalQuantity,
                $availableQuantity + 1
            );

        /*
        |--------------------------------------------------------------------------
        | Update Borrowing
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE library_borrowings
            SET
                return_date = CURDATE(),
                return_time = NOW(),
                status = 'Returned',
                recorded_by = ?
            WHERE id = ?
              AND academic_year_id = ?
              AND status IN (
                  'Borrowed',
                  'Overdue'
              )
            LIMIT 1
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to update the borrowing record.'
            );
        }

        $stmt->bind_param(
            'iii',
            $librarianId,
            $borrowingId,
            $academicYearId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new RuntimeException(
                'Unable to record the book return.'
            );
        }

        if ($stmt->affected_rows !== 1) {

            $stmt->close();

            throw new RuntimeException(
                'The book return could not be recorded. It may already have been returned.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Update Book Quantity
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE library_books
            SET available_quantity = ?
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to update book quantity.'
            );
        }

        $stmt->bind_param(
            'ii',
            $newAvailableQuantity,
            $bookId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new RuntimeException(
                'Unable to update available quantity.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        redirectWithMessage(
            'success',
            'Book "' .
            safeString(
                $borrowing['book_title']
            ) .
            '" was successfully returned by ' .
            safeString(
                $borrowing['student_name']
            ) .
            '.'
        );

    } catch (Throwable $exception) {

        $conn->rollback();

        redirectWithMessage(
            'danger',
            $exception->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    safeString(
        $_GET['search'] ?? ''
    );

$statusFilter =
    safeString(
        $_GET['status'] ?? ''
    );

$page =
    max(
        1,
        (int) (
            $_GET['page']
            ?? 1
        )
    );

$perPage = 20;

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Return Page
|--------------------------------------------------------------------------
|
| Default view shows books that are still borrowed/overdue.
|--------------------------------------------------------------------------
*/

$where = [
    "b.academic_year_id = ?",
    "s.id IS NOT NULL",
    "lb.is_deleted = 0"
];

$params = [
    $academicYearId
];

$types = 'i';

if ($search !== '') {

    $where[] = "
        (
            s.full_name LIKE ?
            OR s.student_code LIKE ?
            OR lb.title LIKE ?
        )
    ";

    $searchValue =
        '%' . $search . '%';

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $types .= 'sss';
}

if (
    in_array(
        $statusFilter,
        [
            'Borrowed',
            'Returned',
            'Overdue'
        ],
        true
    )
) {

    $where[] =
        "b.status = ?";

    $params[] =
        $statusFilter;

    $types .= 's';

} else {

    /*
    |--------------------------------------------------------------------------
    | Default: only books needing return
    |--------------------------------------------------------------------------
    */

    $where[] = "
        b.status IN (
            'Borrowed',
            'Overdue'
        )
    ";
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$totalRecords = 0;

$countSql = "
    SELECT
        COUNT(*) AS total

    FROM library_borrowings b

    INNER JOIN students s
        ON s.id = b.student_id

    INNER JOIN library_books lb
        ON lb.id = b.book_id

    WHERE {$whereSql}
";

$stmt =
    $conn->prepare(
        $countSql
    );

if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $totalRecords =
            (int) $row['total'];
    }

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

    $offset =
        ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Fetch Records
|--------------------------------------------------------------------------
*/

$borrowings = [];

$listSql = "
    SELECT
        b.id,
        b.student_id,
        b.student_registration_id,
        b.book_id,
        b.borrow_date,
        b.borrow_time,
        b.due_date,
        b.return_date,
        b.return_time,
        b.status,
        b.note,

        s.full_name AS student_name,
        s.student_code,

        lb.title AS book_title,
        lb.available_quantity

    FROM library_borrowings b

    INNER JOIN students s
        ON s.id = b.student_id

    INNER JOIN library_books lb
        ON lb.id = b.book_id

    WHERE {$whereSql}

    ORDER BY
        CASE
            WHEN b.status = 'Overdue'
                THEN 1
            WHEN b.status = 'Borrowed'
                THEN 2
            ELSE 3
        END,
        b.borrow_time ASC,
        b.id ASC

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
        $borrowings[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$currentBorrowed = 0;
$overdueCount = 0;
$returnedToday = 0;

/*
|--------------------------------------------------------------------------
| Currently Borrowed
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Borrowed'
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $currentBorrowed =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Overdue
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Overdue'
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $overdueCount =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Returned Today
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Returned'
      AND return_date = CURDATE()
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $returnedToday =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $page,
    string $search,
    string $status
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['search'] =
            $search;
    }

    if ($status !== '') {
        $query['status'] =
            $status;
    }

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
        Return Book | BKHS Library
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
            --border: #e5e7eb;
            --muted: #64748b;
            --bg: #f8fafc;
            --white: #ffffff;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: #0f172a;
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
            color: #fff;
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

        .sidebar-logo {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 10px;
            background: #fff;
        }

        .sidebar-brand-text {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
        }

        .sidebar-brand small {
            display: block;
            color: #94a3b8;
            font-size: 10px;
            font-weight: 500;
            margin-top: 1px;
        }

        .sidebar-section {
            padding: 20px 14px 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 10px;
            font-weight: 700;
        }

        .sidebar-nav {
            padding: 8px 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            margin-bottom: 4px;
            color: var(--sidebar-text);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-nav a i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-nav a:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-nav a.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .sidebar-nav a.active i {
            color: #60a5fa;
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
            height: 76px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .profile-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-text {
            text-align: right;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .profile-role {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .profile-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .academic-year-bar {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .academic-year-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .academic-year-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            border-radius: 9px;
            font-size: 17px;
        }

        .academic-year-label {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 2px;
        }

        .academic-year-value {
            font-weight: 700;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
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
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            margin-bottom: 14px;
        }

        .stat-blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .stat-green {
            background: #dcfce7;
            color: #16a34a;
        }

        .stat-orange {
            background: #ffedd5;
            color: #ea580c;
        }

        .stat-red {
            background: #fee2e2;
            color: #dc2626;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 25px;
            line-height: 1;
            font-weight: 800;
        }

        /*
        |--------------------------------------------------------------------------
        | Card
        |--------------------------------------------------------------------------
        */

        .card-custom {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }

        .card-header-custom {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .card-header-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-return {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Forms
        |--------------------------------------------------------------------------
        */

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #dbe1e8;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow:
                0 0 0 .2rem
                rgba(37,99,235,.10);
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-box input {
            padding-left: 38px;
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-responsive {
            border-radius:
                0 0 14px 14px;
        }

        .table {
            margin: 0;
            vertical-align: middle;
        }

        .table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .student-name {
            font-weight: 700;
            color: #0f172a;
        }

        .student-code {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        .book-title {
            font-weight: 600;
            color: #334155;
        }

        .date-text {
            font-weight: 500;
            color: #334155;
            white-space: nowrap;
        }

        .time-text {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-borrowed {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .status-returned {
            color: #15803d;
            background: #dcfce7;
        }

        .status-overdue {
            color: #b91c1c;
            background: #fee2e2;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #64748b;
        }

        .empty-state-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 14px;
            border-radius: 15px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            color: #94a3b8;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1050;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            margin-right: 10px;
            color: #334155;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Nav
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
                transform:
                    translateX(-100%);
            }

            .sidebar.show {
                transform:
                    translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }
        }

        @media (max-width: 767.98px) {

            body {
                padding-bottom: 66px;
            }

            .topbar {
                height: 68px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .profile-text {
                display: none;
            }

            .profile-avatar {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 16px;
            }

            .academic-year-bar {
                align-items: flex-start;
            }

            .academic-year-right {
                display: none;
            }

            .card-header-custom {
                align-items: flex-start;
                flex-direction: column;
            }

            .table {
                min-width: 1050px;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1150;
                justify-content: space-around;
                align-items: center;
            }

            .mobile-bottom-nav a {
                color: #64748b;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                font-size: 9px;
                font-weight: 600;
                min-width: 58px;
            }

            .mobile-bottom-nav a i {
                font-size: 18px;
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
            class="sidebar-logo"
        >

        <div>

            <div class="sidebar-brand-text">
                BKHS
            </div>

            <small>
                Library Management
            </small>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="books.php">
            <i class="bi bi-book-fill"></i>
            <span>Books</span>
        </a>

        <a href="categories.php">
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a href="study-attendance.php">
            <i class="bi bi-journal-check"></i>
            <span>Study Attendance</span>
        </a>

        <a href="borrow-book.php">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Borrow Book</span>
        </a>

        <a
            href="return-book.php"
            class="active"
        >
            <i class="bi bi-box-arrow-in-down-left"></i>
            <span>Return Book</span>
        </a>

        <a href="borrowing-control.php">
            <i class="bi bi-sliders"></i>
            <span>Borrowing Control</span>
        </a>

        <a href="overdue-books.php">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Overdue Books</span>
        </a>

        <a href="reservations.php">
            <i class="bi bi-bookmark-star-fill"></i>
            <span>Reservations</span>
        </a>

        <a href="fines.php">
            <i class="bi bi-cash-stack"></i>
            <span>Fines</span>
        </a>

        <a href="reports.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

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

    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Return Book
                </h1>

                <div class="page-subtitle">
                    Record returned library books
                </div>

            </div>

        </div>

        <div class="profile-area">

            <div class="profile-text">

                <div class="profile-name">
                    <?= e($librarianName) ?>
                </div>

                <div class="profile-role">
                    Librarian
                </div>

            </div>

            <img
                src="<?= e($photoUrl) ?>"
                alt="Librarian"
                class="profile-avatar"
                onerror="this.src='../public/images/default-avatar.png';"
            >

        </div>

    </header>

    <section class="content">

        <!-- Academic Year -->

        <div class="academic-year-bar">

            <div class="academic-year-left">

                <div class="academic-year-icon">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div>

                    <div class="academic-year-label">
                        Active Academic Year
                    </div>

                    <div class="academic-year-value">
                        <?= e($academicYearName) ?>
                    </div>

                </div>

            </div>

            <div class="academic-year-right">

                <span class="small">
                    Today:
                    <strong>
                        <?= e(
                            $todayEthiopianFormatted
                        ) ?>
                    </strong>
                </span>

            </div>

        </div>

        <!-- Flash Message -->

        <?php if ($flashMessage = (
            $_SESSION['return_book_message']
            ?? null
        )): ?>

            <?php
            unset(
                $_SESSION['return_book_message']
            );
            ?>

            <div
                class="alert alert-<?= e(
                    $flashMessage['type']
                    ?? 'info'
                ) ?> alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-info-circle me-2"></i>

                <?= e(
                    $flashMessage['message']
                    ?? ''
                ) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-blue">
                        <i class="bi bi-book-half"></i>
                    </div>

                    <div class="stat-label">
                        Currently Borrowed
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $currentBorrowed
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-red">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>

                    <div class="stat-label">
                        Overdue
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $overdueCount
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-green">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>

                    <div class="stat-label">
                        Returned Today
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $returnedToday
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-orange">
                        <i class="bi bi-arrow-return-left"></i>
                    </div>

                    <div class="stat-label">
                        Awaiting Return
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $currentBorrowed +
                            $overdueCount
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Records -->

        <div class="card-custom">

            <div class="card-header-custom">

                <div>

                    <h2 class="card-header-title">
                        Books Awaiting Return
                    </h2>

                    <div class="text-muted small mt-1">
                        <?= number_format(
                            $totalRecords
                        ) ?>
                        record<?= $totalRecords === 1 ? '' : 's' ?>
                    </div>

                </div>

            </div>

            <!-- Filters -->

            <div class="p-3 border-bottom">

                <form
                    method="GET"
                    class="row g-2"
                >

                    <div class="col-lg-7">

                        <div class="search-box">

                            <i class="bi bi-search"></i>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search student name, student code or book title..."
                                value="<?= e(
                                    $search
                                ) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-lg-3">

                        <select
                            name="status"
                            class="form-select"
                        >

                            <option value="">
                                Awaiting Return
                            </option>

                            <option
                                value="Borrowed"
                                <?= $statusFilter === 'Borrowed'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Borrowed
                            </option>

                            <option
                                value="Overdue"
                                <?= $statusFilter === 'Overdue'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Overdue
                            </option>

                            <option
                                value="Returned"
                                <?= $statusFilter === 'Returned'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Returned
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2">

                        <button
                            type="submit"
                            class="btn btn-outline-primary w-100"
                        >
                            <i class="bi bi-filter me-1"></i>
                            Filter
                        </button>

                    </div>

                </form>

            </div>

            <!-- Table -->

            <?php if (
                !empty($borrowings)
            ): ?>

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    #
                                </th>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Book
                                </th>

                                <th>
                                    Borrow Date
                                </th>

                                <th>
                                    Due Date
                                </th>

                                <th>
                                    Return
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $borrowings
                            as $index =>
                                $borrowing
                        ): ?>

                            <?php

                            $status =
                                safeString(
                                    $borrowing[
                                        'status'
                                    ] ?? ''
                                );

                            $statusClass =
                                match ($status) {

                                    'Returned' =>
                                        'status-returned',

                                    'Overdue' =>
                                        'status-overdue',

                                    default =>
                                        'status-borrowed'
                                };

                            $statusIcon =
                                match ($status) {

                                    'Returned' =>
                                        'bi-check-circle-fill',

                                    'Overdue' =>
                                        'bi-exclamation-circle-fill',

                                    default =>
                                        'bi-book-fill'
                                };

                            ?>

                            <tr>

                                <td class="text-muted">
                                    <?= e(
                                        $offset +
                                        $index +
                                        1
                                    ) ?>
                                </td>

                                <td>

                                    <div class="student-name">
                                        <?= e(
                                            $borrowing[
                                                'student_name'
                                            ]
                                        ) ?>
                                    </div>

                                    <div class="student-code">
                                        <?= e(
                                            $borrowing[
                                                'student_code'
                                            ]
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="book-title">
                                        <?= e(
                                            $borrowing[
                                                'book_title'
                                            ]
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="date-text">
                                        <?= e(
                                            formatEthiopianDate(
                                                $borrowing[
                                                    'borrow_date'
                                                ]
                                            )
                                        ) ?>
                                    </div>

                                    <div class="time-text">
                                        <?= e(
                                            formatTime(
                                                $borrowing[
                                                    'borrow_time'
                                                ]
                                            )
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="date-text">
                                        <?= e(
                                            formatEthiopianDate(
                                                $borrowing[
                                                    'due_date'
                                                ]
                                            )
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <?php if (
                                        $status !==
                                        'Returned'
                                    ): ?>

                                        <button
                                            type="button"
                                            class="btn btn-success btn-sm btn-return"
                                            data-bs-toggle="modal"
                                            data-bs-target="#returnConfirmModal"
                                            data-id="<?= e(
                                                $borrowing['id']
                                            ) ?>"
                                            data-student="<?= e(
                                                $borrowing[
                                                    'student_name'
                                                ]
                                            ) ?>"
                                            data-book="<?= e(
                                                $borrowing[
                                                    'book_title'
                                                ]
                                            ) ?>"
                                    >
                                            <i class="bi bi-arrow-return-left"></i>
                                            Return
                                        </button>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Returned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <span
                                        class="status-badge <?= e(
                                            $statusClass
                                        ) ?>"
                                    >

                                        <i
                                            class="bi <?= e(
                                                $statusIcon
                                            ) ?>"
                                        ></i>

                                        <?= e(
                                            $status
                                        ) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div class="empty-state-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <h5 class="fw-bold mb-2">
                        No books awaiting return
                    </h5>

                    <p class="mb-0">
                        There are no active borrowing records matching your filter.
                    </p>

                </div>

            <?php endif; ?>

            <!-- Pagination -->

            <?php if (
                $totalPages > 1
            ): ?>

                <div class="px-3 py-3 border-top">

                    <nav aria-label="Pagination">

                        <ul class="pagination pagination-sm mb-0 justify-content-center">

                            <li
                                class="page-item <?= $page <= 1
                                    ? 'disabled'
                                    : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page > 1
                                        ? e(
                                            paginationUrl(
                                                $page - 1,
                                                $search,
                                                $statusFilter
                                            )
                                        )
                                        : '#' ?>"
                                >
                                    Previous
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

                            <?php for (
                                $pageNumber =
                                    $startPage;
                                $pageNumber <=
                                    $endPage;
                                $pageNumber++
                            ): ?>

                                <li
                                    class="page-item <?= $pageNumber === $page
                                        ? 'active'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $pageNumber,
                                                $search,
                                                $statusFilter
                                            )
                                        ) ?>"
                                    >
                                        <?= $pageNumber ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li
                                class="page-item <?= $page >= $totalPages
                                    ? 'disabled'
                                    : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page < $totalPages
                                        ? e(
                                            paginationUrl(
                                                $page + 1,
                                                $search,
                                                $statusFilter
                                            )
                                        )
                                        : '#' ?>"
                                >
                                    Next
                                </a>

                            </li>

                        </ul>

                    </nav>

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

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Home
        </span>

    </a>

    <a href="books.php">

        <i class="bi bi-book-fill"></i>

        <span>
            Books
        </span>

    </a>

    <a href="borrow-book.php">

        <i class="bi bi-box-arrow-up-right"></i>

        <span>
            Borrow
        </span>

    </a>

    <a
        href="return-book.php"
        class="active"
    >

        <i class="bi bi-arrow-return-left"></i>

        <span>
            Return
        </span>

    </a>

    <a href="profile.php">

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>

</nav>

<!--
|--------------------------------------------------------------------------
| Return Confirmation Modal
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="returnConfirmModal"
    tabindex="-1"
    aria-labelledby="returnConfirmModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <form
                method="POST"
                id="returnBookForm"
            >

                <input
                    type="hidden"
                    name="action"
                    value="return_book"
                >

                <input
                    type="hidden"
                    name="borrowing_id"
                    id="returnBorrowingId"
                >

                <div class="modal-header">

                    <div>

                        <h5
                            class="modal-title fw-bold"
                            id="returnConfirmModalLabel"
                        >
                            Return Book
                        </h5>

                        <div class="text-muted small mt-1">
                            Confirm that the book has been returned.
                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <div class="p-3 bg-light rounded-3">

                        <div class="mb-3">

                            <div class="text-muted small mb-1">
                                Student
                            </div>

                            <div
                                class="fw-bold"
                                id="returnStudentName"
                            >
                                —
                            </div>

                        </div>

                        <div class="mb-3">

                            <div class="text-muted small mb-1">
                                Book
                            </div>

                            <div
                                class="fw-bold"
                                id="returnBookTitle"
                            >
                                —
                            </div>

                        </div>

                        <div>

                            <div class="text-muted small mb-1">
                                Return Date
                            </div>

                            <div class="fw-bold">

                                <?= e(
                                    $todayEthiopianFormatted
                                ) ?>

                            </div>

                        </div>

                    </div>

                    <div class="alert alert-warning mt-3 mb-0">

                        <i class="bi bi-exclamation-triangle me-2"></i>

                        The system will automatically record the return time and increase the book's available quantity.

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-success"
                        id="confirmReturnBtn"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Confirm Return
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        const sidebar =
            document.getElementById(
                'sidebar'
            );

        const sidebarOverlay =
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

            sidebarOverlay.classList.add(
                'show'
            );

            document.body.style.overflow =
                'hidden';
        }

        function closeSidebar() {

            sidebar.classList.remove(
                'show'
            );

            sidebarOverlay.classList.remove(
                'show'
            );

            document.body.style.overflow =
                '';
        }

        mobileMenuBtn.addEventListener(
            'click',
            openSidebar
        );

        sidebarOverlay.addEventListener(
            'click',
            closeSidebar
        );

        /*
        |--------------------------------------------------------------------------
        | Return Confirmation Modal
        |--------------------------------------------------------------------------
        */

        const returnModal =
            document.getElementById(
                'returnConfirmModal'
            );

        const returnBorrowingId =
            document.getElementById(
                'returnBorrowingId'
            );

        const returnStudentName =
            document.getElementById(
                'returnStudentName'
            );

        const returnBookTitle =
            document.getElementById(
                'returnBookTitle'
            );

        const returnBookForm =
            document.getElementById(
                'returnBookForm'
            );

        const confirmReturnBtn =
            document.getElementById(
                'confirmReturnBtn'
            );

        returnModal.addEventListener(
            'show.bs.modal',
            function (event) {

                const button =
                    event.relatedTarget;

                if (!button) {
                    return;
                }

                const id =
                    button.getAttribute(
                        'data-id'
                    );

                const student =
                    button.getAttribute(
                        'data-student'
                    );

                const book =
                    button.getAttribute(
                        'data-book'
                    );

                returnBorrowingId.value =
                    id || '';

                returnStudentName.textContent =
                    student || '—';

                returnBookTitle.textContent =
                    book || '—';
            }
        );

        returnBookForm.addEventListener(
            'submit',
            function () {

                confirmReturnBtn.disabled =
                    true;

                confirmReturnBtn.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                    ></span>
                    Recording Return...
                `;
            }
        );

        returnModal.addEventListener(
            'hidden.bs.modal',
            function () {

                returnBorrowingId.value =
                    '';

                returnStudentName.textContent =
                    '—';

                returnBookTitle.textContent =
                    '—';

                confirmReturnBtn.disabled =
                    false;

                confirmReturnBtn.innerHTML = `
                    <i class="bi bi-check-lg me-1"></i>
                    Confirm Return
                `;
            }
        );

    }
);

</script>

</body>

</html>