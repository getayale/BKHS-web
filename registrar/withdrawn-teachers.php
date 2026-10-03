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

$registrarName = (string) ($_SESSION['full_name'] ?? 'Registrar');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

$search = trim((string) ($_GET['search'] ?? ''));

$academicYear = null;
$withdrawals = [];
$totalWithdrawals = 0;

/*
|--------------------------------------------------------------------------
| Get active academic year
|--------------------------------------------------------------------------
*/

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

$academicYear = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Get withdrawn teachers for active academic year
|--------------------------------------------------------------------------
*/

if ($academicYear) {

    $academicYearId = (int) $academicYear['id'];

    if ($search !== '') {

        $searchLike = '%' . $search . '%';

        $stmt = $conn->prepare("
            SELECT
                tw.id,
                tw.teacher_id,
                tw.academic_year_id,
                tw.withdrawal_date,
                tw.reason,
                tw.created_at,

                u.full_name,
                u.email,
                u.phone,

                t.fayda_number,
                t.gender,
                t.education_level,
                t.department,
                t.college_university_institution,
                t.employment_status,

                ru.full_name AS withdrawn_by_name

            FROM teacher_withdrawals tw

            INNER JOIN teachers t
                ON t.id = tw.teacher_id

            INNER JOIN users u
                ON u.id = t.user_id

            LEFT JOIN users ru
                ON ru.id = tw.withdrawn_by

            WHERE tw.academic_year_id = ?

              AND (
                    u.full_name LIKE ?
                    OR u.email LIKE ?
                    OR u.phone LIKE ?
                    OR t.fayda_number LIKE ?
                    OR t.department LIKE ?
                    OR t.college_university_institution LIKE ?
              )

            ORDER BY
                tw.withdrawal_date DESC,
                tw.id DESC
        ");

        $stmt->bind_param(
            'issssss',
            $academicYearId,
            $searchLike,
            $searchLike,
            $searchLike,
            $searchLike,
            $searchLike,
            $searchLike
        );

    } else {

        $stmt = $conn->prepare("
            SELECT
                tw.id,
                tw.teacher_id,
                tw.academic_year_id,
                tw.withdrawal_date,
                tw.reason,
                tw.created_at,

                u.full_name,
                u.email,
                u.phone,

                t.fayda_number,
                t.gender,
                t.education_level,
                t.department,
                t.college_university_institution,
                t.employment_status,

                ru.full_name AS withdrawn_by_name

            FROM teacher_withdrawals tw

            INNER JOIN teachers t
                ON t.id = tw.teacher_id

            INNER JOIN users u
                ON u.id = t.user_id

            LEFT JOIN users ru
                ON ru.id = tw.withdrawn_by

            WHERE tw.academic_year_id = ?

            ORDER BY
                tw.withdrawal_date DESC,
                tw.id DESC
        ");

        $stmt->bind_param(
            'i',
            $academicYearId
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }

    $stmt->close();

    $totalWithdrawals = count($withdrawals);
}

$initials = getInitials($registrarName);

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
        Withdrawn Teachers | Registrar | BKHS
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

        /* =========================================================
           SIDEBAR
        ========================================================= */

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
            flex-shrink: 0;
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
            flex-shrink: 0;
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

        /* =========================================================
           MAIN
        ========================================================= */

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

        /* =========================================================
           PROFILE
        ========================================================= */

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

        /* =========================================================
           CONTENT
        ========================================================= */

        .content {
            padding: 28px;
        }

        .card-box {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fee2e2;
            color: #dc2626;
            font-size: 19px;
            flex-shrink: 0;
        }

        .stat-number {
            font-size: 23px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            color: #6b7280;
            font-size: 11px;
            margin-top: 5px;
        }

        .year-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 13px 15px;
            color: #1e40af;
            font-size: 12px;
        }

        /* =========================================================
           SEARCH
        ========================================================= */

        .search-box {
            position: relative;
        }

        .search-box .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }

        .search-box input {
            padding-left: 40px;
            min-height: 44px;
            border-radius: 8px;
            border-color: #d1d5db;
            font-size: 13px;
        }

        .search-box input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .btn-primary-custom {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            min-height: 44px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            padding: 0 18px;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .btn-outline-custom {
            background: #fff;
            border: 1px solid #d1d5db;
            color: #374151;
            min-height: 44px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            padding: 0 16px;
        }

        .btn-outline-custom:hover {
            background: #f3f4f6;
            color: #111827;
        }

        /* =========================================================
           TABLE
        ========================================================= */

        .table-wrapper {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
            padding: 13px 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table tbody td {
            font-size: 12px;
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .teacher-name {
            font-weight: 700;
            color: #111827;
        }

        .teacher-email {
            color: #6b7280;
            font-size: 10px;
            margin-top: 3px;
        }

        .teacher-phone {
            color: #6b7280;
            font-size: 11px;
            margin-top: 2px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 9px;
            border-radius: 999px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .reason-cell {
            min-width: 180px;
            max-width: 280px;
            white-space: normal;
            line-height: 1.5;
            color: #4b5563;
        }

        .date-cell {
            white-space: nowrap;
            font-weight: 500;
        }

        .fayda-cell {
            white-space: nowrap;
            font-family: monospace;
            font-size: 11px;
        }

        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            text-align: center;
            padding: 65px 20px;
            color: #6b7280;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 27px;
            color: #9ca3af;
        }

        .empty-title {
            font-size: 14px;
            font-weight: 700;
            color: #374151;
        }

        .empty-text {
            font-size: 12px;
            margin-top: 5px;
        }

        /* =========================================================
           MOBILE
        ========================================================= */

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            color: #111827;
            margin-right: 12px;
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
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 18px;
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

            .card-box {
                padding: 16px;
            }

        }

    </style>

</head>

<body>

<!-- =============================================================
     SIDEBAR OVERLAY
============================================================= -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="toggleSidebar()"
></div>

<!-- =============================================================
     SIDEBAR
============================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

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
                aria-expanded="true"
            >

                <i class="bi bi-people-fill"></i>

                <span>
                    Students
                </span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse show"
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
            >

                <i class="bi bi-person-video3"></i>

                <span>
                    Teachers
                </span>

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
                    href="homeroom-teachers.php"
                    class="nav-link-custom nav-sub-link"
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
                aria-expanded="true"
            >

                <i class="bi bi-person-badge-fill"></i>

                <span>
                    Other Staff
                </span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse show"
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

        <!-- Other -->

        <a
            href="certificate.php"
            class="nav-link-custom"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="nav-link-custom"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <a
            href="profile.php"
            class="nav-link-custom"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="nav-link-custom"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<!-- =============================================================
     MAIN
============================================================= -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                onclick="toggleSidebar()"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Withdrawn Teachers
                </h1>

                <div class="page-subtitle">
                    Teachers withdrawn during the active academic year
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

    <!-- CONTENT -->

    <div class="content">

        <!-- ALERTS -->

        <?php if ($success !== ''): ?>

            <div
                class="alert alert-success alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-check-circle-fill me-2"></i>

                <?= e($success) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

            <div
                class="alert alert-danger alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($error) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <?php if (!$academicYear): ?>

            <div class="card-box">

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <div class="empty-title">
                        No Active Academic Year
                    </div>

                    <div class="empty-text">
                        There is currently no active academic year.
                    </div>

                </div>

            </div>

        <?php else: ?>

            <!-- =================================================
                 SUMMARY
            ================================================= -->

            <div class="row g-3 mb-4">

                <div class="col-md-5 col-lg-4">

                    <div class="card-box">

                        <div class="stat-card">

                            <div class="stat-icon">
                                <i class="bi bi-person-dash-fill"></i>
                            </div>

                            <div>

                                <div class="stat-number">
                                    <?= $totalWithdrawals ?>
                                </div>

                                <div class="stat-label">
                                    Withdrawn Teachers This Year
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-7 col-lg-8">

                    <div class="year-box h-100 d-flex align-items-center">

                        <i class="bi bi-calendar3 me-2 fs-6"></i>

                        <div>

                            <strong>
                                Active Academic Year:
                            </strong>

                            <?= e((string) $academicYear['name']) ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 MAIN CARD
            ================================================= -->

            <div class="card-box">

                <!-- CARD HEADER -->

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                    <div>

                        <div class="fw-bold">
                            Withdrawal Records
                        </div>

                        <div class="text-muted small mt-1">
                            Complete withdrawal records for
                            <?= e((string) $academicYear['name']) ?>
                        </div>

                    </div>

                    <div class="d-flex flex-wrap gap-2">

                        <a
                            href="withdraw-teacher.php"
                            class="btn btn-primary-custom"
                        >
                            <i class="bi bi-person-dash me-1"></i>
                            Withdraw Teacher
                        </a>

                        <a
                            href="export-withdrawn-teachers.php"
                            class="btn btn-outline-custom"
                        >
                            <i class="bi bi-file-earmark-excel me-1"></i>
                            Export
                        </a>

                    </div>

                </div>


                <!-- SEARCH -->

                <form
                    method="get"
                    class="mb-4"
                >

                    <div class="row g-2">

                        <div class="col-lg-9">

                            <div class="search-box">

                                <i class="bi bi-search search-icon"></i>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search name, email, phone, Fayda number, department or institution..."
                                    value="<?= e($search) ?>"
                                >

                            </div>

                        </div>

                        <div class="col-lg-3">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary-custom flex-grow-1"
                                >
                                    <i class="bi bi-search me-1"></i>
                                    Search
                                </button>

                                <?php if ($search !== ''): ?>

                                    <a
                                        href="withdrawn-teachers.php"
                                        class="btn btn-outline-custom"
                                        title="Clear search"
                                    >
                                        <i class="bi bi-x-lg"></i>
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </form>


                <!-- TABLE -->

                <?php if (!$withdrawals): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-person-check"></i>
                        </div>

                        <div class="empty-title">

                            <?php if ($search !== ''): ?>

                                No Matching Withdrawal Records

                            <?php else: ?>

                                No Withdrawn Teachers

                            <?php endif; ?>

                        </div>

                        <div class="empty-text">

                            <?php if ($search !== ''): ?>

                                No withdrawal record matches
                                your search.

                            <?php else: ?>

                                No teacher has been withdrawn
                                during this academic year.

                            <?php endif; ?>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

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
                                            Fayda Number
                                        </th>

                                        <th>
                                            Education
                                        </th>

                                        <th>
                                            Department
                                        </th>

                                        <th>
                                            Withdrawal Date
                                        </th>

                                        <th>
                                            Reason
                                        </th>

                                        <th>
                                            Withdrawn By
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($withdrawals as $index => $row): ?>

                                    <tr>

                                        <td>
                                            <?= $index + 1 ?>
                                        </td>

                                        <td>

                                            <div class="teacher-name">
                                                <?= e((string) $row['full_name']) ?>
                                            </div>

                                            <div class="teacher-email">
                                                <?= e((string) $row['email']) ?>
                                            </div>

                                            <div class="teacher-phone">
                                                <?= e((string) $row['phone']) ?>
                                            </div>

                                        </td>

                                        <td>
                                            <?= e((string) $row['phone']) ?>
                                        </td>

                                        <td class="fayda-cell">
                                            <?= e((string) $row['fayda_number']) ?>
                                        </td>

                                        <td>
                                            <?= e((string) ($row['education_level'] ?? '—')) ?>
                                        </td>

                                        <td>
                                            <?= e((string) ($row['department'] ?? '—')) ?>
                                        </td>

                                        <td class="date-cell">

                                            <i class="bi bi-calendar3 me-1 text-muted"></i>

                                            <?= e((string) $row['withdrawal_date']) ?>

                                        </td>

                                        <td>

                                            <div class="reason-cell">
                                                <?= nl2br(e((string) $row['reason'])) ?>
                                            </div>

                                        </td>

                                        <td>
                                            <?= e((string) ($row['withdrawn_by_name'] ?? '—')) ?>
                                        </td>

                                        <td>

                                            <span class="status-badge">

                                                <i class="bi bi-person-dash-fill"></i>

                                                Withdrawn

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">

                        <div class="text-muted small">

                            Showing
                            <strong><?= count($withdrawals) ?></strong>
                            withdrawal record(s)

                        </div>

                        <div class="text-muted small">

                            Academic Year:
                            <strong>
                                <?= e((string) $academicYear['name']) ?>
                            </strong>

                        </div>

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

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    sidebar.classList.toggle('show');

    overlay.classList.toggle('show');

}

</script>

</body>
</html>