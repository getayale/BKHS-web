<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';
require_once __DIR__ . '/homework/helpers.php';
require_once __DIR__ . '/homework/data.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Active academic year
|--------------------------------------------------------------------------
*/

$academicYear = getActiveAcademicYear($conn);

$pageError = null;

if ($academicYear === null) {
    $pageError = 'No active academic year is currently configured.';
}

/*
|--------------------------------------------------------------------------
| Teacher assignments
|--------------------------------------------------------------------------
*/

$teacherAssignments = [];

if ($academicYear !== null) {
    $teacherAssignments = getTeacherSubjectAssignments(
        $conn,
        $teacherUserId,
        (string) $academicYear['name']
    );
}

/*
|--------------------------------------------------------------------------
| Ethiopian date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::today();
$ethiopianMonths = EthiopianCalendar::months('en');

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$flash = getFlashMessage();

/*
|--------------------------------------------------------------------------
| Homework filters
|--------------------------------------------------------------------------
*/

$filterGrade = requestInt($_GET, 'grade');
$filterSection = requestString($_GET, 'section');
$filterSubject = requestInt($_GET, 'subject');
$filterFromDate = requestString($_GET, 'from_date');
$filterToDate = requestString($_GET, 'to_date');

/*
|--------------------------------------------------------------------------
| Homework history
|--------------------------------------------------------------------------
*/

$homeworkHistory = [];

if ($academicYear !== null) {
    $homeworkHistory = getTeacherHomeworkHistory(
        $conn,
        $teacherUserId,
        (string) $academicYear['name'],
        $filterGrade > 0 ? $filterGrade : null,
        $filterSection !== '' ? $filterSection : null,
        $filterSubject > 0 ? $filterSubject : null,
        $filterFromDate !== '' ? $filterFromDate : null,
        $filterToDate !== '' ? $filterToDate : null
    );
}

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$filterGrades = [];
$filterSections = [];
$filterSubjects = [];

foreach ($teacherAssignments as $assignment) {
    $grade = (int) $assignment['grade'];
    $section = (string) $assignment['section'];
    $subjectId = (int) $assignment['grade_subject_id'];
    $subjectName = (string) $assignment['subject_name'];

    $filterGrades[$grade] = $grade;
    $filterSections[$section] = $section;

    $filterSubjects[$subjectId] = [
        'id' => $subjectId,
        'name' => $subjectName,
    ];
}

ksort($filterGrades);
ksort($filterSections);

usort(
    $filterSubjects,
    static fn(array $a, array $b): int =>
        strcasecmp($a['name'], $b['name'])
);

/*
|--------------------------------------------------------------------------
| Ethiopian date helper
|--------------------------------------------------------------------------
*/

function homeworkEthiopianDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }

    $ethiopian = gregorianDateToEthiopian($date);

    if ($ethiopian === null) {
        return $date;
    }

    return formatEthiopianDate($ethiopian);
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

    <meta
        name="description"
        content="Teacher homework history and management."
    >

    <title>Homework History | BKHS</title>

    <!-- Favicon -->
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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Inter Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

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
            background: #f5f7fb;
            color: #1f2937;
            -webkit-font-smoothing: antialiased;
        }

        /* ==========================================================
           SIDEBAR
           ========================================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: #111827;
            color: #ffffff;
            z-index: 1100;
            overflow-y: auto;
        }

        .sidebar-brand {
            height: 68px;
            display: flex;
            align-items: center;
            padding: 0 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.25);
        }

        .sidebar-brand-icon i {
            font-size: 18px;
        }

        .sidebar-brand-text {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }

        .sidebar-menu {
            padding: 16px 10px;
        }

        .sidebar-menu-title {
            color: #9ca3af;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 10px 11px 7px;
            letter-spacing: 0.7px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #d1d5db;
            text-decoration: none;
            padding: 10px 11px;
            border-radius: 9px;
            margin-bottom: 3px;
            font-size: 13px;
            transition:
                background 0.18s ease,
                color 0.18s ease,
                transform 0.18s ease;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.07);
            color: #ffffff;
        }

        .nav-link.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.18);
        }

        .nav-link i {
            width: 19px;
            text-align: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .attendance-toggle {
            cursor: pointer;
        }

        .attendance-arrow {
            margin-left: auto;
            transition: transform 0.2s ease;
        }

        .attendance-submenu {
            display: none;
            padding-left: 18px;
        }

        .attendance-submenu.show {
            display: block;
        }

        .attendance-submenu .nav-link {
            font-size: 12px;
            padding: 8px 11px;
        }

        .sidebar-profile {
            margin: 12px 10px 18px;
            padding: 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.04);
        }

        .sidebar-profile-name {
            font-size: 12px;
            font-weight: 600;
        }

        .sidebar-profile-role {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 3px;
        }

        /* ==========================================================
           MAIN
           ========================================================== */

        .main-content {
            margin-left: 250px;
            padding: 26px;
            max-width: 1550px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        .page-title {
            margin: 0;
            font-size: 25px;
            font-weight: 700;
            color: #111827;
            letter-spacing: -0.4px;
        }

        .page-subtitle {
            margin: 5px 0 0;
            color: #6b7280;
            font-size: 13px;
        }

        .dashboard-btn {
            min-height: 40px;
            padding: 0 15px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        /* ==========================================================
           HOMEWORK NAVIGATION
           ========================================================== */

        .homework-navigation {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 6px;
            display: inline-flex;
            gap: 4px;
            margin-bottom: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
        }

        .homework-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 15px;
            border-radius: 8px;
            color: #64748b;
            background: transparent;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition:
                background 0.18s ease,
                color 0.18s ease,
                box-shadow 0.18s ease;
        }

        .homework-nav-link:hover {
            color: #2563eb;
            background: #eff6ff;
        }

        .homework-nav-link.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
        }

        .homework-nav-link.active:hover {
            background: #2563eb;
            color: #ffffff;
        }

        /* ==========================================================
           SECTION CARD
           ========================================================== */

        .section-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.035);
        }

        .section-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #ffffff;
        }

        .section-card-title {
            margin: 0;
            font-size: 16px;
            font-weight: 650;
            color: #111827;
        }

        .section-card-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 4px;
        }

        .section-card-body {
            padding: 20px;
        }

        /* ==========================================================
           FILTERS
           ========================================================== */

        .filter-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 11px;
            padding: 15px;
        }

        .form-label {
            font-size: 11px;
            font-weight: 600;
            color: #4b5563;
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            min-height: 40px;
            font-size: 12px;
            border-color: #dfe3e8;
            border-radius: 8px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.10);
        }

        .filter-actions .btn {
            min-height: 40px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        /* ==========================================================
           TABLE
           ========================================================== */

        .table-wrapper {
            overflow-x: auto;
            border: 1px solid #edf0f3;
            border-radius: 10px;
        }

        .table {
            margin-bottom: 0;
            min-width: 850px;
        }

        .table th {
            white-space: nowrap;
            font-size: 10px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.35px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 13px;
        }

        .table td {
            vertical-align: middle;
            font-size: 12px;
            padding: 13px;
            border-color: #f0f2f5;
        }

        .table tbody tr {
            transition: background 0.15s ease;
        }

        .table tbody tr:hover {
            background: #f8fbff;
        }

        .homework-title {
            font-weight: 600;
            color: #111827;
        }

        .homework-description {
            color: #6b7280;
            font-size: 10px;
            margin-top: 3px;
            max-width: 260px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ==========================================================
           BADGES
           ========================================================== */

        .badge-soft {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 650;
            white-space: nowrap;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-closed {
            background: #f1f5f9;
            color: #475569;
        }

        /* ==========================================================
           MOBILE HOMEWORK CARDS
           ========================================================== */

        .mobile-homework-list {
            display: none;
        }

        .homework-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 10px;
            background: #ffffff;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.035);
        }

        .homework-card:last-child {
            margin-bottom: 0;
        }

        .homework-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .homework-card-title {
            font-size: 14px;
            font-weight: 650;
            line-height: 1.35;
            color: #111827;
        }

        .homework-card-subject {
            color: #6b7280;
            font-size: 11px;
            margin-top: 4px;
        }

        .homework-card-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 13px;
            padding-top: 12px;
            border-top: 1px solid #f0f0f0;
        }

        .homework-info-label {
            display: block;
            color: #9ca3af;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 3px;
        }

        .homework-info-value {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
        }

        .homework-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 13px;
        }

        .homework-card-actions .btn {
            min-height: 40px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
        }

        /* ==========================================================
           EMPTY STATE
           ========================================================== */

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .empty-state-icon i {
            font-size: 27px;
        }

        .empty-state h5 {
            margin-top: 0;
            font-size: 15px;
            font-weight: 650;
            color: #374151;
        }

        .empty-state p {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 0;
        }

        /* ==========================================================
           MOBILE BOTTOM NAVIGATION
           ========================================================== */

        .mobile-bottom-nav {
            display: none;
        }

        /* ==========================================================
           TABLET
           ========================================================== */

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .sidebar-overlay {
                display: none !important;
            }

            .mobile-header {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
                padding-bottom: 95px;
                max-width: none;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 70px;
                background: rgba(255, 255, 255, 0.97);
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 -5px 20px rgba(15, 23, 42, 0.08);
                z-index: 1200;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                padding-bottom: env(safe-area-inset-bottom);
            }

            .mobile-nav-item {
                min-width: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                color: #64748b;
                text-decoration: none;
                font-size: 9px;
                font-weight: 600;
                transition: color 0.15s ease;
            }

            .mobile-nav-item i {
                font-size: 19px;
                line-height: 1;
            }

            .mobile-nav-item:hover,
            .mobile-nav-item.active {
                color: #2563eb;
            }

            .mobile-nav-item.logout {
                color: #dc2626;
            }

            .mobile-nav-item.logout:hover {
                color: #b91c1c;
            }

            .homework-navigation {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                margin-bottom: 14px;
            }

            .homework-nav-link {
                justify-content: center;
                min-height: 40px;
                padding: 0 8px;
                font-size: 11px;
            }
        }

        /* ==========================================================
           PHONE
           ========================================================== */

        @media (max-width: 767px) {

            .main-content {
                padding: 14px;
                padding-bottom: 92px;
            }

            .page-header {
                align-items: flex-start;
                margin-bottom: 14px;
            }

            .page-title {
                font-size: 21px;
            }

            .page-subtitle {
                font-size: 11px;
            }

            .dashboard-btn {
                min-height: 38px;
                padding: 0 11px;
                font-size: 11px;
            }

            .dashboard-btn span {
                display: none;
            }

            .section-card {
                border-radius: 10px;
                margin-bottom: 14px;
            }

            .section-card-header {
                padding: 14px;
            }

            .section-card-body {
                padding: 14px;
            }

            .section-card-title {
                font-size: 15px;
            }

            .section-card-subtitle {
                font-size: 10px;
            }

            .desktop-homework-list {
                display: none;
            }

            .mobile-homework-list {
                display: block;
            }

            .filter-box {
                padding: 11px;
            }

            .filter-box .row {
                --bs-gutter-y: 10px;
            }

            .filter-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .filter-actions .btn {
                min-height: 42px;
            }

            .empty-state {
                padding: 42px 15px;
            }
        }

        @media (max-width: 420px) {

            .page-title {
                font-size: 19px;
            }

            .page-subtitle {
                font-size: 10px;
            }

            .dashboard-btn {
                width: 38px;
                padding: 0;
                justify-content: center;
            }

            .homework-nav-link {
                font-size: 10px;
            }

            .homework-card {
                padding: 13px;
            }

            .mobile-nav-item {
                font-size: 8px;
            }

            .mobile-nav-item i {
                font-size: 18px;
            }
        }

    </style>

</head>

<body>

<!-- ==============================================================
     SIDEBAR
     ============================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div class="sidebar-brand-text">
            BKHS
        </div>

    </div>

    <div class="sidebar-menu">

        <div class="sidebar-menu-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="nav-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="subjects.php"
            class="nav-link"
        >
            <i class="bi bi-book"></i>
            <span>Subjects</span>
        </a>

        <a
            href="classes.php"
            class="nav-link"
        >
            <i class="bi bi-people"></i>
            <span>Classes</span>
        </a>

        <a
            href="result.php"
            class="nav-link"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Assessment</span>
        </a>

        <div class="sidebar-menu-title">
            Academic
        </div>

        <div
            class="nav-link attendance-toggle"
            id="attendanceToggle"
        >
            <i class="bi bi-calendar-check"></i>
            <span>Attendance</span>

            <i
                class="bi bi-chevron-down attendance-arrow"
                id="attendanceArrow"
            ></i>
        </div>

        <div
            class="attendance-submenu"
            id="attendanceSubmenu"
        >

            <a
                href="daily-attendance.php"
                class="nav-link"
            >
                <i class="bi bi-calendar-day"></i>
                <span>Daily Attendance</span>
            </a>

           

        </div>

        <a
            href="homework.php"
            class="nav-link active"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="nav-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

       

        <div class="sidebar-menu-title">
            Account
        </div>

        <a
            href="profile.php"
            class="nav-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="nav-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

    <div class="sidebar-profile">

        <div class="sidebar-profile-name">
            <?= e((string) ($_SESSION['full_name'] ?? 'Teacher')) ?>
        </div>

        <div class="sidebar-profile-role">
            Teacher
        </div>

    </div>

</aside>


<!-- ==============================================================
     MAIN CONTENT
     ============================================================== -->

<main class="main-content">

    <!-- Page Header -->

    <div class="page-header">

        <div>

            <h1 class="page-title">
                <i class="bi bi-journal-text me-1"></i>
                Homework
            </h1>

            <p class="page-subtitle">
                View and manage your homework history.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-outline-primary dashboard-btn"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

    </div>


    <!-- ==========================================================
         HOMEWORK NAVIGATION
         ========================================================== -->

    <div class="homework-navigation">

        <a
            href="homework.php"
            class="homework-nav-link active"
        >
            <i class="bi bi-clock-history"></i>
            <span>Homework History</span>
        </a>

        <a
            href="homework/create.php"
            class="homework-nav-link"
        >
            <i class="bi bi-plus-circle"></i>
            <span>Create Homework</span>
        </a>

    </div>


    <!-- Error -->

    <?php if ($pageError !== null): ?>

        <div class="alert alert-warning py-2 small">

            <i class="bi bi-exclamation-triangle me-1"></i>

            <?= e($pageError) ?>

        </div>

    <?php endif; ?>


    <!-- Flash -->

    <?php if ($flash !== null): ?>

        <div
            class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show py-2"
            role="alert"
        >

            <?= e($flash['message']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         HOMEWORK HISTORY
         ========================================================== -->

    <div class="section-card">

        <div class="section-card-header">

            <h2 class="section-card-title">
                <i class="bi bi-clock-history me-1"></i>
                Homework History
            </h2>

            <div class="section-card-subtitle">
                Search and manage previous homework.
            </div>

        </div>


        <div class="section-card-body">

            <!-- Filters -->

            <form
                method="GET"
                action="homework.php"
                class="filter-box mb-3"
            >

                <div class="row g-2">

                    <div class="col-lg-3 col-md-6">

                        <label class="form-label">
                            Grade
                        </label>

                        <select
                            name="grade"
                            class="form-select"
                        >

                            <option value="">
                                All Grades
                            </option>

                            <?php foreach ($filterGrades as $grade): ?>

                                <option
                                    value="<?= $grade ?>"
                                    <?= $filterGrade === $grade ? 'selected' : '' ?>
                                >
                                    Grade <?= $grade ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-2 col-md-6">

                        <label class="form-label">
                            Section
                        </label>

                        <select
                            name="section"
                            class="form-select"
                        >

                            <option value="">
                                All Sections
                            </option>

                            <?php foreach ($filterSections as $section): ?>

                                <option
                                    value="<?= e($section) ?>"
                                    <?= $filterSection === $section ? 'selected' : '' ?>
                                >
                                    Section <?= e($section) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-3 col-md-6">

                        <label class="form-label">
                            Subject
                        </label>

                        <select
                            name="subject"
                            class="form-select"
                        >

                            <option value="">
                                All Subjects
                            </option>

                            <?php foreach ($filterSubjects as $subject): ?>

                                <option
                                    value="<?= (int) $subject['id'] ?>"
                                    <?= $filterSubject === (int) $subject['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($subject['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-lg-2 col-md-6">

                        <label class="form-label">
                            From
                        </label>

                        <input
                            type="date"
                            name="from_date"
                            class="form-control"
                            value="<?= e($filterFromDate) ?>"
                        >

                    </div>


                    <div class="col-lg-2 col-md-6">

                        <label class="form-label">
                            To
                        </label>

                        <input
                            type="date"
                            name="to_date"
                            class="form-control"
                            value="<?= e($filterToDate) ?>"
                        >

                    </div>


                    <div class="col-12">

                        <div class="filter-actions d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-fill"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>

                            <a
                                href="homework.php"
                                class="btn btn-outline-secondary flex-fill"
                            >
                                <i class="bi bi-x-lg me-1"></i>
                                Clear
                            </a>

                        </div>

                    </div>

                </div>

            </form>


            <?php if (empty($homeworkHistory)): ?>

                <!-- Empty State -->

                <div class="empty-state">

                    <div class="empty-state-icon">
                        <i class="bi bi-journal-x"></i>
                    </div>

                    <h5>
                        No homework found
                    </h5>

                    <p>
                        No homework matches the selected filters.
                    </p>

                </div>

            <?php else: ?>


                <!-- ==================================================
                     DESKTOP TABLE
                     ================================================== -->

                <div class="desktop-homework-list table-wrapper">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>

                                <th>
                                    Homework
                                </th>

                                <th>
                                    Class
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Assigned
                                </th>

                                <th>
                                    Due
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-end">
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($homeworkHistory as $homework): ?>

                                <tr>

                                    <td>

                                        <div class="homework-title">

                                            <?= e(
                                                (string) (
                                                    $homework['title']
                                                    ?? 'Untitled'
                                                )
                                            ) ?>

                                        </div>


                                        <?php if (!empty($homework['description'])): ?>

                                            <div class="homework-description">

                                                <?= e(
                                                    (string) $homework['description']
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        G<?= e(
                                            (string) (
                                                $homework['grade']
                                                ?? ''
                                            )
                                        ) ?>

                                        -

                                        <?= e(
                                            (string) (
                                                $homework['section']
                                                ?? ''
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= e(
                                            (string) (
                                                $homework['subject_name']
                                                ?? ''
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= e(
                                            homeworkEthiopianDate(
                                                (string) (
                                                    $homework['assigned_date']
                                                    ?? ''
                                                )
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= e(
                                            homeworkEthiopianDate(
                                                (string) (
                                                    $homework['due_date']
                                                    ?? ''
                                                )
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            ($homework['status'] ?? 'Active')
                                            === 'Closed'
                                        ): ?>

                                            <span class="badge-soft badge-closed">
                                                Closed
                                            </span>

                                        <?php else: ?>

                                            <span class="badge-soft badge-active">
                                                Active
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-end">

                                        <div class="btn-group">

                                            <a
                                                href="homework/view.php?id=<?= (int) $homework['id'] ?>"
                                                class="btn btn-sm btn-outline-primary"
                                                title="View"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>

                                            <a
                                                href="homework/edit.php?id=<?= (int) $homework['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary"
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- ==================================================
                     MOBILE CARDS
                     ================================================== -->

                <div class="mobile-homework-list">

                    <?php foreach ($homeworkHistory as $homework): ?>

                        <div class="homework-card">

                            <div class="homework-card-top">

                                <div>

                                    <div class="homework-card-title">

                                        <?= e(
                                            (string) (
                                                $homework['title']
                                                ?? 'Untitled'
                                            )
                                        ) ?>

                                    </div>

                                    <div class="homework-card-subject">

                                        <?= e(
                                            (string) (
                                                $homework['subject_name']
                                                ?? ''
                                            )
                                        ) ?>

                                    </div>

                                </div>


                                <?php if (
                                    ($homework['status'] ?? 'Active')
                                    === 'Closed'
                                ): ?>

                                    <span class="badge-soft badge-closed">
                                        Closed
                                    </span>

                                <?php else: ?>

                                    <span class="badge-soft badge-active">
                                        Active
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="homework-card-info">

                                <div>

                                    <span class="homework-info-label">
                                        Class
                                    </span>

                                    <span class="homework-info-value">

                                        Grade
                                        <?= e(
                                            (string) (
                                                $homework['grade']
                                                ?? ''
                                            )
                                        ) ?>

                                        -

                                        <?= e(
                                            (string) (
                                                $homework['section']
                                                ?? ''
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div>

                                    <span class="homework-info-label">
                                        Due Date
                                    </span>

                                    <span class="homework-info-value">

                                        <?= e(
                                            homeworkEthiopianDate(
                                                (string) (
                                                    $homework['due_date']
                                                    ?? ''
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div>

                                    <span class="homework-info-label">
                                        Assigned
                                    </span>

                                    <span class="homework-info-value">

                                        <?= e(
                                            homeworkEthiopianDate(
                                                (string) (
                                                    $homework['assigned_date']
                                                    ?? ''
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </div>


                                <div>

                                    <span class="homework-info-label">
                                        Subject
                                    </span>

                                    <span class="homework-info-value">

                                        <?= e(
                                            (string) (
                                                $homework['subject_name']
                                                ?? ''
                                            )
                                        ) ?>

                                    </span>

                                </div>

                            </div>


                            <div class="homework-card-actions">

                                <a
                                    href="homework/view.php?id=<?= (int) $homework['id'] ?>"
                                    class="btn btn-outline-primary"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View
                                </a>

                                <a
                                    href="homework/edit.php?id=<?= (int) $homework['id'] ?>"
                                    class="btn btn-outline-secondary"
                                >
                                    <i class="bi bi-pencil me-1"></i>
                                    Edit
                                </a>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

</main>


<!-- ==============================================================
     MOBILE BOTTOM NAVIGATION
     ============================================================== -->

<nav
    class="mobile-bottom-nav"
    aria-label="Teacher mobile navigation"
>

    <a
        href="daily-attendance.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-calendar-check"></i>
        <span>Attendance</span>
    </a>


    <a
        href="result.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Result</span>
    </a>


    <a
        href="announcements.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-megaphone"></i>
        <span>Announcement</span>
    </a>


    <a
        href="profile.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>


    <a
        href="../auth/logout.php"
        class="mobile-nav-item logout"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</nav>


<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| Attendance submenu
|--------------------------------------------------------------------------
*/

const attendanceToggle =
    document.getElementById('attendanceToggle');

const attendanceSubmenu =
    document.getElementById('attendanceSubmenu');

const attendanceArrow =
    document.getElementById('attendanceArrow');


if (
    attendanceToggle &&
    attendanceSubmenu &&
    attendanceArrow
) {

    attendanceToggle.addEventListener(
        'click',
        function () {

            attendanceSubmenu.classList.toggle('show');

            if (
                attendanceSubmenu.classList.contains('show')
            ) {

                attendanceArrow.style.transform =
                    'rotate(180deg)';

            } else {

                attendanceArrow.style.transform =
                    'rotate(0deg)';

            }

        }
    );

}

</script>

</body>

</html>