<?php

declare(strict_types=1);

session_start();

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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'R';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return 'R';
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 1));
    }

    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}

/*
|--------------------------------------------------------------------------
| Registrar
|--------------------------------------------------------------------------
*/

$registrarName = (string) ($_SESSION['full_name'] ?? 'Registrar');

$initials = getInitials($registrarName);

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute();

$result = $stmt->get_result();

$activeAcademicYear = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));

$grade = (int) ($_GET['grade'] ?? 0);

$section = trim((string) ($_GET['section'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/

$academicYearName = '';

if ($activeAcademicYear) {
    $academicYearName = (string) $activeAcademicYear['name'];
}

/*
|--------------------------------------------------------------------------
| Count Records
|--------------------------------------------------------------------------
*/

$totalRecords = 0;

if ($academicYearName !== '') {

    $countSql = "
        SELECT COUNT(*) AS total
        FROM homeroom_teacher_assignments hta

        INNER JOIN users u
            ON u.id = hta.teacher_user_id

        WHERE hta.academic_year = ?
          AND hta.is_active = 1
          AND LOWER(u.role) = 'teacher'
          AND COALESCE(u.is_deleted, 0) = 0
    ";

    $countTypes = 's';

    $countParams = [
        $academicYearName
    ];

    if ($search !== '') {

        $countSql .= "
            AND (
                u.full_name LIKE ?
                OR u.email LIKE ?
                OR u.phone LIKE ?
            )
        ";

        $searchLike = '%' . $search . '%';

        $countTypes .= 'sss';

        $countParams[] = $searchLike;
        $countParams[] = $searchLike;
        $countParams[] = $searchLike;
    }

    if ($grade > 0) {

        $countSql .= "
            AND hta.grade = ?
        ";

        $countTypes .= 'i';

        $countParams[] = $grade;
    }

    if ($section !== '') {

        $countSql .= "
            AND hta.section = ?
        ";

        $countTypes .= 's';

        $countParams[] = $section;
    }

    $stmt = $conn->prepare($countSql);

    $stmt->bind_param(
        $countTypes,
        ...$countParams
    );

    $stmt->execute();

    $countResult = $stmt->get_result();

    $countRow = $countResult->fetch_assoc();

    $totalRecords = (int) ($countRow['total'] ?? 0);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil($totalRecords / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Get Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

if ($academicYearName !== '') {

    $sql = "
        SELECT
            hta.id,
            hta.grade,
            hta.section,
            u.full_name AS teacher_name,
            u.email AS teacher_email,
            u.phone AS teacher_phone

        FROM homeroom_teacher_assignments hta

        INNER JOIN users u
            ON u.id = hta.teacher_user_id

        WHERE hta.academic_year = ?
          AND hta.is_active = 1
          AND LOWER(u.role) = 'teacher'
          AND COALESCE(u.is_deleted, 0) = 0
    ";

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

        $sql .= "
            AND (
                u.full_name LIKE ?
                OR u.email LIKE ?
                OR u.phone LIKE ?
            )
        ";

        $searchLike = '%' . $search . '%';

        $types .= 'sss';

        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    /*
    |--------------------------------------------------------------------------
    | Grade
    |--------------------------------------------------------------------------
    */

    if ($grade > 0) {

        $sql .= "
            AND hta.grade = ?
        ";

        $types .= 'i';

        $params[] = $grade;
    }

    /*
    |--------------------------------------------------------------------------
    | Section
    |--------------------------------------------------------------------------
    */

    if ($section !== '') {

        $sql .= "
            AND hta.section = ?
        ";

        $types .= 's';

        $params[] = $section;
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering + Pagination
    |--------------------------------------------------------------------------
    */

    $sql .= "
        ORDER BY
            hta.grade ASC,
            hta.section ASC,
            u.full_name ASC
        LIMIT ? OFFSET ?
    ";

    $types .= 'ii';

    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $pageNumber,
    string $search,
    int $grade,
    string $section
): string {

    $params = [
        'page' => $pageNumber
    ];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($grade > 0) {
        $params['grade'] = $grade;
    }

    if ($section !== '') {
        $params['section'] = $section;
    }

    return 'homeroom-teachers.php?' .
        http_build_query($params);
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
        Homeroom Teachers | Registrar | BKHS
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
        }

        .brand {
            height: 78px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2563eb;
            font-size: 20px;
            margin-right: 12px;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 700;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 10px 30px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 3px;
            border-radius: 8px;
            color: #9ca3af;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link-custom i {
            width: 22px;
            font-size: 16px;
            margin-right: 10px;
        }

        .nav-link-custom:hover {
            background: rgba(255,255,255,.07);
            color: #fff;
        }

        .nav-link-custom.active {
            background: #2563eb;
            color: #fff;
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
        }

        .nav-parent:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-parent[aria-expanded="true"] {
            color: white;
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
            color: white;
            background: rgba(255,255,255,.06);
        }

        .nav-sub-link.active {
            background: #2563eb;
            color: white;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
        }

        .profile-role {
            font-size: 11px;
            color: #6b7280;
        }

        .content {
            padding: 28px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            height: 100%;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 11px;
            margin-top: 12px;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            margin-top: 3px;
        }

        .filter-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 43px;
            border-color: #d1d5db;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .btn-primary-custom {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            min-height: 43px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 0 17px;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .btn-export {
            background: #15803d;
            border-color: #15803d;
            color: #fff;
            min-height: 43px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 0 17px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn-export:hover {
            background: #166534;
            border-color: #166534;
            color: #fff;
        }

        .table-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .table-header {
            padding: 19px 22px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .table-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0;
        }

        .table-subtitle {
            color: #6b7280;
            font-size: 11px;
            margin-top: 3px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            margin: 0;
            min-width: 700px;
        }

        .table thead th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .02em;
            padding: 13px 16px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            font-size: 12px;
            color: #374151;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .teacher-name {
            font-weight: 600;
            color: #111827;
        }

        .teacher-email {
            font-size: 11px;
            color: #6b7280;
            margin-top: 3px;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            padding: 6px 9px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 700;
        }

        .section-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            padding: 6px 9px;
            border-radius: 7px;
            background: #f3f4f6;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
            color: #6b7280;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #9ca3af;
        }

        .pagination-wrapper {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 11px;
        }

        .pagination {
            margin: 0;
        }

        .pagination .page-link {
            font-size: 12px;
            color: #374151;
            border-color: #e5e7eb;
            min-width: 34px;
            text-align: center;
        }

        .pagination .page-item.active .page-link {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1030;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
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
                align-items: center;
                justify-content: center;
                margin-right: 12px;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 18px;
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

        @media (max-width: 575px) {

            .profile-name,
            .profile-role {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .content {
                padding: 14px;
            }

            .stat-card {
                padding: 16px;
            }

        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="toggleSidebar()"
></div>

<!-- Sidebar -->

<aside class="sidebar" id="sidebar">

    <div class="brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>

            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                Registrar Portal
            </div>

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
                    class="nav-link-custom nav-sub-link active"
                >
                    <i class="bi bi-person-workspace"></i>
                    <span>Homeroom Teachers</span>
                </a>

                <a
                    href="subject-teachers.php"
                    class="nav-link-custom nav-sub-link"
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

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                onclick="toggleSidebar()"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Homeroom Teachers
                </h1>

                <div class="page-subtitle">
                    View active homeroom teacher assignments
                </div>

            </div>

        </div>

        <div class="profile">

            <div class="text-end">

                <div class="profile-name">
                    <?= e($registrarName) ?>
                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

            <div class="avatar">
                <?= e($initials) ?>
            </div>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <?php if (!$activeAcademicYear): ?>

            <div class="alert alert-warning">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                There is no active academic year.

            </div>

        <?php else: ?>

            <!-- Statistics -->

            <div class="row g-3 mb-4">

                <div class="col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon">

                            <i class="bi bi-person-workspace"></i>

                        </div>

                        <div class="stat-label">
                            Active Academic Year
                        </div>

                        <div class="stat-value">
                            <?= e($academicYearName) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-6">

                    <div class="stat-card">

                        <div class="stat-icon">

                            <i class="bi bi-people-fill"></i>

                        </div>

                        <div class="stat-label">
                            Homeroom Teachers
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalRecords) ?>
                        </div>

                    </div>

                </div>

            </div>

            <!-- Filters -->

            <div class="filter-card mb-4">

                <form
                    method="get"
                    action="homeroom-teachers.php"
                >

                    <div class="row g-3 align-items-end">

                        <div class="col-lg-5">

                            <label
                                for="search"
                                class="form-label"
                            >
                                Search Teacher
                            </label>

                            <input
                                type="text"
                                id="search"
                                name="search"
                                class="form-control"
                                placeholder="Name, email or phone"
                                value="<?= e($search) ?>"
                            >

                        </div>

                        <div class="col-md-3 col-lg-2">

                            <label
                                for="grade"
                                class="form-label"
                            >
                                Grade
                            </label>

                            <select
                                id="grade"
                                name="grade"
                                class="form-select"
                            >

                                <option value="0">
                                    All Grades
                                </option>

                                <?php for ($g = 1; $g <= 12; $g++): ?>

                                    <option
                                        value="<?= $g ?>"
                                        <?= $grade === $g ? 'selected' : '' ?>
                                    >
                                        Grade <?= $g ?>
                                    </option>

                                <?php endfor; ?>

                            </select>

                        </div>

                        <div class="col-md-3 col-lg-2">

                            <label
                                for="section"
                                class="form-label"
                            >
                                Section
                            </label>

                            <select
                                id="section"
                                name="section"
                                class="form-select"
                            >

                                <option value="">
                                    All Sections
                                </option>

                                <?php for ($s = 65; $s <= 90; $s++): ?>

                                    <?php $sectionLetter = chr($s); ?>

                                    <option
                                        value="<?= e($sectionLetter) ?>"
                                        <?= $section === $sectionLetter ? 'selected' : '' ?>
                                    >
                                        Section <?= e($sectionLetter) ?>
                                    </option>

                                <?php endfor; ?>

                            </select>

                        </div>

                        <div class="col-md-3 col-lg-3">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary-custom flex-grow-1"
                                >

                                    <i class="bi bi-search me-1"></i>

                                    Search

                                </button>

                                <a
                                    href="homeroom-teachers.php"
                                    class="btn btn-outline-secondary d-flex align-items-center justify-content-center"
                                    style="min-width:43px;border-radius:8px;"
                                    title="Clear filters"
                                >

                                    <i class="bi bi-arrow-counterclockwise"></i>

                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

            <!-- Table -->

            <div class="table-card">

                <div class="table-header">

                    <div>

                        <h2 class="table-title">

                            <i class="bi bi-person-workspace me-2 text-primary"></i>

                            Homeroom Teachers

                        </h2>

                        <div class="table-subtitle">

                            Active assignments for the current academic year

                        </div>

                    </div>

                    <a
                        href="export-homeroom-teachers.php"
                        class="btn-export"
                    >

                        <i class="bi bi-file-earmark-excel-fill me-2"></i>

                        Export

                    </a>

                </div>

                <?php if (!$assignments): ?>

                    <div class="empty-state">

                        <div class="empty-icon">

                            <i class="bi bi-person-workspace"></i>

                        </div>

                        <div class="fw-semibold">

                            No homeroom teachers found

                        </div>

                        <div class="small mt-1">

                            There are no active homeroom teacher assignments
                            matching your filters.

                        </div>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table">

                            <thead>

                                <tr>

                                    <th>
                                        #
                                    </th>

                                    <th>
                                        Teacher
                                    </th>

                                    <th>
                                        Phone
                                    </th>

                                    <th>
                                        Grade
                                    </th>

                                    <th>
                                        Section
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($assignments as $index => $assignment): ?>

                                    <tr>

                                        <td>

                                            <?= $offset + $index + 1 ?>

                                        </td>

                                        <td>

                                            <div class="teacher-name">

                                                <?= e(
                                                    (string) $assignment['teacher_name']
                                                ) ?>

                                            </div>

                                            <div class="teacher-email">

                                                <?= e(
                                                    (string) $assignment['teacher_email']
                                                ) ?>

                                            </div>

                                        </td>

                                        <td>

                                            <?= e(
                                                (string) $assignment['teacher_phone']
                                            ) ?>

                                        </td>

                                        <td>

                                            <span class="grade-badge">

                                                Grade
                                                <?= (int) $assignment['grade'] ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="section-badge">

                                                <?= e(
                                                    (string) $assignment['section']
                                                ) ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <!-- Pagination -->

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            <?php

                            $startRecord = $totalRecords > 0
                                ? $offset + 1
                                : 0;

                            $endRecord = min(
                                $offset + $perPage,
                                $totalRecords
                            );

                            ?>

                            Showing
                            <strong>
                                <?= $startRecord ?>
                            </strong>
                            -
                            <strong>
                                <?= $endRecord ?>
                            </strong>
                            of
                            <strong>
                                <?= $totalRecords ?>
                            </strong>
                            teachers

                        </div>

                        <?php if ($totalPages > 1): ?>

                            <nav aria-label="Homeroom teacher pagination">

                                <ul class="pagination pagination-sm">

                                    <!-- Previous -->

                                    <li
                                        class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                    >

                                        <?php if ($page > 1): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $page - 1,
                                                        $search,
                                                        $grade,
                                                        $section
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
                                                href="<?= e(
                                                    paginationUrl(
                                                        1,
                                                        $search,
                                                        $grade,
                                                        $section
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
                                        $p = $startPage;
                                        $p <= $endPage;
                                        $p++
                                    ): ?>

                                        <li
                                            class="page-item <?= $p === $page ? 'active' : '' ?>"
                                        >

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $p,
                                                        $search,
                                                        $grade,
                                                        $section
                                                    )
                                                ) ?>"
                                            >

                                                <?= $p ?>

                                            </a>

                                        </li>

                                    <?php endfor; ?>

                                    <?php if ($endPage < $totalPages): ?>

                                        <?php if ($endPage < $totalPages - 1): ?>

                                            <li class="page-item disabled">

                                                <span class="page-link">
                                                    ...
                                                </span>

                                            </li>

                                        <?php endif; ?>

                                        <li class="page-item">

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $totalPages,
                                                        $search,
                                                        $grade,
                                                        $section
                                                    )
                                                ) ?>"
                                            >

                                                <?= $totalPages ?>

                                            </a>

                                        </li>

                                    <?php endif; ?>

                                    <!-- Next -->

                                    <li
                                        class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                    >

                                        <?php if ($page < $totalPages): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $page + 1,
                                                        $search,
                                                        $grade,
                                                        $section
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

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

function toggleSidebar() {

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

</script>

</body>

</html>