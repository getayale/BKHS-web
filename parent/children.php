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

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gradeLabel(int $grade): string
{
    return 'Grade ' . $grade;
}

/*
|--------------------------------------------------------------------------
| Logged-in parent
|--------------------------------------------------------------------------
*/

$parentUserId = (int) $_SESSION['user_id'];

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

if (!$parentStmt) {
    die('Unable to prepare parent query.');
}

$parentStmt->bind_param('i', $parentUserId);
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
| Active academic year
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

if (!$academicYearStmt) {
    die('Unable to prepare academic year query.');
}

$academicYearStmt->execute();

$academicYearResult = $academicYearStmt->get_result();
$academicYear = $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    die('No active academic year was found.');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Selected student
|--------------------------------------------------------------------------
*/

$studentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if (!$studentId || $studentId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$studentId = (int) $studentId;

/*
|--------------------------------------------------------------------------
| Get selected child
|--------------------------------------------------------------------------
|
| The student_id from the URL is NOT trusted by itself.
|
| The query confirms:
|
| - The child belongs to the logged-in parent
| - Account access is enabled
| - The child has an active registration
| - The student is not deleted
| - The student user is not deleted
| - The user role is Student
|
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

if (!$childStmt) {
    die('Unable to prepare child query.');
}

$childStmt->bind_param(
    'iii',
    $studentId,
    $academicYearId,
    $parentUserId
);

$childStmt->execute();

$childResult = $childStmt->get_result();
$child = $childResult->fetch_assoc();

$childStmt->close();

if (!$child) {
    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Selected child data
|--------------------------------------------------------------------------
*/

$selectedStudentId = (int) $child['student_id'];

$studentCode = (string) $child['student_code'];
$studentName = (string) $child['full_name'];
$relationship = (string) $child['relationship'];

$gradeNumber = (int) $child['grade_number'];
$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Child-specific URLs
|--------------------------------------------------------------------------
*/

$resultUrl =
    'result.php?student_id=' .
    $selectedStudentId;

$attendanceUrl =
    'attendance.php?student_id=' .
    $selectedStudentId;

$homeworkUrl =
    'homework.php?student_id=' .
    $selectedStudentId;

$childrenUrl =
    'children.php?student_id=' .
    $selectedStudentId;

$announcementsUrl =
    'announcements.php?student_id=' .
    $selectedStudentId;

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
        <?= h($studentName) ?> - My Child
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

        button {
            font-family: inherit;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;

            left: 0;
            top: 0;
            bottom: 0;

            width: 250px;

            background: #ffffff;

            border-right: 1px solid #e5e7eb;

            display: flex;
            flex-direction: column;

            z-index: 100;
        }

        .sidebar-header {
            height: 80px;

            display: flex;
            align-items: center;

            padding: 0 22px;

            border-bottom: 1px solid #e5e7eb;
        }

        .school-name {
            font-size: 18px;

            font-weight: 700;

            color: #111827;
        }

        .portal-label {
            margin-top: 4px;

            font-size: 12px;

            color: #6b7280;
        }

        .sidebar-nav {
            flex: 1;

            padding: 20px 12px;

            overflow-y: auto;
        }

        .nav-item {
            display: flex;

            align-items: center;

            gap: 12px;

            padding: 12px 14px;

            margin-bottom: 5px;

            border-radius: 9px;

            color: #4b5563;

            font-size: 14px;

            font-weight: 500;

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }

        .nav-item:hover {
            background: #f3f4f6;

            color: #111827;
        }

        .nav-item.active {
            background: #eef2ff;

            color: #4f46e5;

            font-weight: 600;
        }

        .nav-icon {
            width: 20px;

            text-align: center;

            font-size: 17px;
        }

        .sidebar-footer {
            padding: 15px 12px;

            border-top: 1px solid #e5e7eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 250px;

            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            height: 80px;

            background: #ffffff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 30px;

            position: sticky;

            top: 0;

            z-index: 50;
        }

        .page-title {
            font-size: 21px;

            font-weight: 700;

            color: #111827;
        }

        .page-subtitle {
            margin-top: 4px;

            color: #6b7280;

            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Dashboard Button
        |--------------------------------------------------------------------------
        */

        .dashboard-button {
            display: none;

            align-items: center;

            gap: 7px;

            padding: 9px 13px;

            border-radius: 8px;

            background: #f3f4f6;

            color: #374151;

            font-size: 13px;

            font-weight: 600;
        }

        .dashboard-button:hover {
            background: #e5e7eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 30px;

            max-width: 1200px;
        }

        /*
        |--------------------------------------------------------------------------
        | Child Header
        |--------------------------------------------------------------------------
        */

        .child-header {
            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 24px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;
        }

        .child-main {
            display: flex;

            align-items: center;

            gap: 16px;
        }

        .child-avatar {
            width: 58px;
            height: 58px;

            border-radius: 50%;

            background: #eef2ff;

            color: #4f46e5;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 22px;

            font-weight: 700;
        }

        .child-name {
            font-size: 21px;

            font-weight: 700;

            color: #111827;
        }

        .child-code {
            margin-top: 5px;

            color: #6b7280;

            font-size: 13px;
        }

        .child-badge {
            padding: 8px 13px;

            border-radius: 20px;

            background: #f3f4f6;

            color: #4b5563;

            font-size: 13px;

            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Sections
        |--------------------------------------------------------------------------
        */

        .section-title {
            margin-bottom: 14px;

            font-size: 18px;

            font-weight: 700;

            color: #111827;
        }

        .information-grid {
            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 28px;
        }

        .information-card {
            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

            padding: 21px;
        }

        .information-card-title {
            font-size: 15px;

            font-weight: 700;

            color: #111827;

            margin-bottom: 17px;
        }

        .information-row {
            display: flex;

            justify-content: space-between;

            gap: 20px;

            padding: 11px 0;

            border-bottom: 1px solid #f3f4f6;
        }

        .information-row:last-child {
            border-bottom: none;

            padding-bottom: 0;
        }

        .information-label {
            color: #6b7280;

            font-size: 13px;
        }

        .information-value {
            color: #111827;

            font-size: 13px;

            font-weight: 600;

            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | School Information
        |--------------------------------------------------------------------------
        */

        .school-information {
            margin-bottom: 30px;
        }

        /*
        |--------------------------------------------------------------------------
        | Quick Links
        |--------------------------------------------------------------------------
        */

        .quick-links {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 16px;
        }

        .quick-link {
            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

            padding: 20px;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .quick-link:hover {
            transform: translateY(-2px);

            box-shadow:
                0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .quick-link-icon {
            font-size: 23px;

            margin-bottom: 12px;
        }

        .quick-link-title {
            font-size: 15px;

            font-weight: 700;

            color: #111827;
        }

        .quick-link-description {
            margin-top: 5px;

            font-size: 12px;

            line-height: 1.5;

            color: #6b7280;
        }

        /*
        |--------------------------------------------------------------------------
        | More Menu
        |--------------------------------------------------------------------------
        */

        .more-menu {
            display: none;

            position: fixed;

            right: 12px;

            bottom: 76px;

            width: 170px;

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;

            box-shadow:
                0 10px 30px rgba(0, 0, 0, 0.15);

            overflow: hidden;

            z-index: 300;
        }

        .more-menu.show {
            display: block;
        }

        .more-menu-item {
            display: flex;

            align-items: center;

            gap: 10px;

            padding: 13px 15px;

            font-size: 14px;

            color: #374151;
        }

        .more-menu-item:hover {
            background: #f9fafb;
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
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 700px) {

            body {
                padding-bottom: 68px;
            }

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                height: 68px;

                padding: 0 15px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                font-size: 11px;
            }

            .dashboard-button {
                display: inline-flex;
            }

            .content {
                padding: 17px 14px 25px;
            }

            .child-header {
                padding: 18px;

                margin-bottom: 18px;

                align-items: flex-start;

                flex-direction: column;

                gap: 14px;
            }

            .child-main {
                width: 100%;
            }

            .child-avatar {
                width: 48px;
                height: 48px;

                font-size: 18px;
            }

            .child-name {
                font-size: 18px;
            }

            .child-code {
                font-size: 12px;
            }

            .child-badge {
                font-size: 12px;

                padding: 7px 11px;
            }

            /*
            | Hide school information on mobile.
            */

            .school-information {
                display: none;
            }

            .information-grid {
                grid-template-columns: 1fr;

                gap: 14px;

                margin-bottom: 20px;
            }

            .information-card {
                padding: 17px;
            }

            .quick-links {
                grid-template-columns: 1fr;

                gap: 12px;
            }

            .quick-link {
                padding: 17px;
            }

            /*
            | Bottom navigation
            */

            .mobile-bottom-nav {
                display: grid;

                grid-template-columns:
                    repeat(5, 1fr);

                position: fixed;

                left: 0;
                right: 0;
                bottom: 0;

                height: 65px;

                background: #ffffff;

                border-top: 1px solid #e5e7eb;

                z-index: 200;

                box-shadow:
                    0 -4px 15px rgba(0, 0, 0, 0.05);
            }

            .bottom-nav-item {
                display: flex;

                flex-direction: column;

                align-items: center;

                justify-content: center;

                gap: 3px;

                color: #6b7280;

                font-size: 10px;

                font-weight: 500;

                min-width: 0;
            }

            .bottom-nav-item:hover,
            .bottom-nav-item.active {
                color: #4f46e5;
            }

            .bottom-nav-icon {
                font-size: 18px;

                line-height: 1;
            }

            .bottom-nav-label {
                white-space: nowrap;
            }

            .more-button {
                border: none;

                background: transparent;

                cursor: pointer;

                color: #6b7280;
            }

            .more-button:hover {
                color: #4f46e5;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Very Small Screens
        |--------------------------------------------------------------------------
        */

        @media (max-width: 380px) {

            .topbar {
                padding-left: 11px;

                padding-right: 11px;
            }

            .content {
                padding-left: 10px;

                padding-right: 10px;
            }

            .page-title {
                font-size: 15px;
            }

            .dashboard-button {
                padding: 8px 9px;

                font-size: 11px;
            }

            .bottom-nav-label {
                font-size: 9px;
            }

            .bottom-nav-icon {
                font-size: 17px;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!--
    |--------------------------------------------------------------------------
    | Desktop Sidebar
    |--------------------------------------------------------------------------
    -->

    <aside class="sidebar">

        <div class="sidebar-header">

            <div>

                <div class="school-name">
                    BKHS School
                </div>

                <div class="portal-label">
                    Parent Portal
                </div>

            </div>

        </div>


        <nav class="sidebar-nav">

            <a
                href="dashboard.php"
                class="nav-item"
            >
                <span class="nav-icon">🏠</span>
                <span>Dashboard</span>
            </a>


            <a
                href="<?= h($childrenUrl) ?>"
                class="nav-item active"
            >
                <span class="nav-icon">👨‍👩‍👧</span>
                <span>My Children</span>
            </a>


            <a
                href="<?= h($resultUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">📊</span>
                <span>Results</span>
            </a>


            <a
                href="<?= h($attendanceUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">📅</span>
                <span>Attendance</span>
            </a>


            <a
                href="<?= h($homeworkUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">📝</span>
                <span>Homework</span>
            </a>


            <a
                href="<?= h($announcementsUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">📢</span>
                <span>Announcements</span>
            </a>


            <a
                href="<?= h($profileUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">👤</span>
                <span>Profile</span>
            </a>

        </nav>


        <div class="sidebar-footer">

            <a
                href="<?= h($logoutUrl) ?>"
                class="nav-item"
            >
                <span class="nav-icon">🚪</span>
                <span>Logout</span>
            </a>

        </div>

    </aside>


    <!--
    |--------------------------------------------------------------------------
    | Main
    |--------------------------------------------------------------------------
    -->

    <main class="main">

        <header class="topbar">

            <div>

                <div class="page-title">
                    My Child
                </div>

                <div class="page-subtitle">
                    <?= h($academicYearName) ?> Academic Year
                </div>

            </div>


            <!-- Mobile only -->

            <a
                href="dashboard.php"
                class="dashboard-button"
            >
                <span>←</span>

                <span>
                    Dashboard
                </span>
            </a>

        </header>


        <div class="content">

            <!--
            |--------------------------------------------------------------------------
            | Selected Child
            |--------------------------------------------------------------------------
            -->

            <section class="child-header">

                <div class="child-main">

                    <div class="child-avatar">
                        <?= h(strtoupper(substr($studentName, 0, 1))) ?>
                    </div>


                    <div>

                        <div class="child-name">
                            <?= h($studentName) ?>
                        </div>

                        <div class="child-code">
                            <?= h($studentCode) ?>
                        </div>

                    </div>

                </div>


                <div class="child-badge">
                    <?= h($relationship) ?>
                </div>

            </section>


            <!--
            |--------------------------------------------------------------------------
            | Student Information
            |--------------------------------------------------------------------------
            -->

            <section>

                <h2 class="section-title">
                    Student Information
                </h2>


                <div class="information-grid">

                    <div class="information-card">

                        <div class="information-card-title">
                            Student Details
                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Full Name
                            </span>

                            <span class="information-value">
                                <?= h($studentName) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Student Code
                            </span>

                            <span class="information-value">
                                <?= h($studentCode) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Relationship
                            </span>

                            <span class="information-value">
                                <?= h($relationship) ?>
                            </span>

                        </div>

                    </div>


                    <div class="information-card">

                        <div class="information-card-title">
                            Current Placement
                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Grade
                            </span>

                            <span class="information-value">
                                <?= h(gradeLabel($gradeNumber)) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Section
                            </span>

                            <span class="information-value">
                                <?= h($section) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Academic Year
                            </span>

                            <span class="information-value">
                                <?= h($academicYearName) ?>
                            </span>

                        </div>

                    </div>

                </div>

            </section>


            <!--
            |--------------------------------------------------------------------------
            | School Information
            |--------------------------------------------------------------------------
            |
            | Desktop only.
            |--------------------------------------------------------------------------
            -->

            <section class="school-information">

                <h2 class="section-title">
                    School Information
                </h2>


                <div class="information-grid">

                    <div class="information-card">

                        <div class="information-card-title">
                            Current Class
                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Grade
                            </span>

                            <span class="information-value">
                                <?= h(gradeLabel($gradeNumber)) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Section
                            </span>

                            <span class="information-value">
                                <?= h($section) ?>
                            </span>

                        </div>

                    </div>


                    <div class="information-card">

                        <div class="information-card-title">
                            Academic Information
                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Academic Year
                            </span>

                            <span class="information-value">
                                <?= h($academicYearName) ?>
                            </span>

                        </div>


                        <div class="information-row">

                            <span class="information-label">
                                Registration
                            </span>

                            <span class="information-value">
                                Active
                            </span>

                        </div>

                    </div>

                </div>

            </section>


            <!--
            |--------------------------------------------------------------------------
            | Quick Access
            |--------------------------------------------------------------------------
            -->

            <section>

                <h2 class="section-title">
                    Quick Access
                </h2>


                <div class="quick-links">

                    <a
                        href="<?= h($resultUrl) ?>"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            📊
                        </div>

                        <div class="quick-link-title">
                            Results
                        </div>

                        <div class="quick-link-description">
                            View your child's semester results.
                        </div>

                    </a>


                    <a
                        href="<?= h($attendanceUrl) ?>"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            📅
                        </div>

                        <div class="quick-link-title">
                            Attendance
                        </div>

                        <div class="quick-link-description">
                            View your child's attendance records.
                        </div>

                    </a>


                    <a
                        href="<?= h($homeworkUrl) ?>"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            📝
                        </div>

                        <div class="quick-link-title">
                            Homework
                        </div>

                        <div class="quick-link-description">
                            View homework assigned to your child.
                        </div>

                    </a>

                </div>

            </section>

        </div>

    </main>


    <!--
    |--------------------------------------------------------------------------
    | Mobile More Menu
    |--------------------------------------------------------------------------
    |
    | Only Profile and Logout.
    | Dashboard is NOT included.
    |--------------------------------------------------------------------------
    -->

    <div
        id="moreMenu"
        class="more-menu"
    >

        <a
            href="<?= h($profileUrl) ?>"
            class="more-menu-item"
        >
            <span>👤</span>
            <span>Profile</span>
        </a>


        <a
            href="<?= h($logoutUrl) ?>"
            class="more-menu-item"
        >
            <span>🚪</span>
            <span>Logout</span>
        </a>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Mobile Bottom Navigation
    |--------------------------------------------------------------------------
    |
    | All child-specific pages use the selected student ID.
    |--------------------------------------------------------------------------
    -->

    <nav class="mobile-bottom-nav">

        <a
            href="<?= h($resultUrl) ?>"
            class="bottom-nav-item"
        >

            <span class="bottom-nav-icon">
                📊
            </span>

            <span class="bottom-nav-label">
                Result
            </span>

        </a>


        <a
            href="<?= h($attendanceUrl) ?>"
            class="bottom-nav-item"
        >

            <span class="bottom-nav-icon">
                📅
            </span>

            <span class="bottom-nav-label">
                Attendance
            </span>

        </a>


        <a
            href="<?= h($homeworkUrl) ?>"
            class="bottom-nav-item"
        >

            <span class="bottom-nav-icon">
                📝
            </span>

            <span class="bottom-nav-label">
                Homework
            </span>

        </a>


        <a
            href="<?= h($announcementsUrl) ?>"
            class="bottom-nav-item"
        >

            <span class="bottom-nav-icon">
                📢
            </span>

            <span class="bottom-nav-label">
                Announcement
            </span>

        </a>


        <button
            type="button"
            class="bottom-nav-item more-button"
            id="moreButton"
            aria-label="More"
            aria-expanded="false"
        >

            <span class="bottom-nav-icon">
                ⋯
            </span>

            <span class="bottom-nav-label">
                More
            </span>

        </button>

    </nav>

</div>


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

                const isOpen =
                    moreMenu.classList.toggle('show');

                moreButton.setAttribute(
                    'aria-expanded',
                    isOpen ? 'true' : 'false'
                );
            }
        );


        document.addEventListener(
            'click',
            function () {

                moreMenu.classList.remove('show');

                moreButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
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