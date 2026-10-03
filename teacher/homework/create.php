<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
|
| create.php is located at:
|
| BKHS/
| └── teacher/
|     └── homework/
|         └── create.php
|
| Therefore config/includes are two levels up.
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

/*
|--------------------------------------------------------------------------
| Teacher Information
|--------------------------------------------------------------------------
*/

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYear = getActiveAcademicYear($conn);

/*
|--------------------------------------------------------------------------
| Teacher Assignments
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
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::today();
$ethiopianMonths = EthiopianCalendar::months('en');

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

$csrf = csrfToken();

/*
|--------------------------------------------------------------------------
| Build Unique Teacher Classes
|--------------------------------------------------------------------------
*/

$createClasses = [];

foreach ($teacherAssignments as $assignment) {
    $classKey =
        (string) $assignment['grade']
        . '|'
        . (string) $assignment['section'];

    if (!isset($createClasses[$classKey])) {
        $createClasses[$classKey] = [
            'grade' => (int) $assignment['grade'],
            'section' => (string) $assignment['section'],
        ];
    }
}

$createClasses = array_values($createClasses);

/*
|--------------------------------------------------------------------------
| Build Assignment Data For JavaScript
|--------------------------------------------------------------------------
*/

$assignmentJsData = [];

foreach ($teacherAssignments as $assignment) {
    $assignmentJsData[] = [
        'id' => (int) $assignment['id'],
        'grade' => (int) $assignment['grade'],
        'section' => (string) $assignment['section'],
        'grade_subject_id' => (int) $assignment['grade_subject_id'],
        'subject_name' => (string) $assignment['subject_name'],
    ];
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date Defaults
|--------------------------------------------------------------------------
*/

$currentEthYear = (int) $todayEthiopian['year'];
$currentEthMonth = (int) $todayEthiopian['month'];
$currentEthDay = (int) $todayEthiopian['day'];

$yearStart = $currentEthYear - 2;
$yearEnd = $currentEthYear + 3;

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
        content="Create homework for assigned classes and subjects."
    >

    <title>Create Homework | BKHS</title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >

    <!-- Bootstrap 5.3.3 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <!-- Inter -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #d1d5db;
            --sidebar-active: #2563eb;
            --border: #e5e7eb;
            --background: #f8fafc;
            --text: #111827;
            --muted: #6b7280;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Desktop Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand img {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 10px;
            margin-right: 12px;
        }

        .sidebar-brand-text {
            line-height: 1.2;
        }

        .sidebar-brand-text strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
        }

        .sidebar-brand-text span {
            display: block;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .sidebar-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 12px 12px 7px;
        }

        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--sidebar-text);
            padding: 10px 12px;
            margin-bottom: 4px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition:
                background .18s ease,
                color .18s ease;
        }

        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: var(--sidebar-active);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Main Content
        |--------------------------------------------------------------------------
        */

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            height: 76px;
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

        .topbar-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .topbar-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Content
        |--------------------------------------------------------------------------
        */

        .page-content {
            padding: 28px;
        }

        /*
        |--------------------------------------------------------------------------
        | Homework Navigation
        |--------------------------------------------------------------------------
        */

        .homework-navigation {
            background: #ffffff;
            border: 1px solid var(--border);
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
                background .18s ease,
                color .18s ease,
                box-shadow .18s ease;
        }

        .homework-nav-link:hover {
            color: var(--primary);
            background: #eff6ff;
        }

        .homework-nav-link.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
        }

        .homework-nav-link.active:hover {
            background: var(--primary);
            color: #ffffff;
        }

        .homework-nav-link i {
            font-size: 15px;
        }

        /*
        |--------------------------------------------------------------------------
        | Section Card
        |--------------------------------------------------------------------------
        */

        .section-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .03);
            overflow: hidden;
        }

        .section-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .section-card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .section-card-body {
            padding: 22px;
        }

        /*
        |--------------------------------------------------------------------------
        | Form
        |--------------------------------------------------------------------------
        */

        .form-label {
            color: #374151;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-color: #d1d5db;
            border-radius: 8px;
            font-size: 14px;
        }

        textarea.form-control {
            min-height: auto;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10);
        }

        #subjectSelect:disabled {
            background-color: #f3f4f6;
            cursor: not-allowed;
        }

        /*
        |--------------------------------------------------------------------------
        | Date Picker
        |--------------------------------------------------------------------------
        */

        .date-picker-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
        }

        .date-picker-box .form-select {
            background-color: #ffffff;
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn {
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
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

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
                padding-bottom: 82px;
            }

            .topbar {
                height: 68px;
                padding: 0 16px;
            }

            .topbar-title {
                font-size: 17px;
            }

            .topbar-subtitle {
                display: none;
            }

            .page-content {
                padding: 16px;
            }

            .section-card-header {
                padding: 17px;
            }

            .section-card-body {
                padding: 17px;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1050;
                padding: 6px 8px calc(6px + env(safe-area-inset-bottom));
                box-shadow: 0 -3px 12px rgba(15, 23, 42, .08);
            }

            .mobile-bottom-nav a {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #6b7280;
                text-decoration: none;
                font-size: 10px;
                font-weight: 600;
                border-radius: 8px;
            }

            .mobile-bottom-nav a i {
                font-size: 18px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }

            .mobile-bottom-nav a:hover {
                color: var(--primary);
            }

            .topbar .dashboard-button {
                font-size: 12px;
                padding: 7px 10px;
            }

            /*
            |--------------------------------------------------------------------------
            | Homework Navigation - Mobile
            |--------------------------------------------------------------------------
            */

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

        @media (max-width: 575px) {

            .page-content {
                padding: 12px;
            }

            .section-card-header {
                padding: 15px;
            }

            .section-card-body {
                padding: 15px;
            }

            .section-card-title {
                font-size: 16px;
            }

            .section-card-title i {
                margin-right: 4px !important;
            }

            .date-picker-box {
                padding: 12px;
            }

            .date-picker-box .row {
                --bs-gutter-x: .45rem;
            }

            .form-text {
                font-size: 11px;
            }

            .btn {
                font-size: 13px;
            }

            .homework-nav-link span {
                white-space: nowrap;
            }
        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Desktop Sidebar
|--------------------------------------------------------------------------
-->

<aside class="sidebar">

    <div class="sidebar-brand">

        <img
            src="../../public/image/logo.webp"
            alt="BKHS Logo"
        >

        <div class="sidebar-brand-text">

            <strong>BKHS</strong>

            <span>Teacher Portal</span>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="sidebar-section-title">
            Main
        </div>

        <a
            href="../dashboard.php"
            class="nav-link"
        >
            <i class="bi bi-grid-1x2"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="../subjects.php"
            class="nav-link"
        >
            <i class="bi bi-book"></i>
            <span>My Subjects</span>
        </a>

        <a
            href="../classes.php"
            class="nav-link"
        >
            <i class="bi bi-people"></i>
            <span>My Classes</span>
        </a>

        <a
            href="../result.php"
            class="nav-link"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Result</span>
        </a>

        <div class="sidebar-section-title">
            Teaching
        </div>

        <a
            href="../daily-attendance.php"
            class="nav-link"
        >
            <i class="bi bi-calendar-check"></i>
            <span>Daily Attendance</span>
        </a>

        <a
            href="../subject-attendance.php"
            class="nav-link"
        >
            <i class="bi bi-person-check"></i>
            <span>Subject Attendance</span>
        </a>

        <a
            href="../homework.php"
            class="nav-link active"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="../announcements.php"
            class="nav-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <a
            href="../roster.php"
            class="nav-link"
        >
            <i class="bi bi-list-ul"></i>
            <span>Roster</span>
        </a>

        <div class="sidebar-section-title">
            Account
        </div>

        <a
            href="../profile.php"
            class="nav-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../../auth/logout.php"
            class="nav-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>


<!--
|--------------------------------------------------------------------------
| Main Content
|--------------------------------------------------------------------------
-->

<main class="main-content">

    <!-- Topbar -->

    <header class="topbar">

        <div>

            <h1 class="topbar-title">
                <i class="bi bi-journal-text me-1"></i>
                Homework
            </h1>

            <div class="topbar-subtitle">
                Create and manage your homework
            </div>

        </div>

        <a
            href="../dashboard.php"
            class="btn btn-light border dashboard-button"
        >
            <i class="bi bi-grid-1x2 me-1"></i>
            Dashboard
        </a>

    </header>


    <!-- Page -->

    <div class="page-content">


        <!--
        |--------------------------------------------------------------------------
        | Homework Navigation
        |--------------------------------------------------------------------------
        |
        | Create page is active here.
        |
        -->

        <div class="homework-navigation">

            <a
                href="../homework.php"
                class="homework-nav-link"
            >
                <i class="bi bi-clock-history"></i>
                <span>Homework History</span>
            </a>

            <a
                href="create.php"
                class="homework-nav-link active"
            >
                <i class="bi bi-plus-circle"></i>
                <span>Create Homework</span>
            </a>

        </div>


        <!--
        |--------------------------------------------------------------------------
        | Create Homework Card
        |--------------------------------------------------------------------------
        -->

        <div class="section-card">

            <div class="section-card-header">

                <div>

                    <h2 class="section-card-title">

                        <i class="bi bi-plus-circle me-2"></i>

                        Create Homework

                    </h2>

                    <small class="text-muted">

                        Create homework for one of your assigned
                        classes and subjects.

                    </small>

                </div>

            </div>


            <div class="section-card-body">


                <?php if ($academicYear === null): ?>

                    <div class="alert alert-warning mb-0">

                        <i class="bi bi-exclamation-triangle me-2"></i>

                        There is no active academic year.
                        Homework cannot be created until an academic
                        year is activated.

                    </div>


                <?php elseif (empty($teacherAssignments)): ?>

                    <div class="alert alert-warning mb-0">

                        <i class="bi bi-exclamation-triangle me-2"></i>

                        You do not have any active subject assignments for
                        <?= e((string) $academicYear['name']) ?>.

                    </div>


                <?php else: ?>


                    <form
                        action="save.php"
                        method="POST"
                        enctype="multipart/form-data"
                        id="createHomeworkForm"
                        novalidate
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrf) ?>"
                        >


                        <!-- Academic Year -->

                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Academic Year
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= e((string) $academicYear['name']) ?>"
                                readonly
                            >

                            <div class="form-text">
                                The active academic year is selected automatically.
                            </div>

                        </div>


                        <div class="row g-3">


                            <!-- Grade + Section -->

                            <div class="col-md-6">

                                <label
                                    for="classSelect"
                                    class="form-label fw-semibold"
                                >

                                    Grade & Section

                                    <span class="text-danger">*</span>

                                </label>


                                <select
                                    class="form-select"
                                    id="classSelect"
                                    name="class_key"
                                    required
                                >

                                    <option value="">
                                        Select your assigned class
                                    </option>


                                    <?php foreach ($createClasses as $class): ?>

                                        <?php
                                        $classKey =
                                            $class['grade']
                                            . '|'
                                            . $class['section'];
                                        ?>

                                        <option
                                            value="<?= e($classKey) ?>"
                                        >

                                            Grade
                                            <?= e((string) $class['grade']) ?>

                                            -
                                            Section
                                            <?= e((string) $class['section']) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>


                                <div class="form-text">
                                    Only classes assigned to you are available.
                                </div>


                                <div
                                    class="invalid-feedback"
                                    id="classError"
                                >
                                    Please select one of your assigned classes.
                                </div>

                            </div>


                            <!-- Subject -->

                            <div class="col-md-6">

                                <label
                                    for="subjectSelect"
                                    class="form-label fw-semibold"
                                >

                                    Subject

                                    <span class="text-danger">*</span>

                                </label>


                                <select
                                    class="form-select"
                                    id="subjectSelect"
                                    name="assignment_id"
                                    required
                                    disabled
                                >

                                    <option value="">
                                        Select a class first
                                    </option>

                                </select>


                                <div class="form-text">
                                    Only subjects you teach in the selected class
                                    are shown.
                                </div>


                                <div
                                    class="invalid-feedback"
                                    id="subjectError"
                                >
                                    Please select your assigned subject.
                                </div>

                            </div>


                            <!-- Title -->

                            <div class="col-12">

                                <label
                                    for="title"
                                    class="form-label fw-semibold"
                                >

                                    Homework Title

                                    <span class="text-danger">*</span>

                                </label>


                                <input
                                    type="text"
                                    class="form-control"
                                    id="title"
                                    name="title"
                                    maxlength="255"
                                    placeholder="Example: Chapter 3 Exercise"
                                    required
                                >


                                <div class="form-text">
                                    Give the homework a clear and short title.
                                </div>

                            </div>


                            <!-- Description -->

                            <div class="col-12">

                                <label
                                    for="description"
                                    class="form-label fw-semibold"
                                >
                                    Instructions / Description
                                </label>


                                <textarea
                                    class="form-control"
                                    id="description"
                                    name="description"
                                    rows="5"
                                    placeholder="Write the homework instructions for students..."
                                ></textarea>

                            </div>


                            <!-- Assigned Date -->

                            <div class="col-md-6">

                                <div class="date-picker-box">

                                    <label class="form-label fw-semibold">

                                        Assigned Date

                                        <span class="text-danger">*</span>

                                    </label>


                                    <div class="row g-2">


                                        <!-- Year -->

                                        <div class="col-4">

                                            <select
                                                class="form-select eth-date-year"
                                                id="assignedYear"
                                                name="assigned_year"
                                                data-date-group="assigned"
                                                required
                                            >

                                                <option value="">
                                                    Year
                                                </option>


                                                <?php for (
                                                    $year = $yearStart;
                                                    $year <= $yearEnd;
                                                    $year++
                                                ): ?>

                                                    <option
                                                        value="<?= $year ?>"
                                                        <?= $year === $currentEthYear ? 'selected' : '' ?>
                                                    >
                                                        <?= $year ?>
                                                    </option>

                                                <?php endfor; ?>

                                            </select>

                                        </div>


                                        <!-- Month -->

                                        <div class="col-5">

                                            <select
                                                class="form-select eth-date-month"
                                                id="assignedMonth"
                                                name="assigned_month"
                                                data-date-group="assigned"
                                                required
                                            >

                                                <option value="">
                                                    Month
                                                </option>


                                                <?php foreach (
                                                    $ethiopianMonths
                                                    as $monthNumber => $monthName
                                                ): ?>

                                                    <option
                                                        value="<?= (int) $monthNumber ?>"
                                                        <?= (int) $monthNumber === $currentEthMonth ? 'selected' : '' ?>
                                                    >

                                                        <?= e((string) $monthName) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <!-- Day -->

                                        <div class="col-3">

                                            <select
                                                class="form-select eth-date-day"
                                                id="assignedDay"
                                                name="assigned_day"
                                                data-date-group="assigned"
                                                required
                                            >

                                                <option value="">
                                                    Day
                                                </option>

                                            </select>

                                        </div>

                                    </div>


                                    <div class="form-text">
                                        Ethiopian calendar
                                    </div>


                                    <div
                                        class="invalid-feedback"
                                        id="assignedDateError"
                                    >
                                        Please select a valid assigned date.
                                    </div>

                                </div>

                            </div>


                            <!-- Due Date -->

                            <div class="col-md-6">

                                <div class="date-picker-box">

                                    <label class="form-label fw-semibold">

                                        Due Date

                                        <span class="text-danger">*</span>

                                    </label>


                                    <div class="row g-2">


                                        <!-- Year -->

                                        <div class="col-4">

                                            <select
                                                class="form-select eth-date-year"
                                                id="dueYear"
                                                name="due_year"
                                                data-date-group="due"
                                                required
                                            >

                                                <option value="">
                                                    Year
                                                </option>


                                                <?php for (
                                                    $year = $yearStart;
                                                    $year <= $yearEnd;
                                                    $year++
                                                ): ?>

                                                    <option
                                                        value="<?= $year ?>"
                                                        <?= $year === $currentEthYear ? 'selected' : '' ?>
                                                    >
                                                        <?= $year ?>
                                                    </option>

                                                <?php endfor; ?>

                                            </select>

                                        </div>


                                        <!-- Month -->

                                        <div class="col-5">

                                            <select
                                                class="form-select eth-date-month"
                                                id="dueMonth"
                                                name="due_month"
                                                data-date-group="due"
                                                required
                                            >

                                                <option value="">
                                                    Month
                                                </option>


                                                <?php foreach (
                                                    $ethiopianMonths
                                                    as $monthNumber => $monthName
                                                ): ?>

                                                    <option
                                                        value="<?= (int) $monthNumber ?>"
                                                        <?= (int) $monthNumber === $currentEthMonth ? 'selected' : '' ?>
                                                    >

                                                        <?= e((string) $monthName) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <!-- Day -->

                                        <div class="col-3">

                                            <select
                                                class="form-select eth-date-day"
                                                id="dueDay"
                                                name="due_day"
                                                data-date-group="due"
                                                required
                                            >

                                                <option value="">
                                                    Day
                                                </option>

                                            </select>

                                        </div>

                                    </div>


                                    <div class="form-text">
                                        Ethiopian calendar
                                    </div>


                                    <div
                                        class="invalid-feedback"
                                        id="dueDateError"
                                    >
                                        Please select a valid due date.
                                    </div>

                                </div>

                            </div>


                            <!-- Teacher Material -->

                            <div class="col-12">

                                <label
                                    for="teacherMaterial"
                                    class="form-label fw-semibold"
                                >
                                    Teacher Material
                                </label>


                                <input
                                    type="file"
                                    class="form-control"
                                    id="teacherMaterial"
                                    name="teacher_material"
                                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                                >


                                <div class="form-text">

                                    Optional. Allowed:
                                    PDF, DOC, DOCX, PPT, PPTX,
                                    XLS, XLSX, JPG, JPEG, PNG and ZIP.
                                    Maximum size: 10 MB.

                                </div>

                            </div>

                        </div>


                        <!-- Security Information -->

                        <div class="alert alert-light border mt-4">

                            <div class="d-flex gap-2">

                                <i class="bi bi-shield-check text-success fs-5"></i>

                                <div>

                                    <strong>
                                        Assignment restriction
                                    </strong>

                                    <div class="small text-muted mt-1">

                                        Homework will be created only for the
                                        Grade, Section and Subject assigned to
                                        your teacher account. The server will
                                        verify the assignment before saving.

                                    </div>

                                </div>

                            </div>

                        </div>


                        <!-- Buttons -->

                        <div class="d-flex justify-content-end gap-2 mt-4">

                            <button
                                type="reset"
                                class="btn btn-light border"
                                id="resetCreateHomework"
                            >

                                <i class="bi bi-arrow-counterclockwise me-1"></i>

                                Reset

                            </button>


                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="createHomeworkButton"
                            >

                                <i class="bi bi-plus-circle me-1"></i>

                                Create Homework

                            </button>

                        </div>


                    </form>

                <?php endif; ?>

            </div>

        </div>

    </div>

</main>


<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a href="../daily-attendance.php">

        <i class="bi bi-calendar-check"></i>

        <span>Attendance</span>

    </a>


    <a href="../result.php">

        <i class="bi bi-bar-chart"></i>

        <span>Result</span>

    </a>


    <a href="../announcements.php">

        <i class="bi bi-megaphone"></i>

        <span>Announcement</span>

    </a>


    <a href="../profile.php">

        <i class="bi bi-person-circle"></i>

        <span>Profile</span>

    </a>


    <a href="../../auth/logout.php">

        <i class="bi bi-box-arrow-right"></i>

        <span>Logout</span>

    </a>

</nav>


<script>

(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | Assignment Data
    |--------------------------------------------------------------------------
    */

    const assignments = <?= json_encode(
        $assignmentJsData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;


    const classSelect =
        document.getElementById('classSelect');

    const subjectSelect =
        document.getElementById('subjectSelect');


    const assignedYear =
        document.getElementById('assignedYear');

    const assignedMonth =
        document.getElementById('assignedMonth');

    const assignedDay =
        document.getElementById('assignedDay');


    const dueYear =
        document.getElementById('dueYear');

    const dueMonth =
        document.getElementById('dueMonth');

    const dueDay =
        document.getElementById('dueDay');


    const form =
        document.getElementById('createHomeworkForm');


    const resetButton =
        document.getElementById('resetCreateHomework');


    /*
    |--------------------------------------------------------------------------
    | Ethiopian Month Days
    |--------------------------------------------------------------------------
    */

    function getDaysInEthiopianMonth(year, month) {

        year = Number(year);
        month = Number(month);

        if (!year || !month) {
            return 0;
        }

        /*
         * Months 1 - 12
         * Each contains 30 days.
         */

        if (month >= 1 && month <= 12) {
            return 30;
        }

        /*
         * Month 13
         *
         * Ethiopian leap year:
         * year % 4 === 3
         */

        if (month === 13) {
            return (year % 4 === 3)
                ? 6
                : 5;
        }

        return 0;
    }


    /*
    |--------------------------------------------------------------------------
    | Populate Day Select
    |--------------------------------------------------------------------------
    */

    function populateDays(
        yearSelect,
        monthSelect,
        daySelect
    ) {

        if (
            !yearSelect ||
            !monthSelect ||
            !daySelect
        ) {
            return;
        }

        const year =
            Number(yearSelect.value);

        const month =
            Number(monthSelect.value);

        const currentDay =
            Number(daySelect.value);

        daySelect.innerHTML = '';

        const placeholder =
            document.createElement('option');

        placeholder.value = '';
        placeholder.textContent = 'Day';

        daySelect.appendChild(
            placeholder
        );

        const days =
            getDaysInEthiopianMonth(
                year,
                month
            );

        for (
            let day = 1;
            day <= days;
            day++
        ) {

            const option =
                document.createElement('option');

            option.value =
                String(day);

            option.textContent =
                String(day);

            if (day === currentDay) {
                option.selected = true;
            }

            daySelect.appendChild(
                option
            );
        }

        /*
         * If previously selected day is
         * larger than the new month allows,
         * select the last valid day.
         */

        if (
            currentDay > days &&
            days > 0
        ) {

            daySelect.value =
                String(days);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Subjects According To Selected Class
    |--------------------------------------------------------------------------
    */

    function updateSubjects() {

        if (
            !classSelect ||
            !subjectSelect
        ) {
            return;
        }

        const classKey =
            classSelect.value;

        subjectSelect.innerHTML = '';

        const placeholder =
            document.createElement('option');

        placeholder.value = '';

        if (!classKey) {

            placeholder.textContent =
                'Select a class first';

            subjectSelect.appendChild(
                placeholder
            );

            subjectSelect.disabled =
                true;

            return;
        }

        placeholder.textContent =
            'Select subject';

        subjectSelect.appendChild(
            placeholder
        );

        const parts =
            classKey.split('|');

        const selectedGrade =
            Number(parts[0]);

        const selectedSection =
            parts[1];

        const classAssignments =
            assignments.filter(
                function (assignment) {

                    return (
                        Number(assignment.grade) ===
                            selectedGrade &&

                        String(assignment.section) ===
                            selectedSection
                    );

                }
            );


        classAssignments.forEach(
            function (assignment) {

                const option =
                    document.createElement('option');

                /*
                 * Submit assignment_id.
                 *
                 * The server will verify the
                 * teacher assignment.
                 */

                option.value =
                    String(assignment.id);

                option.textContent =
                    assignment.subject_name;

                subjectSelect.appendChild(
                    option
                );

            }
        );


        subjectSelect.disabled =
            classAssignments.length === 0;
    }


    /*
    |--------------------------------------------------------------------------
    | Initialize Date Pickers
    |--------------------------------------------------------------------------
    */

    populateDays(
        assignedYear,
        assignedMonth,
        assignedDay
    );

    populateDays(
        dueYear,
        dueMonth,
        dueDay
    );


    /*
    |--------------------------------------------------------------------------
    | Date Changes
    |--------------------------------------------------------------------------
    */

    if (assignedYear) {

        assignedYear.addEventListener(
            'change',
            function () {

                populateDays(
                    assignedYear,
                    assignedMonth,
                    assignedDay
                );

            }
        );

    }


    if (assignedMonth) {

        assignedMonth.addEventListener(
            'change',
            function () {

                populateDays(
                    assignedYear,
                    assignedMonth,
                    assignedDay
                );

            }
        );

    }


    if (dueYear) {

        dueYear.addEventListener(
            'change',
            function () {

                populateDays(
                    dueYear,
                    dueMonth,
                    dueDay
                );

            }
        );

    }


    if (dueMonth) {

        dueMonth.addEventListener(
            'change',
            function () {

                populateDays(
                    dueYear,
                    dueMonth,
                    dueDay
                );

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Class Change
    |--------------------------------------------------------------------------
    */

    if (classSelect) {

        classSelect.addEventListener(
            'change',
            function () {

                classSelect.classList.remove(
                    'is-invalid'
                );

                updateSubjects();

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Subject Change
    |--------------------------------------------------------------------------
    */

    if (subjectSelect) {

        subjectSelect.addEventListener(
            'change',
            function () {

                subjectSelect.classList.remove(
                    'is-invalid'
                );

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Form Validation
    |--------------------------------------------------------------------------
    */

    if (form) {

        form.addEventListener(
            'submit',
            function (event) {

                let valid = true;


                /*
                |--------------------------------------------------------------------------
                | Class
                |--------------------------------------------------------------------------
                */

                if (
                    !classSelect ||
                    classSelect.value === ''
                ) {

                    valid = false;

                    if (classSelect) {

                        classSelect.classList.add(
                            'is-invalid'
                        );

                    }

                } else {

                    classSelect.classList.remove(
                        'is-invalid'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Subject
                |--------------------------------------------------------------------------
                */

                if (
                    !subjectSelect ||
                    subjectSelect.value === ''
                ) {

                    valid = false;

                    if (subjectSelect) {

                        subjectSelect.classList.add(
                            'is-invalid'
                        );

                    }

                } else {

                    subjectSelect.classList.remove(
                        'is-invalid'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Title
                |--------------------------------------------------------------------------
                */

                const title =
                    document.getElementById('title');


                if (
                    !title ||
                    title.value.trim() === ''
                ) {

                    valid = false;

                    if (title) {

                        title.classList.add(
                            'is-invalid'
                        );

                    }

                } else {

                    title.classList.remove(
                        'is-invalid'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Assigned Date
                |--------------------------------------------------------------------------
                */

                if (
                    !assignedYear.value ||
                    !assignedMonth.value ||
                    !assignedDay.value
                ) {

                    valid = false;

                    assignedYear.classList.add(
                        'is-invalid'
                    );

                    assignedMonth.classList.add(
                        'is-invalid'
                    );

                    assignedDay.classList.add(
                        'is-invalid'
                    );

                } else {

                    assignedYear.classList.remove(
                        'is-invalid'
                    );

                    assignedMonth.classList.remove(
                        'is-invalid'
                    );

                    assignedDay.classList.remove(
                        'is-invalid'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Due Date
                |--------------------------------------------------------------------------
                */

                if (
                    !dueYear.value ||
                    !dueMonth.value ||
                    !dueDay.value
                ) {

                    valid = false;

                    dueYear.classList.add(
                        'is-invalid'
                    );

                    dueMonth.classList.add(
                        'is-invalid'
                    );

                    dueDay.classList.add(
                        'is-invalid'
                    );

                } else {

                    dueYear.classList.remove(
                        'is-invalid'
                    );

                    dueMonth.classList.remove(
                        'is-invalid'
                    );

                    dueDay.classList.remove(
                        'is-invalid'
                    );

                }


                /*
                |--------------------------------------------------------------------------
                | Stop Submission
                |--------------------------------------------------------------------------
                */

                if (!valid) {

                    event.preventDefault();

                    const firstInvalid =
                        form.querySelector(
                            '.is-invalid'
                        );

                    if (firstInvalid) {
                        firstInvalid.focus();
                    }

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Prevent Double Submission
                |--------------------------------------------------------------------------
                */

                const submitButton =
                    document.getElementById(
                        'createHomeworkButton'
                    );


                if (submitButton) {

                    submitButton.disabled =
                        true;

                    submitButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2"></span>' +
                        'Creating...';

                }

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Reset Form
    |--------------------------------------------------------------------------
    */

    if (resetButton) {

        resetButton.addEventListener(
            'click',
            function () {

                setTimeout(
                    function () {

                        if (classSelect) {

                            classSelect.value =
                                '';

                            classSelect.classList.remove(
                                'is-invalid'
                            );

                        }


                        updateSubjects();


                        populateDays(
                            assignedYear,
                            assignedMonth,
                            assignedDay
                        );


                        populateDays(
                            dueYear,
                            dueMonth,
                            dueDay
                        );


                        if (form) {

                            form
                                .querySelectorAll(
                                    '.is-invalid'
                                )
                                .forEach(
                                    function (element) {

                                        element.classList.remove(
                                            'is-invalid'
                                        );

                                    }
                                );

                        }

                    },
                    0
                );

            }
        );

    }

})();

</script>

</body>

</html>