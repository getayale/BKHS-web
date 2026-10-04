<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Registrar Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));

$grade = (int) (
    $_GET['grade'] ?? 0
);

$section = strtoupper(
    trim((string) ($_GET['section'] ?? ''))
);

$selectedAcademicYearId = (int) (
    $_GET['academic_year_id'] ?? 0
);

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$perPage = 10;

$errorMessage = '';

$academicYears = [];
$selectedAcademicYear = null;
$activeAcademicYear = null;

$assignments = [];

$totalRecords = 0;
$totalPages = 1;
$offset = 0;

/*
|--------------------------------------------------------------------------
| Load Academic Years
|--------------------------------------------------------------------------
|
| All academic years are loaded so the registrar can view
| current and previous subject teacher assignments.
|
*/

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            status
        FROM academic_years
        ORDER BY id DESC
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare academic years query.'
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $academicYears[] = $row;

        if (
            strtolower(
                trim((string) $row['status'])
            ) === 'active'
        ) {
            $activeAcademicYear = $row;
        }
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS subject teachers academic years error: ' .
        $e->getMessage()
    );

    $errorMessage =
        'Unable to load academic years.';
}

/*
|--------------------------------------------------------------------------
| Select Academic Year
|--------------------------------------------------------------------------
|
| If no year was selected, use the active academic year.
|
*/

if ($selectedAcademicYearId <= 0 && $activeAcademicYear) {

    $selectedAcademicYearId = (int) (
        $activeAcademicYear['id']
    );
}

/*
|--------------------------------------------------------------------------
| Find Selected Academic Year
|--------------------------------------------------------------------------
*/

foreach ($academicYears as $academicYear) {

    if (
        (int) $academicYear['id'] ===
        $selectedAcademicYearId
    ) {
        $selectedAcademicYear = $academicYear;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Fallback To Active Academic Year
|--------------------------------------------------------------------------
*/

if (
    !$selectedAcademicYear &&
    $activeAcademicYear
) {

    $selectedAcademicYear =
        $activeAcademicYear;

    $selectedAcademicYearId =
        (int) $activeAcademicYear['id'];
}

/*
|--------------------------------------------------------------------------
| Load Subject Teacher Assignments
|--------------------------------------------------------------------------
*/

if ($selectedAcademicYear) {

    $academicYearName =
        (string) $selectedAcademicYear['name'];

    /*
    |--------------------------------------------------------------------------
    | Build WHERE Conditions
    |--------------------------------------------------------------------------
    */

    $where = [
        "sta.academic_year = ?",
        "sta.is_active = 1",
        "t.employment_status = 'Active'"
    ];

    $types = 's';

    $params = [
        $academicYearName
    ];

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $where[] = "(
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR gs.subject_name LIKE ?
            OR CAST(sta.grade AS CHAR) LIKE ?
            OR sta.section LIKE ?
        )";

        $searchValue =
            '%' . $search . '%';

        $types .= 'ssssss';

        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    /*
    |--------------------------------------------------------------------------
    | Grade Filter
    |--------------------------------------------------------------------------
    */

    if ($grade > 0) {

        $where[] =
            "sta.grade = ?";

        $types .= 'i';

        $params[] = $grade;
    }

    /*
    |--------------------------------------------------------------------------
    | Section Filter
    |--------------------------------------------------------------------------
    */

    if ($section !== '') {

        $where[] =
            "sta.section = ?";

        $types .= 's';

        $params[] = $section;
    }

    $whereSql =
        implode(' AND ', $where);

    /*
    |--------------------------------------------------------------------------
    | Count Records
    |--------------------------------------------------------------------------
    */

    try {

        $countSql = "
            SELECT COUNT(*) AS total

            FROM subject_teacher_assignments sta

            INNER JOIN users u
                ON u.id = sta.teacher_user_id

            INNER JOIN teachers t
                ON t.user_id = u.id

            INNER JOIN grade_subjects gs
                ON gs.id = sta.grade_subject_id

            WHERE {$whereSql}
        ";

        $countStmt =
            $conn->prepare($countSql);

        if (!$countStmt) {
            throw new RuntimeException(
                'Unable to prepare count query.'
            );
        }

        $countStmt->bind_param(
            $types,
            ...$params
        );

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        $countRow =
            $countResult->fetch_assoc();

        $totalRecords =
            (int) (
                $countRow['total'] ?? 0
            );

        $countStmt->close();

    } catch (Throwable $e) {

        error_log(
            'BKHS subject teachers count error: ' .
            $e->getMessage()
        );

        $errorMessage =
            'Unable to load subject teacher records.';
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $totalPages = max(
        1,
        (int) ceil(
            $totalRecords / $perPage
        )
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset =
        ($page - 1) * $perPage;

    /*
    |--------------------------------------------------------------------------
    | Assignment Records
    |--------------------------------------------------------------------------
    */

    if ($totalRecords > 0) {

        try {

            $sql = "
                SELECT
                    sta.id,
                    sta.grade,
                    sta.section,
                    gs.subject_name,
                    u.full_name AS teacher_name

                FROM subject_teacher_assignments sta

                INNER JOIN users u
                    ON u.id = sta.teacher_user_id

                INNER JOIN teachers t
                    ON t.user_id = u.id

                INNER JOIN grade_subjects gs
                    ON gs.id = sta.grade_subject_id

                WHERE {$whereSql}

                ORDER BY
                    sta.grade ASC,
                    sta.section ASC,
                    gs.subject_name ASC,
                    u.full_name ASC

                LIMIT ? OFFSET ?
            ";

            $stmt =
                $conn->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare subject teacher query.'
                );
            }

            $limit = $perPage;

            $typesWithPagination =
                $types . 'ii';

            $paramsWithPagination =
                array_merge(
                    $params,
                    [
                        $limit,
                        $offset
                    ]
                );

            $stmt->bind_param(
                $typesWithPagination,
                ...$paramsWithPagination
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            while (
                $row = $result->fetch_assoc()
            ) {

                $assignments[] =
                    $row;
            }

            $stmt->close();

        } catch (Throwable $e) {

            error_log(
                'BKHS subject teachers query error: ' .
                $e->getMessage()
            );

            $errorMessage =
                'Unable to load subject teacher assignments.';
        }
    }

} else {

    if (empty($academicYears)) {

        $errorMessage =
            'No academic years were found.';
    } else {

        $errorMessage =
            'The selected academic year could not be found.';
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian Today's Date
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$todayEthiopianFormatted =
    (string) $todayEthiopian['formatted'];

/*
|--------------------------------------------------------------------------
| Preserve Filters For Pagination / Export
|--------------------------------------------------------------------------
*/

$queryParams = [
    'academic_year_id' =>
        $selectedAcademicYearId
];

if ($search !== '') {

    $queryParams['search'] =
        $search;
}

if ($grade > 0) {

    $queryParams['grade'] =
        $grade;
}

if ($section !== '') {

    $queryParams['section'] =
        $section;
}

/*
|--------------------------------------------------------------------------
| Pagination Query String
|--------------------------------------------------------------------------
*/

$queryString =
    http_build_query(
        $queryParams
    );

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
        Subject Teachers | Registrar | BKHS
    </title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: rgba(255, 255, 255, .07);
            --text: #172033;
            --muted: #64748b;
            --border: #e5e7eb;
            --background: #f8fafc;
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
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, .15);
            color: #60a5fa;
            border-radius: 10px;
            font-size: 20px;
        }

        .sidebar-brand-text strong {
            display: block;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sidebar-brand-text span {
            display: block;
            margin-top: 3px;
            color: #9ca3af;
            font-size: 11px;
        }

        .sidebar-nav {
            padding: 14px 10px 25px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            margin-bottom: 3px;
            border-radius: 8px;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .nav-link-custom i {
            width: 22px;
            margin-right: 10px;
            font-size: 17px;
        }

        .nav-link-custom:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .nav-link-custom.active {
            color: #fff;
            background: var(--primary);
        }

        .nav-group {
            margin: 0;
        }

        .nav-parent {
            width: 100%;
            border: 0;
            background: transparent;
            font-family: inherit;
            cursor: pointer;
            text-align: left;
        }

        .nav-parent:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .nav-parent[aria-expanded="true"] {
            color: #fff;
        }

        .submenu-arrow {
            width: auto !important;
            font-size: 11px !important;
            margin-left: auto !important;
            margin-right: 0 !important;
            transition: transform .2s ease;
        }

        .nav-parent[aria-expanded="true"] .submenu-arrow {
            transform: rotate(180deg);
        }

        .nav-sub-link {
            margin-left: 30px;
            margin-right: 10px;
            width: calc(100% - 40px);
            padding: 9px 12px;
            font-size: 13px;
            color: #9ca3af;
        }

        .nav-sub-link i {
            font-size: 15px;
        }

        .nav-sub-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, .06);
        }

        .nav-sub-link.active {
            background: var(--primary);
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
        }

        .topbar-title h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .topbar-title p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .profile-area {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-info span {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: 11px;
        }

        .content {
            padding: 28px 30px 40px;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            z-index: 1030;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Header
        |--------------------------------------------------------------------------
        */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }

        .page-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .today-date {
            margin-top: 8px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 10px;
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
        | Stat Card
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-value {
            margin-top: 5px;
            font-size: 25px;
            font-weight: 800;
        }

        /*
        |--------------------------------------------------------------------------
        | Filter Card
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #374151;
            font-size: 12px;
            font-weight: 600;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #dbe1e8;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .1);
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .table-header h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .academic-year-display {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .academic-year-display i {
            color: var(--primary);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            min-width: 650px;
            margin: 0;
        }

        .table thead th {
            background: #f8fafc;
            color: #475569;
            border-bottom: 1px solid var(--border);
            padding: 13px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px 16px;
            border-color: #eef2f7;
            vertical-align: middle;
            font-size: 13px;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .teacher-name {
            font-weight: 600;
            color: #172033;
        }

        .class-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            padding: 5px 9px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }

        .subject-name {
            font-weight: 600;
            color: #334155;
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
            border-top: 1px solid var(--border);
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            color: var(--primary);
            font-size: 12px;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            font-size: 38px;
            color: #94a3b8;
        }

        .empty-state h4 {
            margin-bottom: 6px;
            color: #334155;
            font-size: 15px;
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            font-size: 13px;
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

            .main-wrapper {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .topbar-left {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .content {
                padding: 22px 18px 35px;
            }

            .profile-info {
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 767.98px) {

            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
            }

            .table-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .pagination-wrapper {
                align-items: flex-start;
                flex-direction: column;
            }

        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div class="sidebar-brand-text">

            <strong>BKHS</strong>

            <span>Registrar Portal</span>

        </div>

    </div>


    <nav class="sidebar-nav">

        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="nav-link-custom"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>


        <!-- Students -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#studentsMenu"
                aria-expanded="false"
                aria-controls="studentsMenu"
            >

                <i class="bi bi-people-fill"></i>

                <span>Students</span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse"
                id="studentsMenu"
            >

                <a
                    href="register.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Register</span>
                </a>

                <a
                    href="update-student.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update Student</span>
                </a>

                <a
                    href="delete-student.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-x-fill"></i>
                    <span>Delete Student</span>
                </a>

                <a
                    href="withdraw-student.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw Student</span>
                </a>

                <a
                    href="withdrawn-students.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-check-fill"></i>
                    <span>Withdrawn Students</span>
                </a>

            </div>

        </div>


        <!-- Teachers -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#teachersMenu"
                aria-expanded="true"
                aria-controls="teachersMenu"
            >

                <i class="bi bi-person-video3"></i>

                <span>Teachers</span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse show"
                id="teachersMenu"
            >

                <a
                    href="teachers.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-people"></i>
                    <span>Teachers</span>
                </a>

                <a
                    href="update-teacher.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update Teacher</span>
                </a>

                <a
                    href="withdraw-teacher.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-dash"></i>
                    <span>Withdraw Teacher</span>
                </a>

                <a
                    href="withdrawn-teachers.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-check-fill"></i>
                    <span>Withdrawn Teachers</span>
                </a>

                <a
                    href="homeroom-teachers.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-workspace"></i>
                    <span>Homeroom Teachers</span>
                </a>

                <a
                    href="subject-teachers.php"
                    class="nav-link-custom nav-sub-link active"
                >
                    <i class="bi bi-person-video2"></i>
                    <span>Subject Teachers</span>
                </a>

            </div>

        </div>


        <!-- Other Staff -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#staffMenu"
                aria-expanded="false"
                aria-controls="staffMenu"
            >

                <i class="bi bi-person-badge-fill"></i>

                <span>Other Staff</span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse"
                id="staffMenu"
            >

                <a
                    href="add-staff.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Add Staff</span>
                </a>

                <a
                    href="staff.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-people-fill"></i>
                    <span>Staff</span>
                </a>

                <a
                    href="withdraw-staff.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw Staff</span>
                </a>

            </div>

        </div>


        <!-- Certificate -->

        <a
            href="certificate.php"
            class="nav-link-custom"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>


        <!-- Roster -->

        <a
            href="Roster.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>


        <!-- Transcript -->

        <a
            href="Transcript.php"
            class="nav-link-custom"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>


        <!-- Profile -->

        <a
            href="profile.php"
            class="nav-link-custom"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>


        <!-- Logout -->

        <a
            href="../auth/logout.php"
            class="nav-link-custom"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>


<!-- Main -->

<div class="main-wrapper">

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

            <div class="topbar-title">

                <h1>
                    Subject Teachers
                </h1>

                <p>
                    Manage subject teacher assignments
                </p>

            </div>

        </div>


        <div class="profile-area">

            <div class="profile-avatar">

                <?= e(
                    strtoupper(
                        substr(
                            (string) (
                                $_SESSION['full_name']
                                ?? 'Registrar'
                            ),
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

            <div class="profile-info">

                <strong>
                    <?= e(
                        (string) (
                            $_SESSION['full_name']
                            ?? 'Registrar'
                        )
                    ) ?>
                </strong>

                <span>
                    Registrar
                </span>

            </div>

        </div>

    </header>


    <!-- Content -->

    <main class="content">

        <!-- Page Header -->

        <div class="page-header">

            <div>

                <h2>
                    Subject Teachers
                </h2>

                <p>
                    View active subject teacher assignments by academic year.
                </p>

                <div class="today-date">

                    <i class="bi bi-calendar3 me-1"></i>

                    Today:
                    <?= e(
                        $todayEthiopianFormatted
                    ) ?>

                </div>

            </div>


            <div class="header-actions">

                <a
                    href="subjectteacher_export.php?<?= e($queryString) ?>"
                    class="btn btn-primary"
                >

                    <i class="bi bi-file-earmark-excel me-1"></i>

                    Export

                </a>

            </div>

        </div>


        <?php if ($errorMessage !== ''): ?>

            <div
                class="alert alert-danger border-0 shadow-sm"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>


        <!-- Statistics -->

        <div class="row">

            <div class="col-12 col-md-4">

                <div class="stat-card">

                    <div class="stat-label">

                        Total Active Subject Assignments

                    </div>

                    <div class="stat-value">

                        <?= number_format(
                            $totalRecords
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- Filters -->

        <div class="filter-card">

            <form
                method="GET"
                action="subject-teachers.php"
            >

                <div class="row g-3 align-items-end">

                    <!-- Academic Year -->

                    <div class="col-12 col-md-4">

                        <label class="form-label">

                            Academic Year

                        </label>

                        <select
                            name="academic_year_id"
                            class="form-select"
                        >

                            <?php if (empty($academicYears)): ?>

                                <option value="0">
                                    No Academic Years
                                </option>

                            <?php else: ?>

                                <?php foreach (
                                    $academicYears
                                    as $academicYear
                                ): ?>

                                    <?php
                                    $yearId =
                                        (int) $academicYear['id'];

                                    $isSelected =
                                        $yearId ===
                                        $selectedAcademicYearId;
                                    ?>

                                    <option
                                        value="<?= $yearId ?>"
                                        <?= $isSelected ? 'selected' : '' ?>
                                    >

                                        <?= e(
                                            (string) $academicYear['name']
                                        ) ?>

                                        <?php if (
                                            strtolower(
                                                trim(
                                                    (string) $academicYear['status']
                                                )
                                            ) === 'active'
                                        ): ?>

                                            (Active)

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </select>

                    </div>


                    <!-- Search -->

                    <div class="col-12 col-md-4">

                        <label class="form-label">

                            Search

                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Teacher or subject..."
                        >

                    </div>


                    <!-- Grade -->

                    <div class="col-6 col-md-2">

                        <label class="form-label">

                            Grade

                        </label>

                        <select
                            name="grade"
                            class="form-select"
                        >

                            <option value="0">

                                All Grades

                            </option>

                            <?php for (
                                $i = 1;
                                $i <= 12;
                                $i++
                            ): ?>

                                <option
                                    value="<?= $i ?>"
                                    <?= $grade === $i ? 'selected' : '' ?>
                                >

                                    Grade <?= $i ?>

                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>


                    <!-- Section -->

                    <div class="col-6 col-md-2">

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

                            <?php foreach (
                                range('A', 'Z')
                                as $letter
                            ): ?>

                                <option
                                    value="<?= $letter ?>"
                                    <?= $section === $letter ? 'selected' : '' ?>
                                >

                                    <?= $letter ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Buttons -->

                    <div class="col-12 d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                        <a
                            href="subject-teachers.php"
                            class="btn btn-outline-secondary"
                            title="Clear filters"
                        >

                            <i class="bi bi-arrow-counterclockwise"></i>

                        </a>

                    </div>

                </div>

            </form>

        </div>


        <!-- Table -->

        <div class="table-card">

            <div class="table-header">

                <h3>
                    Active Subject Teacher Assignments
                </h3>

                <?php if ($selectedAcademicYear): ?>

                    <div class="academic-year-display">

                        <i class="bi bi-calendar3"></i>

                        <span>

                            Academic Year:

                            <?= e(
                                (string) $selectedAcademicYear['name']
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>

            </div>


            <?php if (
                count($assignments) > 0
            ): ?>

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    No.
                                </th>

                                <th>
                                    Grade
                                </th>

                                <th>
                                    Section
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Teacher Name
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $assignments
                                as $index => $assignment
                            ): ?>

                                <tr>

                                    <!-- No. -->

                                    <td>

                                        <?= $offset + $index + 1 ?>

                                    </td>


                                    <!-- Grade -->

                                    <td>

                                        <span class="class-badge">

                                            <?= (int) (
                                                $assignment['grade']
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- Section -->

                                    <td>

                                        <span class="class-badge">

                                            <?= e(
                                                strtoupper(
                                                    (string) (
                                                        $assignment['section']
                                                    )
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- Subject -->

                                    <td>

                                        <div class="subject-name">

                                            <?= e(
                                                (string) (
                                                    $assignment['subject_name']
                                                    ?? 'Unknown Subject'
                                                )
                                            ) ?>

                                        </div>

                                    </td>


                                    <!-- Teacher -->

                                    <td>

                                        <div class="teacher-name">

                                            <?= e(
                                                (string) (
                                                    $assignment['teacher_name']
                                                    ?? 'Unknown Teacher'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- Pagination -->

                <?php if (
                    $totalPages > 1
                ): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing

                            <?= $offset + 1 ?>

                            to

                            <?= min(
                                $offset + $perPage,
                                $totalRecords
                            ) ?>

                            of

                            <?= number_format(
                                $totalRecords
                            ) ?>

                            active assignments

                        </div>


                        <nav>

                            <ul class="pagination pagination-sm">

                                <!-- Previous -->

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="?page=<?= max(1, $page - 1) ?>&<?= e($queryString) ?>"
                                    >

                                        Previous

                                    </a>

                                </li>


                                <?php

                                $startPage =
                                    max(
                                        1,
                                        $page - 2
                                    );

                                $endPage =
                                    min(
                                        $totalPages,
                                        $page + 2
                                    );

                                ?>


                                <?php for (
                                    $p = $startPage;
                                    $p <= $endPage;
                                    $p++
                                ): ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="?page=<?= $p ?>&<?= e($queryString) ?>"
                                        >

                                            <?= $p ?>

                                        </a>

                                    </li>

                                <?php endfor; ?>


                                <!-- Next -->

                                <li
                                    class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="?page=<?= min($totalPages, $page + 1) ?>&<?= e($queryString) ?>"
                                    >

                                        Next

                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>


            <?php else: ?>

                <div class="empty-state">

                    <i class="bi bi-person-video2"></i>

                    <h4>

                        No Active Subject Teacher Assignments

                    </h4>

                    <p>

                        No active subject teacher assignments were found
                        for the selected academic year and filters.

                    </p>

                </div>

            <?php endif; ?>

        </div>

    </main>

</div>


<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');


    function openSidebar() {

        sidebar.classList.add('show');

        overlay.classList.add('show');

    }


    function closeSidebar() {

        sidebar.classList.remove('show');

        overlay.classList.remove('show');

    }


    mobileMenuBtn.addEventListener(
        'click',
        openSidebar
    );


    overlay.addEventListener(
        'click',
        closeSidebar
    );


    document
        .querySelectorAll('.sidebar a')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <= 991
                    ) {

                        closeSidebar();

                    }

                }
            );

        });

</script>

</body>

</html>
