<?php

declare(strict_types=1);

session_start();

require_once '../config/database.php';

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Current student registration
|--------------------------------------------------------------------------
*/

$studentSql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.id AS grade_id,
        g.grade_number,
        sec.id AS section_id,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year
    FROM students s
    INNER JOIN student_registrations sr
        ON sr.student_id = s.id
    INNER JOIN grades g
        ON g.id = sr.grade_id
    INNER JOIN sections sec
        ON sec.id = sr.section_id
    INNER JOIN academic_years ay
        ON ay.id = sr.academic_year_id
    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND ay.status = 'Active'
    ORDER BY sr.id DESC
    LIMIT 1
";

$stmt = $conn->prepare($studentSql);

if (!$stmt) {
    die('Unable to load student information.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    die('No active student registration was found.');
}

$studentId = (int) $student['student_id'];
$registrationId = (int) $student['registration_id'];
$gradeNumber = (int) $student['grade_number'];
$section = (string) $student['section'];
$academicYearId = (int) $student['academic_year_id'];
$academicYear = (string) $student['academic_year'];

/*
|--------------------------------------------------------------------------
| Subjects + Results
|--------------------------------------------------------------------------
|
| Four semesters:
|
| Mid Semester       / 50
| First Semester     / 100
| Quarter Semester   / 50
| Second Semester    / 100
|
*/

$subjects = [];

$subjectSql = "
    SELECT
        gs.id AS grade_subject_id,
        gs.subject_name,

        MAX(
            CASE
                WHEN sem.name = 'Mid Semester'
                THEN r.mark
            END
        ) AS mid_mark,

        MAX(
            CASE
                WHEN sem.name = 'First Semester'
                THEN r.mark
            END
        ) AS first_mark,

        MAX(
            CASE
                WHEN sem.name = 'Quarter Semester'
                THEN r.mark
            END
        ) AS quarter_mark,

        MAX(
            CASE
                WHEN sem.name = 'Second Semester'
                THEN r.mark
            END
        ) AS second_mark

    FROM grade_subjects gs

    LEFT JOIN results r
        ON r.grade_subject_id = gs.id
        AND r.student_registration_id = ?

    LEFT JOIN semesters sem
        ON sem.id = r.semester_id
        AND sem.academic_year_id = ?

    WHERE gs.grade = ?
      AND gs.is_active = 1

    GROUP BY
        gs.id,
        gs.subject_name

    ORDER BY
        gs.subject_name ASC
";

$stmt = $conn->prepare($subjectSql);

if (!$stmt) {
    die('Unable to load student results.');
}

$stmt->bind_param(
    'iii',
    $registrationId,
    $academicYearId,
    $gradeNumber
);

$stmt->execute();

$subjectResult = $stmt->get_result();

while ($row = $subjectResult->fetch_assoc()) {
    $subjects[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Current page
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Results | BKHS</title>

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

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f5f7fb;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --bottom-nav-height: 76px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 72px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-brand strong {
            font-size: 17px;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .nav-section {
            color: #9ca3af;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 12px 12px 7px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d1d5db;
            text-decoration: none;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            font-size: 14px;
            transition: .2s;
        }

        .sidebar-link:hover {
            background: rgba(255,255,255,.08);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        .sidebar-link i {
            width: 20px;
            font-size: 17px;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: 72px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: #f3f4f6;
            width: 40px;
            height: 40px;
            border-radius: 9px;
            font-size: 20px;
        }

        .page-title {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
        }

        .page-subtitle {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .student-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
        }

        .content {
            padding: 28px;
        }

        /* =========================================================
           STUDENT BANNER
        ========================================================= */

        .student-banner {
            background: linear-gradient(
                135deg,
                #2563eb,
                #1e40af
            );
            color: #fff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(37, 99, 235, .15);
        }

        .student-banner h2 {
            margin: 0 0 5px;
            font-size: 22px;
        }

        .student-banner p {
            margin: 0;
            opacity: .88;
            font-size: 14px;
        }

        .student-info {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .info-pill {
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
        }

        /* =========================================================
           RESULTS
        ========================================================= */

        .results-section {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .results-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .results-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .results-header p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .results-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }

        .results-table th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            padding: 13px 15px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .results-table td {
            padding: 14px 15px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 13px;
            vertical-align: middle;
        }

        .results-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .results-table tbody tr:hover {
            background: #fafcff;
        }

        .subject-name {
            font-weight: 600;
        }

        .not-entered {
            color: #9ca3af;
            font-size: 12px;
        }

        .mark-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 54px;
            padding: 5px 8px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-state i {
            font-size: 44px;
            color: #9ca3af;
        }

        .empty-state h4 {
            margin-top: 14px;
            font-size: 18px;
        }

        .empty-state p {
            color: var(--muted);
            margin: 0;
            font-size: 13px;
        }

        /* =========================================================
           SIDEBAR OVERLAY
        ========================================================= */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        /* =========================================================
           MOBILE BOTTOM NAVIGATION
        ========================================================= */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-nav-item {
            display: none;
        }

        /* =========================================================
           TABLET
        ========================================================= */

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

            .mobile-menu {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

        }

        /* =========================================================
           MOBILE BOTTOM NAV
        ========================================================= */

        @media screen and (max-width: 767.98px) {

            body {
                padding-bottom: calc(
                    var(--bottom-nav-height) +
                    env(safe-area-inset-bottom)
                ) !important;
            }

            .mobile-bottom-nav {
                position: fixed !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;

                height: calc(
                    var(--bottom-nav-height) +
                    env(safe-area-inset-bottom)
                ) !important;

                min-height: var(--bottom-nav-height) !important;

                display: flex !important;
                align-items: stretch !important;
                justify-content: space-around !important;

                background: #ffffff !important;

                border-top: 1px solid #e5e7eb !important;

                box-shadow:
                    0 -4px 20px rgba(0,0,0,.10) !important;

                z-index: 99999 !important;

                padding:
                    5px
                    4px
                    env(safe-area-inset-bottom)
                    4px !important;

                margin: 0 !important;

                visibility: visible !important;
                opacity: 1 !important;
            }

            .mobile-nav-item {
                display: flex !important;

                flex: 1 1 0 !important;
                min-width: 0 !important;
                height: 100% !important;

                flex-direction: column !important;

                align-items: center !important;
                justify-content: center !important;

                gap: 4px !important;

                margin: 0 2px !important;
                padding: 5px 2px !important;

                border-radius: 10px !important;

                text-decoration: none !important;

                color: #6b7280 !important;

                background: transparent !important;

                font-size: 10px !important;
                font-weight: 600 !important;

                visibility: visible !important;
                opacity: 1 !important;

                -webkit-tap-highlight-color: transparent;
            }

            .mobile-nav-item i {
                display: block !important;

                width: auto !important;

                font-size: 21px !important;

                line-height: 1 !important;

                visibility: visible !important;
            }

            .mobile-nav-item span {
                display: block !important;

                max-width: 100% !important;

                white-space: nowrap !important;

                overflow: hidden !important;

                text-overflow: ellipsis !important;

                line-height: 1.1 !important;

                visibility: visible !important;
            }

            .mobile-nav-item.active {
                color: #2563eb !important;
                background: #eff6ff !important;
            }

            .mobile-nav-item:active {
                transform: scale(.96);
            }

        }

        /* =========================================================
           SMALL MOBILE
        ========================================================= */

        @media (max-width: 575.98px) {

            .topbar {
                height: 64px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .student-avatar {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 15px;
            }

            .student-banner {
                padding: 19px;
                border-radius: 13px;
            }

            .student-banner h2 {
                font-size: 19px;
            }

            .student-info {
                gap: 7px;
            }

            .info-pill {
                font-size: 11px;
                padding: 7px 9px;
            }

            .results-header {
                padding: 16px;
            }

            .results-header h3 {
                font-size: 16px;
            }

        }

        /* =========================================================
           VERY SMALL PHONES
        ========================================================= */

        @media screen and (max-width: 380px) {

            :root {
                --bottom-nav-height: 72px;
            }

            .mobile-bottom-nav {
                padding-left: 2px !important;
                padding-right: 2px !important;
            }

            .mobile-nav-item {
                margin: 0 1px !important;
                padding-left: 1px !important;
                padding-right: 1px !important;
                font-size: 9px !important;
                gap: 3px !important;
            }

            .mobile-nav-item i {
                font-size: 19px !important;
            }

        }

    </style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <strong>BKHS Student</strong>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="subjects.php"
            class="sidebar-link <?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-book-fill"></i>
            <span>My Subjects</span>
        </a>

        <a
            href="materials.php"
            class="sidebar-link <?= $currentPage === 'materials.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-folder-fill"></i>
            <span>Materials</span>
        </a>

        <a
            href="result.php"
            class="sidebar-link <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Results</span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link <?= $currentPage === 'attendance.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="homework.php"
            class="sidebar-link <?= $currentPage === 'homework.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link <?= $currentPage === 'announcements.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcements</span>
        </a>

        <div class="nav-section">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>"
        >
            <i class="bi bi-person-fill"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    My Results
                </h1>

                <p class="page-subtitle">
                    View your academic performance.
                </p>

            </div>

        </div>

        <div class="student-avatar">

            <?= e(
                strtoupper(
                    substr(
                        trim((string) $student['full_name']),
                        0,
                        1
                    )
                )
            ) ?>

        </div>

    </header>

    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <div class="content">

        <!-- Student Information -->

        <section class="student-banner">

            <h2>
                <?= e((string) $student['full_name']) ?>
            </h2>

            <p>
                Academic results for the current academic year
            </p>

            <div class="student-info">

                <span class="info-pill">
                    <i class="bi bi-person-badge me-1"></i>
                    <?= e((string) $student['student_code']) ?>
                </span>

                <span class="info-pill">
                    <i class="bi bi-mortarboard me-1"></i>
                    Grade <?= $gradeNumber ?>
                </span>

                <span class="info-pill">
                    <i class="bi bi-diagram-3 me-1"></i>
                    Section <?= e($section) ?>
                </span>

                <span class="info-pill">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= e($academicYear) ?>
                </span>

            </div>

        </section>

        <!-- Results -->

        <section class="results-section">

            <div class="results-header">

                <div>

                    <h3>
                        Subject Results
                    </h3>

                    <p>
                        Your marks for each semester.
                    </p>

                </div>

                <span class="badge text-bg-light">

                    <?= count($subjects) ?>

                    <?= count($subjects) === 1 ? 'Subject' : 'Subjects' ?>

                </span>

            </div>

            <?php if (count($subjects) > 0): ?>

                <div class="table-wrapper">

                    <table class="results-table">

                        <thead>

                            <tr>

                                <th>
                                    #
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Mid Semester
                                    <small class="d-block text-muted">
                                        / 50
                                    </small>
                                </th>

                                <th>
                                    First Semester
                                    <small class="d-block text-muted">
                                        / 100
                                    </small>
                                </th>

                                <th>
                                    Quarter Semester
                                    <small class="d-block text-muted">
                                        / 50
                                    </small>
                                </th>

                                <th>
                                    Second Semester
                                    <small class="d-block text-muted">
                                        / 100
                                    </small>
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($subjects as $index => $subject): ?>

                                <tr>

                                    <td>
                                        <?= $index + 1 ?>
                                    </td>

                                    <td>

                                        <div class="subject-name">
                                            <?= e((string) $subject['subject_name']) ?>
                                        </div>

                                    </td>

                                    <!-- Mid Semester -->

                                    <td>

                                        <?php if ($subject['mid_mark'] !== null): ?>

                                            <span class="mark-badge">
                                                <?= number_format(
                                                    (float) $subject['mid_mark'],
                                                    2
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- First Semester -->

                                    <td>

                                        <?php if ($subject['first_mark'] !== null): ?>

                                            <span class="mark-badge">
                                                <?= number_format(
                                                    (float) $subject['first_mark'],
                                                    2
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Quarter Semester -->

                                    <td>

                                        <?php if ($subject['quarter_mark'] !== null): ?>

                                            <span class="mark-badge">
                                                <?= number_format(
                                                    (float) $subject['quarter_mark'],
                                                    2
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Second Semester -->

                                    <td>

                                        <?php if ($subject['second_mark'] !== null): ?>

                                            <span class="mark-badge">
                                                <?= number_format(
                                                    (float) $subject['second_mark'],
                                                    2
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <i class="bi bi-bar-chart"></i>

                    <h4>
                        No subjects found
                    </h4>

                    <p>
                        No active subjects are currently registered for your grade.
                    </p>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<!-- =========================================================
     MOBILE BOTTOM NAVIGATION
========================================================= -->

<nav
    class="mobile-bottom-nav"
    aria-label="Student mobile navigation"
>

    <a
        href="dashboard.php"
        class="mobile-nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        aria-label="Dashboard"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Home</span>
    </a>

    <a
        href="subjects.php"
        class="mobile-nav-item <?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
        aria-label="My Subjects"
    >
        <i class="bi bi-book-fill"></i>
        <span>Subjects</span>
    </a>

    <a
        href="materials.php"
        class="mobile-nav-item <?= $currentPage === 'materials.php' ? 'active' : '' ?>"
        aria-label="Materials"
    >
        <i class="bi bi-folder-fill"></i>
        <span>Materials</span>
    </a>

    <a
        href="homework.php"
        class="mobile-nav-item <?= $currentPage === 'homework.php' ? 'active' : '' ?>"
        aria-label="Homework"
    >
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>

    <a
        href="result.php"
        class="mobile-nav-item <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        aria-label="Results"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Results</span>
    </a>

</nav>

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenu = document.getElementById('mobileMenu');

    function openSidebar() {

        sidebar.classList.add('show');
        overlay.classList.add('show');

        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {

        sidebar.classList.remove('show');
        overlay.classList.remove('show');

        document.body.style.overflow = '';
    }

    if (mobileMenu) {

        mobileMenu.addEventListener(
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
        .querySelectorAll('.sidebar-link')
        .forEach(link => {

            link.addEventListener('click', () => {

                if (window.innerWidth <= 991) {
                    closeSidebar();
                }

            });

        });

    window.addEventListener('resize', () => {

        if (window.innerWidth > 991) {
            closeSidebar();
        }

    });

</script>

</body>

</html>