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

$registrarId = (int) $_SESSION['user_id'];

if ($registrarId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

$student = null;
$errorMessage = '';
$successMessage = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

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
    LIMIT 1
");

$stmt->bind_param('i', $registrarId);
$stmt->execute();

$registrar = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$registrar) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Search Student
|--------------------------------------------------------------------------
*/

$studentCode = trim($_GET['student_code'] ?? '');

if ($studentCode !== '') {

    $studentCode = strtoupper($studentCode);

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.user_id,
            s.student_code,
            s.full_name,
            s.date_of_birth,
            s.gender,
            s.photo_path,
            s.is_deleted,

            sr.id AS registration_id,
            sr.grade_id,
            sr.section_id,

            g.name AS grade_name,
            sec.name AS section_name,
            sec.code AS section_code,
            ay.name AS academic_year_name

        FROM students s

        LEFT JOIN student_registrations sr
            ON sr.student_id = s.id

        LEFT JOIN grades g
            ON g.id = sr.grade_id

        LEFT JOIN sections sec
            ON sec.id = sr.section_id

        LEFT JOIN academic_years ay
            ON ay.id = sr.academic_year_id

        WHERE s.student_code = ?

        ORDER BY sr.id DESC

        LIMIT 1
    ");

    $stmt->bind_param('s', $studentCode);
    $stmt->execute();

    $result = $stmt->get_result();

    $student = $result->fetch_assoc();

    $stmt->close();

    if (!$student) {
        $errorMessage =
            'No student was found with this Student ID.';
    }
}

/*
|--------------------------------------------------------------------------
| Archive Student
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedCsrf =
        $_POST['csrf_token'] ?? '';

    $studentId =
        (int) ($_POST['student_id'] ?? 0);

    if (
        empty($postedCsrf) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $postedCsrf
        )
    ) {

        $errorMessage =
            'Invalid security token. Please try again.';

    } elseif ($studentId <= 0) {

        $errorMessage =
            'Invalid student.';

    } else {

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Get Student
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    user_id,
                    student_code,
                    full_name,
                    is_deleted
                FROM students
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->bind_param(
                'i',
                $studentId
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $studentToDelete =
                $result->fetch_assoc();

            $stmt->close();

            if (!$studentToDelete) {
                throw new Exception(
                    'Student was not found.'
                );
            }

            if (
                (int) $studentToDelete['is_deleted'] === 1
            ) {
                throw new Exception(
                    'This student has already been archived.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Archive Student
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE students
                SET
                    is_deleted = 1
                WHERE id = ?
                  AND is_deleted = 0
            ");

            $stmt->bind_param(
                'i',
                $studentId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Failed to archive the student.'
                );
            }

            if ($stmt->affected_rows !== 1) {
                throw new Exception(
                    'The student could not be archived.'
                );
            }

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Disable Student Login
            |--------------------------------------------------------------------------
            */

            if (!empty($studentToDelete['user_id'])) {

                $studentUserId =
                    (int) $studentToDelete['user_id'];

                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        is_deleted = 1
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'i',
                    $studentUserId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Student was archived but the login account could not be disabled.'
                    );
                }

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $conn->commit();

            $successMessage =
                'Student ' .
                $studentToDelete['student_code'] .
                ' has been archived successfully.';

            $student = null;

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            $errorMessage =
                $e->getMessage();
        }
    }
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
        Archive Student | BKHS
    </title>

    <!-- FAVICON -->
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --danger: #dc2626;
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
            color: white;
            z-index: 1050;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .brand {
            height: 74px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            flex-shrink: 0;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
        }

        .brand-title {
            font-weight: 800;
            font-size: 16px;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-menu {
            padding: 18px 10px 100px;
            flex: 1;
        }

        .sidebar-section {
            padding: 14px 14px 8px;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin: 3px 0;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link-custom:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-link-custom.active {
            background: var(--primary);
            color: white;
        }

        .nav-link-custom i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Groups
        |--------------------------------------------------------------------------
        */

        .nav-group {
            margin: 0;
        }

        .nav-parent {
            width: calc(100% - 0px);
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
            transition: transform .2s ease;
        }

        .nav-parent[aria-expanded="true"] .submenu-arrow {
            transform: rotate(180deg);
        }

        .nav-sub-link {
            margin-left: 30px;
            margin-right: 0;
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
            background: var(--primary);
            color: white;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Profile
        |--------------------------------------------------------------------------
        */

        .sidebar-profile {
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,.08);
            background: #0f172a;
            flex-shrink: 0;
        }

        .sidebar-profile-inner {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            background: #374151;
        }

        .sidebar-profile-name {
            font-size: 13px;
            font-weight: 600;
            color: white;
        }

        .sidebar-profile-role {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 74px;
            background: white;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .mobile-menu {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
            color: #111827;
            font-size: 20px;
            align-items: center;
            justify-content: center;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .topbar-user i {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 30px;
            max-width: 1200px;
            margin: auto;
        }

        .card-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 18px rgba(15,23,42,.04);
        }

        .search-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .search-description {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            min-height: 44px;
            border-color: var(--border);
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .btn {
            min-height: 44px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .student-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .student-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .student-name {
            font-size: 18px;
            font-weight: 700;
        }

        .student-code {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .info-box {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px;
        }

        .info-label {
            color: var(--muted);
            font-size: 10px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 13px;
            font-weight: 600;
        }

        .warning-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            border-radius: 12px;
            padding: 16px;
            font-size: 12px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .warning-box i {
            font-size: 17px;
            margin-right: 7px;
        }

        .danger-button {
            background: var(--danger);
            border-color: var(--danger);
            color: #fff;
        }

        .danger-button:hover {
            background: #b91c1c;
            border-color: #b91c1c;
            color: #fff;
        }

        .success-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 12px;
            padding: 16px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /*
        |--------------------------------------------------------------------------
        | Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: inline-flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .topbar-user span {
                display: none;
            }
        }

        @media (max-width: 767px) {

            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .card-box {
                padding: 18px;
            }
        }

        @media (max-width: 575px) {

            .content {
                padding: 14px;
            }

            .page-subtitle {
                display: none;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .card-box {
                padding: 16px;
            }

            .student-header {
                align-items: flex-start;
            }

            .student-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .student-name {
                font-size: 16px;
            }

            .d-flex.justify-content-end {
                flex-direction: column;
            }

            .d-flex.justify-content-end .btn,
            .d-flex.justify-content-end a {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<!-- SIDEBAR OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- SIDEBAR -->

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

    <nav class="sidebar-menu">

        <div class="sidebar-section">
            Main Menu
        </div>

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

                <span class="flex-grow-1 text-start">
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
                    class="nav-link-custom nav-sub-link active"
                >
                    <i class="bi bi-person-x-fill"></i>
                    <span>Archive Student</span>
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
                aria-expanded="false"
            >

                <i class="bi bi-person-video3"></i>

                <span class="flex-grow-1 text-start">
                    Teachers
                </span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse"
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
                aria-expanded="false"
            >

                <i class="bi bi-person-badge-fill"></i>

                <span class="flex-grow-1 text-start">
                    Other Staff
                </span>

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


        <div class="sidebar-section">
            Account
        </div>


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


    <!-- Sidebar Profile -->

    <div class="sidebar-profile">

        <div class="sidebar-profile-inner">

            <?php

            $sidebarPhoto = !empty($registrar['photo'])
                ? '../' . ltrim($registrar['photo'], '/')
                : '../public/images/default-avatar.png';

            ?>

            <img
                src="<?= e($sidebarPhoto) ?>"
                alt="Registrar"
                class="sidebar-avatar"
                onerror="this.onerror=null;this.src='../public/images/default-avatar.png';"
            >

            <div>

                <div class="sidebar-profile-name">
                    <?= e($registrar['full_name']) ?>
                </div>

                <div class="sidebar-profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </div>

</aside>


<!-- MAIN -->

<main class="main">

    <header class="topbar">

        <div class="page-heading">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Delete Student
                </h1>

                <div class="page-subtitle">
                    Deactivate a student while preserving academic history
                </div>

            </div>

        </div>


        <div class="topbar-user">

            <i class="bi bi-person"></i>

            <span>
                <?= e($registrar['full_name']) ?>
            </span>

        </div>

    </header>


    <div class="content">

        <?php if ($errorMessage): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-circle me-2"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>


        <?php if ($successMessage): ?>

            <div class="success-box">

                <i class="bi bi-check-circle me-2"></i>

                <?= e($successMessage) ?>

            </div>

        <?php endif; ?>


        <!-- SEARCH -->

        <section class="card-box">

            <div class="search-title">

                <i class="bi bi-search me-2 text-primary"></i>

                Find Student

            </div>

            <div class="search-description">

                Enter the student's permanent Student ID.

            </div>

            <form method="GET">

                <div class="row g-3 align-items-end">

                    <div class="col-md-9">

                        <label class="form-label">

                            Student ID

                        </label>

                        <input
                            type="text"
                            name="student_code"
                            class="form-control"
                            placeholder="BKHS-STU-000001"
                            value="<?= e($studentCode) ?>"
                            required
                        >

                    </div>

                    <div class="col-md-3">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                    </div>

                </div>

            </form>

        </section>


        <?php if ($student): ?>

            <?php if ((int) $student['is_deleted'] === 1): ?>

                <div class="alert alert-warning">

                    <i class="bi bi-archive me-2"></i>

                    This student has already been archived.

                </div>

            <?php else: ?>


                <!-- STUDENT -->

                <section class="card-box">

                    <div class="student-header">

                        <div class="student-icon">

                            <i class="bi bi-person-fill"></i>

                        </div>

                        <div>

                            <div class="student-name">

                                <?= e($student['full_name']) ?>

                            </div>

                            <div class="student-code">

                                <?= e($student['student_code']) ?>

                            </div>

                        </div>

                    </div>


                    <!-- INFORMATION -->

                    <div class="info-grid">

                        <div class="info-box">

                            <div class="info-label">
                                Grade
                            </div>

                            <div class="info-value">

                                <?= e(
                                    $student['grade_name'] ?? '—'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Section
                            </div>

                            <div class="info-value">

                                <?= e(
                                    $student['section_code']
                                    ?? $student['section_name']
                                    ?? '—'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Academic Year
                            </div>

                            <div class="info-value">

                                <?= e(
                                    $student['academic_year_name']
                                    ?? '—'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Gender
                            </div>

                            <div class="info-value">

                                <?= e(
                                    $student['gender']
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Date of Birth
                            </div>

                            <div class="info-value">

                                <?= e(
                                    $student['date_of_birth']
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Student Account
                            </div>

                            <div class="info-value text-success">

                                <i class="bi bi-check-circle me-1"></i>

                                Active

                            </div>

                        </div>

                    </div>


                    <!-- WARNING -->

                    <div class="warning-box">

                        <div class="mb-1">

                            <i class="bi bi-exclamation-triangle-fill"></i>

                            <strong>Important:</strong>

                        </div>

                        Archiving this student will deactivate the student's
                        account and remove the student from active records.
                        Academic registrations, admissions, section history,
                        and other historical records will remain preserved.

                    </div>


                    <!-- ARCHIVE FORM -->

                    <form
                        method="POST"
                        onsubmit="return confirmArchive();"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="student_id"
                            value="<?= (int) $student['id'] ?>"
                        >


                        <div class="d-flex gap-2 justify-content-end">

                            <a
                                href="dashboard.php"
                                class="btn btn-light border"
                            >

                                <i class="bi bi-arrow-left me-1"></i>

                                Cancel

                            </a>


                            <button
                                type="submit"
                                class="btn danger-button"
                            >

                                <i class="bi bi-person-x-fill me-1"></i>

                                Archive Student

                            </button>

                        </div>

                    </form>

                </section>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</main>


<!-- BOOTSTRAP JS -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

    const mobileMenu =
        document.getElementById('mobileMenu');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');


    /*
    |--------------------------------------------------------------------------
    | Mobile Sidebar
    |--------------------------------------------------------------------------
    */

    if (mobileMenu) {

        mobileMenu.addEventListener(
            'click',
            function () {

                sidebar.classList.toggle('open');

                sidebarOverlay.classList.toggle('show');

            }
        );

    }


    if (sidebarOverlay) {

        sidebarOverlay.addEventListener(
            'click',
            function () {

                sidebar.classList.remove('open');

                sidebarOverlay.classList.remove('show');

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Close Mobile Sidebar After Navigation
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.sidebar a')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    sidebar.classList.remove('open');

                    sidebarOverlay.classList.remove('show');

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Confirm Archive
    |--------------------------------------------------------------------------
    */

    function confirmArchive() {

        return confirm(
            'Are you sure you want to archive this student?\n\n' +
            'The student account will be deactivated, but all academic history will be preserved.'
        );

    }

</script>

</body>

</html>