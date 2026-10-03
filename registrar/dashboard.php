<?php
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

$registrar = null;
$academicYearCount = 0;
$activeAcademicYear = null;
$gradeCount = 0;
$sectionCount = 0;
$errorMessage = '';

try {
    /*
    |--------------------------------------------------------------------------
    | Registrar
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            r.photo
        FROM users u
        LEFT JOIN registrars r
            ON r.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'registrar'
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $registrar = $result->fetch_assoc();

    $stmt->close();

    if (!$registrar) {
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Academic Years
    |--------------------------------------------------------------------------
    */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM academic_years
    ");

    if ($result) {
        $academicYearCount = (int) $result->fetch_assoc()['total'];
    }

    /*
    |--------------------------------------------------------------------------
    | Active Academic Year
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
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
        WHERE LOWER(status) = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute();

    $result = $stmt->get_result();
    $activeAcademicYear = $result->fetch_assoc();

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Grades
    |--------------------------------------------------------------------------
    */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM grades
    ");

    if ($result) {
        $gradeCount = (int) $result->fetch_assoc()['total'];
    }

    /*
    |--------------------------------------------------------------------------
    | Configured Sections
    |--------------------------------------------------------------------------
    */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM academic_year_grade_sections
    ");

    if ($result) {
        $sectionCount = (int) $result->fetch_assoc()['total'];
    }

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/
$registrarName = htmlspecialchars(
    $registrar['full_name'] ?? 'Registrar',
    ENT_QUOTES,
    'UTF-8'
);

$registrarEmail = htmlspecialchars(
    $registrar['email'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

/*
|--------------------------------------------------------------------------
| Profile Photo
|--------------------------------------------------------------------------
*/
$photoPath = '../public/images/default-avatar.png';

if (!empty($registrar['photo'])) {
    $candidate = '../' . ltrim($registrar['photo'], '/');

    if (file_exists($candidate)) {
        $photoPath = $candidate;
    }
}

$photoPath = htmlspecialchars(
    $photoPath,
    ENT_QUOTES,
    'UTF-8'
);

/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/
$academicYearName = $activeAcademicYear
    ? htmlspecialchars(
        (string) $activeAcademicYear['name'],
        ENT_QUOTES,
        'UTF-8'
    )
    : 'Not Set';

$academicYearStatus = $activeAcademicYear
    ? ucfirst(
        strtolower(
            (string) $activeAcademicYear['status']
        )
    )
    : 'No Active Year';
?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Registrar Dashboard | School Management System</title>

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
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
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
        background: var(--sidebar);
        color: #fff;
        z-index: 1050;
        display: flex;
        flex-direction: column;
        transition: transform .3s ease;
    }

    .brand {
        height: 78px;
        display: flex;
        align-items: center;
        padding: 0 24px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        flex-shrink: 0;
    }

    .brand-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: rgba(37,99,235,.18);
        color: #60a5fa;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
        margin-right: 12px;
    }

    .brand-title {
        font-size: 17px;
        font-weight: 700;
        line-height: 1.2;
    }

    .brand-subtitle {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 3px;
    }

    .sidebar-menu {
        padding: 20px 12px;
        flex: 1;
        overflow-y: auto;
    }

    .menu-label {
        padding: 0 12px 9px;
        font-size: 10px;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 13px;
        margin-bottom: 4px;
        border-radius: 9px;
        color: #cbd5e1;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: .2s ease;
    }

    .nav-link i {
        font-size: 17px;
        width: 20px;
        text-align: center;
        flex-shrink: 0;
    }

    .nav-link:hover {
        background: var(--sidebar-hover);
        color: #fff;
    }

    .nav-link.active {
        background: var(--primary);
        color: #fff;
    }

    /* =========================================================
       COLLAPSIBLE MENUS
    ========================================================= */

    .menu-parent {
        cursor: pointer;
        user-select: none;
    }

    .menu-parent .menu-arrow {
        width: auto;
        margin-left: auto;
        font-size: 12px;
        transition: transform .2s ease;
    }

    .menu-parent.open .menu-arrow {
        transform: rotate(180deg);
    }

    .submenu {
        display: none;
        margin: 2px 0 7px;
    }

    .submenu.show {
        display: block;
    }

    .submenu .nav-link {
        padding: 9px 13px 9px 45px;
        margin-bottom: 2px;
        font-size: 12px;
        color: #94a3b8;
        position: relative;
    }

    .submenu .nav-link i {
        position: absolute;
        left: 20px;
        width: 14px;
        font-size: 13px;
    }

    .submenu .nav-link:hover {
        background: var(--sidebar-hover);
        color: #fff;
    }

    .logout-link {
        margin-top: auto;
        color: #fca5a5;
    }

    .logout-link:hover {
        background: rgba(220,38,38,.12);
        color: #fecaca;
    }

    /* =========================================================
       MAIN
    ========================================================= */

    .main {
        margin-left: 260px;
        min-height: 100vh;
    }

    /* =========================================================
       TOPBAR
    ========================================================= */

    .topbar {
        height: 78px;
        background: #fff;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 32px;
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .topbar-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .mobile-menu {
        display: none;
        border: 0;
        background: transparent;
        font-size: 24px;
    }

    .page-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    .page-subtitle {
        font-size: 12px;
        color: var(--muted);
        margin-top: 3px;
    }

    .top-profile {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .top-profile img {
        width: 39px;
        height: 39px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #eff6ff;
    }

    .top-profile-name {
        font-size: 13px;
        font-weight: 600;
    }

    .top-profile-role {
        color: var(--muted);
        font-size: 11px;
        margin-top: 2px;
    }

    /* =========================================================
       CONTENT
    ========================================================= */

    .content {
        padding: 30px 32px;
    }

    /* =========================================================
       WELCOME
    ========================================================= */

    .welcome-card {
        background: linear-gradient(
            135deg,
            #1d4ed8,
            #2563eb
        );
        color: #fff;
        border-radius: 18px;
        padding: 27px 30px;
        position: relative;
        overflow: hidden;
        margin-bottom: 25px;
    }

    .welcome-card::after {
        content: '';
        position: absolute;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        right: -80px;
        top: -110px;
        background: rgba(255,255,255,.08);
    }

    .welcome-card::before {
        content: '';
        position: absolute;
        width: 170px;
        height: 170px;
        border-radius: 50%;
        right: 80px;
        bottom: -110px;
        background: rgba(255,255,255,.06);
    }

    .welcome-title {
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 7px;
        position: relative;
        z-index: 1;
    }

    .welcome-text {
        font-size: 13px;
        color: rgba(255,255,255,.82);
        margin: 0;
        position: relative;
        z-index: 1;
    }

    /* =========================================================
       STATISTICS
    ========================================================= */

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 28px;
    }

    .stat-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 21px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: 0 2px 5px rgba(0,0,0,.025);
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
        flex-shrink: 0;
    }

    .stat-icon.blue {
        background: #eff6ff;
        color: #2563eb;
    }

    .stat-icon.green {
        background: #f0fdf4;
        color: #16a34a;
    }

    .stat-icon.orange {
        background: #fff7ed;
        color: #ea580c;
    }

    .stat-icon.purple {
        background: #faf5ff;
        color: #9333ea;
    }

    .stat-label {
        color: var(--muted);
        font-size: 11px;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 20px;
        font-weight: 700;
    }

    /* =========================================================
       SECTION HEADER
    ========================================================= */

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
    }

    /* =========================================================
       QUICK ACTIONS
    ========================================================= */

    .quick-actions {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin-bottom: 28px;
    }

    .quick-action {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 20px;
        text-decoration: none;
        color: var(--text);
        transition: .2s ease;
    }

    .quick-action:hover {
        border-color: #bfdbfe;
        transform: translateY(-2px);
        box-shadow: 0 7px 20px rgba(37,99,235,.08);
        color: var(--primary);
    }

    .quick-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #eff6ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 13px;
        font-size: 19px;
    }

    .quick-title {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .quick-description {
        font-size: 11px;
        color: var(--muted);
    }

    /* =========================================================
       ACADEMIC OVERVIEW
    ========================================================= */

    .dashboard-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 22px;
    }

    .academic-info {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }

    .info-box {
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 16px;
    }

    .info-label {
        font-size: 11px;
        color: var(--muted);
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 15px;
        font-weight: 700;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 20px;
        background: #f0fdf4;
        color: #15803d;
        font-size: 10px;
        font-weight: 600;
    }

    .status-dot {
        width: 6px;
        height: 6px;
        background: #22c55e;
        border-radius: 50%;
    }

    /* =========================================================
       ERROR
    ========================================================= */

    .error-alert {
        border-radius: 12px;
        margin-bottom: 20px;
    }

    /* =========================================================
       SIDEBAR OVERLAY
    ========================================================= */

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        z-index: 1040;
    }

    /* =========================================================
       RESPONSIVE
    ========================================================= */

    @media (max-width: 1200px) {

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .quick-actions {
            grid-template-columns: repeat(2, 1fr);
        }

        .academic-info {
            grid-template-columns: repeat(2, 1fr);
        }
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

        .main {
            margin-left: 0;
        }

        .mobile-menu {
            display: block;
        }
    }

    @media (max-width: 650px) {

        .topbar {
            padding: 0 17px;
        }

        .top-profile-name,
        .top-profile-role {
            display: none;
        }

        .content {
            padding: 20px 15px;
        }

        .welcome-card {
            padding: 22px;
        }

        .welcome-title {
            font-size: 19px;
        }

        .stats-grid,
        .quick-actions,
        .academic-info {
            grid-template-columns: 1fr;
        }

        .page-title {
            font-size: 17px;
        }

        .page-subtitle {
            display: none;
        }
    }

</style>
```

</head>

<body>

<div class="app">

```
<!-- =========================================================
     SIDEBAR
========================================================== -->

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

    <nav class="sidebar-menu">

        <div class="menu-label">
            Main Menu
        </div>

        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="nav-link active"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>


        <!-- =================================================
             STUDENTS
        ================================================== -->

        <div
            class="nav-link menu-parent"
            data-menu="studentsMenu"
        >
            <i class="bi bi-people-fill"></i>

            <span>Students</span>

            <i class="bi bi-chevron-down menu-arrow"></i>
        </div>

        <div
            class="submenu"
            id="studentsMenu"
        >

            <a
                href="register.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="students.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="update-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="delete-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete</span>
            </a>

            <a
                href="withdraw-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

        </div>


        <!-- =================================================
             TEACHERS
        ================================================== -->

        <div
            class="nav-link menu-parent"
            data-menu="teachersMenu"
        >
            <i class="bi bi-person-video3"></i>

            <span>Teachers</span>

            <i class="bi bi-chevron-down menu-arrow"></i>
        </div>

        <div
            class="submenu"
            id="teachersMenu"
        >

            <a
                href="teachers.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="update-teacher.php"
                class="nav-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="withdraw-teacher.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

            <a
                href="homeroom-teachers.php"
                class="nav-link"
            >
                <i class="bi bi-house-door-fill"></i>
                <span>Homeroom</span>
            </a>

            <a
                href="subject-teachers.php"
                class="nav-link"
            >
                <i class="bi bi-book-fill"></i>
                <span>Subject</span>
            </a>

        </div>


        <!-- =================================================
             OTHER STAFF
        ================================================== -->

        <div
            class="nav-link menu-parent"
            data-menu="staffMenu"
        >
            <i class="bi bi-person-badge-fill"></i>

            <span>Other Staff</span>

            <i class="bi bi-chevron-down menu-arrow"></i>
        </div>

        <div
            class="submenu"
            id="staffMenu"
        >

            <a
                href="add-staff.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Add</span>
            </a>

            <a
                href="staff.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="withdraw-staff.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

        </div>


        <!-- =================================================
             EXISTING MENU ITEMS
        ================================================== -->

        <a
            href="certificate.php"
            class="nav-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="nav-link"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="nav-link"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <a
            href="profile.php"
            class="nav-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <div style="height: 15px;"></div>

        <a
            href="../auth/logout.php"
            class="nav-link logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<!-- =========================================================
     MAIN
========================================================== -->

<main class="main">

    <!-- =====================================================
         TOPBAR
    ====================================================== -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Registrar Dashboard
                </h1>

                <div class="page-subtitle">
                    Manage student registration and academic records
                </div>

            </div>

        </div>

        <!-- Single profile location -->

        <div class="top-profile">

            <img
                src="<?= $photoPath ?>"
                alt="Registrar"
            >

            <div>

                <div class="top-profile-name">
                    <?= $registrarName ?>
                </div>

                <div class="top-profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>


    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <div class="content">

        <?php if ($errorMessage): ?>

            <div class="alert alert-danger error-alert">

                <i class="bi bi-exclamation-triangle me-2"></i>

                <?= htmlspecialchars(
                    $errorMessage,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             WELCOME
        ================================================== -->

        <section class="welcome-card">

            <div class="welcome-title">
                Welcome back, <?= $registrarName ?>
            </div>

            <p class="welcome-text">
                Manage registrations, admissions, student records,
                and certificates from your registrar workspace.
            </p>

        </section>


        <!-- =================================================
             STATISTICS
        ================================================== -->

        <section class="stats-grid">

            <!-- Academic Years -->

            <div class="stat-card">

                <div class="stat-icon blue">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div>

                    <div class="stat-label">
                        Academic Years
                    </div>

                    <div class="stat-value">
                        <?= $academicYearCount ?>
                    </div>

                </div>

            </div>


            <!-- Active Year -->

            <div class="stat-card">

                <div class="stat-icon green">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>

                <div>

                    <div class="stat-label">
                        Active Academic Year
                    </div>

                    <div class="stat-value">
                        <?= $academicYearName ?>
                    </div>

                </div>

            </div>


            <!-- Grades -->

            <div class="stat-card">

                <div class="stat-icon orange">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>

                <div>

                    <div class="stat-label">
                        Available Grades
                    </div>

                    <div class="stat-value">
                        <?= $gradeCount ?>
                    </div>

                </div>

            </div>


            <!-- Sections -->

            <div class="stat-card">

                <div class="stat-icon purple">
                    <i class="bi bi-diagram-3-fill"></i>
                </div>

                <div>

                    <div class="stat-label">
                        Configured Sections
                    </div>

                    <div class="stat-value">
                        <?= $sectionCount ?>
                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             QUICK ACTIONS
        ================================================== -->

        <section>

            <div class="section-header">

                <h2 class="section-title">
                    Quick Actions
                </h2>

            </div>

            <div class="quick-actions">

                <a
                    href="register.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>

                    <div class="quick-title">
                        Register Student
                    </div>

                    <div class="quick-description">
                        Register a new or returning student
                    </div>

                </a>


                <a
                    href="students.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div class="quick-title">
                        Student List
                    </div>

                    <div class="quick-description">
                        View and manage registered students
                    </div>

                </a>


                <a
                    href="update-student.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        <i class="bi bi-person-gear"></i>
                    </div>

                    <div class="quick-title">
                        Update Student
                    </div>

                    <div class="quick-description">
                        Update student information and accounts
                    </div>

                </a>


                <a
                    href="withdraw-student.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        <i class="bi bi-person-dash-fill"></i>
                    </div>

                    <div class="quick-title">
                        Withdraw Student
                    </div>

                    <div class="quick-description">
                        Manage student withdrawals
                    </div>

                </a>

            </div>

        </section>


        <!-- =================================================
             ACADEMIC OVERVIEW
        ================================================== -->

        <section class="dashboard-card">

            <div class="section-header">

                <h2 class="section-title">
                    Academic Overview
                </h2>

                <span class="status-badge">

                    <span class="status-dot"></span>

                    <?= $academicYearStatus ?>

                </span>

            </div>

            <div class="academic-info">

                <div class="info-box">

                    <div class="info-label">
                        Current Academic Year
                    </div>

                    <div class="info-value">
                        <?= $academicYearName ?>
                    </div>

                </div>


                <div class="info-box">

                    <div class="info-label">
                        Grades Available
                    </div>

                    <div class="info-value">
                        <?= $gradeCount ?> Grades
                    </div>

                </div>


                <div class="info-box">

                    <div class="info-label">
                        Configured Sections
                    </div>

                    <div class="info-value">
                        <?= $sectionCount ?> Sections
                    </div>

                </div>


                <div class="info-box">

                    <div class="info-label">
                        Academic Status
                    </div>

                    <div class="info-value">
                        <?= $academicYearStatus ?>
                    </div>

                </div>

            </div>

        </section>

    </div>

</main>
```

</div>

<script>

    /*
    |--------------------------------------------------------------------------
    | Mobile Sidebar
    |--------------------------------------------------------------------------
    */

    const mobileMenu = document.getElementById('mobileMenu');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    mobileMenu.addEventListener('click', function () {

        sidebar.classList.toggle('show');
        sidebarOverlay.classList.toggle('show');

    });

    sidebarOverlay.addEventListener('click', function () {

        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');

    });


    /*
    |--------------------------------------------------------------------------
    | Collapsible Sidebar Menus
    |--------------------------------------------------------------------------
    */

    const menuParents = document.querySelectorAll('.menu-parent');

    menuParents.forEach(function (parent) {

        parent.addEventListener('click', function () {

            const menuId = parent.getAttribute('data-menu');
            const submenu = document.getElementById(menuId);

            if (!submenu) {
                return;
            }

            const isOpen = submenu.classList.contains('show');

            document.querySelectorAll('.submenu').forEach(function (menu) {
                menu.classList.remove('show');
            });

            document.querySelectorAll('.menu-parent').forEach(function (item) {
                item.classList.remove('open');
            });

            if (!isOpen) {
                submenu.classList.add('show');
                parent.classList.add('open');
            }

        });

    });


    /*
    |--------------------------------------------------------------------------
    | Close Mobile Sidebar When A Link Is Selected
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.sidebar a.nav-link').forEach(function (link) {

        link.addEventListener('click', function () {

            if (window.innerWidth <= 900) {

                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');

            }

        });

    });

</script>

</body>

</html>
