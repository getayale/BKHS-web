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

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Principal Information
|--------------------------------------------------------------------------
*/

$principalId = (int) $_SESSION['user_id'];

$principal = [
    'id' => $principalId,
    'full_name' => 'Principal',
    'email' => '',
    'phone' => '',
    'photo' => null,
];

$principalStmt = $conn->prepare(
    "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        p.photo
    FROM users u
    LEFT JOIN principals p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'principal'
      AND u.is_deleted = 0
    LIMIT 1
    "
);

if ($principalStmt) {

    $principalStmt->bind_param(
        'i',
        $principalId
    );

    $principalStmt->execute();

    $principalResult = $principalStmt->get_result();

    if ($principalRow = $principalResult->fetch_assoc()) {
        $principal = $principalRow;
    }

    $principalStmt->close();
}

/*
|--------------------------------------------------------------------------
| Principal Photo
|--------------------------------------------------------------------------
*/

$principalPhoto = '';

if (
    !empty($principal['photo']) &&
    is_string($principal['photo'])
) {
    $principalPhoto = '../' . ltrim(
        $principal['photo'],
        '/'
    );
}

/*
|--------------------------------------------------------------------------
| Selected Filters
|--------------------------------------------------------------------------
*/

$selectedAcademicYearId = filter_input(
    INPUT_GET,
    'academic_year_id',
    FILTER_VALIDATE_INT
);

$selectedSemesterId = filter_input(
    INPUT_GET,
    'semester_id',
    FILTER_VALIDATE_INT
);

$selectedGradeId = filter_input(
    INPUT_GET,
    'grade_id',
    FILTER_VALIDATE_INT
);

$selectedSectionId = filter_input(
    INPUT_GET,
    'section_id',
    FILTER_VALIDATE_INT
);

$selectedAcademicYearId = $selectedAcademicYearId ?: 0;
$selectedSemesterId = $selectedSemesterId ?: 0;
$selectedGradeId = $selectedGradeId ?: 0;
$selectedSectionId = $selectedSectionId ?: 0;

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/

$academicYears = [];

$academicYearResult = $conn->query(
    "
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
    "
);

if ($academicYearResult) {

    while ($row = $academicYearResult->fetch_assoc()) {
        $academicYears[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Academic Year
|--------------------------------------------------------------------------
*/

$selectedAcademicYear = null;

if ($selectedAcademicYearId > 0) {

    $stmt = $conn->prepare(
        "
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
        WHERE id = ?
        LIMIT 1
        "
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedAcademicYearId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $selectedAcademicYear = $result->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Semesters
|--------------------------------------------------------------------------
|
| Only First Semester and Second Semester are used.
|--------------------------------------------------------------------------
*/

$semesters = [];

if ($selectedAcademicYearId > 0) {

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            academic_year_id,
            name,
            order_number,
            max_mark,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day,
            status
        FROM semesters
        WHERE academic_year_id = ?
          AND LOWER(TRIM(name)) IN (
              'first semester',
              'second semester'
          )
        ORDER BY order_number ASC, id ASC
        "
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedAcademicYearId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $semesters[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selected Semester
|--------------------------------------------------------------------------
*/

$selectedSemester = null;

if ($selectedSemesterId > 0) {

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            academic_year_id,
            name,
            order_number,
            max_mark,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day,
            status
        FROM semesters
        WHERE id = ?
          AND academic_year_id = ?
          AND LOWER(TRIM(name)) IN (
              'first semester',
              'second semester'
          )
        LIMIT 1
        "
    );

    if ($stmt) {

        $stmt->bind_param(
            'ii',
            $selectedSemesterId,
            $selectedAcademicYearId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $selectedSemester = $result->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

$grades = [];

$gradeResult = $conn->query(
    "
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
    "
);

if ($gradeResult) {

    while ($row = $gradeResult->fetch_assoc()) {
        $grades[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Grade
|--------------------------------------------------------------------------
*/

$selectedGrade = null;

if ($selectedGradeId > 0) {

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            name,
            grade_number
        FROM grades
        WHERE id = ?
        LIMIT 1
        "
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedGradeId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $selectedGrade = $result->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
|
| Sections are loaded directly from the sections table.
|
| academic_year_grade_sections is currently empty, so we do not use it
| for the dropdown.
|--------------------------------------------------------------------------
*/

$sections = [];

if ($selectedGradeId > 0) {

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            name,
            code
        FROM sections
        ORDER BY name ASC
        "
    );

    if ($stmt) {

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selected Section
|--------------------------------------------------------------------------
*/

$selectedSection = null;

if ($selectedSectionId > 0) {

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            name,
            code
        FROM sections
        WHERE id = ?
        LIMIT 1
        "
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedSectionId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $selectedSection = $result->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$statistics = [];

$statisticsLoaded = (
    $selectedAcademicYear !== null &&
    $selectedSemester !== null &&
    $selectedGrade !== null &&
    $selectedSection !== null
);

if ($statisticsLoaded) {

    /*
    |--------------------------------------------------------------------------
    | Subject Statistics
    |--------------------------------------------------------------------------
    |
    | Every active subject for the selected grade is shown.
    |
    | < 50
    | 50 - 74.99
    | >= 75
    |
    | No average is calculated.
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            gs.id AS subject_id,
            gs.subject_name,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'male'
                     AND r.mark < 50
                    THEN s.id
                END
            ) AS below_50_male,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'female'
                     AND r.mark < 50
                    THEN s.id
                END
            ) AS below_50_female,

            COUNT(
                DISTINCT CASE
                    WHEN r.mark < 50
                    THEN s.id
                END
            ) AS below_50_total,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'male'
                     AND r.mark >= 50
                     AND r.mark < 75
                    THEN s.id
                END
            ) AS range_50_74_male,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'female'
                     AND r.mark >= 50
                     AND r.mark < 75
                    THEN s.id
                END
            ) AS range_50_74_female,

            COUNT(
                DISTINCT CASE
                    WHEN r.mark >= 50
                     AND r.mark < 75
                    THEN s.id
                END
            ) AS range_50_74_total,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'male'
                     AND r.mark >= 75
                    THEN s.id
                END
            ) AS above_75_male,

            COUNT(
                DISTINCT CASE
                    WHEN LOWER(TRIM(s.gender)) = 'female'
                     AND r.mark >= 75
                    THEN s.id
                END
            ) AS above_75_female,

            COUNT(
                DISTINCT CASE
                    WHEN r.mark >= 75
                    THEN s.id
                END
            ) AS above_75_total

        FROM grade_subjects gs

        LEFT JOIN results r
            ON r.grade_subject_id = gs.id
           AND r.semester_id = ?

        LEFT JOIN student_registrations sr
            ON sr.id = r.student_registration_id
           AND sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?

        LEFT JOIN students s
            ON s.id = sr.student_id
           AND s.is_deleted = 0

        WHERE gs.grade = ?
          AND gs.is_active = 1

        GROUP BY
            gs.id,
            gs.subject_name

        ORDER BY
            gs.subject_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        $stmt->bind_param(
            'iiiii',
            $selectedSemesterId,
            $selectedAcademicYearId,
            $selectedGradeId,
            $selectedSectionId,
            $selectedGrade['grade_number']
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $row['below_50_male'] =
                (int) $row['below_50_male'];

            $row['below_50_female'] =
                (int) $row['below_50_female'];

            $row['below_50_total'] =
                (int) $row['below_50_total'];

            $row['range_50_74_male'] =
                (int) $row['range_50_74_male'];

            $row['range_50_74_female'] =
                (int) $row['range_50_74_female'];

            $row['range_50_74_total'] =
                (int) $row['range_50_74_total'];

            $row['above_75_male'] =
                (int) $row['above_75_male'];

            $row['above_75_female'] =
                (int) $row['above_75_female'];

            $row['above_75_total'] =
                (int) $row['above_75_total'];

            $statistics[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Overall Totals
|--------------------------------------------------------------------------
*/

$totals = [
    'below_50_male' => 0,
    'below_50_female' => 0,
    'below_50_total' => 0,

    'range_50_74_male' => 0,
    'range_50_74_female' => 0,
    'range_50_74_total' => 0,

    'above_75_male' => 0,
    'above_75_female' => 0,
    'above_75_total' => 0,
];

foreach ($statistics as $row) {

    $totals['below_50_male'] +=
        $row['below_50_male'];

    $totals['below_50_female'] +=
        $row['below_50_female'];

    $totals['below_50_total'] +=
        $row['below_50_total'];

    $totals['range_50_74_male'] +=
        $row['range_50_74_male'];

    $totals['range_50_74_female'] +=
        $row['range_50_74_female'];

    $totals['range_50_74_total'] +=
        $row['range_50_74_total'];

    $totals['above_75_male'] +=
        $row['above_75_male'];

    $totals['above_75_female'] +=
        $row['above_75_female'];

    $totals['above_75_total'] +=
        $row['above_75_total'];
}

/*
|--------------------------------------------------------------------------
| Ethiopian UI Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::todayFormatted('en');

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
        content="Principal student subject statistics - Bole Kale Hiwot School."
    >

    <link
        rel="icon"
        type="image/webp"
        href="../public/logo.webp"
    >

    <title>Statistics | Principal | BKHS</title>
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

    <!-- Inter -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #d1d5db;
            --sidebar-muted: #9ca3af;
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
            background: var(--body-bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
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
            bottom: 0;
            width: 260px;
            background: var(--sidebar);
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
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
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
        }

        .sidebar-brand-text small {
            display: block;
            margin-top: 3px;
            color: var(--sidebar-muted);
            font-size: 11px;
            font-weight: 500;
        }

        .sidebar-section {
            padding: 18px 14px 7px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sidebar-nav {
            padding: 0 10px 20px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 44px;
            margin-bottom: 3px;
            padding: 0 12px;
            border-radius: 9px;
            color: var(--sidebar-text);
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .sidebar-link.active {
            color: #fff;
            background: var(--primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -.02em;
        }

        .page-subtitle {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .principal-profile-text {
            text-align: right;
        }

        .principal-profile-name {
            color: #111827;
            font-size: 13px;
            font-weight: 600;
        }

        .principal-profile-role {
            color: var(--muted);
            font-size: 11px;
        }

        .principal-avatar {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
            background: #eef2ff;
        }

        .principal-avatar-placeholder {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            border: 2px solid #e5e7eb;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .03);
        }

        .filter-card {
            background: #fff;
        }

        .filter-card .card-body {
            padding: 22px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #374151;
            font-size: 12px;
            font-weight: 600;
        }

        .form-select {
            min-height: 44px;
            border-color: #dfe3ea;
            border-radius: 9px;
            font-size: 13px;
            box-shadow: none;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .10);
        }

        .btn-primary {
            min-height: 44px;
            padding: 0 20px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            min-height: 42px;
            padding: 0 17px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Report Header
        |--------------------------------------------------------------------------
        */

        .report-header {
            margin-top: 22px;
            padding: 22px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px 14px 0 0;
        }

        .report-header-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .report-header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .report-header-meta span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /*
        |--------------------------------------------------------------------------
        | Statistics Table
        |--------------------------------------------------------------------------
        */

        .statistics-card {
            overflow: hidden;
            background: #fff;
            border-top: 0;
            border-radius: 0 0 14px 14px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .statistics-table {
            min-width: 980px;
            margin: 0;
            vertical-align: middle;
        }

        .statistics-table th,
        .statistics-table td {
            padding: 12px 10px;
            border-color: var(--border);
            text-align: center;
            white-space: nowrap;
        }

        .statistics-table thead th {
            color: #374151;
            background: #f8fafc;
            font-size: 11px;
            font-weight: 700;
        }

        .statistics-table thead .subject-header {
            min-width: 190px;
            text-align: left;
        }

        .statistics-table .group-header {
            font-size: 12px;
            font-weight: 700;
        }

        .statistics-table tbody td {
            color: #374151;
            font-size: 13px;
        }

        .statistics-table tbody td:first-child {
            text-align: left;
            color: #111827;
            font-weight: 600;
        }

        .statistics-table tbody tr:hover td {
            background: #fafafa;
        }

        .statistics-table .total-row td {
            background: #f8fafc;
            color: #111827;
            font-weight: 700;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            color: #a5b4fc;
            font-size: 42px;
        }

        .empty-state h5 {
            margin-bottom: 6px;
            color: #374151;
            font-size: 15px;
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        .summary-row {
            margin-top: 22px;
        }

        .summary-card {
            height: 100%;
            padding: 18px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .summary-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .summary-value {
            margin-top: 5px;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
        }

        .summary-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--primary);
            font-size: 18px;
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
            z-index: 1040;
            background: rgba(15, 23, 42, .55);
        }

        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: #374151;
            font-size: 19px;
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

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

        }

        @media (max-width: 575.98px) {

            .topbar {
                height: 70px;
                padding: 0 14px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .principal-profile-text {
                display: none;
            }

            .principal-avatar,
            .principal-avatar-placeholder {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 14px;
            }

            .filter-card .card-body {
                padding: 16px;
            }

            .report-header {
                padding: 17px;
            }

            .report-header-title {
                font-size: 16px;
            }

            .btn-success {
                width: 100%;
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
            src="../public/logo.webp"
            alt="BKHS Logo"
        >

        <div class="sidebar-brand-text">
            BKHS
            <small>Principal Portal</small>
        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcements</span>
        </a>

        <a
            href="subject-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-fill"></i>
            <span>Subject Assignment</span>
        </a>

        <a
            href="homeroom-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a
            href="student-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-people-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="roster.php"
            class="sidebar-link"
        >
            <i class="bi bi-card-list"></i>
            <span>Roster</span>
        </a>

        <a
            href="certificate.php"
            class="sidebar-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="result.php"
            class="sidebar-link"
        >
            <i class="bi bi-journal-check"></i>
            <span>Result</span>
        </a>

        <a
            href="statistics.php"
            class="sidebar-link active"
        >
            <i class="bi bi-bar-chart-line-fill"></i>
            <span>Statistics</span>
        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<!--
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
-->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Statistics
                </h1>

                <p class="page-subtitle">
                    Subject performance distribution by grade and section
                </p>

            </div>

        </div>

        <div class="principal-profile">

            <div class="principal-profile-text">

                <div class="principal-profile-name">
                    <?= e($principal['full_name']) ?>
                </div>

                <div class="principal-profile-role">
                    Principal
                </div>

            </div>

            <?php if ($principalPhoto !== ''): ?>

                <img
                    src="<?= e($principalPhoto) ?>"
                    alt="Principal"
                    class="principal-avatar"
                >

            <?php else: ?>

                <div class="principal-avatar-placeholder">
                    <i class="bi bi-person-fill"></i>
                </div>

            <?php endif; ?>

        </div>

    </header>

    <!-- Content -->

    <section class="content">

        <!-- Filter Card -->

        <div class="card filter-card">

            <div class="card-body">

                <form
                    method="GET"
                    action="statistics.php"
                    id="statisticsForm"
                >

                    <div class="row g-3 align-items-end">

                        <!-- Academic Year -->

                        <div class="col-12 col-md-6 col-lg-3">

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
                            >

                                <option value="">
                                    Select Academic Year
                                </option>

                                <?php foreach ($academicYears as $academicYear): ?>

                                    <option
                                        value="<?= (int) $academicYear['id'] ?>"
                                        <?= (
                                            $selectedAcademicYearId ===
                                            (int) $academicYear['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= e($academicYear['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Semester -->

                        <div class="col-12 col-md-6 col-lg-3">

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
                                <?= $selectedAcademicYear === null ? 'disabled' : '' ?>
                            >

                                <option value="">
                                    Select Semester
                                </option>

                                <?php foreach ($semesters as $semester): ?>

                                    <option
                                        value="<?= (int) $semester['id'] ?>"
                                        <?= (
                                            $selectedSemesterId ===
                                            (int) $semester['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= e($semester['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Grade -->

                        <div class="col-12 col-md-6 col-lg-2">

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
                                <?= $selectedAcademicYear === null ? 'disabled' : '' ?>
                            >

                                <option value="">
                                    Select Grade
                                </option>

                                <?php foreach ($grades as $grade): ?>

                                    <option
                                        value="<?= (int) $grade['id'] ?>"
                                        <?= (
                                            $selectedGradeId ===
                                            (int) $grade['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= e($grade['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Section -->

                        <div class="col-12 col-md-6 col-lg-2">

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
                                <?= (
                                    $selectedGrade === null
                                ) ? 'disabled' : '' ?>
                            >

                                <option value="">
                                    Select Section
                                </option>

                                <?php foreach ($sections as $section): ?>

                                    <option
                                        value="<?= (int) $section['id'] ?>"
                                        <?= (
                                            $selectedSectionId ===
                                            (int) $section['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= e($section['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Button -->

                        <div class="col-12 col-lg-2">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                <?= (
                                    $selectedAcademicYear === null ||
                                    $selectedSemester === null ||
                                    $selectedGrade === null ||
                                    $selectedSection === null
                                ) ? 'disabled' : '' ?>
                            >
                                <i class="bi bi-bar-chart-line-fill me-1"></i>
                                Generate
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <?php if ($statisticsLoaded): ?>

            <!-- Report Header -->

            <div class="report-header">

                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

                    <div>

                        <h2 class="report-header-title">
                            Subject Statistics
                        </h2>

                        <div class="report-header-meta">

                            <span>
                                <i class="bi bi-calendar3"></i>
                                Academic Year:
                                <strong>
                                    <?= e($selectedAcademicYear['name']) ?>
                                </strong>
                            </span>

                            <span>
                                <i class="bi bi-calendar2-week"></i>
                                Semester:
                                <strong>
                                    <?= e($selectedSemester['name']) ?>
                                </strong>
                            </span>

                            <span>
                                <i class="bi bi-mortarboard-fill"></i>
                                <?= e($selectedGrade['name']) ?>
                            </span>

                            <span>
                                <i class="bi bi-diagram-3-fill"></i>
                                Section:
                                <strong>
                                    <?= e($selectedSection['name']) ?>
                                </strong>
                            </span>

                            <span>
                                <i class="bi bi-clock"></i>
                                Generated:
                                <strong>
                                    <?= e($todayEthiopian) ?>
                                </strong>
                            </span>

                        </div>

                    </div>

                    <!-- Export Excel -->

                    <a
                        href="export-statistics.php?academic_year_id=<?= (int) $selectedAcademicYearId ?>&semester_id=<?= (int) $selectedSemesterId ?>&grade_id=<?= (int) $selectedGradeId ?>&section_id=<?= (int) $selectedSectionId ?>"
                        class="btn btn-success"
                    >
                        <i class="bi bi-file-earmark-excel-fill me-1"></i>
                        Export Excel
                    </a>

                </div>

            </div>

            <!-- Statistics Table -->

            <div class="card statistics-card">

                <?php if (!empty($statistics)): ?>

                    <div class="table-responsive">

                        <table
                            class="table table-bordered statistics-table"
                        >

                            <thead>

                                <tr>

                                    <th
                                        rowspan="2"
                                        class="subject-header align-middle"
                                    >
                                        Subject
                                    </th>

                                    <th
                                        colspan="3"
                                        class="group-header"
                                    >
                                        &lt; 50
                                    </th>

                                    <th
                                        colspan="3"
                                        class="group-header"
                                    >
                                        50–74
                                    </th>

                                    <th
                                        colspan="3"
                                        class="group-header"
                                    >
                                        ≥ 75
                                    </th>

                                </tr>

                                <tr>

                                    <th>Male</th>
                                    <th>Female</th>
                                    <th>Total</th>

                                    <th>Male</th>
                                    <th>Female</th>
                                    <th>Total</th>

                                    <th>Male</th>
                                    <th>Female</th>
                                    <th>Total</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($statistics as $row): ?>

                                    <tr>

                                        <td>
                                            <?= e($row['subject_name']) ?>
                                        </td>

                                        <td>
                                            <?= $row['below_50_male'] ?>
                                        </td>

                                        <td>
                                            <?= $row['below_50_female'] ?>
                                        </td>

                                        <td>
                                            <?= $row['below_50_total'] ?>
                                        </td>

                                        <td>
                                            <?= $row['range_50_74_male'] ?>
                                        </td>

                                        <td>
                                            <?= $row['range_50_74_female'] ?>
                                        </td>

                                        <td>
                                            <?= $row['range_50_74_total'] ?>
                                        </td>

                                        <td>
                                            <?= $row['above_75_male'] ?>
                                        </td>

                                        <td>
                                            <?= $row['above_75_female'] ?>
                                        </td>

                                        <td>
                                            <?= $row['above_75_total'] ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                            <tfoot>

                                <tr class="total-row">

                                    <td>Total</td>

                                    <td>
                                        <?= $totals['below_50_male'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['below_50_female'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['below_50_total'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['range_50_74_male'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['range_50_74_female'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['range_50_74_total'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['above_75_male'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['above_75_female'] ?>
                                    </td>

                                    <td>
                                        <?= $totals['above_75_total'] ?>
                                    </td>

                                </tr>

                            </tfoot>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-bar-chart-line"></i>

                        <h5>
                            No Statistics Available
                        </h5>

                        <p>
                            There are no subject results available for the
                            selected academic year, semester, grade and section.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

            <!-- Summary -->

            <div class="row g-3 summary-row">

                <div class="col-12 col-md-4">

                    <div class="summary-card">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="summary-label">
                                    Subjects
                                </div>

                                <div class="summary-value">
                                    <?= count($statistics) ?>
                                </div>

                            </div>

                            <div class="summary-icon">
                                <i class="bi bi-book-fill"></i>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <div class="summary-card">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="summary-label">
                                    Students Below 50
                                </div>

                                <div class="summary-value">
                                    <?= $totals['below_50_total'] ?>
                                </div>

                            </div>

                            <div class="summary-icon">
                                <i class="bi bi-graph-down-arrow"></i>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <div class="summary-card">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="summary-label">
                                    Students 75 and Above
                                </div>

                                <div class="summary-value">
                                    <?= $totals['above_75_total'] ?>
                                </div>

                            </div>

                            <div class="summary-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        <?php else: ?>

            <!-- Initial State -->

            <div class="card mt-4">

                <div class="empty-state">

                    <i class="bi bi-bar-chart-line"></i>

                    <h5>
                        View Subject Statistics
                    </h5>

                    <p>
                        Select an academic year, semester, grade and section
                        to view the subject-by-subject score distribution.
                    </p>

                </div>

            </div>

        <?php endif; ?>

    </section>

</main>

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

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

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

window.addEventListener(
    'resize',
    function () {

        if (window.innerWidth > 991) {
            closeSidebar();
        }

    }
);

/*
|--------------------------------------------------------------------------
| Statistics Filter
|--------------------------------------------------------------------------
*/

const statisticsForm =
    document.getElementById('statisticsForm');

const academicYearSelect =
    document.getElementById('academic_year_id');

const semesterSelect =
    document.getElementById('semester_id');

const gradeSelect =
    document.getElementById('grade_id');

const sectionSelect =
    document.getElementById('section_id');

/*
|--------------------------------------------------------------------------
| Academic Year Change
|--------------------------------------------------------------------------
*/

if (academicYearSelect) {

    academicYearSelect.addEventListener(
        'change',
        function () {

            if (semesterSelect) {
                semesterSelect.value = '';
            }

            if (gradeSelect) {
                gradeSelect.value = '';
            }

            if (sectionSelect) {
                sectionSelect.value = '';
            }

            statisticsForm.submit();

        }
    );

}

/*
|--------------------------------------------------------------------------
| Grade Change
|--------------------------------------------------------------------------
*/

if (gradeSelect) {

    gradeSelect.addEventListener(
        'change',
        function () {

            if (sectionSelect) {
                sectionSelect.value = '';
            }

            statisticsForm.submit();

        }
    );

}

/*
|--------------------------------------------------------------------------
| Semester Change
|--------------------------------------------------------------------------
|
| Submit after selecting the semester so the selected semester ID is
| present in the URL before Generate is used.
|--------------------------------------------------------------------------
*/

if (semesterSelect) {

    semesterSelect.addEventListener(
        'change',
        function () {

            statisticsForm.submit();

        }
    );

}

/*
|--------------------------------------------------------------------------
| Section Change
|--------------------------------------------------------------------------
|
| Submit after selecting the section so the statistics are immediately
| generated for the selected filters.
|--------------------------------------------------------------------------
*/

if (sectionSelect) {

    sectionSelect.addEventListener(
        'change',
        function () {

            statisticsForm.submit();

        }
    );

}

</script>

</body>

</html>