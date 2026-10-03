<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Student Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

/*
|--------------------------------------------------------------------------
| Escape Helper
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
| Student Information
|--------------------------------------------------------------------------
*/

$student = null;

$sql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year
    FROM students s
    INNER JOIN student_registrations sr
        ON sr.student_id = s.id
    INNER JOIN grades g
        ON g.id = sr.grade_id
    INNER JOIN sections sec
        ON sec.id = sr.section_id
    INNER JOIN academic_years ay
        ON ay.id = sr.academic_year_id
    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND ay.status = 'Active'
    ORDER BY sr.id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare student query.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    die('Student record or active registration was not found.');
}

$studentId = (int) $student['student_id'];
$registrationId = (int) $student['registration_id'];
$academicYearId = (int) $student['academic_year_id'];

$gradeNumber = (int) $student['grade_number'];
$section = (string) $student['section'];

$studentName = (string) $student['full_name'];
$studentCode = (string) $student['student_code'];
$academicYear = (string) $student['academic_year'];

/*
|--------------------------------------------------------------------------
| Subject Count
|--------------------------------------------------------------------------
*/

$subjectCount = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM grade_subjects
    WHERE grade = ?
      AND is_active = 1
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        'i',
        $gradeNumber
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $subjectCount = (int) (
        $row['total'] ?? 0
    );

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Homework Count
|--------------------------------------------------------------------------
*/

$homeworkCount = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM homeworks
    WHERE academic_year = ?
      AND grade = ?
      AND section = ?
      AND status = 'Active'
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        'sis',
        $academicYear,
        $gradeNumber,
        $section
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $homeworkCount = (int) (
        $row['total'] ?? 0
    );

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Material Count
|--------------------------------------------------------------------------
*/

$materialCount = 0;

$checkTable = $conn->query("
    SELECT COUNT(*) AS total
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'subject_materials'
");

if ($checkTable) {

    $tableExists = (int) (
        $checkTable->fetch_assoc()['total'] ?? 0
    );

    if ($tableExists > 0) {

        $sql = "
            SELECT COUNT(*) AS total
            FROM subject_materials sm
            INNER JOIN grade_subjects gs
                ON gs.id = sm.grade_subject_id
            WHERE sm.is_active = 1
              AND gs.grade = ?
              AND gs.is_active = 1
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $gradeNumber
            );

            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            $materialCount = (int) (
                $row['total'] ?? 0
            );

            $stmt->close();
        }
    }

    $checkTable->close();
}

/*
|--------------------------------------------------------------------------
| Result Count
|--------------------------------------------------------------------------
*/

$resultCount = 0;

$checkTable = $conn->query("
    SELECT COUNT(*) AS total
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'results'
");

if ($checkTable) {

    $tableExists = (int) (
        $checkTable->fetch_assoc()['total'] ?? 0
    );

    if ($tableExists > 0) {

        $sql = "
            SELECT COUNT(*) AS total
            FROM results
            WHERE student_registration_id = ?
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $registrationId
            );

            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            $resultCount = (int) (
                $row['total'] ?? 0
            );

            $stmt->close();
        }
    }

    $checkTable->close();
}

/*
|--------------------------------------------------------------------------
| Student Avatar Initial
|--------------------------------------------------------------------------
*/

$avatarInitial = '';

if ($studentName !== '') {

    $avatarInitial = strtoupper(
        substr($studentName, 0, 1)
    );
}

/*
|--------------------------------------------------------------------------
| Mobile More Navigation State
|--------------------------------------------------------------------------
*/

$morePages = [
    'subjects.php',
    'materials.php',
    'announcements.php',
    'profile.php'
];

$isMoreActive = in_array(
    $currentPage,
    $morePages,
    true
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

    <title>Student Dashboard | BKHS</title>

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

    <style>

        :root {

            --sidebar-width: 260px;

            --primary: #2563eb;
            --primary-dark: #1d4ed8;

            --bg: #f5f7fb;

            --text: #172033;

            --muted: #6b7280;

            --border: #e5e7eb;

            --bottom-nav-height: 70px;
        }


        * {
            box-sizing: border-box;
        }


        html {
            scroll-behavior: smooth;
        }


        body {

            margin: 0;

            background: var(--bg);

            color: var(--text);

            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }


        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {

            position: fixed;

            top: 0;
            left: 0;

            width: var(--sidebar-width);

            height: 100vh;

            background: #111827;

            color: #fff;

            z-index: 1050;

            overflow-y: auto;

            transition:
                transform .25s ease;
        }


        .sidebar-brand {

            height: 72px;

            display: flex;

            align-items: center;

            padding: 0 22px;

            border-bottom:
                1px solid rgba(255,255,255,.08);
        }


        .sidebar-brand-icon {

            width: 40px;
            height: 40px;

            border-radius: 10px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: var(--primary);

            margin-right: 12px;

            flex-shrink: 0;
        }


        .sidebar-brand h5 {

            margin: 0;

            font-size: 17px;

            font-weight: 700;
        }


        .sidebar-brand small {

            color: #9ca3af;
        }


        .sidebar-nav {

            padding: 18px 12px;
        }


        .sidebar-label {

            padding: 8px 12px;

            color: #6b7280;

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .08em;
        }


        .sidebar-link {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 11px 13px;

            margin-bottom: 4px;

            border-radius: 9px;

            color: #d1d5db;

            text-decoration: none;

            font-size: 14px;

            transition: .2s;
        }


        .sidebar-link:hover,
        .sidebar-link.active {

            color: #fff;

            background:
                rgba(37,99,235,.9);
        }


        .sidebar-link i {

            width: 20px;

            font-size: 17px;
        }


        .logout-link {

            color: #fca5a5;
        }


        /* =========================================================
           MAIN
        ========================================================= */

        .main {

            margin-left: var(--sidebar-width);

            min-height: 100vh;
        }


        /* =========================================================
           TOPBAR
        ========================================================= */

        .topbar {

            height: 72px;

            background: #fff;

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 28px;

            position: sticky;

            top: 0;

            z-index: 1000;
        }


        .menu-button {

            display: none;

            border: 0;

            background: transparent;

            font-size: 25px;

            padding: 5px;

            color: var(--text);

            line-height: 1;

            cursor: pointer;
        }


        .topbar-title h4 {

            margin: 0;

            font-size: 19px;

            font-weight: 700;
        }


        .topbar-title small {

            color: var(--muted);
        }


        .student-avatar {

            width: 42px;
            height: 42px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #dbeafe;

            color: var(--primary);

            font-weight: 700;

            flex-shrink: 0;
        }


        /* =========================================================
           CONTENT
        ========================================================= */

        .content {

            padding: 28px;
        }


        /* =========================================================
           WELCOME
        ========================================================= */

        .welcome-card {

            border-radius: 18px;

            padding: 26px;

            background:
                linear-gradient(
                    135deg,
                    #2563eb,
                    #1d4ed8
                );

            color: #fff;

            margin-bottom: 24px;

            box-shadow:
                0 10px 30px
                rgba(37,99,235,.16);
        }


        .welcome-card h2 {

            font-size: 25px;

            font-weight: 700;

            margin-bottom: 5px;
        }


        .welcome-card p {

            margin: 0;

            color:
                rgba(255,255,255,.8);
        }


        .student-code {

            margin-top: 16px;

            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding: 7px 12px;

            border-radius: 8px;

            background:
                rgba(255,255,255,.13);

            font-size: 13px;
        }


        /* =========================================================
           INFORMATION CARDS
        ========================================================= */

        .info-card {

            background: #fff;

            border:
                1px solid var(--border);

            border-radius: 16px;

            padding: 22px;

            height: 100%;
        }


        .info-item {

            display: flex;

            align-items: center;

            gap: 14px;

            min-width: 0;
        }


        .info-icon {

            width: 44px;
            height: 44px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #eff6ff;

            color: var(--primary);

            flex-shrink: 0;

            font-size: 19px;
        }


        .info-label {

            color: var(--muted);

            font-size: 12px;

            margin-bottom: 3px;
        }


        .info-value {

            font-size: 15px;

            font-weight: 700;

            word-break: break-word;
        }


        /* =========================================================
           STATISTICS
        ========================================================= */

        .stat-card {

            background: #fff;

            border:
                1px solid var(--border);

            border-radius: 16px;

            padding: 20px;

            height: 100%;

            transition:
                transform .2s,
                box-shadow .2s;
        }


        .stat-card:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 8px 24px
                rgba(0,0,0,.06);
        }


        .stat-icon {

            width: 46px;
            height: 46px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #eff6ff;

            color: var(--primary);

            font-size: 20px;

            margin-bottom: 15px;
        }


        .stat-number {

            font-size: 26px;

            font-weight: 750;

            line-height: 1;
        }


        .stat-title {

            color: var(--muted);

            font-size: 13px;

            margin-top: 7px;
        }


        /* =========================================================
           SECTION
        ========================================================= */

        .section-card {

            background: #fff;

            border:
                1px solid var(--border);

            border-radius: 16px;

            overflow: hidden;
        }


        .section-header {

            padding: 18px 20px;

            border-bottom:
                1px solid var(--border);

            display: flex;

            justify-content: space-between;

            align-items: center;
        }


        .section-header h5 {

            margin: 0;

            font-size: 16px;

            font-weight: 700;
        }


        .section-body {

            padding: 22px;
        }


        .quick-link {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 14px;

            border:
                1px solid var(--border);

            border-radius: 12px;

            text-decoration: none;

            color: var(--text);

            transition: .2s;

            min-width: 0;
        }


        .quick-link:hover {

            border-color: #bfdbfe;

            background: #eff6ff;
        }


        .quick-link-icon {

            width: 40px;
            height: 40px;

            border-radius: 10px;

            background: #eff6ff;

            color: var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            flex-shrink: 0;
        }


        .quick-link strong {

            font-size: 14px;
        }


        .quick-link small {

            display: block;

            color: var(--muted);

            margin-top: 2px;
        }


        /* =========================================================
           SIDEBAR OVERLAY
        ========================================================= */

        .sidebar-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(0,0,0,.45);

            z-index: 1040;
        }


        /* =========================================================
           MOBILE BOTTOM NAVIGATION
        ========================================================= */

        .mobile-bottom-nav {

            display: none;
        }


        .mobile-more-menu {

            display: none;
        }


        /* =========================================================
           TABLET / MOBILE SIDEBAR
        ========================================================= */

        @media screen and (max-width: 991.98px) {

            .sidebar {

                transform:
                    translateX(-100%);

                box-shadow:
                    8px 0 30px
                    rgba(0,0,0,.18);
            }


            .sidebar.show {

                transform:
                    translateX(0);
            }


            .sidebar-overlay.show {

                display: block;
            }


            .main {

                margin-left: 0;
            }


            .menu-button {

                display: inline-flex;

                align-items: center;

                justify-content: center;
            }


            .topbar {

                padding: 0 18px;
            }
        }


        /* =========================================================
           MOBILE
           
           Bottom navigation:
           
           Home | Homework | Results | Attendance | More
        ========================================================= */

        @media screen and (max-width: 991.98px) {

            body {

                padding-bottom:
                    calc(
                        var(--bottom-nav-height)
                        + env(safe-area-inset-bottom)
                    ) !important;
            }


            .mobile-bottom-nav {

                position: fixed !important;

                left: 0 !important;

                right: 0 !important;

                bottom: 0 !important;

                width: 100% !important;

                height:
                    calc(
                        var(--bottom-nav-height)
                        + env(safe-area-inset-bottom)
                    ) !important;

                min-height:
                    var(--bottom-nav-height) !important;

                display: flex !important;

                align-items: stretch !important;

                justify-content: space-around !important;

                background:
                    rgba(255,255,255,.98) !important;

                border-top:
                    1px solid var(--border) !important;

                box-shadow:
                    0 -5px 22px
                    rgba(0,0,0,.10) !important;

                z-index:
                    99999 !important;

                padding:
                    4px
                    4px
                    env(safe-area-inset-bottom)
                    4px !important;

                margin: 0 !important;

                visibility:
                    visible !important;

                opacity:
                    1 !important;

                backdrop-filter:
                    blur(12px);

                -webkit-backdrop-filter:
                    blur(12px);
            }


            /* -----------------------------------------------------
               Navigation Item
            ----------------------------------------------------- */

            .mobile-nav-item {

                display: flex !important;

                flex:
                    1 1 0 !important;

                min-width:
                    0 !important;

                height:
                    100% !important;

                flex-direction:
                    column !important;

                align-items:
                    center !important;

                justify-content:
                    center !important;

                gap:
                    4px !important;

                margin:
                    0 2px !important;

                padding:
                    5px 2px !important;

                border:
                    0 !important;

                border-radius:
                    10px !important;

                text-decoration:
                    none !important;

                color:
                    #6b7280 !important;

                background:
                    transparent !important;

                font-family:
                    inherit !important;

                font-size:
                    10px !important;

                font-weight:
                    600 !important;

                line-height:
                    1.1 !important;

                cursor:
                    pointer !important;

                visibility:
                    visible !important;

                opacity:
                    1 !important;

                -webkit-tap-highlight-color:
                    transparent;

                transition:
                    background-color .2s ease,
                    color .2s ease,
                    transform .15s ease;
            }


            .mobile-nav-item i {

                display:
                    block !important;

                width:
                    auto !important;

                font-size:
                    21px !important;

                line-height:
                    1 !important;

                visibility:
                    visible !important;

                opacity:
                    1 !important;
            }


            .mobile-nav-item span {

                display:
                    block !important;

                max-width:
                    100% !important;

                white-space:
                    nowrap !important;

                overflow:
                    hidden !important;

                text-overflow:
                    ellipsis !important;

                text-align:
                    center !important;

                line-height:
                    1.1 !important;

                visibility:
                    visible !important;

                opacity:
                    1 !important;
            }


            /* -----------------------------------------------------
               Active Item
            ----------------------------------------------------- */

            .mobile-nav-item.active {

                color:
                    var(--primary) !important;

                background:
                    #eff6ff !important;
            }


            .mobile-nav-item.active i {

                transform:
                    translateY(-1px);
            }


            .mobile-nav-item:active {

                transform:
                    scale(.95);
            }


            /* -----------------------------------------------------
               More Button
            ----------------------------------------------------- */

            .mobile-more-button {

                appearance: none;

                -webkit-appearance: none;

                outline: none;
            }


            /* -----------------------------------------------------
               More Popup
            ----------------------------------------------------- */

            .mobile-more-menu {

                position: fixed;

                right: 10px;

                bottom:
                    calc(
                        var(--bottom-nav-height)
                        + env(safe-area-inset-bottom)
                        + 8px
                    );

                width: 210px;

                background: #fff;

                border:
                    1px solid var(--border);

                border-radius: 14px;

                box-shadow:
                    0 12px 35px
                    rgba(0,0,0,.16);

                padding: 7px;

                z-index: 100000;

                display: none;

                animation:
                    moreMenuIn .18s ease;
            }


            .mobile-more-menu.show {

                display: block;
            }


            .mobile-more-link {

                display: flex;

                align-items: center;

                gap: 12px;

                padding: 11px 12px;

                border-radius: 9px;

                color: var(--text);

                text-decoration: none;

                font-size: 13px;

                font-weight: 600;

                transition:
                    background-color .2s ease,
                    color .2s ease;
            }


            .mobile-more-link:hover {

                background:
                    #eff6ff;

                color:
                    var(--primary);
            }


            .mobile-more-link i {

                width: 20px;

                font-size: 17px;

                color:
                    var(--primary);
            }


            .mobile-more-link.logout {

                color:
                    #dc2626;

                border-top:
                    1px solid var(--border);

                margin-top:
                    5px;

                padding-top:
                    12px;
            }


            .mobile-more-link.logout i {

                color:
                    #dc2626;
            }


            @keyframes moreMenuIn {

                from {

                    opacity: 0;

                    transform:
                        translateY(8px);
                }

                to {

                    opacity: 1;

                    transform:
                        translateY(0);
                }
            }


            /* -----------------------------------------------------
               Topbar
            ----------------------------------------------------- */

            .topbar {

                height:
                    64px;

                padding:
                    0 14px;
            }


            .topbar-title h4 {

                font-size:
                    16px;
            }


            .topbar-title small {

                display:
                    none;
            }


            .student-avatar {

                width:
                    36px;

                height:
                    36px;

                font-size:
                    14px;
            }


            /* -----------------------------------------------------
               Content
            ----------------------------------------------------- */

            .content {

                padding:
                    14px;

                padding-bottom:
                    24px;
            }


            /* -----------------------------------------------------
               Welcome
            ----------------------------------------------------- */

            .welcome-card {

                padding:
                    20px;

                border-radius:
                    15px;

                margin-bottom:
                    16px;
            }


            .welcome-card h2 {

                font-size:
                    21px;

                line-height:
                    1.35;
            }


            .welcome-card p {

                font-size:
                    13px;

                line-height:
                    1.5;
            }


            .student-code {

                margin-top:
                    13px;

                font-size:
                    12px;
            }


            /* -----------------------------------------------------
               Information
            ----------------------------------------------------- */

            .info-card {

                padding:
                    17px;

                border-radius:
                    14px;
            }


            .info-item {

                gap:
                    10px;
            }


            .info-icon {

                width:
                    40px;

                height:
                    40px;

                font-size:
                    17px;
            }


            .info-label {

                font-size:
                    11px;
            }


            .info-value {

                font-size:
                    14px;
            }


            /* -----------------------------------------------------
               Statistics
            ----------------------------------------------------- */

            .stat-card {

                padding:
                    17px;

                border-radius:
                    14px;
            }


            .stat-icon {

                width:
                    42px;

                height:
                    42px;

                font-size:
                    18px;

                margin-bottom:
                    13px;
            }


            .stat-number {

                font-size:
                    23px;
            }


            .stat-title {

                font-size:
                    12px;
            }


            /* -----------------------------------------------------
               Quick Access
            ----------------------------------------------------- */

            .section-card {

                border-radius:
                    14px;
            }


            .section-header {

                padding:
                    16px;
            }


            .section-body {

                padding:
                    14px;
            }


            .quick-link {

                padding:
                    12px;
            }


            .quick-link-icon {

                width:
                    38px;

                height:
                    38px;
            }
        }


        /* =========================================================
           SMALL PHONES
        ========================================================= */

        @media screen and (max-width: 380px) {

            :root {

                --bottom-nav-height:
                    66px;
            }


            .mobile-bottom-nav {

                padding-left:
                    2px !important;

                padding-right:
                    2px !important;
            }


            .mobile-nav-item {

                margin:
                    0 1px !important;

                padding:
                    4px 1px !important;

                font-size:
                    9px !important;

                gap:
                    3px !important;
            }


            .mobile-nav-item i {

                font-size:
                    19px !important;
            }


            .mobile-more-menu {

                right:
                    7px;

                width:
                    195px;
            }


            .content {

                padding:
                    11px;

                padding-bottom:
                    20px;
            }


            .welcome-card {

                padding:
                    17px;
            }


            .welcome-card h2 {

                font-size:
                    19px;
            }


            .info-card {

                padding:
                    14px;
            }


            .stat-card {

                padding:
                    14px;
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
></div>


<!-- =============================================================
     SIDEBAR
============================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">

            <i class="bi bi-mortarboard-fill"></i>

        </div>

        <div>

            <h5>
                BKHS
            </h5>

            <small>
                Student Portal
            </small>

        </div>

    </div>


    <nav class="sidebar-nav">

        <div class="sidebar-label">
            Main
        </div>


        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="sidebar-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <!-- Subjects -->

        <a
            href="subjects.php"
            class="sidebar-link <?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-book"></i>

            <span>
                My Subjects
            </span>

        </a>


        <!-- Materials -->

        <a
            href="materials.php"
            class="sidebar-link <?= $currentPage === 'materials.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-folder2-open"></i>

            <span>
                Materials
            </span>

        </a>


        <!-- Results -->

        <a
            href="result.php"
            class="sidebar-link <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-bar-chart"></i>

            <span>
                Results
            </span>

        </a>


        <!-- Attendance -->

        <a
            href="attendance.php"
            class="sidebar-link <?= $currentPage === 'attendance.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-calendar-check"></i>

            <span>
                Attendance
            </span>

        </a>


        <!-- Homework -->

        <a
            href="homework.php"
            class="sidebar-link <?= $currentPage === 'homework.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-journal-text"></i>

            <span>
                Homework
            </span>

        </a>


        <!-- Announcements -->

        <a
            href="announcements.php"
            class="sidebar-link <?= $currentPage === 'announcements.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-megaphone"></i>

            <span>
                Announcements
            </span>

        </a>


        <div class="sidebar-label mt-3">
            Account
        </div>


        <!-- Profile -->

        <a
            href="profile.php"
            class="sidebar-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-person"></i>

            <span>
                Profile
            </span>

        </a>


        <!-- Logout -->

        <a
            href="../auth/logout.php"
            class="sidebar-link logout-link"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </nav>

</aside>


<!-- =============================================================
     MAIN
============================================================= -->

<main class="main">


    <!-- =========================================================
         TOPBAR
    ========================================================= -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-2">

            <button
                type="button"
                class="menu-button"
                id="menuButton"
                aria-label="Open menu"
                aria-controls="sidebar"
                aria-expanded="false"
            >

                <i class="bi bi-list"></i>

            </button>


            <div class="topbar-title">

                <h4>
                    Student Dashboard
                </h4>

                <small>
                    BKHS Student Portal
                </small>

            </div>

        </div>


        <div
            class="student-avatar"
            title="<?= e($studentName) ?>"
        >

            <?= e($avatarInitial) ?>

        </div>

    </header>


    <!-- =========================================================
         CONTENT
    ========================================================= -->

    <div class="content">


        <!-- =====================================================
             WELCOME
        ===================================================== -->

        <section class="welcome-card">

            <h2>
                Welcome back, <?= e($studentName) ?>!
            </h2>

            <p>
                Here is your current academic information.
            </p>


            <div class="student-code">

                <i class="bi bi-person-badge"></i>

                <?= e($studentCode) ?>

            </div>

        </section>


        <!-- =====================================================
             STUDENT INFORMATION
        ===================================================== -->

        <div class="row g-3 mb-4">


            <!-- Student Name -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="info-card">

                    <div class="info-item">

                        <div class="info-icon">

                            <i class="bi bi-person-fill"></i>

                        </div>

                        <div>

                            <div class="info-label">
                                Student Name
                            </div>

                            <div class="info-value">
                                <?= e($studentName) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Grade -->

            <div class="col-6 col-xl-3">

                <div class="info-card">

                    <div class="info-item">

                        <div class="info-icon">

                            <i class="bi bi-mortarboard-fill"></i>

                        </div>

                        <div>

                            <div class="info-label">
                                Grade
                            </div>

                            <div class="info-value">
                                Grade <?= $gradeNumber ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Section -->

            <div class="col-6 col-xl-3">

                <div class="info-card">

                    <div class="info-item">

                        <div class="info-icon">

                            <i class="bi bi-people-fill"></i>

                        </div>

                        <div>

                            <div class="info-label">
                                Section
                            </div>

                            <div class="info-value">
                                <?= e($section) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Academic Year -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="info-card">

                    <div class="info-item">

                        <div class="info-icon">

                            <i class="bi bi-calendar3"></i>

                        </div>

                        <div>

                            <div class="info-label">
                                Academic Year
                            </div>

                            <div class="info-value">
                                <?= e($academicYear) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             STATISTICS
        ===================================================== -->

        <div class="row g-3 mb-4">


            <!-- Subjects -->

            <div class="col-6 col-lg-3">

                <div class="stat-card">

                    <div class="stat-icon">

                        <i class="bi bi-book-fill"></i>

                    </div>

                    <div class="stat-number">
                        <?= $subjectCount ?>
                    </div>

                    <div class="stat-title">
                        My Subjects
                    </div>

                </div>

            </div>


            <!-- Materials -->

            <div class="col-6 col-lg-3">

                <div class="stat-card">

                    <div class="stat-icon">

                        <i class="bi bi-folder-fill"></i>

                    </div>

                    <div class="stat-number">
                        <?= $materialCount ?>
                    </div>

                    <div class="stat-title">
                        Materials
                    </div>

                </div>

            </div>


            <!-- Homework -->

            <div class="col-6 col-lg-3">

                <div class="stat-card">

                    <div class="stat-icon">

                        <i class="bi bi-journal-text"></i>

                    </div>

                    <div class="stat-number">
                        <?= $homeworkCount ?>
                    </div>

                    <div class="stat-title">
                        Active Homework
                    </div>

                </div>

            </div>


            <!-- Results -->

            <div class="col-6 col-lg-3">

                <div class="stat-card">

                    <div class="stat-icon">

                        <i class="bi bi-bar-chart-fill"></i>

                    </div>

                    <div class="stat-number">
                        <?= $resultCount ?>
                    </div>

                    <div class="stat-title">
                        Results Entered
                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             QUICK ACCESS
        ===================================================== -->

        <div class="section-card">

            <div class="section-header">

                <h5>
                    Quick Access
                </h5>

            </div>


            <div class="section-body">

                <div class="row g-3">


                    <!-- Subjects -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <a
                            href="subjects.php"
                            class="quick-link"
                        >

                            <div class="quick-link-icon">

                                <i class="bi bi-book"></i>

                            </div>

                            <div>

                                <strong>
                                    My Subjects
                                </strong>

                                <small>
                                    View your subjects
                                </small>

                            </div>

                        </a>

                    </div>


                    <!-- Materials -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <a
                            href="materials.php"
                            class="quick-link"
                        >

                            <div class="quick-link-icon">

                                <i class="bi bi-folder2-open"></i>

                            </div>

                            <div>

                                <strong>
                                    Materials
                                </strong>

                                <small>
                                    Study materials
                                </small>

                            </div>

                        </a>

                    </div>


                    <!-- Homework -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <a
                            href="homework.php"
                            class="quick-link"
                        >

                            <div class="quick-link-icon">

                                <i class="bi bi-journal-text"></i>

                            </div>

                            <div>

                                <strong>
                                    Homework
                                </strong>

                                <small>
                                    View assignments
                                </small>

                            </div>

                        </a>

                    </div>


                    <!-- Results -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <a
                            href="result.php"
                            class="quick-link"
                        >

                            <div class="quick-link-icon">

                                <i class="bi bi-bar-chart"></i>

                            </div>

                            <div>

                                <strong>
                                    Results
                                </strong>

                                <small>
                                    View your results
                                </small>

                            </div>

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>


<!-- =============================================================
     MOBILE BOTTOM NAVIGATION

     Exactly 5 items:

     Home | Homework | Results | Attendance | More
============================================================= -->

<nav
    class="mobile-bottom-nav"
    aria-label="Student mobile navigation"
>


    <!-- =========================================================
         HOME
    ========================================================= -->

    <a
        href="dashboard.php"
        class="mobile-nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        <?= $currentPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>
    >

        <i class="bi bi-house-door-fill"></i>

        <span>
            Home
        </span>

    </a>


    <!-- =========================================================
         HOMEWORK
    ========================================================= -->

    <a
        href="homework.php"
        class="mobile-nav-item <?= $currentPage === 'homework.php' ? 'active' : '' ?>"
        <?= $currentPage === 'homework.php' ? 'aria-current="page"' : '' ?>
    >

        <i class="bi bi-journal-text"></i>

        <span>
            Homework
        </span>

    </a>


    <!-- =========================================================
         RESULTS
    ========================================================= -->

    <a
        href="result.php"
        class="mobile-nav-item <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        <?= $currentPage === 'result.php' ? 'aria-current="page"' : '' ?>
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Results
        </span>

    </a>


    <!-- =========================================================
         ATTENDANCE
    ========================================================= -->

    <a
        href="attendance.php"
        class="mobile-nav-item <?= $currentPage === 'attendance.php' ? 'active' : '' ?>"
        <?= $currentPage === 'attendance.php' ? 'aria-current="page"' : '' ?>
    >

        <i class="bi bi-calendar-check-fill"></i>

        <span>
            Attendance
        </span>

    </a>


    <!-- =========================================================
         MORE
    ========================================================= -->

    <button
        type="button"
        class="mobile-nav-item mobile-more-button <?= $isMoreActive ? 'active' : '' ?>"
        id="mobileMoreButton"
        aria-expanded="false"
        aria-controls="mobileMoreMenu"
        aria-label="Open more student navigation"
    >

        <i class="bi bi-three-dots"></i>

        <span>
            More
        </span>

    </button>

</nav>


<!-- =============================================================
     MOBILE MORE MENU

     Hidden navigation items:
     
     Subjects
     Materials
     Announcements
     Profile
     Logout
============================================================= -->

<div
    class="mobile-more-menu"
    id="mobileMoreMenu"
    aria-hidden="true"
>


    <!-- Subjects -->

    <a
        href="subjects.php"
        class="mobile-more-link"
    >

        <i class="bi bi-book-fill"></i>

        <span>
            Subjects
        </span>

    </a>


    <!-- Materials -->

    <a
        href="materials.php"
        class="mobile-more-link"
    >

        <i class="bi bi-folder-fill"></i>

        <span>
            Materials
        </span>

    </a>


    <!-- Announcements -->

    <a
        href="announcements.php"
        class="mobile-more-link"
    >

        <i class="bi bi-megaphone-fill"></i>

        <span>
            Announcements
        </span>

    </a>


    <!-- Profile -->

    <a
        href="profile.php"
        class="mobile-more-link"
    >

        <i class="bi bi-person-fill"></i>

        <span>
            Profile
        </span>

    </a>


    <!-- Logout -->

    <a
        href="../auth/logout.php"
        class="mobile-more-link logout"
    >

        <i class="bi bi-box-arrow-right"></i>

        <span>
            Logout
        </span>

    </a>

</div>


<!-- =============================================================
     JAVASCRIPT
============================================================= -->

<script>

/*
|--------------------------------------------------------------------------
| Sidebar Elements
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('sidebarOverlay');

const menuButton =
    document.getElementById('menuButton');


/*
|--------------------------------------------------------------------------
| Mobile More Elements
|--------------------------------------------------------------------------
*/

const mobileMoreButton =
    document.getElementById('mobileMoreButton');

const mobileMoreMenu =
    document.getElementById('mobileMoreMenu');


/*
|--------------------------------------------------------------------------
| Open Sidebar
|--------------------------------------------------------------------------
*/

function openSidebar() {

    if (
        !sidebar ||
        !overlay ||
        !menuButton
    ) {
        return;
    }

    /*
    | Close More menu if it is open.
    */

    closeMobileMoreMenu();

    sidebar.classList.add('show');

    overlay.classList.add('show');

    menuButton.setAttribute(
        'aria-expanded',
        'true'
    );

    document.body.style.overflow = 'hidden';
}


/*
|--------------------------------------------------------------------------
| Close Sidebar
|--------------------------------------------------------------------------
*/

function closeSidebar() {

    if (
        !sidebar ||
        !overlay ||
        !menuButton
    ) {
        return;
    }

    sidebar.classList.remove('show');

    overlay.classList.remove('show');

    menuButton.setAttribute(
        'aria-expanded',
        'false'
    );

    document.body.style.overflow = '';
}


/*
|--------------------------------------------------------------------------
| Open Mobile More Menu
|--------------------------------------------------------------------------
*/

function openMobileMoreMenu() {

    if (
        !mobileMoreButton ||
        !mobileMoreMenu
    ) {
        return;
    }

    mobileMoreMenu.classList.add('show');

    mobileMoreButton.setAttribute(
        'aria-expanded',
        'true'
    );

    mobileMoreMenu.setAttribute(
        'aria-hidden',
        'false'
    );
}


/*
|--------------------------------------------------------------------------
| Close Mobile More Menu
|--------------------------------------------------------------------------
*/

function closeMobileMoreMenu() {

    if (
        !mobileMoreButton ||
        !mobileMoreMenu
    ) {
        return;
    }

    mobileMoreMenu.classList.remove('show');

    mobileMoreButton.setAttribute(
        'aria-expanded',
        'false'
    );

    mobileMoreMenu.setAttribute(
        'aria-hidden',
        'true'
    );
}


/*
|--------------------------------------------------------------------------
| Menu Button
|--------------------------------------------------------------------------
*/

if (menuButton) {

    menuButton.addEventListener(
        'click',
        function () {

            if (
                sidebar &&
                sidebar.classList.contains('show')
            ) {

                closeSidebar();

            } else {

                openSidebar();

            }

        }
    );
}


/*
|--------------------------------------------------------------------------
| Overlay Click
|--------------------------------------------------------------------------
*/

if (overlay) {

    overlay.addEventListener(
        'click',
        closeSidebar
    );
}


/*
|--------------------------------------------------------------------------
| Sidebar Navigation
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.sidebar-link')
    .forEach(
        function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth < 992
                    ) {

                        closeSidebar();

                    }

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| Mobile More Button
|--------------------------------------------------------------------------
*/

if (mobileMoreButton) {

    mobileMoreButton.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

            if (
                mobileMoreMenu &&
                mobileMoreMenu.classList.contains('show')
            ) {

                closeMobileMoreMenu();

            } else {

                /*
                | Close sidebar before opening More.
                */

                closeSidebar();

                openMobileMoreMenu();

            }

        }
    );
}


/*
|--------------------------------------------------------------------------
| Close More Menu When Clicking Outside
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'click',
    function (event) {

        if (
            !mobileMoreMenu ||
            !mobileMoreButton
        ) {
            return;
        }

        if (
            !mobileMoreMenu.contains(event.target) &&
            !mobileMoreButton.contains(event.target)
        ) {

            closeMobileMoreMenu();

        }

    }
);


/*
|--------------------------------------------------------------------------
| More Menu Links
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.mobile-more-link')
    .forEach(
        function (link) {

            link.addEventListener(
                'click',
                function () {

                    closeMobileMoreMenu();

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| Close Sidebar / More Menu When Resizing
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'resize',
    function () {

        /*
        | Desktop
        */

        if (
            window.innerWidth >= 992
        ) {

            closeSidebar();

            closeMobileMoreMenu();

        }

    }
);


/*
|--------------------------------------------------------------------------
| Escape Key
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key !== 'Escape'
        ) {
            return;
        }

        if (
            sidebar &&
            sidebar.classList.contains('show')
        ) {

            closeSidebar();

        }

        closeMobileMoreMenu();

    }
);

</script>


</body>

</html>