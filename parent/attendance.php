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

/*
|--------------------------------------------------------------------------
| Selected Student
|--------------------------------------------------------------------------
*/

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
| Pagination
|--------------------------------------------------------------------------
*/

$recordsPerPage = 10;

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

if (!$currentPage || $currentPage < 1) {
    $currentPage = 1;
}

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
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
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
| Count Absent + Late Attendance
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_records
    FROM student_attendance
    WHERE student_id = ?
      AND academic_year_id = ?
      AND status IN ('Absent', 'Late')
");

$countStmt->bind_param(
    'ii',
    $studentId,
    $academicYearId
);

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$countStmt->close();

$totalRecords = (int) ($countRow['total_records'] ?? 0);

$totalPages = max(
    1,
    (int) ceil($totalRecords / $recordsPerPage)
);

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $recordsPerPage;

/*
|--------------------------------------------------------------------------
| Get Attendance Records
|--------------------------------------------------------------------------
|
| Latest attendance appears first.
| Only Absent and Late are displayed.
|
*/

$attendanceStmt = $conn->prepare("
    SELECT
        id,
        ethiopian_date,
        status
    FROM student_attendance
    WHERE student_id = ?
      AND academic_year_id = ?
      AND status IN ('Absent', 'Late')
    ORDER BY attendance_date DESC, id DESC
    LIMIT ? OFFSET ?
");

$attendanceStmt->bind_param(
    'iiii',
    $studentId,
    $academicYearId,
    $recordsPerPage,
    $offset
);

$attendanceStmt->execute();

$attendanceResult = $attendanceStmt->get_result();

$attendanceRecords = [];

while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRecords[] = $row;
}

$attendanceStmt->close();

/*
|--------------------------------------------------------------------------
| Navigation URLs
|--------------------------------------------------------------------------
*/

$dashboardUrl = 'dashboard.php';

$childrenUrl =
    'children.php?student_id=' .
    $studentId;

$resultUrl =
    'result.php?student_id=' .
    $studentId;

$attendanceUrl =
    'attendance.php?student_id=' .
    $studentId;

$homeworkUrl =
    'homework.php?student_id=' .
    $studentId;

$announcementsUrl =
    'announcements.php?student_id=' .
    $studentId;

$profileUrl = 'profile.php';

$logoutUrl = '../auth/logout.php';

/*
|--------------------------------------------------------------------------
| Pagination Helper
|--------------------------------------------------------------------------
*/

function attendancePageUrl(
    int $studentId,
    int $page
): string {
    return 'attendance.php?student_id=' .
        $studentId .
        '&page=' .
        $page;
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
        Attendance - <?= htmlspecialchars($studentName) ?>
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

        /*
        |--------------------------------------------------------------------------
        | App
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

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
        }

        .dashboard-btn:hover {
            background: #f9fafb;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

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
        | Attendance Card
        |--------------------------------------------------------------------------
        */

        .attendance-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .attendance-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .attendance-card-title {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
        }

        .attendance-card-subtitle {
            font-size: 13px;
            color: #6b7280;
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        .attendance-table th:last-child {
            text-align: center;
        }

        .attendance-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 14px;
            color: #374151;
        }

        .attendance-table tr:last-child td {
            border-bottom: none;
        }

        .attendance-table td:last-child {
            text-align: center;
        }

        .ethiopian-date {
            font-weight: 600;
            color: #111827;
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
            min-width: 72px;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-absent {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-late {
            background: #fff7ed;
            color: #ea580c;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
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

        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 13px;
        }

        .pagination {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .page-link {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #ffffff;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
        }

        .page-link:hover {
            background: #f3f4f6;
        }

        .page-link.active {
            background: #4338ca;
            border-color: #4338ca;
            color: #ffffff;
        }

        .page-link.disabled {
            color: #9ca3af;
            background: #f9fafb;
            pointer-events: none;
        }

        .page-dots {
            padding: 0 3px;
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

        .more-menu {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

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

            .attendance-card-header {
                padding: 15px;
                align-items: flex-start;
                flex-direction: column;
                gap: 5px;
            }

            .attendance-card-title {
                font-size: 16px;
            }

            /*
            |--------------------------------------------------------------------------
            | Mobile Table
            |--------------------------------------------------------------------------
            */

            .attendance-table th,
            .attendance-table td {
                padding: 13px 15px;
            }

            /*
            |--------------------------------------------------------------------------
            | Mobile Pagination
            |--------------------------------------------------------------------------
            */

            .pagination-wrapper {
                padding: 14px 15px;
                flex-direction: column;
                align-items: center;
            }

            .pagination {
                justify-content: center;
            }

            .page-link {
                min-width: 32px;
                height: 32px;
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
                box-shadow: 0 -3px 12px rgba(0, 0, 0, 0.05);
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
            }

            .mobile-nav-item span:first-child {
                font-size: 17px;
                line-height: 18px;
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
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
                overflow: hidden;
                z-index: 1000;
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
            }

            .more-menu a:last-child {
                border-bottom: none;
            }

            .more-menu a:hover {
                background: #f9fafb;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- =========================================================
         Desktop Sidebar
    ========================================================== -->

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
                href="<?= htmlspecialchars($dashboardUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a
                href="<?= htmlspecialchars($childrenUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">👨‍👩‍👧</span>
                <span>My Children</span>
            </a>

            <a
                href="<?= htmlspecialchars($resultUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">📊</span>
                <span>Results</span>
            </a>

            <a
                href="<?= htmlspecialchars($attendanceUrl) ?>"
                class="nav-link active"
            >
                <span class="nav-icon">✓</span>
                <span>Attendance</span>
            </a>

            <a
                href="<?= htmlspecialchars($homeworkUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">📝</span>
                <span>Homework</span>
            </a>

            <a
                href="<?= htmlspecialchars($announcementsUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">📢</span>
                <span>Announcements</span>
            </a>

            <a
                href="<?= htmlspecialchars($profileUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">👤</span>
                <span>Profile</span>
            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="<?= htmlspecialchars($logoutUrl) ?>"
                class="nav-link"
            >
                <span class="nav-icon">↪</span>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <!-- =========================================================
         Main
    ========================================================== -->

    <main class="main">

        <!-- Topbar -->

        <header class="topbar">

            <div class="topbar-left">

                <h1>
                    Attendance
                </h1>

                <p>
                    Attendance record for
                    <?= htmlspecialchars($studentName) ?>
                </p>

            </div>

            <a
                href="<?= htmlspecialchars($dashboardUrl) ?>"
                class="dashboard-btn"
            >
                ← Dashboard
            </a>

        </header>

        <section class="content">

            <!-- =================================================
                 Student Information
            ================================================== -->

            <div class="student-header">

                <div class="student-header-top">

                    <div>

                        <div class="student-name">
                            <?= htmlspecialchars($studentName) ?>
                        </div>

                        <div class="student-code">
                            <?= htmlspecialchars($studentCode) ?>
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
                                <?= htmlspecialchars($section) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            Academic Year:
                            <strong>
                                <?= htmlspecialchars($academicYearName) ?>
                            </strong>
                        </div>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 Attendance Card
            ================================================== -->

            <div class="attendance-card">

                <div class="attendance-card-header">

                    <div class="attendance-card-title">
                        Attendance
                    </div>

                    <div class="attendance-card-subtitle">
                        Absent and Late records
                    </div>

                </div>

                <?php if (count($attendanceRecords) > 0): ?>

                    <div class="table-wrapper">

                        <table class="attendance-table">

                            <thead>

                                <tr>

                                    <th>
                                        Ethiopian Date
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($attendanceRecords as $record): ?>

                                <?php
                                $status = (string) $record['status'];

                                $statusClass =
                                    $status === 'Absent'
                                        ? 'status-absent'
                                        : 'status-late';
                                ?>

                                <tr>

                                    <td>
                                        <span class="ethiopian-date">
                                            <?= htmlspecialchars(
                                                (string) $record['ethiopian_date']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>

                                        <span
                                            class="status-badge <?= $statusClass ?>"
                                        >
                                            <?= htmlspecialchars($status) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <!-- Pagination -->

                    <?php if ($totalPages > 1): ?>

                        <div class="pagination-wrapper">

                            <div class="pagination-info">

                                Page
                                <?= $currentPage ?>
                                of
                                <?= $totalPages ?>

                            </div>

                            <div class="pagination">

                                <!-- Previous -->

                                <?php if ($currentPage > 1): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            attendancePageUrl(
                                                $studentId,
                                                $currentPage - 1
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

                                <?php
                                /*
                                |--------------------------------------------------------------------------
                                | Page Numbers
                                |--------------------------------------------------------------------------
                                */

                                $startPage = max(
                                    1,
                                    $currentPage - 2
                                );

                                $endPage = min(
                                    $totalPages,
                                    $currentPage + 2
                                );

                                if ($startPage > 1):
                                ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            attendancePageUrl(
                                                $studentId,
                                                1
                                            )
                                        ) ?>"
                                        class="page-link"
                                    >
                                        1
                                    </a>

                                    <?php if ($startPage > 2): ?>

                                        <span class="page-dots">
                                            ...
                                        </span>

                                    <?php endif; ?>

                                <?php endif; ?>

                                <?php for (
                                    $page = $startPage;
                                    $page <= $endPage;
                                    $page++
                                ): ?>

                                    <?php if ($page === $currentPage): ?>

                                        <span class="page-link active">
                                            <?= $page ?>
                                        </span>

                                    <?php else: ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                attendancePageUrl(
                                                    $studentId,
                                                    $page
                                                )
                                            ) ?>"
                                            class="page-link"
                                        >
                                            <?= $page ?>
                                        </a>

                                    <?php endif; ?>

                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>

                                    <?php if ($endPage < $totalPages - 1): ?>

                                        <span class="page-dots">
                                            ...
                                        </span>

                                    <?php endif; ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            attendancePageUrl(
                                                $studentId,
                                                $totalPages
                                            )
                                        ) ?>"
                                        class="page-link"
                                    >
                                        <?= $totalPages ?>
                                    </a>

                                <?php endif; ?>

                                <!-- Next -->

                                <?php if ($currentPage < $totalPages): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            attendancePageUrl(
                                                $studentId,
                                                $currentPage + 1
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

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="empty-state">
                        No absent or late attendance records found.
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<!-- =============================================================
     Mobile More Menu
============================================================== -->

<div
    class="more-menu"
    id="moreMenu"
>

    <a href="<?= htmlspecialchars($profileUrl) ?>">
        👤 Profile
    </a>

    <a href="<?= htmlspecialchars($logoutUrl) ?>">
        ↪ Logout
    </a>

</div>

<!-- =============================================================
     Mobile Bottom Navigation
============================================================== -->

<nav class="mobile-bottom-nav">

    <a
        href="<?= htmlspecialchars($resultUrl) ?>"
        class="mobile-nav-item"
    >
        <span>📊</span>
        <span>Result</span>
    </a>

    <a
        href="<?= htmlspecialchars($attendanceUrl) ?>"
        class="mobile-nav-item active"
    >
        <span>✓</span>
        <span>Attendance</span>
    </a>

    <a
        href="<?= htmlspecialchars($homeworkUrl) ?>"
        class="mobile-nav-item"
    >
        <span>📝</span>
        <span>Homework</span>
    </a>

    <a
        href="<?= htmlspecialchars($announcementsUrl) ?>"
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