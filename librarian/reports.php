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

    if ($row = $result->fetch_assoc()) {
        $librarianName = $row['full_name'] ?? 'Librarian';
        $librarianEmail = $row['email'] ?? '';
        $librarianPhoto = $row['photo_path'] ?? null;
    }

    $stmt->close();
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

    $photoPath = ltrim($photoPath, '/');

    if (
        str_starts_with($photoPath, 'public/') &&
        !str_contains($photoPath, '..')
    ) {
        $photoUrl = '../' . $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::today();

$todayEthiopianFormatted =
    $todayEthiopian['formatted'] ?? '';

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage = 'reports';

/*
|--------------------------------------------------------------------------
| Report Links
|--------------------------------------------------------------------------
*/

$studentStudyReportUrl = 'student-study-report.php';
$bookReportUrl = 'book-report.php';
$borrowingReportUrl = 'borrowing-report.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Reports | BKHS Library</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Bootstrap 5.3.3 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Inter -->
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
        | Reports submenu
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

        .welcome-card {
            background: linear-gradient(
                135deg,
                #2563eb 0%,
                #1d4ed8 100%
            );
            border-radius: 16px;
            padding: 26px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
            right: -80px;
            top: -100px;
        }

        .welcome-title {
            position: relative;
            z-index: 1;
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 7px;
        }

        .welcome-text {
            position: relative;
            z-index: 1;
            margin: 0;
            max-width: 700px;
            font-size: 13px;
            color: rgba(255, 255, 255, .86);
            line-height: 1.6;
        }

        /*
        |--------------------------------------------------------------------------
        | Report Cards
        |--------------------------------------------------------------------------
        */

        .report-card {
            height: 100%;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            transition:
                transform .2s ease,
                box-shadow .2s ease,
                border-color .2s ease;
        }

        .report-card:hover {
            transform: translateY(-3px);
            border-color: #bfdbfe;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }

        .report-icon {
            width: 54px;
            height: 54px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            font-size: 25px;
            margin-bottom: 18px;
        }

        .report-title {
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .report-description {
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.65;
            min-height: 60px;
            margin-bottom: 20px;
        }

        .report-features {
            margin: 0 0 20px;
            padding: 0;
            list-style: none;
        }

        .report-features li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 9px;
        }

        .report-features li i {
            color: #16a34a;
            font-size: 14px;
        }

        .report-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 42px;
            background: var(--primary);
            color: #fff;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            transition: background .2s ease, transform .2s ease;
        }

        .report-button:hover {
            background: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        /*
        |--------------------------------------------------------------------------
        | Section Header
        |--------------------------------------------------------------------------
        */

        .section-heading {
            margin-bottom: 16px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
        }

        .section-heading p {
            margin: 5px 0 0;
            font-size: 12px;
            color: var(--text-muted);
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

            .content {
                padding: 22px 18px 90px;
            }

            .mobile-menu-btn {
                width: 40px;
                height: 40px;
                border: 1px solid var(--border);
                border-radius: 9px;
                background: #fff;
                color: var(--text-dark);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-right: 12px;
            }

            .ethiopian-date {
                display: none;
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

            .welcome-card {
                padding: 21px;
                border-radius: 14px;
            }

            .welcome-title {
                font-size: 19px;
            }

            .welcome-text {
                font-size: 12px;
            }

            .report-card {
                padding: 20px;
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

            .welcome-card {
                padding: 18px;
            }
        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Sidebar Overlay
|--------------------------------------------------------------------------
-->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

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

            <a href="<?= e($studentStudyReportUrl) ?>">
                <i class="bi bi-person-lines-fill"></i>
                <span>Student Study</span>
            </a>

            <a href="<?= e($bookReportUrl) ?>">
                <i class="bi bi-bookshelf"></i>
                <span>Book List</span>
            </a>

            <a href="<?= e($borrowingReportUrl) ?>">
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

<!--
|--------------------------------------------------------------------------
| Main Wrapper
|--------------------------------------------------------------------------
-->

<div class="main-wrapper">

    <!--
    |--------------------------------------------------------------------------
    | Topbar
    |--------------------------------------------------------------------------
    -->

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
                    Reports
                </h1>

                <p class="page-subtitle">
                    Library reports and downloadable records
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

    <!--
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    -->

    <main class="content">

        <section class="welcome-card">

            <h2 class="welcome-title">
                Library Reports
            </h2>

            <p class="welcome-text">
                View student study records, the complete list of books
                in the school library, and borrowing records. Each report
                has its own filters and Excel export option.
            </p>

        </section>

        <div class="section-heading">

            <h2>
                Select a Report
            </h2>

            <p>
                Choose the report you want to view and export.
            </p>

        </div>

        <div class="row g-4">

            <!--
            |--------------------------------------------------------------------------
            | Student Study Report
            |--------------------------------------------------------------------------
            -->

            <div class="col-12 col-md-6 col-xl-4">

                <div class="report-card">

                    <div class="report-icon">
                        <i class="bi bi-person-lines-fill"></i>
                    </div>

                    <div class="report-title">
                        Student Study Report
                    </div>

                    <div class="report-description">
                        View students who study or read books inside
                        the library, with grade, section, book and
                        study-time details.
                    </div>

                    <ul class="report-features">

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Daily, weekly and monthly records
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Grade and section filtering
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Student and book details
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Excel export
                        </li>

                    </ul>

                    <a
                        href="<?= e($studentStudyReportUrl) ?>"
                        class="report-button"
                    >
                        <i class="bi bi-bar-chart-line"></i>
                        Open Student Study Report
                    </a>

                </div>

            </div>

            <!--
            |--------------------------------------------------------------------------
            | Book List Report
            |--------------------------------------------------------------------------
            -->

            <div class="col-12 col-md-6 col-xl-4">

                <div class="report-card">

                    <div class="report-icon">
                        <i class="bi bi-bookshelf"></i>
                    </div>

                    <div class="report-title">
                        Library Book List
                    </div>

                    <div class="report-description">
                        View the complete list of books registered
                        in the school library together with their
                        category and quantity.
                    </div>

                    <ul class="report-features">

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Complete library book list
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Search by book title
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Filter by category
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Excel export
                        </li>

                    </ul>

                    <a
                        href="<?= e($bookReportUrl) ?>"
                        class="report-button"
                    >
                        <i class="bi bi-book"></i>
                        Open Book List
                    </a>

                </div>

            </div>

            <!--
            |--------------------------------------------------------------------------
            | Borrowing Report
            |--------------------------------------------------------------------------
            -->

            <div class="col-12 col-md-6 col-xl-4">

                <div class="report-card">

                    <div class="report-icon">
                        <i class="bi bi-journal-arrow-up"></i>
                    </div>

                    <div class="report-title">
                        Borrowing Report
                    </div>

                    <div class="report-description">
                        View books borrowed by students and track
                        borrowing, due and return information.
                    </div>

                    <ul class="report-features">

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Daily, weekly and monthly records
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Grade and section filtering
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Student and book details
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Excel export
                        </li>

                    </ul>

                    <a
                        href="<?= e($borrowingReportUrl) ?>"
                        class="report-button"
                    >
                        <i class="bi bi-journal-text"></i>
                        Open Borrowing Report
                    </a>

                </div>

            </div>

        </div>

    </main>

</div>

<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2-fill"></i>

        <span>Dashboard</span>

    </a>

    <a href="books.php">

        <i class="bi bi-book-fill"></i>

        <span>Books</span>

    </a>

    <a
        href="reports.php"
        class="active"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>Reports</span>

    </a>

    <a href="profile.php">

        <i class="bi bi-person-circle"></i>

        <span>Profile</span>

    </a>

</nav>

<!-- Bootstrap JS -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    /*
    |--------------------------------------------------------------------------
    | Mobile Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    function openSidebar() {
        sidebar.classList.add('show');
        sidebarOverlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
        document.body.style.overflow = '';
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

    window.addEventListener('resize', function () {

        if (window.innerWidth >= 992) {
            closeSidebar();
        }

    });

</script>

</body>
</html>