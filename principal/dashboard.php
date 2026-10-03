<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Principal Information
|--------------------------------------------------------------------------
| Account information comes from users.
| Profile photo comes from principals.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
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
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$principal = $result->fetch_assoc();

$stmt->close();

if (!$principal) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$principalName = $principal['full_name'];

$principalPhoto = $principal['photo'] ?? '';

$principalPhotoUrl = '';

if (!empty($principalPhoto)) {
    $principalPhotoUrl = '../' . ltrim($principalPhoto, '/');
}

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
| Temporary values until the corresponding modules are connected.
|--------------------------------------------------------------------------
*/

$totalStudents = 0;
$totalTeachers = 0;
$totalPresent = 0;
$totalAbsent = 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Principal Dashboard | BKHS</title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
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
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .app {
            min-height: 100vh;
            display: flex;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: var(--sidebar);
            color: #fff;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(79, 70, 229, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: #a5b4fc;
            font-size: 20px;
        }

        .brand-text {
            line-height: 1.2;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-menu {
            padding: 22px 14px;
            flex: 1;
            overflow-y: auto;
        }

        .menu-label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0 12px;
            margin-bottom: 10px;
        }

        .nav-link {
            color: #cbd5e1;
            padding: 11px 12px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .nav-link i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .nav-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .nav-link.active {
            color: #fff;
            background: var(--primary);
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.25);
        }

        .logout-link {
            color: #fca5a5;
        }

        .logout-link:hover {
            color: #fecaca;
            background: rgba(239, 68, 68, 0.12);
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
        }

        /* =========================================================
           TOPBAR
        ========================================================= */

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .page-title h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-title p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .topbar-date {
            color: var(--muted);
            font-size: 12px;
            display: none;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text);
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .principal-info {
            line-height: 1.2;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
            color: var(--text);
        }

        /* =========================================================
           CONTENT
        ========================================================= */

        .content {
            padding: 30px;
        }

        .welcome-card {
            background: linear-gradient(
                135deg,
                #4f46e5 0%,
                #6366f1 55%,
                #818cf8 100%
            );
            color: #fff;
            border-radius: 18px;
            padding: 26px 28px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::after {
            content: "";
            width: 190px;
            height: 190px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            position: absolute;
            right: -50px;
            top: -70px;
        }

        .welcome-card::before {
            content: "";
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            position: absolute;
            right: 100px;
            bottom: -75px;
        }

        .welcome-content {
            position: relative;
            z-index: 2;
        }

        .welcome-label {
            font-size: 12px;
            opacity: 0.85;
            margin-bottom: 7px;
        }

        .welcome-title {
            font-size: 25px;
            font-weight: 800;
            margin: 0 0 7px;
        }

        .welcome-text {
            margin: 0;
            font-size: 13px;
            opacity: 0.9;
        }

        /* =========================================================
           STAT CARDS
        ========================================================= */

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.07);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 17px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .icon-students {
            background: #eef2ff;
            color: #4f46e5;
        }

        .icon-teachers {
            background: #ecfdf5;
            color: #059669;
        }

        .icon-present {
            background: #eff6ff;
            color: #2563eb;
        }

        .icon-absent {
            background: #fef2f2;
            color: #dc2626;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
            margin-top: 3px;
        }

        .stat-description {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 6px;
        }

        /* =========================================================
           SECTION CARDS
        ========================================================= */

        .section-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
        }

        .section-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0;
        }

        .section-link {
            color: var(--primary);
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
        }

        .section-link:hover {
            text-decoration: underline;
        }

        .section-body {
            padding: 20px;
        }

        .empty-state {
            min-height: 180px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted);
        }

        .empty-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 21px;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #4b5563;
        }

        .empty-state p {
            margin: 0;
            font-size: 11px;
        }

        /* =========================================================
           QUICK ACTIONS
        ========================================================= */

        .quick-action {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text);
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .quick-action:last-child {
            margin-bottom: 0;
        }

        .quick-action:hover {
            border-color: #c7d2fe;
            background: #f8faff;
            transform: translateX(2px);
        }

        .quick-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .quick-text {
            flex: 1;
        }

        .quick-title {
            font-size: 12px;
            font-weight: 700;
        }

        .quick-description {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .quick-arrow {
            color: #9ca3af;
        }

        /* =========================================================
           OVERLAY
        ========================================================= */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 999;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (min-width: 1200px) {
            .topbar-date {
                display: block;
            }
        }

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
                width: 100%;
            }

            .mobile-menu {
                display: inline-block;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 20px;
            }
        }

        @media (max-width: 575.98px) {

            .topbar {
                height: 68px;
                padding: 0 15px;
            }

            .page-title h1 {
                font-size: 17px;
            }

            .page-title p {
                display: none;
            }

            .principal-info {
                display: none;
            }

            .avatar {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 15px;
            }

            .welcome-card {
                padding: 22px;
                border-radius: 15px;
            }

            .welcome-title {
                font-size: 21px;
            }

            .stat-card {
                padding: 17px;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="sidebar" id="sidebar">

        <div class="brand">

            <div class="brand-icon">
                <i class="bi bi-building"></i>
            </div>

            <div class="brand-text">

                <div class="brand-title">
                    BKHS
                </div>

                <div class="brand-subtitle">
                    School Management
                </div>

            </div>

        </div>

        <div class="sidebar-menu">

            <div class="menu-label">
                Principal
            </div>

            <a
                href="dashboard.php"
                class="nav-link active"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="announcements.php"
                class="nav-link"
            >
                <i class="bi bi-megaphone-fill"></i>
                <span>Announcement</span>
            </a>

            <a
                href="subject-assignment.php"
                class="nav-link"
            >
                <i class="bi bi-book-half"></i>
                <span>Subject Assignment</span>
            </a>

            <a
                href="homeroom-assignment.php"
                class="nav-link"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Homeroom Assignment</span>
            </a>

            <a
                href="student-assignment.php"
                class="nav-link"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>Student Assignment</span>
            </a>

            <a
                href="attendance.php"
                class="nav-link"
            >
                <i class="bi bi-calendar-check-fill"></i>
                <span>Attendance</span>
            </a>

            <a
                href="roster.php"
                class="nav-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Roster</span>
            </a>

            <a
    href="statistics.php"
    class="nav-link"
>
    <i class="bi bi-bar-chart-line-fill"></i>
    <span>Statistics</span>
</a>

            <a
    href="report-card/index.php"
    class="nav-link"
>
    <i class="bi bi-award-fill"></i>
    <span>Report Card</span>
</a>

            <a
                href="results.php"
                class="nav-link"
            >
                <i class="bi bi-bar-chart-fill"></i>
                <span>Result</span>
            </a>

            <a
                href="profile.php"
                class="nav-link"
            >
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>

            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">

                <a
                    href="../auth/logout.php"
                    class="nav-link logout-link"
                >
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>

            </div>

        </div>

    </aside>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="d-flex align-items-center gap-3">

                <button
                    type="button"
                    class="mobile-menu"
                    id="mobileMenu"
                    aria-label="Open menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div class="page-title">

                    <h1>
                        Principal Dashboard
                    </h1>

                    <p>
                        School management overview
                    </p>

                </div>

            </div>

            <div class="topbar-right">

                <div class="topbar-date">

                    <i class="bi bi-calendar3 me-1"></i>

                    <?= date('F j, Y') ?>

                </div>

                <a
                    href="profile.php"
                    class="principal-profile"
                >

                    <!-- PRINCIPAL PHOTO -->

                    <div class="avatar">

                        <?php if (!empty($principalPhotoUrl)): ?>

                            <img
                                src="<?= htmlspecialchars($principalPhotoUrl) ?>"
                                alt="Principal Photo"
                            >

                        <?php else: ?>

                            <i class="bi bi-person-fill"></i>

                        <?php endif; ?>

                    </div>

                    <div class="principal-info">

                        <div class="principal-name">
                            <?= htmlspecialchars($principalName) ?>
                        </div>

                        <div class="principal-role">
                            Principal
                        </div>

                    </div>

                </a>

            </div>

        </header>

        <!-- =====================================================
             CONTENT
        ====================================================== -->

        <div class="content">

            <!-- WELCOME -->

            <section class="welcome-card">

                <div class="welcome-content">

                    <div class="welcome-label">
                        Welcome back
                    </div>

                    <h2 class="welcome-title">
                        <?= htmlspecialchars($principalName) ?>
                    </h2>

                    <p class="welcome-text">
                        Manage school activities, teachers, students,
                        academic performance and announcements from one place.
                    </p>

                </div>

            </section>

            <!-- =================================================
                 STATISTICS
            ================================================== -->

            <div class="row g-3 mb-4">

                <!-- STUDENTS -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon icon-students">
                                <i class="bi bi-people-fill"></i>
                            </div>

                        </div>

                        <div class="stat-label">
                            Total Students
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalStudents) ?>
                        </div>

                        <div class="stat-description">
                            Registered students
                        </div>

                    </div>

                </div>

                <!-- TEACHERS -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon icon-teachers">
                                <i class="bi bi-person-workspace"></i>
                            </div>

                        </div>

                        <div class="stat-label">
                            Total Teachers
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalTeachers) ?>
                        </div>

                        <div class="stat-description">
                            Active teaching staff
                        </div>

                    </div>

                </div>

                <!-- PRESENT -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon icon-present">
                                <i class="bi bi-person-check-fill"></i>
                            </div>

                        </div>

                        <div class="stat-label">
                            Present Today
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalPresent) ?>
                        </div>

                        <div class="stat-description">
                            Attendance recorded today
                        </div>

                    </div>

                </div>

                <!-- ABSENT -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-icon icon-absent">
                                <i class="bi bi-person-x-fill"></i>
                            </div>

                        </div>

                        <div class="stat-label">
                            Absent Today
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalAbsent) ?>
                        </div>

                        <div class="stat-description">
                            Attendance recorded today
                        </div>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 DASHBOARD SECTIONS
            ================================================== -->

            <div class="row g-4">

                <!-- ATTENDANCE -->

                <div class="col-12 col-xl-8">

                    <div class="section-card">

                        <div class="section-header">

                            <h5 class="section-title">
                                Today's Attendance
                            </h5>

                            <a
                                href="attendance.php"
                                class="section-link"
                            >
                                View Attendance
                            </a>

                        </div>

                        <div class="section-body">

                            <div class="empty-state">

                                <div class="empty-icon">
                                    <i class="bi bi-calendar-check"></i>
                                </div>

                                <h6>
                                    Attendance data will appear here
                                </h6>

                                <p>
                                    This section will connect to the
                                    attendance module once it is built.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- QUICK ACTIONS -->

                <div class="col-12 col-xl-4">

                    <div class="section-card">

                        <div class="section-header">

                            <h5 class="section-title">
                                Quick Actions
                            </h5>

                        </div>

                        <div class="section-body">

                            <a
                                href="announcements.php"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    <i class="bi bi-megaphone-fill"></i>
                                </div>

                                <div class="quick-text">

                                    <div class="quick-title">
                                        Create Announcement
                                    </div>

                                    <div class="quick-description">
                                        Publish a school announcement
                                    </div>

                                </div>

                                <i class="bi bi-chevron-right quick-arrow"></i>

                            </a>

                            <a
                                href="subject-assignment.php"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    <i class="bi bi-book-half"></i>
                                </div>

                                <div class="quick-text">

                                    <div class="quick-title">
                                        Subject Assignment
                                    </div>

                                    <div class="quick-description">
                                        Assign teachers to subjects
                                    </div>

                                </div>

                                <i class="bi bi-chevron-right quick-arrow"></i>

                            </a>

                            <a
                                href="homeroom-assignment.php"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    <i class="bi bi-person-workspace"></i>
                                </div>

                                <div class="quick-text">

                                    <div class="quick-title">
                                        Homeroom Assignment
                                    </div>

                                    <div class="quick-description">
                                        Manage homeroom teachers
                                    </div>

                                </div>

                                <i class="bi bi-chevron-right quick-arrow"></i>

                            </a>

                            <a
                                href="student-assignment.php"
                                class="quick-action"
                            >

                                <div class="quick-icon">
                                    <i class="bi bi-person-check-fill"></i>
                                </div>

                                <div class="quick-text">

                                    <div class="quick-title">
                                        Student Assignment
                                    </div>

                                    <div class="quick-description">
                                        Manage student placement
                                    </div>

                                </div>

                                <i class="bi bi-chevron-right quick-arrow"></i>

                            </a>

                        </div>

                    </div>

                </div>

                <!-- ANNOUNCEMENTS -->

                <div class="col-12 col-xl-6">

                    <div class="section-card">

                        <div class="section-header">

                            <h5 class="section-title">
                                Recent Announcements
                            </h5>

                            <a
                                href="announcements.php"
                                class="section-link"
                            >
                                View All
                            </a>

                        </div>

                        <div class="section-body">

                            <div class="empty-state">

                                <div class="empty-icon">
                                    <i class="bi bi-megaphone"></i>
                                </div>

                                <h6>
                                    No announcements to display
                                </h6>

                                <p>
                                    Recent school announcements will appear here.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- RESULTS -->

                <div class="col-12 col-xl-6">

                    <div class="section-card">

                        <div class="section-header">

                            <h5 class="section-title">
                                Academic Results
                            </h5>

                            <a
                                href="result.php"
                                class="section-link"
                            >
                                View Results
                            </a>

                        </div>

                        <div class="section-body">

                            <div class="empty-state">

                                <div class="empty-icon">
                                    <i class="bi bi-bar-chart"></i>
                                </div>

                                <h6>
                                    Results data will appear here
                                </h6>

                                <p>
                                    Academic performance information will be
                                    connected after the result module is built.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<script>

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenu =
        document.getElementById('mobileMenu');

    function openSidebar() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }

    mobileMenu?.addEventListener(
        'click',
        openSidebar
    );

    overlay?.addEventListener(
        'click',
        closeSidebar
    );

    document
        .querySelectorAll('.sidebar .nav-link')
        .forEach(link => {

            link.addEventListener('click', () => {

                if (window.innerWidth <= 991) {
                    closeSidebar();
                }

            });

        });

</script>

</body>
</html>