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
$subjectAssignments = [];

$todayEthiopian = EthiopianCalendar::todayFormatted();

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
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'T';
}

/*
|--------------------------------------------------------------------------
| Resolve teacher photo
|--------------------------------------------------------------------------
*/
function resolveTeacherPhotoUrl(?string $storedPath): string
{
    $storedPath = trim((string) $storedPath);

    if ($storedPath === '') {
        return '';
    }

    /*
    |----------------------------------------------------------------------
    | External URL
    |----------------------------------------------------------------------
    */
    if (preg_match('#^https?://#i', $storedPath)) {
        return $storedPath;
    }

    /*
    |----------------------------------------------------------------------
    | Absolute web path
    |----------------------------------------------------------------------
    */
    if (str_starts_with($storedPath, '/')) {
        return $storedPath;
    }

    $projectRoot = dirname(__DIR__);

    $relativePath = ltrim(
        str_replace('\\', '/', $storedPath),
        '/'
    );

    /*
    |----------------------------------------------------------------------
    | If the database already contains a valid project-relative path
    |----------------------------------------------------------------------
    */
    $fullStoredPath = $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        );

    if (is_file($fullStoredPath)) {
        return '../' . $relativePath;
    }

    /*
    |----------------------------------------------------------------------
    | Extract filename for common teacher profile locations
    |----------------------------------------------------------------------
    */
    $filename = basename($relativePath);

    if (
        $filename === '' ||
        $filename === '.' ||
        $filename === '..'
    ) {
        return '';
    }

    /*
    |----------------------------------------------------------------------
    | public/uploads/profiles
    |----------------------------------------------------------------------
    */
    $profileFile = $projectRoot .
        DIRECTORY_SEPARATOR .
        'public' .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'profiles' .
        DIRECTORY_SEPARATOR .
        $filename;

    if (is_file($profileFile)) {
        return '../public/uploads/profiles/' . rawurlencode($filename);
    }

    /*
    |----------------------------------------------------------------------
    | uploads/teachers
    |----------------------------------------------------------------------
    */
    $teacherFile = $projectRoot .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'teachers' .
        DIRECTORY_SEPARATOR .
        $filename;

    if (is_file($teacherFile)) {
        return '../uploads/teachers/' . rawurlencode($filename);
    }

    /*
    |----------------------------------------------------------------------
    | Common database path formats
    |----------------------------------------------------------------------
    */
    if (str_starts_with($relativePath, 'public/')) {
        return '../' . $relativePath;
    }

    if (str_starts_with($relativePath, 'uploads/')) {
        return '../' . $relativePath;
    }

    /*
    |----------------------------------------------------------------------
    | Default profile location
    |----------------------------------------------------------------------
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
| Load teacher subject assignments
|--------------------------------------------------------------------------
*/

if ($academicYearName !== '') {

    $subjectSql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            sta.academic_year,
            gs.id AS grade_subject_id,
            gs.subject_name,
            gs.book_pdf
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $subjectStmt = $conn->prepare($subjectSql);

    if ($subjectStmt) {

        $subjectStmt->bind_param(
            'is',
            $teacherUserId,
            $academicYearName
        );

        $subjectStmt->execute();

        $subjectResult = $subjectStmt->get_result();

        while ($row = $subjectResult->fetch_assoc()) {
            $subjectAssignments[] = $row;
        }

        $subjectStmt->close();
    }
}

$totalAssignments = count($subjectAssignments);

$uniqueSubjects = [];

foreach ($subjectAssignments as $assignment) {

    $subjectName = (string) $assignment['subject_name'];

    if (!in_array(
        $subjectName,
        $uniqueSubjects,
        true
    )) {
        $uniqueSubjects[] = $subjectName;
    }
}

$totalUniqueSubjects = count($uniqueSubjects);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Subjects | BKHS Teacher Portal</title>

    <!-- BKHS Favicon -->
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
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
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
            transition: .2s ease;
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

        .subject-card {
            height: 100%;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            transition: .2s ease;
        }

        .subject-card:hover {
            border-color: #93c5fd;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, .08);
        }

        .subject-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            color: #2563eb;
            background: #dbeafe;
            font-size: 23px;
        }

        .subject-name {
            margin-top: 17px;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
        }

        .subject-meta {
            margin-top: 8px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .subject-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .grade-badge {
            padding: 6px 9px;
            border-radius: 7px;
            color: #1d4ed8;
            background: #eff6ff;
            font-size: 11px;
            font-weight: 800;
        }

        .section-badge {
            padding: 6px 9px;
            border-radius: 7px;
            color: #047857;
            background: #d1fae5;
            font-size: 11px;
            font-weight: 800;
        }

        .book-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
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
            href="subject.php"
            class="nav-link-custom active"
        >
            <i class="bi bi-book-fill"></i>
            <span>Subjects</span>
        </a>

        <a
            href="classes.php"
            class="nav-link-custom"
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
            href="subject-attendance.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Subject Attendance</span>
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
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <span style="display:none;">
                        <?= e($teacherInitials) ?>
                    </span>

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
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-heading">

                <h1>
                    My Subjects
                </h1>

                <p>
                    View the subjects and classes assigned to you.
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
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >

                    <span style="display:none;">
                        <?= e($teacherInitials) ?>
                    </span>

                <?php else: ?>

                    <?= e($teacherInitials) ?>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <div class="content">

        <section class="page-banner">

            <h2>
                My Teaching Subjects
            </h2>

            <p>
                These are the subjects assigned to you by the Principal
                for the current academic year.
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
                        Total Assignments
                    </div>

                    <div class="stat-icon blue">
                        <i class="bi bi-book-fill"></i>
                    </div>

                </div>

                <div class="stat-value">
                    <?= $totalAssignments ?>
                </div>

                <div class="stat-description">
                    Subject and class assignments
                </div>

            </div>

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Unique Subjects
                    </div>

                    <div class="stat-icon green">
                        <i class="bi bi-journal-bookmark-fill"></i>
                    </div>

                </div>

                <div class="stat-value">
                    <?= $totalUniqueSubjects ?>
                </div>

                <div class="stat-description">
                    Different subjects you teach
                </div>

            </div>

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Academic Year
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
                    Current active academic year
                </div>

            </div>

        </section>

        <section class="panel">

            <div class="panel-header">

                <div>

                    <h3 class="panel-title">
                        Assigned Subjects
                    </h3>

                    <p class="panel-subtitle">
                        Each card represents one subject assignment.
                    </p>

                </div>

                <span class="grade-badge">
                    <?= $totalAssignments ?> Assignments
                </span>

            </div>

            <div class="panel-body">

                <?php if ($subjectAssignments): ?>

                    <div class="row g-4">

                        <?php foreach ($subjectAssignments as $assignment): ?>

                            <div class="col-md-6 col-xl-4">

                                <div class="subject-card">

                                    <div class="subject-icon">

                                        <i class="bi bi-book-half"></i>

                                    </div>

                                    <div class="subject-name">

                                        <?= e($assignment['subject_name']) ?>

                                    </div>

                                    <div class="subject-meta">

                                        <i class="bi bi-calendar2-week me-1"></i>

                                        Academic Year:

                                        <?= e($assignment['academic_year']) ?>

                                    </div>

                                    <div class="subject-footer">

                                        <span class="grade-badge">

                                            Grade
                                            <?= e((string) $assignment['grade']) ?>

                                        </span>

                                        <span class="section-badge">

                                            Section
                                            <?= e($assignment['section']) ?>

                                        </span>

                                    </div>

                                    <?php if (!empty($assignment['book_pdf'])): ?>

                                        <div class="mt-3">

                                            <a
                                                href="../<?= e((string) $assignment['book_pdf']) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="book-link"
                                            >

                                                <i class="bi bi-file-earmark-pdf-fill"></i>

                                                View Subject Book

                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-book"></i>

                        <?php if ($academicYearName === ''): ?>

                            There is no active academic year.

                        <?php else: ?>

                            No subjects have been assigned to you yet.

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</main>

<script>

    const sidebar = document.getElementById('sidebar');

    const menuToggle = document.getElementById('menuToggle');

    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {

        sidebar.classList.add('show');

        sidebarOverlay.classList.add('show');

    }

    function closeSidebar() {

        sidebar.classList.remove('show');

        sidebarOverlay.classList.remove('show');

    }

    menuToggle?.addEventListener('click', openSidebar);

    sidebarOverlay?.addEventListener('click', closeSidebar);

    document.querySelectorAll('.nav-link-custom').forEach(link => {

        link.addEventListener('click', () => {

            if (window.innerWidth <= 991) {

                closeSidebar();

            }

        });

    });

    window.addEventListener('resize', () => {

        if (window.innerWidth > 991) {

            closeSidebar();

        }

    });

</script>

</body>

</html>