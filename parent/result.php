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
| Helper: Letter Grade
|--------------------------------------------------------------------------
*/

function getLetterGrade(?float $mark): string
{
    if ($mark === null) {
        return 'Not Entered';
    }

    if ($mark >= 90) {
        return 'A+';
    }

    if ($mark >= 85) {
        return 'A';
    }

    if ($mark >= 80) {
        return 'B+';
    }

    if ($mark >= 75) {
        return 'B';
    }

    if ($mark >= 70) {
        return 'C+';
    }

    if ($mark >= 65) {
        return 'C';
    }

    if ($mark >= 60) {
        return 'D';
    }

    return 'F';
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

$registrationId = (int) $child['registration_id'];
$studentId = (int) $child['student_id'];
$studentName = (string) $child['full_name'];
$studentCode = (string) $child['student_code'];
$gradeNumber = (int) $child['grade_number'];
$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Get Results
|--------------------------------------------------------------------------
*/

$resultStmt = $conn->prepare("
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

    FROM grade_subjects AS gs

    LEFT JOIN results AS r
        ON r.grade_subject_id = gs.id
        AND r.student_registration_id = ?

    LEFT JOIN semesters AS sem
        ON sem.id = r.semester_id
        AND sem.academic_year_id = ?

    WHERE gs.grade = ?
      AND gs.is_active = 1

    GROUP BY
        gs.id,
        gs.subject_name

    ORDER BY
        gs.subject_name ASC
");

$resultStmt->bind_param(
    'iii',
    $registrationId,
    $academicYearId,
    $gradeNumber
);

$resultStmt->execute();

$resultsQuery = $resultStmt->get_result();

$results = [];

while ($row = $resultsQuery->fetch_assoc()) {
    $results[] = $row;
}

$resultStmt->close();

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

    <title>Results - <?= htmlspecialchars($studentName) ?></title>

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
        }

        .dashboard-btn:hover {
            background: #f9fafb;
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
        | Results Card
        |--------------------------------------------------------------------------
        */

        .results-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .results-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .results-card-title {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
        }

        .academic-year {
            font-size: 13px;
            color: #6b7280;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .results-table th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 14px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: center;
            white-space: nowrap;
        }

        .results-table th:first-child {
            text-align: left;
            padding-left: 20px;
        }

        .results-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 14px;
            text-align: center;
            vertical-align: middle;
        }

        .results-table tr:last-child td {
            border-bottom: none;
        }

        .results-table td:first-child {
            text-align: left;
            padding-left: 20px;
            font-weight: 600;
            color: #111827;
        }

        .mark {
            font-weight: 600;
            color: #111827;
        }

        .not-entered {
            color: #9ca3af;
            font-weight: 500;
            font-size: 13px;
        }

        .semester-result {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .semester-mark {
            font-weight: 600;
            color: #111827;
        }

        .letter-grade {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            padding: 3px 7px;
            border-radius: 5px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 700;
        }

        .letter-grade.fail {
            background: #fef2f2;
            color: #dc2626;
        }

        .letter-grade.not-entered-grade {
            background: #f3f4f6;
            color: #9ca3af;
        }

        .empty-state {
            padding: 45px 20px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Grading Information
        |--------------------------------------------------------------------------
        */

        .grading-info {
            margin-top: 18px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px 20px;
        }

        .grading-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
        }

        .grading-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .grade-range {
            padding: 7px 10px;
            border-radius: 6px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            color: #4b5563;
        }

        .grade-range strong {
            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-dashboard {
            display: none;
        }

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

            .results-card-header {
                padding: 15px;
                align-items: flex-start;
                flex-direction: column;
                gap: 5px;
            }

            .results-card-title {
                font-size: 16px;
            }

            .grading-info {
                padding: 15px;
                margin-top: 14px;
            }

            .grading-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .grade-range {
                font-size: 11px;
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

        @media (max-width: 420px) {

            .grading-list {
                grid-template-columns: 1fr;
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
                class="nav-link active"
            >
                <span class="nav-icon">📊</span>
                <span>Results</span>
            </a>

            <a
                href="<?= htmlspecialchars($attendanceUrl) ?>"
                class="nav-link"
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

    <!-- Main -->
    <main class="main">

        <!-- Topbar -->
        <header class="topbar">

            <div class="topbar-left">

                <h1>
                    Results
                </h1>

                <p>
                    Academic results for <?= htmlspecialchars($studentName) ?>
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

            <!-- Student Information -->
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

            <!-- Results -->
            <div class="results-card">

                <div class="results-card-header">

                    <div class="results-card-title">
                        Semester Results
                    </div>

                    <div class="academic-year">
                        <?= htmlspecialchars($academicYearName) ?>
                    </div>

                </div>

                <?php if (count($results) > 0): ?>

                    <div class="table-wrapper">

                        <table class="results-table">

                            <thead>

                                <tr>

                                    <th>
                                        Subject
                                    </th>

                                    <th>
                                        Mid Semester
                                        <br>
                                        <small>/ 50</small>
                                    </th>

                                    <th>
                                        First Semester
                                        <br>
                                        <small>/ 100</small>
                                    </th>

                                    <th>
                                        Quarter Semester
                                        <br>
                                        <small>/ 50</small>
                                    </th>

                                    <th>
                                        Second Semester
                                        <br>
                                        <small>/ 100</small>
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($results as $row): ?>

                                <?php
                                $midMark = $row['mid_mark'] !== null
                                    ? (float) $row['mid_mark']
                                    : null;

                                $firstMark = $row['first_mark'] !== null
                                    ? (float) $row['first_mark']
                                    : null;

                                $quarterMark = $row['quarter_mark'] !== null
                                    ? (float) $row['quarter_mark']
                                    : null;

                                $secondMark = $row['second_mark'] !== null
                                    ? (float) $row['second_mark']
                                    : null;

                                $firstGrade = getLetterGrade($firstMark);
                                $secondGrade = getLetterGrade($secondMark);

                                $firstGradeClass =
                                    $firstMark !== null && $firstMark < 60
                                        ? 'fail'
                                        : '';

                                $secondGradeClass =
                                    $secondMark !== null && $secondMark < 60
                                        ? 'fail'
                                        : '';
                                ?>

                                <tr>

                                    <!-- Subject -->
                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $row['subject_name']
                                        ) ?>
                                    </td>

                                    <!-- Mid Semester -->
                                    <td>

                                        <?php if ($midMark !== null): ?>

                                            <span class="mark">
                                                <?= number_format(
                                                    $midMark,
                                                    2
                                                ) ?>
                                                / 50
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- First Semester -->
                                    <td>

                                        <?php if ($firstMark !== null): ?>

                                            <div class="semester-result">

                                                <span class="semester-mark">
                                                    <?= number_format(
                                                        $firstMark,
                                                        2
                                                    ) ?>
                                                    / 100
                                                </span>

                                                <span
                                                    class="letter-grade <?= htmlspecialchars($firstGradeClass) ?>"
                                                >
                                                    <?= htmlspecialchars(
                                                        $firstGrade
                                                    ) ?>
                                                </span>

                                            </div>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Quarter Semester -->
                                    <td>

                                        <?php if ($quarterMark !== null): ?>

                                            <span class="mark">
                                                <?= number_format(
                                                    $quarterMark,
                                                    2
                                                ) ?>
                                                / 50
                                            </span>

                                        <?php else: ?>

                                            <span class="not-entered">
                                                Not Entered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Second Semester -->
                                    <td>

                                        <?php if ($secondMark !== null): ?>

                                            <div class="semester-result">

                                                <span class="semester-mark">
                                                    <?= number_format(
                                                        $secondMark,
                                                        2
                                                    ) ?>
                                                    / 100
                                                </span>

                                                <span
                                                    class="letter-grade <?= htmlspecialchars($secondGradeClass) ?>"
                                                >
                                                    <?= htmlspecialchars(
                                                        $secondGrade
                                                    ) ?>
                                                </span>

                                            </div>

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
                        No results have been entered for this student yet.
                    </div>

                <?php endif; ?>

            </div>

            <!-- Grading Scale -->
            <div class="grading-info">

                <div class="grading-title">
                    Grading Scale
                </div>

                <div class="grading-list">

                    <div class="grade-range">
                        <strong>A+</strong> — 90–100
                    </div>

                    <div class="grade-range">
                        <strong>A</strong> — 85–89
                    </div>

                    <div class="grade-range">
                        <strong>B+</strong> — 80–84
                    </div>

                    <div class="grade-range">
                        <strong>B</strong> — 75–79
                    </div>

                    <div class="grade-range">
                        <strong>C+</strong> — 70–74
                    </div>

                    <div class="grade-range">
                        <strong>C</strong> — 65–69
                    </div>

                    <div class="grade-range">
                        <strong>D</strong> — 60–64
                    </div>

                    <div class="grade-range">
                        <strong>F</strong> — Below 60
                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

<!-- Mobile More Menu -->
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

<!-- Mobile Bottom Navigation -->
<nav class="mobile-bottom-nav">

    <a
        href="<?= htmlspecialchars($resultUrl) ?>"
        class="mobile-nav-item active"
    >
        <span>📊</span>
        <span>Result</span>
    </a>

    <a
        href="<?= htmlspecialchars($attendanceUrl) ?>"
        class="mobile-nav-item"
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
    const moreButton = document.getElementById('moreButton');
    const moreMenu = document.getElementById('moreMenu');

    if (moreButton && moreMenu) {

        moreButton.addEventListener('click', function (event) {

            event.stopPropagation();

            moreMenu.classList.toggle('show');

        });

        document.addEventListener('click', function () {

            moreMenu.classList.remove('show');

        });

        moreMenu.addEventListener('click', function (event) {

            event.stopPropagation();

        });

    }
</script>

</body>
</html>