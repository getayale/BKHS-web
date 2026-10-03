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
| Helper
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
    $stmt->bind_param('i', $librarianId);
    $stmt->execute();

    $result = $stmt->get_result();
    $librarian = $result->fetch_assoc();

    if ($librarian) {
        $librarianName = (string) (
            $librarian['full_name'] ?? 'Librarian'
        );

        $librarianEmail = (string) (
            $librarian['email'] ?? ''
        );

        $librarianPhoto = $librarian['photo_path'] ?? null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEth = EthiopianCalendar::today();

$todayFormatted = EthiopianCalendar::format(
    $todayEth['year'],
    $todayEth['month'],
    $todayEth['day'],
    'en'
);

$todayFormattedAm = EthiopianCalendar::format(
    $todayEth['year'],
    $todayEth['month'],
    $todayEth['day'],
    'am'
);

$dayName = $todayEth['day_name'];

/*
|--------------------------------------------------------------------------
| Gregorian Date Used Internally
|--------------------------------------------------------------------------
|
| Database dates are stored internally as Gregorian dates.
| The UI displays Ethiopian dates.
|
*/

$todayGregorian = EthiopianCalendar::toGregorian(
    $todayEth['year'],
    $todayEth['month'],
    $todayEth['day']
);

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
        start_year,
        start_month,
        start_day,
        end_year,
        end_month,
        end_day,
        status
    FROM academic_years
    WHERE LOWER(status) = 'active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();
    $activeAcademicYear = $result->fetch_assoc();

    $stmt->close();
}

$academicYearName = $activeAcademicYear['name'] ?? '—';

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$totalBooks = 0;
$availableBooks = 0;
$borrowedBooks = 0;
$overdueBooks = 0;

$studentsStudiedToday = 0;
$booksIssuedToday = 0;
$booksReturnedToday = 0;

/*
|--------------------------------------------------------------------------
| Check Table Exists
|--------------------------------------------------------------------------
*/

function tableExists(mysqli $conn, string $table): bool
{
    $tableEscaped = $conn->real_escape_string($table);

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = '{$tableEscaped}'
    ");

    if (!$result) {
        return false;
    }

    $row = $result->fetch_assoc();

    return isset($row['total'])
        && (int) $row['total'] > 0;
}

/*
|--------------------------------------------------------------------------
| Books Statistics
|--------------------------------------------------------------------------
*/

if (tableExists($conn, 'library_books')) {

    $result = $conn->query("
        SELECT
            COUNT(*) AS total_books,
            COALESCE(SUM(available_quantity), 0) AS available_books
        FROM library_books
        WHERE is_deleted = 0
    ");

    if ($result) {

        $row = $result->fetch_assoc();

        $totalBooks = (int) (
            $row['total_books'] ?? 0
        );

        $availableBooks = (int) (
            $row['available_books'] ?? 0
        );
    }
}

/*
|--------------------------------------------------------------------------
| Study Attendance - Today
|--------------------------------------------------------------------------
|
| library_study_attendance uses attendance_date.
|
*/

if (tableExists($conn, 'library_study_attendance')) {

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT student_id) AS total
        FROM library_study_attendance
        WHERE attendance_date = ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            's',
            $todayGregorian
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $studentsStudiedToday = (int) (
            $row['total'] ?? 0
        );

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Borrowing Statistics
|--------------------------------------------------------------------------
*/

if (tableExists($conn, 'library_borrowings')) {

    /*
    |--------------------------------------------------------------------------
    | Automatically mark overdue borrowed books
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE library_borrowings
        SET status = 'Overdue'
        WHERE status = 'Borrowed'
          AND due_date IS NOT NULL
          AND due_date < ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            's',
            $todayGregorian
        );

        $stmt->execute();

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Currently Borrowed
    |--------------------------------------------------------------------------
    */

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM library_borrowings
        WHERE status = 'Borrowed'
    ");

    if ($result) {

        $row = $result->fetch_assoc();

        $borrowedBooks = (int) (
            $row['total'] ?? 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Overdue Books
    |--------------------------------------------------------------------------
    */

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM library_borrowings
        WHERE status = 'Overdue'
    ");

    if ($result) {

        $row = $result->fetch_assoc();

        $overdueBooks = (int) (
            $row['total'] ?? 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Books Issued Today
    |--------------------------------------------------------------------------
    |
    | Correct field:
    | borrow_date
    |
    */

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM library_borrowings
        WHERE borrow_date = ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            's',
            $todayGregorian
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $booksIssuedToday = (int) (
            $row['total'] ?? 0
        );

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Books Returned Today
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM library_borrowings
        WHERE return_date = ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            's',
            $todayGregorian
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $booksReturnedToday = (int) (
            $row['total'] ?? 0
        );

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Librarian Photo
|--------------------------------------------------------------------------
*/

$photoUrl = '../public/images/default-avatar.png';

if (!empty($librarianPhoto)) {

    $photoPath = str_replace(
        '\\',
        '/',
        trim((string) $librarianPhoto)
    );

    $photoPath = ltrim(
        $photoPath,
        '/'
    );

    /*
    |--------------------------------------------------------------------------
    | Supported:
    |
    | public/images/librarian.jpg
    | images/librarian.jpg
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with($photoPath, 'public/')
        && !str_contains($photoPath, '..')
    ) {

        $photoUrl = '../' . $photoPath;

    } elseif (
        str_starts_with($photoPath, 'images/')
        && !str_contains($photoPath, '..')
    ) {

        $photoUrl = '../public/' . $photoPath;
    }
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

    <title>Librarian Dashboard | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- Inter -->
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
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;
            --white: #ffffff;
            --danger: #dc2626;
            --success: #16a34a;
            --warning: #d97706;
            --purple: #7c3aed;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
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
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            transition: transform 0.25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 3px;
        }

        .brand-text {
            line-height: 1.15;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.3px;
        }

        .brand-subtitle {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding: 18px 12px;
        }

        .sidebar-section-title {
            padding: 10px 12px 7px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .menu-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            min-height: 44px;
            padding: 10px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .menu-item:hover {
            background: var(--sidebar-hover);
            color: #fff;
            transform: translateX(2px);
        }

        .menu-item.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 8px;
            bottom: 8px;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: #fff;
        }

        .logout-item:hover {
            background: rgba(220, 38, 38, 0.14);
            color: #fca5a5;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Footer
        |--------------------------------------------------------------------------
        */

        .sidebar-footer {
            padding: 14px 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 10px;
            background: rgba(255,255,255,0.04);
        }

        .sidebar-user img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.15);
        }

        .sidebar-user-name {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            color: #9ca3af;
            font-size: 10px;
            margin-top: 2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-content {
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
            z-index: 900;
            height: 76px;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .date-box {
            text-align: right;
        }

        .date-day {
            font-size: 12px;
            font-weight: 700;
            color: var(--text);
        }

        .date-eth {
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px;
        }

        .topbar-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 16px;
            border-left: 1px solid var(--border);
        }

        .topbar-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .topbar-profile-name {
            font-size: 12px;
            font-weight: 700;
        }

        .topbar-profile-role {
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px 30px 40px;
        }

        .welcome-card {
            background: linear-gradient(
                135deg,
                #1e40af 0%,
                #2563eb 55%,
                #3b82f6 100%
            );
            color: #fff;
            border-radius: 16px;
            padding: 25px 28px;
            position: relative;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .welcome-card::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            right: -70px;
            top: -100px;
        }

        .welcome-card h2 {
            position: relative;
            z-index: 2;
            margin: 0;
            font-size: 22px;
            font-weight: 800;
        }

        .welcome-card p {
            position: relative;
            z-index: 2;
            margin: 7px 0 0;
            font-size: 12px;
            color: rgba(255,255,255,0.82);
        }

        .academic-badge {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 15px;
            padding: 7px 11px;
            border-radius: 8px;
            background: rgba(255,255,255,0.12);
            font-size: 11px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        .stat-card {
            height: 100%;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15,23,42,0.06);
        }

        .stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .stat-value {
            margin-top: 7px;
            font-size: 27px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.8px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .icon-blue {
            color: var(--primary);
            background: #eff6ff;
        }

        .icon-green {
            color: var(--success);
            background: #f0fdf4;
        }

        .icon-orange {
            color: var(--warning);
            background: #fffbeb;
        }

        .icon-red {
            color: var(--danger);
            background: #fef2f2;
        }

        .icon-purple {
            color: var(--purple);
            background: #f5f3ff;
        }

        .stat-footer {
            margin-top: 13px;
            padding-top: 11px;
            border-top: 1px solid #f1f5f9;
            font-size: 10px;
            color: var(--muted);
        }

        /*
        |--------------------------------------------------------------------------
        | Section Cards
        |--------------------------------------------------------------------------
        */

        .section-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
        }

        .section-header {
            padding: 17px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .section-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .section-header a {
            font-size: 11px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .section-body {
            padding: 0;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 30px;
            color: #cbd5e1;
        }

        .empty-state p {
            margin: 10px 0 0;
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Quick Actions
        |--------------------------------------------------------------------------
        */

        .quick-action {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 15px 18px;
            text-decoration: none;
            color: var(--text);
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s ease;
        }

        .quick-action:last-child {
            border-bottom: 0;
        }

        .quick-action:hover {
            background: #f8fafc;
            color: var(--text);
        }

        .quick-action-icon {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
        }

        .quick-action-title {
            font-size: 12px;
            font-weight: 700;
        }

        .quick-action-text {
            margin-top: 2px;
            font-size: 10px;
            color: var(--muted);
        }

        .quick-action-arrow {
            margin-left: auto;
            color: #9ca3af;
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
        | Overlay
        |--------------------------------------------------------------------------
        */

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.45);
            z-index: 1050;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Menu Button
        |--------------------------------------------------------------------------
        */

        .mobile-menu-button {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 9px;
            align-items: center;
            justify-content: center;
            color: var(--text);
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-overlay.show {
                display: block;
            }

            .mobile-menu-button {
                display: inline-flex !important;
            }
        }

        @media (max-width: 767px) {

            .topbar {
                height: 68px;
                padding: 0 16px;
            }

            .content {
                padding: 18px 14px 105px;
            }

            .page-heading h1 {
                font-size: 17px;
            }

            .page-heading p {
                display: none;
            }

            .date-box {
                display: none;
            }

            .topbar-profile {
                padding-left: 0;
                border-left: 0;
            }

            .topbar-profile div {
                display: none;
            }

            .topbar-profile img {
                width: 37px;
                height: 37px;
            }

            .welcome-card {
                padding: 20px;
                border-radius: 13px;
            }

            .welcome-card h2 {
                font-size: 18px;
            }

            .welcome-card p {
                font-size: 11px;
            }

            .stat-card {
                padding: 17px;
            }

            .stat-value {
                font-size: 24px;
            }

            /*
            |--------------------------------------------------------------------------
            | Bottom Navigation
            |--------------------------------------------------------------------------
            */

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                background: rgba(255,255,255,0.98);
                backdrop-filter: blur(12px);
                border-top: 1px solid var(--border);
                z-index: 1200;
                padding-bottom: env(safe-area-inset-bottom);
            }

            .bottom-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #6b7280;
                text-decoration: none;
                font-size: 9px;
                font-weight: 600;
                min-width: 0;
            }

            .bottom-nav-item i {
                font-size: 19px;
                line-height: 1;
            }

            .bottom-nav-item.active {
                color: var(--primary);
            }

            .bottom-nav-item:active {
                transform: scale(0.95);
            }
        }

        @media (min-width: 768px) and (max-width: 1100px) {

            .content {
                padding-left: 22px;
                padding-right: 22px;
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

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS Logo"
            class="brand-logo"
            onerror="this.src='../public/images/default-avatar.png'"
        >

        <div class="brand-text">

            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                School Management
            </div>

        </div>

    </div>

    <nav class="sidebar-menu">

        <!-- Main -->

        <div class="sidebar-section-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="menu-item active"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <!-- Library -->

        <div class="sidebar-section-title">
            Library
        </div>

        <a
            href="books.php"
            class="menu-item"
        >
            <i class="bi bi-book-fill"></i>
            <span>Books</span>
        </a>

        <a
            href="categories.php"
            class="menu-item"
        >
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a
            href="study-attendance.php"
            class="menu-item"
        >
            <i class="bi bi-person-check-fill"></i>
            <span>Study Attendance</span>
        </a>

        <a
            href="borrow-book.php"
            class="menu-item"
        >
            <i class="bi bi-journal-plus"></i>
            <span>Borrow Book</span>
        </a>

        <a
            href="return-book.php"
            class="menu-item"
        >
            <i class="bi bi-journal-check"></i>
            <span>Return Book</span>
        </a>

        <a
            href="borrowing-control.php"
            class="menu-item"
        >
            <i class="bi bi-arrow-left-right"></i>
            <span>Borrowing Control</span>
        </a>

        <a
            href="overdue-books.php"
            class="menu-item"
        >
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Overdue Books</span>
        </a>

        <a
            href="reports.php"
            class="menu-item"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

        <!-- Account -->

        <div class="sidebar-section-title">
            Account
        </div>

        <a
            href="profile.php"
            class="menu-item"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="menu-item logout-item"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-footer">

        <div class="sidebar-user">

            <img
                src="<?= e($photoUrl) ?>"
                alt="Librarian"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div style="min-width:0;">

                <div class="sidebar-user-name">
                    <?= e($librarianName) ?>
                </div>

                <div class="sidebar-user-role">
                    Librarian
                </div>

            </div>

        </div>

    </div>

</aside>

<!-- Mobile Overlay -->

<div
    class="mobile-overlay"
    id="mobileOverlay"
></div>

<!--
|--------------------------------------------------------------------------
| Main Content
|--------------------------------------------------------------------------
-->

<main class="main-content">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open menu"
            >
                <i class="bi bi-list fs-5"></i>
            </button>

            <div class="page-heading">

                <h1>
                    Librarian Dashboard
                </h1>

                <p>
                    Library and study room management
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="date-box">

                <div class="date-day">
                    <?= e($dayName) ?>
                </div>

                <div class="date-eth">
                    <?= e($todayFormatted) ?>
                </div>

            </div>

            <div class="topbar-profile">

                <img
                    src="<?= e($photoUrl) ?>"
                    alt="<?= e($librarianName) ?>"
                    onerror="this.src='../public/images/default-avatar.png'"
                >

                <div>

                    <div class="topbar-profile-name">
                        <?= e($librarianName) ?>
                    </div>

                    <div class="topbar-profile-role">
                        Librarian
                    </div>

                </div>

            </div>

        </div>

    </header>

    <!-- Page Content -->

    <section class="content">

        <!-- Welcome -->

        <div class="welcome-card">

            <h2>
                Welcome, <?= e($librarianName) ?>
            </h2>

            <p>
                Manage books, borrowing, returns, and daily student study attendance.
            </p>

            <div class="academic-badge">

                <i class="bi bi-calendar3"></i>

                <span>
                    Academic Year <?= e($academicYearName) ?>
                </span>

            </div>

        </div>

        <!-- Statistics -->

        <div class="row g-3 mb-4">

            <!-- Total Books -->

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Total Books
                            </div>

                            <div class="stat-value">
                                <?= number_format($totalBooks) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-blue">

                            <i class="bi bi-book-fill"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Books in library
                    </div>

                </div>

            </div>

            <!-- Available Books -->

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Available Books
                            </div>

                            <div class="stat-value">
                                <?= number_format($availableBooks) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-green">

                            <i class="bi bi-check-circle-fill"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Currently available
                    </div>

                </div>

            </div>

            <!-- Borrowed Books -->

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Borrowed Books
                            </div>

                            <div class="stat-value">
                                <?= number_format($borrowedBooks) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-orange">

                            <i class="bi bi-journal-arrow-up"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Currently borrowed
                    </div>

                </div>

            </div>

            <!-- Overdue Books -->

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Overdue Books
                            </div>

                            <div class="stat-value">
                                <?= number_format($overdueBooks) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-red">

                            <i class="bi bi-exclamation-circle-fill"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Need return follow-up
                    </div>

                </div>

            </div>

        </div>

        <!-- Today's Statistics -->

        <div class="row g-3 mb-4">

            <!-- Students Studying -->

            <div class="col-6 col-lg-4">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Students Studying Today
                            </div>

                            <div class="stat-value">
                                <?= number_format($studentsStudiedToday) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-purple">

                            <i class="bi bi-person-workspace"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        <?= e($todayFormatted) ?>
                    </div>

                </div>

            </div>

            <!-- Books Issued -->

            <div class="col-6 col-lg-4">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Books Issued Today
                            </div>

                            <div class="stat-value">
                                <?= number_format($booksIssuedToday) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-blue">

                            <i class="bi bi-box-arrow-up-right"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Today's borrowing activity
                    </div>

                </div>

            </div>

            <!-- Books Returned -->

            <div class="col-6 col-lg-4">

                <div class="stat-card">

                    <div class="stat-top">

                        <div>

                            <div class="stat-label">
                                Books Returned Today
                            </div>

                            <div class="stat-value">
                                <?= number_format($booksReturnedToday) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-green">

                            <i class="bi bi-box-arrow-in-down-left"></i>

                        </div>

                    </div>

                    <div class="stat-footer">
                        Today's return activity
                    </div>

                </div>

            </div>

        </div>

        <!-- Lower Sections -->

        <div class="row g-3">

            <!-- Study Attendance -->

            <div class="col-lg-7">

                <div class="section-card">

                    <div class="section-header">

                        <h3>
                            Today's Study Attendance
                        </h3>

                        <a href="study-attendance.php">

                            View Attendance

                            <i class="bi bi-arrow-right"></i>

                        </a>

                    </div>

                    <div class="section-body">

                        <?php if ($studentsStudiedToday > 0): ?>

                            <div class="empty-state">

                                <i class="bi bi-person-check"></i>

                                <p>

                                    <?= number_format($studentsStudiedToday) ?>

                                    students have recorded study attendance today.

                                </p>

                            </div>

                        <?php else: ?>

                            <div class="empty-state">

                                <i class="bi bi-person-workspace"></i>

                                <p>
                                    No study attendance has been recorded today.
                                </p>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <!-- Quick Actions -->

            <div class="col-lg-5">

                <div class="section-card">

                    <div class="section-header">

                        <h3>
                            Quick Actions
                        </h3>

                    </div>

                    <div class="section-body">

                        <!-- Books -->

                        <a
                            href="books.php"
                            class="quick-action"
                        >

                            <div class="quick-action-icon">

                                <i class="bi bi-book"></i>

                            </div>

                            <div>

                                <div class="quick-action-title">
                                    Manage Books
                                </div>

                                <div class="quick-action-text">
                                    Add, edit, delete and search books
                                </div>

                            </div>

                            <i class="bi bi-chevron-right quick-action-arrow"></i>

                        </a>

                        <!-- Study Attendance -->

                        <a
                            href="study-attendance.php"
                            class="quick-action"
                        >

                            <div class="quick-action-icon">

                                <i class="bi bi-person-check"></i>

                            </div>

                            <div>

                                <div class="quick-action-title">
                                    Study Attendance
                                </div>

                                <div class="quick-action-text">
                                    Record today's student study attendance
                                </div>

                            </div>

                            <i class="bi bi-chevron-right quick-action-arrow"></i>

                        </a>

                        <!-- Borrow Book -->

                        <a
                            href="borrow-book.php"
                            class="quick-action"
                        >

                            <div class="quick-action-icon">

                                <i class="bi bi-journal-plus"></i>

                            </div>

                            <div>

                                <div class="quick-action-title">
                                    Borrow Book
                                </div>

                                <div class="quick-action-text">
                                    Issue a book to a student
                                </div>

                            </div>

                            <i class="bi bi-chevron-right quick-action-arrow"></i>

                        </a>

                        <!-- Return Book -->

                        <a
                            href="return-book.php"
                            class="quick-action"
                        >

                            <div class="quick-action-icon">

                                <i class="bi bi-journal-check"></i>

                            </div>

                            <div>

                                <div class="quick-action-title">
                                    Return Book
                                </div>

                                <div class="quick-action-text">
                                    Process returned library books
                                </div>

                            </div>

                            <i class="bi bi-chevron-right quick-action-arrow"></i>

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a
        href="dashboard.php"
        class="bottom-nav-item active"
    >

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Dashboard
        </span>

    </a>

    <a
        href="books.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-book-fill"></i>

        <span>
            Books
        </span>

    </a>

    <a
        href="study-attendance.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-person-check-fill"></i>

        <span>
            Study
        </span>

    </a>

    <a
        href="borrow-book.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-journal-plus"></i>

        <span>
            Borrow
        </span>

    </a>

    <a
        href="borrowing-control.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-arrow-left-right"></i>

        <span>
            Control
        </span>

    </a>

</nav>

<script>

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    const menuButton = document.getElementById('mobileMenuButton');

    function openSidebar() {

        sidebar.classList.add('open');

        overlay.classList.add('show');

        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {

        sidebar.classList.remove('open');

        overlay.classList.remove('show');

        document.body.style.overflow = '';
    }

    if (menuButton) {

        menuButton.addEventListener(
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

    document.querySelectorAll('.menu-item').forEach(
        function (item) {

            item.addEventListener(
                'click',
                function () {

                    if (window.innerWidth <= 1100) {
                        closeSidebar();
                    }

                }
            );

        }
    );

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 1100) {
                closeSidebar();
            }

        }
    );

</script>

</body>

</html>