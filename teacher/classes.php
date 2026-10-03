<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

$teacher = null;
$academicYear = null;
$classes = [];

$todayEthiopian = EthiopianCalendar::todayFormatted();

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'T';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return strtoupper(substr($name, 0, 1));
    }

    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return $initials ?: 'T';
}

/*
|--------------------------------------------------------------------------
| Resolve teacher photo
|--------------------------------------------------------------------------
|
| Teacher photos are stored in:
|
| BKHS/public/uploads/profiles/
|
| The database may contain:
|
| - filename.webp
| - uploads/profiles/filename.webp
| - public/uploads/profiles/filename.webp
| - uploads/teachers/filename.webp
|
| This function supports all of them.
|--------------------------------------------------------------------------
*/

function resolveTeacherPhotoUrl(?string $storedPath): string
{
    $storedPath = trim((string) $storedPath);

    if ($storedPath === '') {
        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | External or absolute URL
    |--------------------------------------------------------------------------
    */

    if (
        preg_match('#^https?://#i', $storedPath) ||
        str_starts_with($storedPath, '/')
    ) {
        return $storedPath;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize slashes
    |--------------------------------------------------------------------------
    */

    $relativePath = ltrim(
        str_replace('\\', '/', $storedPath),
        '/'
    );

    $projectRoot = dirname(__DIR__);

    /*
    |--------------------------------------------------------------------------
    | If database already contains a full relative project path
    |--------------------------------------------------------------------------
    */

    $relativeFile = $projectRoot
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        );

    if (is_file($relativeFile)) {
        return '../' . $relativePath;
    }

    /*
    |--------------------------------------------------------------------------
    | Get filename only
    |--------------------------------------------------------------------------
    */

    $filename = basename($relativePath);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | New teacher profile photo location
    |--------------------------------------------------------------------------
    */

    $profileDirectory =
        $projectRoot
        . DIRECTORY_SEPARATOR
        . 'public'
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'profiles';

    $profileFile =
        $profileDirectory
        . DIRECTORY_SEPARATOR
        . $filename;

    if (is_file($profileFile)) {
        return '../public/uploads/profiles/' . rawurlencode($filename);
    }

    /*
    |--------------------------------------------------------------------------
    | Old teacher photo location
    |--------------------------------------------------------------------------
    */

    $oldDirectory =
        $projectRoot
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'teachers';

    $oldFile =
        $oldDirectory
        . DIRECTORY_SEPARATOR
        . $filename;

    if (is_file($oldFile)) {
        return '../uploads/teachers/' . rawurlencode($filename);
    }

    /*
    |--------------------------------------------------------------------------
    | Known database path formats
    |--------------------------------------------------------------------------
    */

    if (str_starts_with($relativePath, 'public/')) {
        return '../' . $relativePath;
    }

    if (str_starts_with($relativePath, 'uploads/')) {
        return '../' . $relativePath;
    }

    /*
    |--------------------------------------------------------------------------
    | Default to current profile upload directory
    |--------------------------------------------------------------------------
    */

    return '../public/uploads/profiles/' . rawurlencode($filename);
}

/*
|--------------------------------------------------------------------------
| Load teacher profile
|--------------------------------------------------------------------------
*/

$teacherSql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.photo_path
    FROM users u
    LEFT JOIN teachers t
        ON t.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0
    LIMIT 1
";

$teacherStmt = $conn->prepare($teacherSql);

if ($teacherStmt) {
    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult = $teacherStmt->get_result();

    $teacher = $teacherResult->fetch_assoc();

    $teacherStmt->close();
}

if (!$teacher) {
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

$teacherName = (string) (
    $teacher['full_name'] ?? 'Teacher'
);

$teacherInitials = getInitials($teacherName);

$teacherPhoto = resolveTeacherPhotoUrl(
    $teacher['photo_path'] ?? null
);

/*
|--------------------------------------------------------------------------
| Load active academic year
|--------------------------------------------------------------------------
*/

$academicYearSql = "
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
";

$academicYearResult = $conn->query($academicYearSql);

if ($academicYearResult) {
    $academicYear = $academicYearResult->fetch_assoc();
}

$academicYearId = (int) (
    $academicYear['id'] ?? 0
);

$academicYearName = (string) (
    $academicYear['name'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Load assigned classes
|--------------------------------------------------------------------------
|
| A class can be assigned through:
|
| 1. Subject teacher assignment
| 2. Homeroom teacher assignment
|
| UNION removes duplicate grade/section combinations.
|--------------------------------------------------------------------------
*/

if (
    $academicYearId > 0 &&
    $academicYearName !== ''
) {
    $classesSql = "
        SELECT
            teacher_classes.grade,
            teacher_classes.section,
            COUNT(DISTINCT sr.student_id) AS total_students,
            GROUP_CONCAT(
                DISTINCT teacher_classes.assignment_type
                ORDER BY teacher_classes.assignment_type
                SEPARATOR ', '
            ) AS assignment_types
        FROM (
            SELECT DISTINCT
                grade,
                section,
                'Subject Teacher' AS assignment_type
            FROM subject_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND is_active = 1

            UNION

            SELECT DISTINCT
                grade,
                section,
                'Homeroom Teacher' AS assignment_type
            FROM homeroom_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND is_active = 1
        ) teacher_classes

        LEFT JOIN grades g
            ON g.grade_number = teacher_classes.grade

        LEFT JOIN sections sec
            ON sec.code = teacher_classes.section

        LEFT JOIN student_registrations sr
            ON sr.grade_id = g.id
            AND sr.section_id = sec.id
            AND sr.academic_year_id = ?

        GROUP BY
            teacher_classes.grade,
            teacher_classes.section

        ORDER BY
            teacher_classes.grade ASC,
            teacher_classes.section ASC
    ";

    $classesStmt = $conn->prepare($classesSql);

    if ($classesStmt) {
        $classesStmt->bind_param(
            'isisi',
            $teacherUserId,
            $academicYearName,
            $teacherUserId,
            $academicYearName,
            $academicYearId
        );

        $classesStmt->execute();

        $classesResult = $classesStmt->get_result();

        while ($row = $classesResult->fetch_assoc()) {
            $classes[] = $row;
        }

        $classesStmt->close();
    }
}

$totalClasses = count($classes);

$totalStudents = 0;

foreach ($classes as $class) {
    $totalStudents += (int) (
        $class['total_students'] ?? 0
    );
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
        content="BKHS Teacher Portal - My Classes"
    >

    <!-- BKHS Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        rel="shortcut icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <title>My Classes | BKHS Teacher Portal</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --sidebar: #111827;
            --body-bg: #f5f7fb;
            --text-dark: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--body-bg);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 260px;
            height: 100vh;
            padding: 22px 16px;
            background: var(--sidebar);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 1050;
            transition: transform .25s ease;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 10px 24px;
            color: #fff;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            font-size: 22px;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 11px;
        }

        .sidebar-label {
            padding: 0 12px;
            margin: 8px 0;
            color: #6b7280;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 5px;
            overflow-y: auto;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            border-radius: 9px;
            color: #d1d5db;
            font-size: 13px;
            font-weight: 600;
            transition: background .2s ease, color .2s ease;
        }

        .nav-link-custom i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background: var(--primary);
            color: #fff;
        }

        .nav-link-custom.logout {
            color: #fca5a5;
        }

        .nav-link-custom.logout:hover {
            background: #991b1b;
            color: #fff;
        }

        /* =========================================================
           SIDEBAR PROFILE
        ========================================================= */

        .sidebar-profile {
            margin-top: auto;
            padding: 14px 10px 0;
            border-top: 1px solid #374151;
        }

        .sidebar-profile-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 800;
        }

        .avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            max-width: 145px;
            overflow: hidden;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-role {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 11px;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            min-height: 74px;
            padding: 15px 30px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .date-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: #374151;
            background: #f9fafb;
            font-size: 12px;
            font-weight: 600;
        }

        .menu-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: #111827;
            font-size: 20px;
        }

        .content {
            max-width: 1450px;
            margin: 0 auto;
            padding: 30px;
        }

        /* =========================================================
           BANNER
        ========================================================= */

        .page-banner {
            padding: 25px 28px;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(
                135deg,
                #1d4ed8,
                #2563eb 55%,
                #3b82f6
            );
        }

        .page-banner h2 {
            margin: 0;
            font-size: 23px;
            font-weight: 800;
        }

        .page-banner p {
            max-width: 650px;
            margin: 9px 0 0;
            color: #dbeafe;
            font-size: 13px;
        }

        .academic-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 16px;
            padding: 8px 12px;
            border-radius: 8px;
            color: #eff6ff;
            background: rgba(255, 255, 255, .15);
            font-size: 12px;
            font-weight: 700;
        }

        /* =========================================================
           STATS
        ========================================================= */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 22px;
        }

        .stat-card {
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .035);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .blue {
            color: #2563eb;
            background: #dbeafe;
        }

        .green {
            color: #15803d;
            background: #dcfce7;
        }

        .purple {
            color: #7e22ce;
            background: #f3e8ff;
        }

        .stat-value {
            margin-top: 18px;
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-description {
            margin-top: 9px;
            color: var(--text-muted);
            font-size: 11px;
        }

        /* =========================================================
           PANEL
        ========================================================= */

        .panel {
            margin-top: 22px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .035);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }

        .panel-subtitle {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 11px;
        }

        .panel-body {
            padding: 22px;
        }

        /* =========================================================
           CLASS CARD
        ========================================================= */

        .class-card {
            height: 100%;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            transition: .2s ease;
        }

        .class-card:hover {
            border-color: #93c5fd;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, .08);
        }

        .class-icon {
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            color: #2563eb;
            background: #dbeafe;
            font-size: 25px;
        }

        .class-title {
            margin-top: 18px;
            color: #111827;
            font-size: 19px;
            font-weight: 800;
        }

        .class-subtitle {
            margin-top: 6px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .class-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #f1f5f9;
        }

        .badge-custom {
            padding: 7px 10px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 800;
        }

        .badge-blue {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .badge-green {
            color: #047857;
            background: #d1fae5;
        }

        .badge-orange {
            color: #c2410c;
            background: #ffedd5;
        }

        .empty-state {
            padding: 40px 15px;
            color: var(--text-muted);
            text-align: center;
            font-size: 12px;
        }

        .empty-state i {
            display: block;
            margin-bottom: 10px;
            color: #9ca3af;
            font-size: 36px;
        }

        /* =========================================================
           OVERLAY
        ========================================================= */

        .overlay {
            display: none;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 1199px) {

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }

            .overlay.show {
                position: fixed;
                inset: 0;
                display: block;
                z-index: 1040;
                background: rgba(15, 23, 42, .45);
            }

            .topbar {
                padding: 15px 20px;
            }

            .content {
                padding: 22px 20px;
            }
        }

        @media (max-width: 575px) {

            .date-pill {
                display: none;
            }

            .page-heading h1 {
                font-size: 18px;
            }

            .page-banner {
                padding: 22px;
            }

            .page-banner h2 {
                font-size: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .panel-header,
            .panel-body {
                padding: 17px;
            }
        }

    </style>

</head>

<body>

<div
    class="overlay"
    id="sidebarOverlay"
></div>

<aside
    class="sidebar"
    id="sidebar"
>

    <a
        href="dashboard.php"
        class="brand"
    >

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>
            <div class="brand-title">
                BKHS School
            </div>

            <div class="brand-subtitle">
                Teacher Portal
            </div>
        </div>

    </a>

    <div class="sidebar-label">
        Main Menu
    </div>

    <nav class="nav-menu">

        <a
            href="dashboard.php"
            class="nav-link-custom"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="subjects.php"
            class="nav-link-custom"
        >
            <i class="bi bi-book-fill"></i>
            <span>Subjects</span>
        </a>

        <a
            href="classes.php"
            class="nav-link-custom active"
        >
            <i class="bi bi-people-fill"></i>
            <span>Classes</span>
        </a>

        <a
            href="result.php"
            class="nav-link-custom"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="sidebar-label">
            Academic
        </div>

        <a
            href="daily-attendance.php"
            class="nav-link-custom"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Daily Attendance</span>
        </a>

      

        <a
            href="homework.php"
            class="nav-link-custom"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="nav-link-custom"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

       

        <div class="sidebar-label">
            Account
        </div>

        <a
            href="profile.php"
            class="nav-link-custom"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="nav-link-custom logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-profile">

        <a
            href="profile.php"
            class="sidebar-profile-link"
        >

            <div class="avatar">

                <?php if ($teacherPhoto !== ''): ?>

                    <img
                        src="<?= e($teacherPhoto) ?>"
                        alt="Teacher photo"
                        loading="lazy"
                    >

                <?php else: ?>

                    <?= e($teacherInitials) ?>

                <?php endif; ?>

            </div>

            <div>

                <div class="profile-name">
                    <?= e($teacherName) ?>
                </div>

                <div class="profile-role">
                    Teacher
                </div>

            </div>

        </a>

    </div>

</aside>

<main class="main">

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Open menu"
                aria-controls="sidebar"
                aria-expanded="false"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-heading">

                <h1>
                    My Classes
                </h1>

                <p>
                    View all classes assigned to you.
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="date-pill">

                <i class="bi bi-calendar3"></i>

                <?= e($todayEthiopian) ?>

            </div>

            <div class="avatar">

                <?php if ($teacherPhoto !== ''): ?>

                    <img
                        src="<?= e($teacherPhoto) ?>"
                        alt="Teacher photo"
                    >

                <?php else: ?>

                    <?= e($teacherInitials) ?>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <div class="content">

        <section class="page-banner">

            <h2>
                My Assigned Classes
            </h2>

            <p>
                View the grades and sections where you teach a subject
                or serve as the homeroom teacher.
            </p>

            <?php if ($academicYearName !== ''): ?>

                <div class="academic-badge">

                    <i class="bi bi-calendar2-week"></i>

                    Academic Year:
                    <?= e($academicYearName) ?>

                </div>

            <?php else: ?>

                <div class="academic-badge">

                    <i class="bi bi-exclamation-circle"></i>

                    No active academic year

                </div>

            <?php endif; ?>

        </section>

        <section class="stats-grid">

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Assigned Classes
                    </div>

                    <div class="stat-icon blue">
                        <i class="bi bi-people-fill"></i>
                    </div>

                </div>

                <div class="stat-value">
                    <?= $totalClasses ?>
                </div>

                <div class="stat-description">
                    Unique grade and section combinations
                </div>

            </div>

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Total Students
                    </div>

                    <div class="stat-icon green">
                        <i class="bi bi-person-lines-fill"></i>
                    </div>

                </div>

                <div class="stat-value">
                    <?= $totalStudents ?>
                </div>

                <div class="stat-description">
                    Students in your assigned classes
                </div>

            </div>

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Current Academic Year
                    </div>

                    <div class="stat-icon purple">
                        <i class="bi bi-calendar2-week"></i>
                    </div>

                </div>

                <div
                    class="stat-value"
                    style="font-size: 20px;"
                >
                    <?= e($academicYearName ?: 'Not set') ?>
                </div>

                <div class="stat-description">
                    Active academic year
                </div>

            </div>

        </section>

        <section class="panel">

            <div class="panel-header">

                <div>

                    <h3 class="panel-title">
                        Class List
                    </h3>

                    <p class="panel-subtitle">
                        Your assigned grade and section classes
                    </p>

                </div>

                <span class="badge-custom badge-blue">
                    <?= $totalClasses ?> Classes
                </span>

            </div>

            <div class="panel-body">

                <?php if ($classes): ?>

                    <div class="row g-4">

                        <?php foreach ($classes as $class): ?>

                            <?php

                            $assignmentTypes = (string) (
                                $class['assignment_types'] ?? ''
                            );

                            $isHomeroom = str_contains(
                                $assignmentTypes,
                                'Homeroom Teacher'
                            );

                            $isSubjectTeacher = str_contains(
                                $assignmentTypes,
                                'Subject Teacher'
                            );

                            ?>

                            <div class="col-md-6 col-xl-4">

                                <div class="class-card">

                                    <div class="class-icon">

                                        <i class="bi bi-people-fill"></i>

                                    </div>

                                    <div class="class-title">

                                        Grade
                                        <?= e((string) $class['grade']) ?>

                                        -

                                        Section
                                        <?= e((string) $class['section']) ?>

                                    </div>

                                    <div class="class-subtitle">
                                        <?= e($academicYearName) ?>
                                    </div>

                                    <div class="class-footer">

                                        <span class="badge-custom badge-blue">

                                            <i class="bi bi-person-fill me-1"></i>

                                            <?= (int) $class['total_students'] ?>

                                            Students

                                        </span>

                                        <?php if ($isSubjectTeacher): ?>

                                            <span class="badge-custom badge-orange">
                                                Subject Teacher
                                            </span>

                                        <?php endif; ?>

                                        <?php if ($isHomeroom): ?>

                                            <span class="badge-custom badge-green">
                                                Homeroom
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-people"></i>

                        <?php if ($academicYearName === ''): ?>

                            There is no active academic year.

                        <?php else: ?>

                            No classes have been assigned to you yet.

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</main>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {

        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.add('show');
        sidebarOverlay.classList.add('show');

        if (menuToggle) {
            menuToggle.setAttribute(
                'aria-expanded',
                'true'
            );
        }
    }

    function closeSidebar() {

        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');

        if (menuToggle) {
            menuToggle.setAttribute(
                'aria-expanded',
                'false'
            );
        }
    }

    if (menuToggle) {
        menuToggle.addEventListener(
            'click',
            function (event) {

                event.preventDefault();

                if (sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }

            }
        );
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener(
            'click',
            function () {
                closeSidebar();
            }
        );
    }

    document
        .querySelectorAll('.nav-link-custom')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (window.innerWidth <= 991) {
                        closeSidebar();
                    }

                }
            );

        });

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 991) {
                closeSidebar();
            }

        }
    );

});

</script>

</body>

</html>