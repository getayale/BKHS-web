<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Principal Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../../config/database.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Database Check
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

/*
|--------------------------------------------------------------------------
| Load Academic Years
|--------------------------------------------------------------------------
*/

$academicYears = [];

$sql = "
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
    ORDER BY start_year DESC, id DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $academicYears[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Load Grades
|--------------------------------------------------------------------------
*/

$grades = [];

$sql = "
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Load Sections
|--------------------------------------------------------------------------
*/

$sections = [];

$sql = "
    SELECT
        id,
        name,
        code
    FROM sections
    ORDER BY name ASC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Selected Values
|--------------------------------------------------------------------------
*/

$selectedAcademicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$selectedGradeId = isset($_GET['grade_id'])
    ? (int) $_GET['grade_id']
    : 0;

$selectedSectionId = isset($_GET['section_id'])
    ? (int) $_GET['section_id']
    : 0;

$selectedSemesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

/*
|--------------------------------------------------------------------------
| Default Academic Year
|--------------------------------------------------------------------------
*/

if ($selectedAcademicYearId === 0 && !empty($academicYears)) {
    foreach ($academicYears as $year) {
        if (strcasecmp((string) $year['status'], 'Active') === 0) {
            $selectedAcademicYearId = (int) $year['id'];
            break;
        }
    }

    if ($selectedAcademicYearId === 0) {
        $selectedAcademicYearId = (int) $academicYears[0]['id'];
    }
}

/*
|--------------------------------------------------------------------------
| Load Semesters For Selected Academic Year
|--------------------------------------------------------------------------
*/

$semesters = [];

if ($selectedAcademicYearId > 0) {
    $stmt = $conn->prepare("
        SELECT
            id,
            academic_year_id,
            name,
            order_number,
            max_mark,
            status
        FROM semesters
        WHERE academic_year_id = ?
        ORDER BY order_number ASC, id ASC
    ");

    if ($stmt) {
        $stmt->bind_param('i', $selectedAcademicYearId);
        $stmt->execute();

        $semesterResult = $stmt->get_result();

        while ($row = $semesterResult->fetch_assoc()) {
            $semesters[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Default Semester
|--------------------------------------------------------------------------
*/

if ($selectedSemesterId === 0 && !empty($semesters)) {

    /*
     * Prefer Mid Semester.
     * Otherwise use the first semester.
     */
    foreach ($semesters as $semester) {
        if (strcasecmp((string) $semester['name'], 'Mid Semester') === 0) {
            $selectedSemesterId = (int) $semester['id'];
            break;
        }
    }

    if ($selectedSemesterId === 0) {
        $selectedSemesterId = (int) $semesters[0]['id'];
    }
}

/*
|--------------------------------------------------------------------------
| Selected Semester Information
|--------------------------------------------------------------------------
*/

$selectedSemester = null;

foreach ($semesters as $semester) {
    if ((int) $semester['id'] === $selectedSemesterId) {
        $selectedSemester = $semester;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Student Count
|--------------------------------------------------------------------------
|
| Count students in the selected:
| Academic Year + Grade + Section + Semester
|
| A student is counted only if at least one result exists.
|
| IMPORTANT:
| The actual result table is `results`.
|
*/

$studentCount = 0;

if (
    $selectedAcademicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0 &&
    $selectedSemesterId > 0
) {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT sr.student_id) AS total_students
        FROM student_registrations sr
        INNER JOIN results r
            ON r.student_registration_id = sr.id
        INNER JOIN students s
            ON s.id = sr.student_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND r.semester_id = ?
          AND s.is_deleted = 0
    ");

    if ($stmt) {
        $stmt->bind_param(
            'iiii',
            $selectedAcademicYearId,
            $selectedGradeId,
            $selectedSectionId,
            $selectedSemesterId
        );

        $stmt->execute();

        $countResult = $stmt->get_result();
        $countRow = $countResult->fetch_assoc();

        $studentCount = (int) ($countRow['total_students'] ?? 0);

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selected Grade
|--------------------------------------------------------------------------
*/

$selectedGrade = null;

foreach ($grades as $grade) {
    if ((int) $grade['id'] === $selectedGradeId) {
        $selectedGrade = $grade;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Section
|--------------------------------------------------------------------------
*/

$selectedSection = null;

foreach ($sections as $section) {
    if ((int) $section['id'] === $selectedSectionId) {
        $selectedSection = $section;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Academic Year
|--------------------------------------------------------------------------
*/

$selectedAcademicYear = null;

foreach ($academicYears as $year) {
    if ((int) $year['id'] === $selectedAcademicYearId) {
        $selectedAcademicYear = $year;
        break;
    }
}

$principalName = $_SESSION['full_name'] ?? 'Principal';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Report Card | Principal</title>

    <!-- BKHS Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
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
            --sidebar-width: 260px;
            --topbar-height: 76px;
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --body-bg: #f5f7fb;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--body-bg);
            color: var(--text);
        }

        /* --------------------------------------------------------------
           Sidebar
        -------------------------------------------------------------- */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar);
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            color: #fff;
            text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
        }

        .sidebar-brand span {
            font-size: 16px;
            font-weight: 700;
        }

        .nav-section {
            padding: 22px 14px 8px;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar .nav-link {
            color: #d1d5db;
            padding: 11px 14px;
            margin: 3px 8px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            font-weight: 500;
            transition: .2s ease;
        }

        .sidebar .nav-link i {
            font-size: 17px;
            width: 21px;
        }

        .sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: rgba(79,70,229,.2);
            color: #fff;
        }

        .sidebar .nav-link.active i {
            color: #818cf8;
        }

        /* --------------------------------------------------------------
           Main
        -------------------------------------------------------------- */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .page-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .principal-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .principal-info {
            line-height: 1.2;
        }

        .principal-info strong {
            display: block;
            font-size: 13px;
        }

        .principal-info small {
            color: var(--muted);
            font-size: 11px;
        }

        .content {
            padding: 28px;
        }

        /* --------------------------------------------------------------
           Cards
        -------------------------------------------------------------- */

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 12px rgba(15,23,42,.04);
        }

        .card-header-custom {
            padding: 22px 24px;
            border-bottom: 1px solid var(--border);
        }

        .card-header-custom h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .card-header-custom p {
            margin: 5px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        .card-body-custom {
            padding: 24px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .form-select {
            min-height: 46px;
            border-color: #dfe3ea;
            border-radius: 10px;
            font-size: 13px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(79,70,229,.1);
        }

        .btn-primary-custom {
            min-height: 46px;
            border: 0;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 0 20px;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .btn-outline-custom {
            min-height: 46px;
            border: 1px solid #dfe3ea;
            border-radius: 10px;
            background: #fff;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            padding: 0 20px;
        }

        .btn-outline-custom:hover {
            background: #f9fafb;
        }

        /* --------------------------------------------------------------
           Info
        -------------------------------------------------------------- */

        .info-card {
            padding: 20px;
            border-radius: 14px;
            border: 1px solid #e0e7ff;
            background: #f8f9ff;
        }

        .info-label {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 700;
        }

        .student-count {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .count-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .count-number {
            font-size: 24px;
            font-weight: 800;
        }

        .count-label {
            font-size: 11px;
            color: var(--muted);
        }

        .empty-state {
            padding: 34px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 34px;
            margin-bottom: 10px;
            color: #9ca3af;
        }

        .selection-summary {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            background: #fff;
        }

        .summary-title {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 14px;
            font-weight: 700;
        }

        /* --------------------------------------------------------------
           Mobile
        -------------------------------------------------------------- */

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        .mobile-toggle {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-toggle {
                display: inline-flex;
            }

            .mobile-overlay.show {
                display: block;
            }

            .content {
                padding: 20px;
            }

            .principal-info {
                display: none;
            }
        }

        @media (max-width: 575.98px) {

            .topbar {
                padding: 0 16px;
            }

            .content {
                padding: 15px;
            }

            .card-body-custom {
                padding: 18px;
            }

            .page-title {
                font-size: 17px;
            }

            .btn-primary-custom,
            .btn-outline-custom {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<!-- ================================================================
     Sidebar
================================================================ -->

<aside class="sidebar" id="sidebar">

    <a href="../dashboard.php" class="sidebar-brand">

        <img
            src="../../public/image/logo.webp"
            alt="BKHS Logo"
        >

        <span>Bole Kale Hiwot</span>

    </a>

    <div class="nav-section">Main</div>

    <a
        href="../dashboard.php"
        class="nav-link"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="../announcements.php"
        class="nav-link"
    >
        <i class="bi bi-megaphone-fill"></i>
        <span>Announcements</span>
    </a>

    <div class="nav-section">Academic</div>

    <a
        href="../results.php"
        class="nav-link"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Results</span>
    </a>

    <a
        href="index.php"
        class="nav-link active"
    >
        <i class="bi bi-file-earmark-person-fill"></i>
        <span>Report Card</span>
    </a>

    <a
        href="../transcript/index.php"
        class="nav-link"
    >
        <i class="bi bi-file-earmark-text-fill"></i>
        <span>Transcript</span>
    </a>

    <div class="nav-section">Account</div>

    <a
        href="../profile.php"
        class="nav-link"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../logout.php"
        class="nav-link"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</aside>

<div
    class="mobile-overlay"
    id="mobileOverlay"
    onclick="closeSidebar()"
></div>

<!-- ================================================================
     Main
================================================================ -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-toggle"
                onclick="openSidebar()"
                aria-label="Open navigation"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Report Card
                </h1>

                <div class="page-subtitle">
                    Generate semester report cards by section
                </div>

            </div>

        </div>

        <div class="principal-profile">

            <div class="principal-avatar">
                <?= e(strtoupper(substr($principalName, 0, 1))) ?>
            </div>

            <div class="principal-info">

                <strong>
                    <?= e($principalName) ?>
                </strong>

                <small>
                    Principal
                </small>

            </div>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <div class="card-box">

            <div class="card-header-custom">

                <h5>
                    <i class="bi bi-file-earmark-person me-2"></i>
                    Generate Report Cards
                </h5>

                <p>
                    Select one academic year, grade, section and semester.
                    Report cards are generated two students per A4 page.
                </p>

            </div>

            <div class="card-body-custom">

                <form
                    method="GET"
                    action="index.php"
                    id="reportCardForm"
                >

                    <div class="row g-3">

                        <!-- Academic Year -->

                        <div class="col-lg-3 col-md-6">

                            <label
                                for="academic_year_id"
                                class="form-label"
                            >
                                Academic Year
                            </label>

                            <select
                                name="academic_year_id"
                                id="academic_year_id"
                                class="form-select"
                                onchange="reloadSemesters()"
                                required
                            >

                                <option value="">
                                    Select Academic Year
                                </option>

                                <?php foreach ($academicYears as $year): ?>

                                    <option
                                        value="<?= (int) $year['id'] ?>"
                                        <?= (
                                            (int) $year['id'] ===
                                            $selectedAcademicYearId
                                        ) ? 'selected' : '' ?>
                                    >

                                        <?= e((string) $year['name']) ?> E.C

                                        <?php if (
                                            strcasecmp(
                                                (string) $year['status'],
                                                'Active'
                                            ) === 0
                                        ): ?>

                                            — Active

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Grade -->

                        <div class="col-lg-3 col-md-6">

                            <label
                                for="grade_id"
                                class="form-label"
                            >
                                Grade
                            </label>

                            <select
                                name="grade_id"
                                id="grade_id"
                                class="form-select"
                                onchange="this.form.submit()"
                                required
                            >

                                <option value="">
                                    Select Grade
                                </option>

                                <?php foreach ($grades as $grade): ?>

                                    <option
                                        value="<?= (int) $grade['id'] ?>"
                                        <?= (
                                            (int) $grade['id'] ===
                                            $selectedGradeId
                                        ) ? 'selected' : '' ?>
                                    >

                                        <?= e((string) $grade['name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Section -->

                        <div class="col-lg-3 col-md-6">

                            <label
                                for="section_id"
                                class="form-label"
                            >
                                Section
                            </label>

                            <select
                                name="section_id"
                                id="section_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Section
                                </option>

                                <?php foreach ($sections as $section): ?>

                                    <option
                                        value="<?= (int) $section['id'] ?>"
                                        <?= (
                                            (int) $section['id'] ===
                                            $selectedSectionId
                                        ) ? 'selected' : '' ?>
                                    >

                                        <?= e((string) $section['name']) ?>

                                        <?php if (
                                            !empty($section['code']) &&
                                            $section['code'] !== $section['name']
                                        ): ?>

                                            (<?= e((string) $section['code']) ?>)

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Semester -->

                        <div class="col-lg-3 col-md-6">

                            <label
                                for="semester_id"
                                class="form-label"
                            >
                                Semester
                            </label>

                            <select
                                name="semester_id"
                                id="semester_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Semester
                                </option>

                                <?php foreach ($semesters as $semester): ?>

                                    <option
                                        value="<?= (int) $semester['id'] ?>"
                                        <?= (
                                            (int) $semester['id'] ===
                                            $selectedSemesterId
                                        ) ? 'selected' : '' ?>
                                    >

                                        <?= e((string) $semester['name']) ?>
                                        —
                                        <?= (int) $semester['max_mark'] ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">

                        <button
                            type="submit"
                            class="btn-primary-custom"
                        >
                            <i class="bi bi-search me-2"></i>
                            Load Section
                        </button>

                        <?php if (
                            $studentCount > 0 &&
                            $selectedAcademicYearId > 0 &&
                            $selectedGradeId > 0 &&
                            $selectedSectionId > 0 &&
                            $selectedSemesterId > 0
                        ): ?>

                            <a
                                href="print.php?academic_year_id=<?= $selectedAcademicYearId ?>&grade_id=<?= $selectedGradeId ?>&section_id=<?= $selectedSectionId ?>&semester_id=<?= $selectedSemesterId ?>"
                                target="_blank"
                                class="btn-outline-custom d-inline-flex align-items-center justify-content-center"
                            >

                                <i class="bi bi-printer me-2"></i>

                                Generate Report Cards

                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </div>

        </div>

        <!-- ============================================================
             Selection Summary
        ============================================================= -->

        <?php if (
            $selectedAcademicYear &&
            $selectedGrade &&
            $selectedSection &&
            $selectedSemester
        ): ?>

            <div class="card-box mt-4">

                <div class="card-body-custom">

                    <div class="row g-3">

                        <div class="col-md-3">

                            <div class="selection-summary">

                                <div class="summary-title">
                                    Academic Year
                                </div>

                                <div class="summary-value">

                                    <?= e((string) $selectedAcademicYear['name']) ?>
                                    E.C

                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="selection-summary">

                                <div class="summary-title">
                                    Grade
                                </div>

                                <div class="summary-value">

                                    <?= e((string) $selectedGrade['name']) ?>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="selection-summary">

                                <div class="summary-title">
                                    Section
                                </div>

                                <div class="summary-value">

                                    <?= e((string) $selectedSection['name']) ?>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="selection-summary">

                                <div class="summary-title">
                                    Semester / Maximum Mark
                                </div>

                                <div class="summary-value">

                                    <?= e((string) $selectedSemester['name']) ?>
                                    /
                                    <?= (int) $selectedSemester['max_mark'] ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- ========================================================
                 Student Count
            ========================================================= -->

            <div class="card-box mt-4">

                <div class="card-body-custom">

                    <?php if ($studentCount > 0): ?>

                        <div
                            class="d-flex flex-wrap justify-content-between align-items-center gap-3"
                        >

                            <div class="student-count">

                                <div class="count-icon">

                                    <i class="bi bi-people-fill"></i>

                                </div>

                                <div>

                                    <div class="count-number">
                                        <?= $studentCount ?>
                                    </div>

                                    <div class="count-label">
                                        student(s) with entered results
                                    </div>

                                </div>

                            </div>

                            <div class="text-muted small">

                                <i class="bi bi-info-circle me-1"></i>

                                2 students will appear on each A4 page.

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-file-earmark-x d-block"></i>

                            <strong class="d-block mb-1">
                                No entered results found
                            </strong>

                            <span class="small">
                                There are no result records for this
                                section and semester.
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</main>

<script>

    function openSidebar() {

        document
            .getElementById('sidebar')
            .classList.add('show');

        document
            .getElementById('mobileOverlay')
            .classList.add('show');

    }

    function closeSidebar() {

        document
            .getElementById('sidebar')
            .classList.remove('show');

        document
            .getElementById('mobileOverlay')
            .classList.remove('show');

    }

    function reloadSemesters() {

        const form =
            document.getElementById('reportCardForm');

        const grade =
            document.getElementById('grade_id');

        const section =
            document.getElementById('section_id');

        if (grade) {
            grade.value = '';
        }

        if (section) {
            section.value = '';
        }

        form.submit();

    }

</script>

</body>
</html>