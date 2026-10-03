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

require_once '../config/database.php';
require_once 'roster-data.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function getQueryInt(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key])
        ? (int) $_GET[$key]
        : $default;
}

/*
|--------------------------------------------------------------------------
| Principal Profile
|--------------------------------------------------------------------------
*/

$principalId = (int) $_SESSION['user_id'];

$principal = [
    'id' => $principalId,
    'full_name' => 'Principal',
    'email' => '',
    'phone' => '',
    'photo' => '',
];

$profileStmt = $conn->prepare(
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
    LIMIT 1
    "
);

if ($profileStmt) {
    $profileStmt->bind_param('i', $principalId);
    $profileStmt->execute();

    $profileResult = $profileStmt->get_result();

    if ($profileResult && ($profileRow = $profileResult->fetch_assoc())) {
        $principal = array_merge(
            $principal,
            $profileRow
        );
    }

    $profileStmt->close();
}

/*
|--------------------------------------------------------------------------
| Principal Photo
|--------------------------------------------------------------------------
*/

$principalPhoto = trim((string) ($principal['photo'] ?? ''));

if ($principalPhoto !== '') {

    if (
        str_starts_with($principalPhoto, 'http://') ||
        str_starts_with($principalPhoto, 'https://') ||
        str_starts_with($principalPhoto, '/')
    ) {
        $principalPhotoUrl = $principalPhoto;
    } else {
        $principalPhotoUrl = '../' . ltrim($principalPhoto, '/');
    }

} else {
    $principalPhotoUrl = '';
}

/*
|--------------------------------------------------------------------------
| Filter Values
|--------------------------------------------------------------------------
*/

$selectedAcademicYearId = getQueryInt(
    'academic_year_id',
    0
);

$selectedGradeId = getQueryInt(
    'grade_id',
    0
);

$selectedSectionId = getQueryInt(
    'section_id',
    0
);

$selectedRosterType = isset($_GET['roster_type'])
    ? strtolower(trim((string) $_GET['roster_type']))
    : 'first';

$allowedRosterTypes = [
    'first',
    'second',
    'annual',
];

if (!in_array(
    $selectedRosterType,
    $allowedRosterTypes,
    true
)) {
    $selectedRosterType = 'first';
}

$allowedPerPage = [
    10,
    20,
    30,
    50,
    100,
];

$perPage = getQueryInt(
    'per_page',
    20
);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$page = max(
    1,
    getQueryInt('page', 1)
);

/*
|--------------------------------------------------------------------------
| Academic Years / Grades / Sections
|--------------------------------------------------------------------------
*/

$academicYears = getRosterAcademicYears($conn);
$grades = getRosterGrades($conn);
$sections = getRosterSections($conn);

/*
|--------------------------------------------------------------------------
| Default Academic Year
|--------------------------------------------------------------------------
*/

if ($selectedAcademicYearId <= 0 && !empty($academicYears)) {

    foreach ($academicYears as $academicYear) {

        if (
            strtolower(
                trim((string) ($academicYear['status'] ?? ''))
            ) === 'active'
        ) {
            $selectedAcademicYearId =
                (int) $academicYear['id'];

            break;
        }
    }

    if ($selectedAcademicYearId <= 0) {
        $selectedAcademicYearId =
            (int) $academicYears[0]['id'];
    }
}

/*
|--------------------------------------------------------------------------
| Roster Data
|--------------------------------------------------------------------------
*/

$roster = [
    'subjects' => [],
    'students' => [],
    'total_students' => 0,
    'ready' => false,
    'message' => '',
];

if (
    $selectedAcademicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0
) {

    $roster = getRoster(
        $conn,
        $selectedAcademicYearId,
        $selectedGradeId,
        $selectedSectionId,
        $selectedRosterType
    );
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalStudents = (int) (
    $roster['total_students'] ??
    count($roster['students'] ?? [])
);

$totalPages = max(
    1,
    (int) ceil($totalStudents / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
| getRoster() loads the complete roster so that Rank is calculated
| globally. Pagination is applied only to the displayed rows.
|--------------------------------------------------------------------------
*/

$displayStudents = array_slice(
    $roster['students'] ?? [],
    $offset,
    $perPage
);

/*
|--------------------------------------------------------------------------
| Subjects
|--------------------------------------------------------------------------
|
| roster-data.php returns:
|
| [
|     [
|         'id'   => 1,
|         'name' => 'Maths'
|     ],
|     ...
| ]
|
| Marks are stored using subject ID:
|
| $student['subjects'][$subjectId]
|
|--------------------------------------------------------------------------
*/

$subjects = $roster['subjects'] ?? [];

/*
|--------------------------------------------------------------------------
| Selected Names
|--------------------------------------------------------------------------
*/

$selectedAcademicYearName = '';

foreach ($academicYears as $academicYear) {

    if (
        (int) $academicYear['id'] ===
        $selectedAcademicYearId
    ) {
        $selectedAcademicYearName =
            (string) $academicYear['name'];

        break;
    }
}

$selectedGradeName = '';

foreach ($grades as $grade) {

    if (
        (int) $grade['id'] ===
        $selectedGradeId
    ) {
        $selectedGradeName =
            (string) $grade['name'];

        break;
    }
}

$selectedSectionName = '';

foreach ($sections as $section) {

    if (
        (int) $section['id'] ===
        $selectedSectionId
    ) {
        $selectedSectionName =
            (string) $section['name'];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Roster Type Label
|--------------------------------------------------------------------------
*/

$rosterTypeLabel = match ($selectedRosterType) {
    'first' => '1st Semester',
    'second' => '2nd Semester',
    'annual' => 'Annual Roster',
    default => '1st Semester',
};

/*
|--------------------------------------------------------------------------
| Export URL
|--------------------------------------------------------------------------
*/

$exportUrl =
    'roster-export.php?' .
    http_build_query([
        'academic_year_id' => $selectedAcademicYearId,
        'grade_id' => $selectedGradeId,
        'section_id' => $selectedSectionId,
        'roster_type' => $selectedRosterType,
    ]);

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function rosterPageUrl(
    int $pageNumber,
    int $perPage,
    int $academicYearId,
    int $gradeId,
    int $sectionId,
    string $rosterType
): string {
    return '?' . http_build_query([
        'academic_year_id' => $academicYearId,
        'grade_id' => $gradeId,
        'section_id' => $sectionId,
        'roster_type' => $rosterType,
        'per_page' => $perPage,
        'page' => $pageNumber,
    ]);
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
        content="Principal roster management - Bole Kale Hiwot School"
    >

    <title>
        Roster | Principal Portal | BKHS
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
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
        rel="stylesheet"
        href="roster-style.php"
    >

    <style>

        :root {
            --primary: #4f46e5;
            --primary-dark: #312e81;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --border: #e5e7eb;
            --muted: #6b7280;
            --background: #f8fafc;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: #111827;
            font-family: 'Inter', sans-serif;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 260px;
            background: var(--sidebar);
            z-index: 1100;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand-text {
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -.3px;
        }

        .sidebar-brand small {
            display: block;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 500;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .nav-label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 10px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d1d5db;
            text-decoration: none;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 10px;
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

        .sidebar-link.logout {
            color: #fca5a5;
            margin-top: 8px;
        }

        .sidebar-link.logout:hover {
            background: rgba(239,68,68,.12);
            color: #fecaca;
        }

        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

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

        .topbar-left h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .topbar-left p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .principal-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .principal-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            font-size: 11px;
            color: var(--muted);
        }

        .content {
            padding: 28px;
        }

        .page-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 15px rgba(15,23,42,.04);
        }

        .filter-card {
            padding: 22px;
            margin-bottom: 22px;
        }

        .filter-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-select {
            min-height: 42px;
            border-color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(79,70,229,.12);
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            min-height: 42px;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-export {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 9px;
            min-height: 42px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-export:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .roster-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .roster-title {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .roster-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .roster-table {
            width: 100%;
            min-width: 1050px;
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
        }

        .roster-table th {
            background: #f8fafc;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            padding: 13px 12px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid #eef0f3;
            text-align: center;
        }

        .roster-table th:first-child,
        .roster-table th:nth-child(2),
        .roster-table th:nth-child(3) {
            text-align: left;
        }

        .roster-table td {
            padding: 13px 12px;
            border-bottom: 1px solid #eef0f3;
            border-right: 1px solid #f1f3f5;
            font-size: 12px;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }

        .roster-table td:nth-child(2),
        .roster-table td:nth-child(3) {
            text-align: left;
        }

        .roster-table tbody tr:hover td {
            background: #fafbff;
        }

        .student-code {
            color: var(--primary);
            font-weight: 600;
        }

        .student-name {
            font-weight: 600;
            color: #111827;
        }

        .semester-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef2ff;
            color: var(--primary-dark);
            font-size: 10px;
            font-weight: 700;
        }

        .annual-row td {
            background: #fafafa;
            font-weight: 600;
        }

        .annual-average-row td {
            background: #f5f3ff;
            font-weight: 700;
        }

        .number-strong {
            font-weight: 700;
        }

        .empty-state {
            padding: 55px 25px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            font-size: 38px;
            margin-bottom: 12px;
            color: #9ca3af;
        }

        .empty-state h5 {
            color: #374151;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 12px;
            margin: 0;
        }

        .pagination-wrapper {
            padding: 18px 22px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination .page-link {
            font-size: 12px;
            color: #374151;
            border-color: #e5e7eb;
        }

        .pagination .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
            z-index: 1050;
        }

        @media (max-width: 900px) {

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

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .topbar-left {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .content {
                padding: 18px;
            }
        }

        @media (max-width: 576px) {

            .topbar {
                height: 70px;
            }

            .topbar-left h1 {
                font-size: 17px;
            }

            .topbar-left p {
                display: none;
            }

            .principal-name,
            .principal-role {
                display: none;
            }

            .content {
                padding: 12px;
            }

            .filter-card {
                padding: 16px;
            }

            .roster-header {
                padding: 17px;
            }

            .pagination-wrapper {
                padding: 15px;
            }
        }

    </style>

</head>

<body>

<!-- ==========================================================
     SIDEBAR OVERLAY
=========================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- ==========================================================
     SIDEBAR
=========================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div>

            <div class="sidebar-brand-text">
                BKHS
            </div>

            <small>
                Principal Portal
            </small>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-label">
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
            href="announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcements</span>
        </a>

        <a
            href="results.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Results</span>
        </a>

        <a
            href="Roster.php"
            class="sidebar-link active"
        >
            <i class="bi bi-people-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="sidebar-link"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <div class="nav-label mt-3">
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

<!-- ==========================================================
     MAIN WRAPPER
=========================================================== -->

<div class="main-wrapper">

    <!-- ======================================================
         TOPBAR
    ======================================================= -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open navigation"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1>
                    Student Roster
                </h1>

                <p>
                    View semester and annual student rosters
                </p>

            </div>

        </div>

        <div class="principal-profile">

            <div class="text-end">

                <div class="principal-name">
                    <?= h($principal['full_name']) ?>
                </div>

                <div class="principal-role">
                    Principal
                </div>

            </div>

            <div class="principal-avatar">

                <?php if ($principalPhotoUrl !== ''): ?>

                    <img
                        src="<?= h($principalPhotoUrl) ?>"
                        alt="Principal"
                    >

                <?php else: ?>

                    <?= h(
                        strtoupper(
                            substr(
                                trim((string) $principal['full_name']),
                                0,
                                1
                            )
                        )
                    ) ?>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <!-- ======================================================
         CONTENT
    ======================================================= -->

    <main class="content">

        <!-- ==================================================
             FILTER CARD
        =================================================== -->

        <div class="page-card filter-card">

            <div class="filter-title">
                <i class="bi bi-funnel me-2 text-primary"></i>
                Roster Filters
            </div>

            <form
                method="GET"
                action=""
            >

                <div class="row g-3 align-items-end">

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
                            required
                        >

                            <option value="">
                                Select Academic Year
                            </option>

                            <?php foreach ($academicYears as $academicYear): ?>

                                <option
                                    value="<?= (int) $academicYear['id'] ?>"
                                    <?= (
                                        (int) $academicYear['id'] ===
                                        $selectedAcademicYearId
                                    ) ? 'selected' : '' ?>
                                >
                                    <?= h($academicYear['name']) ?>

                                    <?php if (
                                        strtolower(
                                            trim(
                                                (string) (
                                                    $academicYear['status']
                                                    ?? ''
                                                )
                                            )
                                        ) === 'active'
                                    ): ?>

                                        (Active)

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Grade -->

                    <div class="col-lg-2 col-md-6">

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
                                    <?= h($grade['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Section -->

                    <div class="col-lg-2 col-md-6">

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
                                    <?= h($section['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Roster Type -->

                    <div class="col-lg-2 col-md-6">

                        <label
                            for="roster_type"
                            class="form-label"
                        >
                            Roster Type
                        </label>

                        <select
                            name="roster_type"
                            id="roster_type"
                            class="form-select"
                        >

                            <option
                                value="first"
                                <?= $selectedRosterType === 'first'
                                    ? 'selected'
                                    : '' ?>
                            >
                                1st Semester
                            </option>

                            <option
                                value="second"
                                <?= $selectedRosterType === 'second'
                                    ? 'selected'
                                    : '' ?>
                            >
                                2nd Semester
                            </option>

                            <option
                                value="annual"
                                <?= $selectedRosterType === 'annual'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Annual Roster
                            </option>

                        </select>

                    </div>

                    <!-- Submit -->

                    <div class="col-lg-3 col-md-6">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >
                                <i class="bi bi-search me-1"></i>
                                View Roster
                            </button>

                            <?php if (
                                $selectedAcademicYearId > 0 &&
                                $selectedGradeId > 0 &&
                                $selectedSectionId > 0 &&
                                !empty($roster['students'])
                            ): ?>

                                <a
                                    href="<?= h($exportUrl) ?>"
                                    class="btn btn-export"
                                    title="Export roster"
                                >
                                    <i class="bi bi-file-earmark-excel"></i>
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <!-- ==================================================
             ROSTER CARD
        =================================================== -->

        <div class="page-card">

            <div class="roster-header">

                <div>

                    <h2 class="roster-title">
                        <?= h($rosterTypeLabel) ?>
                    </h2>

                    <?php if (
                        $selectedAcademicYearName !== '' ||
                        $selectedGradeName !== '' ||
                        $selectedSectionName !== ''
                    ): ?>

                        <div class="roster-subtitle">

                            <?= h($selectedAcademicYearName) ?>

                            <?php if ($selectedGradeName !== ''): ?>
                                <span class="mx-1">•</span>
                                <?= h($selectedGradeName) ?>
                            <?php endif; ?>

                            <?php if ($selectedSectionName !== ''): ?>
                                <span class="mx-1">•</span>
                                <?= h($selectedSectionName) ?>
                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalStudents > 0): ?>

                    <div class="text-muted small">

                        <i class="bi bi-people me-1"></i>

                        <?= number_format($totalStudents) ?>
                        Students

                    </div>

                <?php endif; ?>

            </div>

            <!-- ==================================================
                 ANNUAL NOT READY
            =================================================== -->

            <?php if (
                $selectedRosterType === 'annual' &&
                isset($roster['ready']) &&
                !$roster['ready']
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-hourglass-split"></i>

                    <h5>
                        Annual Roster Not Ready
                    </h5>

                    <p>
                        The annual roster becomes available after
                        both First Semester and Second Semester are
                        completed.
                    </p>

                </div>

            <!-- ==================================================
                 NO STUDENTS
            =================================================== -->

            <?php elseif (empty($displayStudents)): ?>

                <div class="empty-state">

                    <i class="bi bi-people"></i>

                    <h5>
                        No Students Found
                    </h5>

                    <p>
                        Select an academic year, grade, section,
                        and roster type to view the roster.
                    </p>

                </div>

            <!-- ==================================================
                 TABLE
            =================================================== -->

            <?php else: ?>

                <div class="table-wrapper">

                    <table class="roster-table">

                        <thead>

                            <tr>

                                <th>
                                    No
                                </th>

                                <th>
                                    Student Code
                                </th>

                                <th>
                                    Student Name
                                </th>

                                <?php if (
                                    $selectedRosterType === 'annual'
                                ): ?>

                                    <th>
                                        Semester
                                    </th>

                                <?php else: ?>

                                    <th>
                                        Semester
                                    </th>

                                <?php endif; ?>

                                <!-- =================================
                                     DYNAMIC SUBJECTS
                                ================================== -->

                                <?php foreach ($subjects as $subject): ?>

                                    <th>
                                        <?= h(
                                            (string) $subject['name']
                                        ) ?>
                                    </th>

                                <?php endforeach; ?>

                                <th>
                                    Sum
                                </th>

                                <th>
                                    Average
                                </th>

                                <th>
                                    Rank
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $displayStudents as $studentIndex => $student
                        ): ?>

                            <?php
                            $globalNumber =
                                $offset +
                                $studentIndex +
                                1;
                            ?>

                            <!-- =====================================
                                 NORMAL SEMESTER ROSTER
                            ====================================== -->

                            <?php if (
                                $selectedRosterType !== 'annual'
                            ): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= $globalNumber ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span class="student-code">
                                            <?= h(
                                                $student['student_code']
                                                ?? ''
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="student-name">
                                            <?= h(
                                                $student['student_name']
                                                ?? $student['full_name']
                                                ?? ''
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>

                                        <span class="semester-badge">

                                            <?= $selectedRosterType === 'first'
                                                ? '1st Semester'
                                                : '2nd Semester'
                                            ?>

                                        </span>

                                    </td>

                                    <!-- =================================
                                         SUBJECT MARKS

                                         IMPORTANT:
                                         Use subject ID, NOT subject name.
                                    ================================== -->

                                    <?php foreach (
                                        $subjects as $subject
                                    ): ?>

                                        <?php
                                        $subjectId =
                                            (int) $subject['id'];

                                        $mark =
                                            $student['subjects']
                                                [$subjectId]
                                            ?? null;
                                        ?>

                                        <td>
                                            <?= h(
                                                formatRosterMark($mark)
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                    <!-- Sum -->

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['sum']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <!-- Average -->

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['average']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <!-- Rank -->

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterRank(
                                                $student['rank']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <!-- =====================================
                                 ANNUAL ROSTER
                            ====================================== -->

                            <?php else: ?>

                                <!-- ================================
                                     FIRST SEMESTER
                                ================================= -->

                                <tr>

                                    <td rowspan="3">
                                        <strong>
                                            <?= $globalNumber ?>
                                        </strong>
                                    </td>

                                    <td rowspan="3">

                                        <span class="student-code">
                                            <?= h(
                                                $student['student_code']
                                                ?? ''
                                            ) ?>
                                        </span>

                                    </td>

                                    <td rowspan="3">

                                        <span class="student-name">
                                            <?= h(
                                                $student['student_name']
                                                ?? $student['full_name']
                                                ?? ''
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <span class="semester-badge">
                                            1st Semester
                                        </span>

                                    </td>

                                    <!-- FIRST SEMESTER SUBJECTS -->

                                    <?php foreach (
                                        $subjects as $subject
                                    ): ?>

                                        <?php
                                        $subjectId =
                                            (int) $subject['id'];

                                        $mark =
                                            $student['first_subjects']
                                                [$subjectId]
                                            ?? null;
                                        ?>

                                        <td>
                                            <?= h(
                                                formatRosterMark($mark)
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['first_sum']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['first_average']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterRank(
                                                $student['first_rank']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                                <!-- ================================
                                     SECOND SEMESTER
                                ================================= -->

                                <tr>

                                    <td>

                                        <span class="semester-badge">
                                            2nd Semester
                                        </span>

                                    </td>

                                    <!-- SECOND SEMESTER SUBJECTS -->

                                    <?php foreach (
                                        $subjects as $subject
                                    ): ?>

                                        <?php
                                        $subjectId =
                                            (int) $subject['id'];

                                        $mark =
                                            $student['second_subjects']
                                                [$subjectId]
                                            ?? null;
                                        ?>

                                        <td>
                                            <?= h(
                                                formatRosterMark($mark)
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['second_sum']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['second_average']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterRank(
                                                $student['second_rank']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                                <!-- ================================
                                     ANNUAL AVERAGE
                                ================================= -->

                                <tr class="annual-average-row">

                                    <td>

                                        <span class="semester-badge">
                                            Annual Average
                                        </span>

                                    </td>

                                    <!-- ANNUAL SUBJECT AVERAGES -->

                                    <?php foreach (
                                        $subjects as $subject
                                    ): ?>

                                        <?php
                                        $subjectId =
                                            (int) $subject['id'];

                                        $mark =
                                            $student['annual_subjects']
                                                [$subjectId]
                                            ?? null;
                                        ?>

                                        <td>
                                            <?= h(
                                                formatRosterMark($mark)
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['annual_sum']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterNumber(
                                                $student['annual_average']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                    <td class="number-strong">
                                        <?= h(
                                            formatRosterRank(
                                                $student['annual_rank']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endif; ?>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- ==================================================
                     PAGINATION
                =================================================== -->

                <?php if ($totalStudents > 0): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing

                            <strong>
                                <?= $offset + 1 ?>
                            </strong>

                            to

                            <strong>
                                <?= min(
                                    $offset + $perPage,
                                    $totalStudents
                                ) ?>
                            </strong>

                            of

                            <strong>
                                <?= number_format($totalStudents) ?>
                            </strong>

                            students

                        </div>

                        <div class="d-flex align-items-center gap-3">

                            <!-- Per Page -->

                            <form
                                method="GET"
                                action=""
                                class="d-flex align-items-center gap-2"
                            >

                                <input
                                    type="hidden"
                                    name="academic_year_id"
                                    value="<?= $selectedAcademicYearId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="grade_id"
                                    value="<?= $selectedGradeId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="section_id"
                                    value="<?= $selectedSectionId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="roster_type"
                                    value="<?= h(
                                        $selectedRosterType
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="page"
                                    value="1"
                                >

                                <select
                                    name="per_page"
                                    class="form-select form-select-sm"
                                    style="width:80px;"
                                    onchange="this.form.submit()"
                                    aria-label="Rows per page"
                                >

                                    <?php foreach (
                                        $allowedPerPage as $option
                                    ): ?>

                                        <option
                                            value="<?= $option ?>"
                                            <?= $perPage === $option
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= $option ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </form>

                            <!-- Pagination -->

                            <?php if ($totalPages > 1): ?>

                                <nav aria-label="Roster pagination">

                                    <ul class="pagination pagination-sm mb-0">

                                        <!-- Previous -->

                                        <li
                                            class="page-item
                                            <?= $page <= 1
                                                ? 'disabled'
                                                : '' ?>"
                                        >

                                            <?php if ($page > 1): ?>

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        rosterPageUrl(
                                                            $page - 1,
                                                            $perPage,
                                                            $selectedAcademicYearId,
                                                            $selectedGradeId,
                                                            $selectedSectionId,
                                                            $selectedRosterType
                                                        )
                                                    ) ?>"
                                                >
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>

                                            <?php else: ?>

                                                <span class="page-link">
                                                    <i class="bi bi-chevron-left"></i>
                                                </span>

                                            <?php endif; ?>

                                        </li>

                                        <?php

                                        $startPage = max(
                                            1,
                                            $page - 2
                                        );

                                        $endPage = min(
                                            $totalPages,
                                            $page + 2
                                        );

                                        ?>

                                        <?php if ($startPage > 1): ?>

                                            <li class="page-item">

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        rosterPageUrl(
                                                            1,
                                                            $perPage,
                                                            $selectedAcademicYearId,
                                                            $selectedGradeId,
                                                            $selectedSectionId,
                                                            $selectedRosterType
                                                        )
                                                    ) ?>"
                                                >
                                                    1
                                                </a>

                                            </li>

                                            <?php if ($startPage > 2): ?>

                                                <li class="page-item disabled">

                                                    <span class="page-link">
                                                        ...
                                                    </span>

                                                </li>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                        <?php for (
                                            $paginationPage = $startPage;
                                            $paginationPage <= $endPage;
                                            $paginationPage++
                                        ): ?>

                                            <li
                                                class="page-item
                                                <?= $paginationPage === $page
                                                    ? 'active'
                                                    : '' ?>"
                                            >

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        rosterPageUrl(
                                                            $paginationPage,
                                                            $perPage,
                                                            $selectedAcademicYearId,
                                                            $selectedGradeId,
                                                            $selectedSectionId,
                                                            $selectedRosterType
                                                        )
                                                    ) ?>"
                                                >
                                                    <?= $paginationPage ?>
                                                </a>

                                            </li>

                                        <?php endfor; ?>

                                        <?php if (
                                            $endPage < $totalPages
                                        ): ?>

                                            <?php if (
                                                $endPage <
                                                $totalPages - 1
                                            ): ?>

                                                <li class="page-item disabled">

                                                    <span class="page-link">
                                                        ...
                                                    </span>

                                                </li>

                                            <?php endif; ?>

                                            <li class="page-item">

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        rosterPageUrl(
                                                            $totalPages,
                                                            $perPage,
                                                            $selectedAcademicYearId,
                                                            $selectedGradeId,
                                                            $selectedSectionId,
                                                            $selectedRosterType
                                                        )
                                                    ) ?>"
                                                >
                                                    <?= $totalPages ?>
                                                </a>

                                            </li>

                                        <?php endif; ?>

                                        <!-- Next -->

                                        <li
                                            class="page-item
                                            <?= $page >= $totalPages
                                                ? 'disabled'
                                                : '' ?>"
                                        >

                                            <?php if (
                                                $page < $totalPages
                                            ): ?>

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        rosterPageUrl(
                                                            $page + 1,
                                                            $perPage,
                                                            $selectedAcademicYearId,
                                                            $selectedGradeId,
                                                            $selectedSectionId,
                                                            $selectedRosterType
                                                        )
                                                    ) ?>"
                                                >
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>

                                            <?php else: ?>

                                                <span class="page-link">
                                                    <i class="bi bi-chevron-right"></i>
                                                </span>

                                            <?php endif; ?>

                                        </li>

                                    </ul>

                                </nav>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </main>

</div>

<!-- ==========================================================
     BOOTSTRAP
=========================================================== -->

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

    }

    function closeSidebar() {

        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');

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

    document
        .querySelectorAll('.sidebar-link')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <= 900
                    ) {
                        closeSidebar();
                    }

                }
            );

        });

</script>

</body>
</html>