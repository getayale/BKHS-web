<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'parent'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$parentUserId = (int) $_SESSION['user_id'];

$selectedStudentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if (!$selectedStudentId || $selectedStudentId <= 0) {
    header('Location: children.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

final class EthiopianCalendar
{
    private const ETHIOPIAN_EPOCH = 1723856;

    private const MONTHS = [
        1  => 'Meskerem',
        2  => 'Tikimt',
        3  => 'Hidar',
        4  => 'Tahsas',
        5  => 'Tir',
        6  => 'Yekatit',
        7  => 'Megabit',
        8  => 'Miazia',
        9  => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    public static function fromGregorian(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return $date;
        }

        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $day = (int) date('j', $timestamp);

        $jd = self::gregorianToJd(
            $year,
            $month,
            $day
        );

        $ethiopianYear = (int) floor(
            ($jd - self::ETHIOPIAN_EPOCH) / 365.25
        ) + 1;

        $ethiopianNewYearJd = self::ethiopianToJd(
            $ethiopianYear,
            1,
            1
        );

        $ethiopianMonth = (int) floor(
            ($jd - $ethiopianNewYearJd) / 30
        ) + 1;

        $ethiopianDay =
            $jd
            - $ethiopianNewYearJd
            - (30 * ($ethiopianMonth - 1))
            + 1;

        return $ethiopianDay
            . ' '
            . self::MONTHS[$ethiopianMonth]
            . ' '
            . $ethiopianYear;
    }

    private static function gregorianToJd(
        int $year,
        int $month,
        int $day
    ): int {
        $a = (int) floor((14 - $month) / 12);
        $y = $year + 4800 - $a;
        $m = $month + (12 * $a) - 3;

        return $day
            + (int) floor((153 * $m + 2) / 5)
            + (365 * $y)
            + (int) floor($y / 4)
            - (int) floor($y / 100)
            + (int) floor($y / 400)
            - 32045;
    }

    private static function ethiopianToJd(
        int $year,
        int $month,
        int $day
    ): int {
        return self::ETHIOPIAN_EPOCH
            + (365 * ($year - 1))
            + (int) floor($year / 4)
            + (30 * ($month - 1))
            + $day
            - 1;
    }
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function homeworkHistoryPageUrl(
    int $studentId,
    int $page
): string {
    return 'homework-history.php?student_id='
        . $studentId
        . '&page='
        . $page;
}

/*
|--------------------------------------------------------------------------
| Date
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Addis_Ababa');

$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

$page = ($page && $page > 0)
    ? $page
    : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Get Parent Record
|--------------------------------------------------------------------------
*/

$parentStmt = $conn->prepare("
    SELECT
        parents.id AS parent_id,
        parents.user_id,
        parents.full_name,
        parents.phone
    FROM parents
    INNER JOIN users
        ON users.id = parents.user_id
    WHERE parents.user_id = ?
      AND users.is_deleted = 0
      AND LOWER(users.role) = 'parent'
    LIMIT 1
");

$parentStmt->bind_param(
    'i',
    $parentUserId
);

$parentStmt->execute();

$parentResult = $parentStmt->get_result();
$parent = $parentResult->fetch_assoc();

$parentStmt->close();

if (!$parent) {
    header('Location: ../auth/login.php');
    exit;
}

$parentId = (int) $parent['parent_id'];

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        academic_years.id,
        academic_years.name
    FROM academic_years
    WHERE academic_years.status = 'Active'
    ORDER BY academic_years.id DESC
    LIMIT 1
");

$academicYearStmt->execute();

$academicYearResult = $academicYearStmt->get_result();
$academicYear = $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    die('No active academic year found.');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify Selected Child Belongs To Parent
|--------------------------------------------------------------------------
*/

$childStmt = $conn->prepare("
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,

        sp.relationship,

        sr.id AS registration_id,

        g.grade_number,
        sec.code AS section

    FROM parents AS p

    INNER JOIN student_parents AS sp
        ON sp.parent_id = p.id
        AND sp.student_id = ?
        AND sp.is_account_access = 1

    INNER JOIN students AS s
        ON s.id = sp.student_id

    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id
        AND sr.academic_year_id = ?

    INNER JOIN grades AS g
        ON g.id = sr.grade_id

    INNER JOIN sections AS sec
        ON sec.id = sr.section_id

    INNER JOIN users AS u
        ON u.id = s.user_id

    WHERE p.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'

    ORDER BY sr.id DESC
    LIMIT 1
");

$childStmt->bind_param(
    'iii',
    $selectedStudentId,
    $academicYearId,
    $parentUserId
);

$childStmt->execute();

$childResult = $childStmt->get_result();
$child = $childResult->fetch_assoc();

$childStmt->close();

if (!$child) {
    header('Location: children.php');
    exit;
}

$studentId = (int) $child['student_id'];
$studentName = (string) $child['full_name'];
$studentCode = (string) $child['student_code'];
$gradeNumber = (int) $child['grade_number'];
$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Count Homework History
|--------------------------------------------------------------------------
|
| History means:
| due_date < today
|
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM homeworks
    WHERE academic_year = ?
      AND grade = ?
      AND section = ?
      AND due_date < ?
");

$countStmt->bind_param(
    'siss',
    $academicYearName,
    $gradeNumber,
    $section,
    $today
);

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$countStmt->close();

$totalHomework = (int) ($countRow['total'] ?? 0);

$totalPages = max(
    1,
    (int) ceil($totalHomework / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Get Homework History
|--------------------------------------------------------------------------
*/

$homeworkStmt = $conn->prepare("
    SELECT
        h.id,
        h.title,
        h.assigned_date,
        h.due_date,

        gs.subject_name,

        u.full_name AS teacher_name,

        COALESCE(
            hss.status,
            'Not Done'
        ) AS student_status

    FROM homeworks AS h

    INNER JOIN grade_subjects AS gs
        ON gs.id = h.grade_subject_id

    INNER JOIN users AS u
        ON u.id = h.teacher_user_id
        AND u.is_deleted = 0

    LEFT JOIN homework_student_status AS hss
        ON hss.homework_id = h.id
        AND hss.student_id = ?

    WHERE h.academic_year = ?
      AND h.grade = ?
      AND h.section = ?
      AND h.due_date < ?

    ORDER BY
        h.due_date DESC,
        h.id DESC

    LIMIT ? OFFSET ?
");

$homeworkStmt->bind_param(
    'isisiii',
    $studentId,
    $academicYearName,
    $gradeNumber,
    $section,
    $today,
    $perPage,
    $offset
);

$homeworkStmt->execute();

$homeworkResult = $homeworkStmt->get_result();

$homeworks = [];

while ($row = $homeworkResult->fetch_assoc()) {
    $homeworks[] = $row;
}

$homeworkStmt->close();

/*
|--------------------------------------------------------------------------
| URLs
|--------------------------------------------------------------------------
*/

$dashboardUrl = 'dashboard.php';
$childrenUrl = 'children.php?student_id=' . $studentId;
$resultUrl = 'result.php?student_id=' . $studentId;
$attendanceUrl = 'attendance.php?student_id=' . $studentId;
$homeworkUrl = 'homework.php?student_id=' . $studentId;
$historyUrl = 'homework-history.php?student_id=' . $studentId;
$announcementsUrl = 'announcements.php?student_id=' . $studentId;
$profileUrl = 'profile.php';
$logoutUrl = '../auth/logout.php';

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
        Homework History - <?= e($studentName) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f5f7fb;
            color: #1f2937;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .app {
            display: flex;
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            width: 250px;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 100;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .sidebar-title {
            font-size: 21px;
            font-weight: 700;
            color: #111827;
        }

        .sidebar-subtitle {
            margin-top: 5px;
            font-size: 13px;
            color: #6b7280;
        }

        .sidebar-nav {
            padding: 18px 12px;
            flex: 1;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 5px;
            border-radius: 9px;
            color: #4b5563;
            font-size: 14px;
            font-weight: 500;
            transition: 0.2s;
        }

        .nav-link:hover {
            background: #f3f4f6;
            color: #111827;
        }

        .nav-link.active {
            background: #eef2ff;
            color: #4338ca;
            font-weight: 600;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-footer {
            padding: 12px;
            border-top: 1px solid #e5e7eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
        }

        .topbar {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .topbar-left h1 {
            font-size: 22px;
            color: #111827;
        }

        .topbar-left p {
            margin-top: 4px;
            font-size: 13px;
            color: #6b7280;
        }

        .dashboard-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }

        .dashboard-btn:hover {
            background: #f9fafb;
            transform: translateX(-2px);
        }

        .content {
            padding: 28px 30px 40px;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Header
        |--------------------------------------------------------------------------
        */

        .student-header {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .student-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .student-name {
            font-size: 21px;
            font-weight: 700;
            color: #111827;
        }

        .student-code {
            margin-top: 5px;
            color: #6b7280;
            font-size: 13px;
        }

        .student-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .meta-item {
            background: #f3f4f6;
            border-radius: 7px;
            padding: 7px 11px;
            font-size: 13px;
            color: #4b5563;
        }

        .meta-item strong {
            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | Homework Card
        |--------------------------------------------------------------------------
        */

        .homework-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .homework-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .homework-card-title {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
        }

        .current-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #4338ca;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }

        .current-link:hover {
            transform: translateX(-2px);
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .homework-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .homework-table th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 14px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            white-space: nowrap;
        }

        .homework-table th:first-child {
            padding-left: 20px;
        }

        .homework-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 14px;
            vertical-align: middle;
        }

        .homework-table tr:last-child td {
            border-bottom: none;
        }

        .homework-table tbody tr {
            transition: 0.2s;
        }

        .homework-table tbody tr:hover {
            background: #fafafa;
        }

        .homework-table td:first-child {
            padding-left: 20px;
        }

        .subject-name {
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
        }

        .homework-title {
            color: #374151;
            font-weight: 500;
        }

        .teacher-name {
            color: #6b7280;
            white-space: nowrap;
        }

        .date {
            color: #4b5563;
            white-space: nowrap;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        .status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 76px;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .status.done {
            background: #ecfdf3;
            color: #15803d;
        }

        .status.not-done {
            background: #fff7ed;
            color: #c2410c;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 18px;
            border-top: 1px solid #e5e7eb;
            flex-wrap: wrap;
        }

        .page-link {
            min-width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 9px;
            border-radius: 7px;
            background: #f5f3ff;
            color: #4338ca;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }

        .page-link:hover {
            background: #e0e7ff;
            transform: translateY(-1px);
        }

        .page-link.active {
            background: #4338ca;
            color: #ffffff;
        }

        .page-link.disabled {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: default;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        .more-menu {
            display: none;
        }

        @media (max-width: 800px) {

            body {
                padding-bottom: 68px;
            }

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .topbar {
                min-height: 64px;
                padding: 0 16px;
            }

            .topbar-left h1 {
                font-size: 18px;
            }

            .topbar-left p {
                display: none;
            }

            .dashboard-btn {
                font-size: 12px;
                padding: 8px 10px;
            }

            .content {
                padding: 16px 12px 25px;
            }

            .student-header {
                padding: 16px;
                margin-bottom: 14px;
            }

            .student-header-top {
                align-items: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            .student-name {
                font-size: 18px;
            }

            .student-meta {
                gap: 6px;
            }

            .meta-item {
                font-size: 12px;
                padding: 6px 8px;
            }

            .homework-card-header {
                padding: 15px;
            }

            .homework-card-title {
                font-size: 16px;
            }

            .current-link {
                font-size: 12px;
            }

            .homework-table {
                min-width: 760px;
            }

            .homework-table th,
            .homework-table td {
                padding: 11px 10px;
            }

            .homework-table th:first-child,
            .homework-table td:first-child {
                padding-left: 14px;
            }

            /*
            |--------------------------------------------------------------------------
            | Mobile Bottom Navigation
            |--------------------------------------------------------------------------
            */

            .mobile-bottom-nav {
                position: fixed;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: #ffffff;
                border-top: 1px solid #e5e7eb;
                z-index: 999;
                box-shadow:
                    0 -3px 12px rgba(0, 0, 0, 0.05);
            }

            .mobile-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #6b7280;
                font-size: 10px;
                font-weight: 600;
                transition: 0.2s;
            }

            .mobile-nav-item span:first-child {
                font-size: 17px;
                line-height: 18px;
            }

            .mobile-nav-item:hover {
                color: #4338ca;
            }

            .mobile-nav-item.active {
                color: #4338ca;
            }

            .mobile-more-btn {
                border: none;
                background: transparent;
                cursor: pointer;
                font-family: inherit;
            }

            /*
            |--------------------------------------------------------------------------
            | More Menu
            |--------------------------------------------------------------------------
            */

            .more-menu {
                position: fixed;
                display: none;
                right: 10px;
                bottom: 72px;
                width: 170px;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                box-shadow:
                    0 8px 30px rgba(0, 0, 0, 0.12);
                overflow: hidden;
                z-index: 1000;
                animation: moreMenuIn 0.18s ease;
            }

            .more-menu.show {
                display: block;
            }

            .more-menu a {
                display: block;
                padding: 13px 15px;
                font-size: 13px;
                color: #374151;
                border-bottom: 1px solid #f3f4f6;
                transition: 0.2s;
            }

            .more-menu a:last-child {
                border-bottom: none;
            }

            .more-menu a:hover {
                background: #f9fafb;
                color: #4338ca;
            }

            @keyframes moreMenuIn {

                from {
                    opacity: 0;
                    transform: translateY(8px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- Desktop Sidebar -->
    <aside class="sidebar">

        <div class="sidebar-header">

            <div class="sidebar-title">
                Parent Portal
            </div>

            <div class="sidebar-subtitle">
                BKHS School
            </div>

        </div>

        <nav class="sidebar-nav">

            <a
                href="<?= e($dashboardUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a
                href="<?= e($childrenUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">👨‍👩‍👧</span>
                <span>My Children</span>
            </a>

            <a
                href="<?= e($resultUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">📊</span>
                <span>Results</span>
            </a>

            <a
                href="<?= e($attendanceUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">✓</span>
                <span>Attendance</span>
            </a>

            <a
                href="<?= e($homeworkUrl) ?>"
                class="nav-link active"
            >
                <span class="nav-icon">📝</span>
                <span>Homework</span>
            </a>

            <a
                href="<?= e($announcementsUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">📢</span>
                <span>Announcements</span>
            </a>

            <a
                href="<?= e($profileUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">👤</span>
                <span>Profile</span>
            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="<?= e($logoutUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">↪</span>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <!-- Main -->
    <main class="main">

        <!-- Topbar -->
        <header class="topbar">

            <div class="topbar-left">

                <h1>
                    Homework History
                </h1>

                <p>
                    Previous homework for
                    <?= e($studentName) ?>
                </p>

            </div>

            <a
                href="<?= e($dashboardUrl) ?>"
                class="dashboard-btn"
            >
                ← Dashboard
            </a>

        </header>

        <section class="content">

            <!-- Student Information -->
            <div class="student-header">

                <div class="student-header-top">

                    <div>

                        <div class="student-name">
                            <?= e($studentName) ?>
                        </div>

                        <div class="student-code">
                            <?= e($studentCode) ?>
                        </div>

                    </div>

                    <div class="student-meta">

                        <div class="meta-item">
                            Grade:
                            <strong>
                                <?= $gradeNumber ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            Section:
                            <strong>
                                <?= e($section) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            Academic Year:
                            <strong>
                                <?= e($academicYearName) ?>
                            </strong>
                        </div>

                    </div>

                </div>

            </div>

            <!-- Homework History -->
            <div class="homework-card">

                <div class="homework-card-header">

                    <div class="homework-card-title">
                        Homework History
                    </div>

                    <a
                        href="<?= e($homeworkUrl) ?>"
                        class="current-link"
                    >
                        ← Current Homework
                    </a>

                </div>

                <?php if (count($homeworks) > 0): ?>

                    <div class="table-wrapper">

                        <table class="homework-table">

                            <thead>

                                <tr>

                                    <th>
                                        Subject
                                    </th>

                                    <th>
                                        Homework
                                    </th>

                                    <th>
                                        Teacher
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

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($homeworks as $homework): ?>

                                <?php
                                $studentStatus =
                                    (string) $homework['student_status'];

                                $statusClass =
                                    $studentStatus === 'Done'
                                        ? 'done'
                                        : 'not-done';
                                ?>

                                <tr>

                                    <td>
                                        <span class="subject-name">
                                            <?= e(
                                                (string) $homework['subject_name']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="homework-title">
                                            <?= e(
                                                (string) $homework['title']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="teacher-name">
                                            <?= e(
                                                (string) $homework['teacher_name']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="date">
                                            <?= e(
                                                EthiopianCalendar::fromGregorian(
                                                    (string) $homework['assigned_date']
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="date">
                                            <?= e(
                                                EthiopianCalendar::fromGregorian(
                                                    (string) $homework['due_date']
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>

                                        <span
                                            class="status <?= e($statusClass) ?>"
                                        >
                                            <?= e($studentStatus) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if ($totalPages > 1): ?>

                        <div class="pagination">

                            <?php if ($page > 1): ?>

                                <a
                                    href="<?= e(
                                        homeworkHistoryPageUrl(
                                            $studentId,
                                            $page - 1
                                        )
                                    ) ?>"
                                    class="page-link"
                                >
                                    ‹
                                </a>

                            <?php else: ?>

                                <span class="page-link disabled">
                                    ‹
                                </span>

                            <?php endif; ?>


                            <?php for (
                                $i = 1;
                                $i <= $totalPages;
                                $i++
                            ): ?>

                                <a
                                    href="<?= e(
                                        homeworkHistoryPageUrl(
                                            $studentId,
                                            $i
                                        )
                                    ) ?>"
                                    class="page-link <?= $i === $page
                                        ? 'active'
                                        : '' ?>"
                                >
                                    <?= $i ?>
                                </a>

                            <?php endfor; ?>


                            <?php if ($page < $totalPages): ?>

                                <a
                                    href="<?= e(
                                        homeworkHistoryPageUrl(
                                            $studentId,
                                            $page + 1
                                        )
                                    ) ?>"
                                    class="page-link"
                                >
                                    ›
                                </a>

                            <?php else: ?>

                                <span class="page-link disabled">
                                    ›
                                </span>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="empty-state">
                        There is no homework history for this student.
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>


<!-- Mobile More Menu -->
<div
    class="more-menu"
    id="moreMenu"
>

    <a href="<?= e($profileUrl) ?>">
        👤 Profile
    </a>

    <a href="<?= e($logoutUrl) ?>">
        ↪ Logout
    </a>

</div>


<!-- Mobile Bottom Navigation -->
<nav class="mobile-bottom-nav">

    <a
        href="<?= e($resultUrl) ?>"
        class="mobile-nav-item"
    >
        <span>📊</span>
        <span>Result</span>
    </a>

    <a
        href="<?= e($attendanceUrl) ?>"
        class="mobile-nav-item"
    >
        <span>✓</span>
        <span>Attendance</span>
    </a>

    <a
        href="<?= e($homeworkUrl) ?>"
        class="mobile-nav-item active"
    >
        <span>📝</span>
        <span>Homework</span>
    </a>

    <a
        href="<?= e($announcementsUrl) ?>"
        class="mobile-nav-item"
    >
        <span>📢</span>
        <span>Announcement</span>
    </a>

    <button
        type="button"
        class="mobile-nav-item mobile-more-btn"
        id="moreButton"
    >
        <span>☰</span>
        <span>More</span>
    </button>

</nav>


<script>

    const moreButton =
        document.getElementById('moreButton');

    const moreMenu =
        document.getElementById('moreMenu');

    if (moreButton && moreMenu) {

        moreButton.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

                moreMenu.classList.toggle('show');

            }
        );

        document.addEventListener(
            'click',
            function () {

                moreMenu.classList.remove('show');

            }
        );

        moreMenu.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

            }
        );

    }

</script>

</body>
</html>