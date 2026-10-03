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
$homeroomAssignments = [];

$assignedSubjectCount = 0;
$homeroomCount = 0;
$totalStudents = 0;

$todayEthiopian = EthiopianCalendar::todayFormatted();

/*
|--------------------------------------------------------------------------
| Helpers
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
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'T';
}

/*
|--------------------------------------------------------------------------
| Resolve Teacher Photo
|--------------------------------------------------------------------------
*/

function resolveTeacherPhoto(?string $storedPath): string
{
    $storedPath = trim((string) $storedPath);

    if ($storedPath === '') {
        return '';
    }

    if (
        str_starts_with($storedPath, 'http://') ||
        str_starts_with($storedPath, 'https://')
    ) {
        return $storedPath;
    }

    if (str_starts_with($storedPath, '/')) {
        return $storedPath;
    }

    if (str_starts_with($storedPath, 'public/')) {
        return '../' . $storedPath;
    }

    if (str_starts_with($storedPath, 'uploads/')) {
        return '../' . $storedPath;
    }

    if (str_contains($storedPath, '/')) {
        return '../' . ltrim($storedPath, './');
    }

    $profileFile = __DIR__
        . '/../public/uploads/profiles/'
        . $storedPath;

    if (is_file($profileFile)) {
        return '../public/uploads/profiles/' . $storedPath;
    }

    $oldTeacherFile = __DIR__
        . '/../uploads/teachers/'
        . $storedPath;

    if (is_file($oldTeacherFile)) {
        return '../uploads/teachers/' . $storedPath;
    }

    $publicImageFile = __DIR__
        . '/../public/image/'
        . $storedPath;

    if (is_file($publicImageFile)) {
        return '../public/image/' . $storedPath;
    }

    return '../public/uploads/profiles/' . $storedPath;
}

/*
|--------------------------------------------------------------------------
| Load Teacher Profile
|--------------------------------------------------------------------------
*/

$teacherSql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.id AS teacher_id,
        t.gender,
        t.birth_eth_year,
        t.birth_eth_month,
        t.birth_eth_day,
        t.region,
        t.zone,
        t.woreda,
        t.marital_status,
        t.education_level,
        t.department,
        t.college_university_institution,
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

$teacherName = $teacher['full_name'] ?? 'Teacher';

$teacherInitials = getInitials(
    (string) $teacherName
);

$teacherPhoto = resolveTeacherPhoto(
    $teacher['photo_path'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Load Active Academic Year
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

$academicYearResult = $conn->query(
    $academicYearSql
);

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
| Load Subject Assignments
|--------------------------------------------------------------------------
*/

if ($academicYearName !== '') {

    $subjectSql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            gs.subject_name,
            gs.id AS grade_subject_id
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $subjectStmt = $conn->prepare(
        $subjectSql
    );

    if ($subjectStmt) {

        $subjectStmt->bind_param(
            'is',
            $teacherUserId,
            $academicYearName
        );

        $subjectStmt->execute();

        $subjectResult =
            $subjectStmt->get_result();

        while (
            $row = $subjectResult->fetch_assoc()
        ) {
            $subjectAssignments[] = $row;
        }

        $subjectStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Load Homeroom Assignments
|--------------------------------------------------------------------------
*/

if ($academicYearName !== '') {

    $homeroomSql = "
        SELECT
            hta.id,
            hta.grade,
            hta.section
        FROM homeroom_teacher_assignments hta
        WHERE hta.teacher_user_id = ?
          AND hta.academic_year = ?
          AND hta.is_active = 1
        ORDER BY
            hta.grade ASC,
            hta.section ASC
    ";

    $homeroomStmt = $conn->prepare(
        $homeroomSql
    );

    if ($homeroomStmt) {

        $homeroomStmt->bind_param(
            'is',
            $teacherUserId,
            $academicYearName
        );

        $homeroomStmt->execute();

        $homeroomResult =
            $homeroomStmt->get_result();

        while (
            $row = $homeroomResult->fetch_assoc()
        ) {
            $homeroomAssignments[] = $row;
        }

        $homeroomStmt->close();
    }
}

$assignedSubjectCount =
    count($subjectAssignments);

$homeroomCount =
    count($homeroomAssignments);

/*
|--------------------------------------------------------------------------
| Count Students
|--------------------------------------------------------------------------
*/

if (
    $academicYearId > 0 &&
    $academicYearName !== ''
) {

    $studentSql = "
        SELECT
            COUNT(DISTINCT sr.student_id)
            AS total_students
        FROM student_registrations sr
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        INNER JOIN (
            SELECT DISTINCT
                grade,
                section
            FROM subject_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND is_active = 1

            UNION

            SELECT DISTINCT
                grade,
                section
            FROM homeroom_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND is_active = 1
        ) teacher_classes
            ON teacher_classes.grade =
               g.grade_number
            AND teacher_classes.section =
                sec.code
        WHERE sr.academic_year_id = ?
    ";

    $studentStmt = $conn->prepare(
        $studentSql
    );

    if ($studentStmt) {

        $studentStmt->bind_param(
            'isisi',
            $teacherUserId,
            $academicYearName,
            $teacherUserId,
            $academicYearName,
            $academicYearId
        );

        $studentStmt->execute();

        $studentResult =
            $studentStmt->get_result();

        $studentRow =
            $studentResult->fetch_assoc();

        $totalStudents = (int) (
            $studentRow['total_students'] ?? 0
        );

        $studentStmt->close();
    }
}

$hasHomeroom =
    $homeroomCount > 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta
        name="theme-color"
        content="#111827"
    >

    <title>Teacher Dashboard | BKHS</title>

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
            --success: #16a34a;
            --warning: #d97706;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--body-bg);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 260px;
            height: 100dvh;
            padding: 20px 14px;
            background: var(--sidebar);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 1050;
            overflow: hidden;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 10px 20px;
            color: #fff;
            flex-shrink: 0;
        }

        .brand-icon {
            width: 43px;
            height: 43px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            font-size: 21px;
            flex-shrink: 0;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 10px;
        }

        .sidebar-label {
            padding: 0 12px;
            margin: 9px 0 7px;
            color: #6b7280;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 3px;
            overflow-y: auto;
            padding-right: 2px;
            scrollbar-width: thin;
        }

        .nav-link-custom {
            min-height: 43px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 12px;
            border-radius: 9px;
            color: #d1d5db;
            font-size: 12px;
            font-weight: 600;
            transition: .2s ease;
        }

        .nav-link-custom i {
            width: 21px;
            text-align: center;
            font-size: 16px;
            flex-shrink: 0;
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
            padding: 13px 8px 0;
            border-top: 1px solid #374151;
            flex-shrink: 0;
        }

        .sidebar-profile-link {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #fff;
            min-width: 0;
        }

        .avatar {
            width: 39px;
            height: 39px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-name {
            max-width: 145px;
            overflow: hidden;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-role {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 10px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            min-height: 100vh;
            margin-left: 260px;
            width: calc(100% - 260px);
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            min-height: 70px;
            padding: 13px 28px;
            background: rgba(255, 255, 255, .97);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .page-heading {
            min-width: 0;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 11px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .date-pill {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: #374151;
            background: #f9fafb;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            width: 100%;
            max-width: 1450px;
            margin: 0 auto;
            padding: 25px 28px 35px;
        }

        /*
        |--------------------------------------------------------------------------
        | Welcome
        |--------------------------------------------------------------------------
        */

        .welcome-card {
            position: relative;
            overflow: hidden;
            padding: 25px;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(
                135deg,
                #1d4ed8,
                #2563eb 55%,
                #3b82f6
            );
        }

        .welcome-card::after {
            content: '';
            position: absolute;
            width: 210px;
            height: 210px;
            right: -75px;
            top: -80px;
            border: 32px solid rgba(255, 255, 255, .08);
            border-radius: 50%;
        }

        .welcome-card h2 {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .welcome-card p {
            position: relative;
            z-index: 1;
            max-width: 650px;
            margin: 8px 0 0;
            color: #dbeafe;
            font-size: 12px;
            line-height: 1.6;
        }

        .academic-badge {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 15px;
            padding: 7px 10px;
            border-radius: 8px;
            color: #eff6ff;
            background: rgba(255, 255, 255, .15);
            font-size: 11px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-top: 18px;
        }

        .stat-card {
            min-width: 0;
            padding: 17px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 600;
            line-height: 1.4;
        }

        .stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            color: #2563eb;
            background: #dbeafe;
        }

        .stat-icon.green {
            color: #15803d;
            background: #dcfce7;
        }

        .stat-icon.orange {
            color: #c2410c;
            background: #ffedd5;
        }

        .stat-icon.purple {
            color: #7e22ce;
            background: #f3e8ff;
        }

        .stat-value {
            margin-top: 14px;
            font-size: 25px;
            font-weight: 800;
            line-height: 1;
            overflow-wrap: anywhere;
        }

        .stat-description {
            margin-top: 7px;
            color: var(--text-muted);
            font-size: 10px;
            line-height: 1.4;
        }

        /*
        |--------------------------------------------------------------------------
        | Panels
        |--------------------------------------------------------------------------
        */

        .section-grid {
            display: grid;
            grid-template-columns:
                minmax(0, 1.35fr)
                minmax(0, 1fr);
            gap: 18px;
            margin-top: 18px;
        }

        .panel {
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px 19px;
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        .panel-body {
            padding: 17px 19px;
        }

        /*
        |--------------------------------------------------------------------------
        | Assignments
        |--------------------------------------------------------------------------
        */

        .assignment-list {
            display: flex;
            flex-direction: column;
        }

        .assignment-item {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .assignment-item:first-child {
            padding-top: 0;
        }

        .assignment-item:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .assignment-main {
            min-width: 0;
        }

        .assignment-title {
            overflow: hidden;
            color: #111827;
            font-size: 12px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .assignment-meta {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 10px;
        }

        .assignment-badge {
            flex-shrink: 0;
            padding: 5px 8px;
            border-radius: 7px;
            color: #1d4ed8;
            background: #dbeafe;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Quick Actions
        |--------------------------------------------------------------------------
        */

        .quick-actions {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .quick-action {
            min-width: 0;
            min-height: 55px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #374151;
            background: #fff;
            transition: .2s ease;
        }

        .quick-action:hover {
            border-color: #93c5fd;
            color: var(--primary);
            background: #eff6ff;
        }

        .quick-action i {
            color: var(--primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .quick-action span {
            min-width: 0;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.35;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 23px 10px;
            color: var(--text-muted);
            text-align: center;
            font-size: 11px;
        }

        .empty-state i {
            display: block;
            margin-bottom: 7px;
            font-size: 27px;
            color: #9ca3af;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-more-menu {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Tablet
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1199px) {

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .section-grid {
                grid-template-columns: 1fr;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            body {
                padding-bottom:
                    calc(
                        70px +
                        env(safe-area-inset-bottom)
                    );
            }

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .topbar {
                min-height: 66px;
                padding: 11px 18px;
            }

            .content {
                padding: 20px 18px 35px;
            }

            .menu-toggle {
                display: none !important;
            }

            /*
            |--------------------------------------------------------------------------
            | Bottom Navigation
            |--------------------------------------------------------------------------
            */

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 1200;

                display: flex;
                align-items: stretch;
                justify-content: space-around;

                width: 100%;
                height:
                    calc(
                        70px +
                        env(safe-area-inset-bottom)
                    );

                min-height: 70px;

                padding:
                    4px
                    4px
                    env(safe-area-inset-bottom)
                    4px;

                background: rgba(255, 255, 255, .98);
                border-top: 1px solid var(--border);

                box-shadow:
                    0 -5px 25px
                    rgba(15, 23, 42, .10);

                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);

                pointer-events: auto;
            }

            .mobile-nav-item {
                position: relative;

                display: flex;
                flex: 1 1 0;

                min-width: 0;
                height: 100%;

                flex-direction: column;
                align-items: center;
                justify-content: center;

                gap: 4px;

                margin: 0 2px;
                padding: 5px 2px;

                border: 0;
                border-radius: 10px;

                color: #6b7280;
                background: transparent;

                font-family: inherit;
                font-size: 9px;
                font-weight: 700;
                line-height: 1.1;

                cursor: pointer;

                appearance: none;
                -webkit-appearance: none;

                text-decoration: none;

                transition:
                    background-color .18s ease,
                    color .18s ease,
                    transform .15s ease;

                touch-action: manipulation;
            }

            .mobile-nav-item i {
                display: block;
                font-size: 19px;
                line-height: 1;
                pointer-events: none;
            }

            .mobile-nav-item span {
                display: block;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                pointer-events: none;
            }

            .mobile-nav-item.active {
                color: var(--primary);
                background: #eff6ff;
            }

            .mobile-nav-item.active::before {
                content: '';

                position: absolute;

                top: -4px;
                left: 50%;

                transform: translateX(-50%);

                width: 25px;
                height: 3px;

                border-radius:
                    0 0 5px 5px;

                background:
                    var(--primary);
            }

            .mobile-nav-item:active {
                transform: scale(.95);
            }

            /*
            |--------------------------------------------------------------------------
            | More Popup
            |--------------------------------------------------------------------------
            */

            .mobile-more-menu {
                position: fixed;

                right: 10px;

                bottom:
                    calc(
                        78px +
                        env(safe-area-inset-bottom)
                    );

                z-index: 1300;

                width: 205px;

                padding: 7px;

                border: 1px solid var(--border);
                border-radius: 14px;

                background: #fff;

                box-shadow:
                    0 12px 35px
                    rgba(15, 23, 42, .18);

                display: none;

                pointer-events: none;

                opacity: 0;

                transform:
                    translateY(8px)
                    scale(.97);

                transform-origin:
                    bottom right;

                transition:
                    opacity .16s ease,
                    transform .16s ease;
            }

            .mobile-more-menu.show {
                display: block;

                pointer-events: auto;

                opacity: 1;

                transform:
                    translateY(0)
                    scale(1);
            }

            .mobile-more-item {
                display: flex;

                align-items: center;

                gap: 11px;

                width: 100%;

                min-height: 45px;

                padding: 9px 11px;

                border-radius: 9px;

                color: #374151;

                font-size: 11px;

                font-weight: 700;

                transition: .18s ease;
            }

            .mobile-more-item:hover,
            .mobile-more-item:active {
                background: #f3f4f6;
                color: var(--primary);
            }

            .mobile-more-item i {
                width: 22px;

                text-align: center;

                font-size: 17px;

                flex-shrink: 0;
            }

            .mobile-more-item span {
                pointer-events: none;
            }

            .mobile-more-item.logout {
                color: #dc2626;
            }

            .mobile-more-item.logout:hover,
            .mobile-more-item.logout:active {
                background: #fef2f2;
                color: #b91c1c;
            }

            .mobile-more-divider {
                height: 1px;

                margin: 5px 3px;

                background:
                    var(--border);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Tablet
        |--------------------------------------------------------------------------
        */

        @media (min-width: 576px) and (max-width: 991px) {

            .welcome-card {
                padding: 23px;
            }

            .quick-actions {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

            .quick-action {
                min-height: 62px;
            }

            .quick-action span {
                font-size: 10px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Phone
        |--------------------------------------------------------------------------
        */

        @media (max-width: 575px) {

            .topbar {
                align-items: center;

                padding:
                    10px
                    12px;

                gap: 8px;
            }

            .page-heading h1 {
                font-size: 16px;
            }

            .page-heading p {
                display: none;
            }

            .topbar-right {
                gap: 6px;
            }

            .topbar-right > .avatar {
                width: 35px;
                height: 35px;
                font-size: 10px;
            }

            .date-pill {
                display: none;
            }

            .content {
                padding:
                    14px
                    12px
                    20px;
            }

            .welcome-card {
                padding: 19px;
                border-radius: 13px;
            }

            .welcome-card h2 {
                font-size: 18px;
                line-height: 1.35;
            }

            .welcome-card p {
                margin-top: 7px;
                font-size: 10px;
                line-height: 1.55;
            }

            .academic-badge {
                margin-top: 12px;
                padding: 6px 8px;
                font-size: 9px;
            }

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));

                gap: 9px;

                margin-top: 12px;
            }

            .stat-card {
                padding: 13px;
                border-radius: 11px;
            }

            .stat-label {
                font-size: 9px;
            }

            .stat-icon {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                font-size: 15px;
            }

            .stat-value {
                margin-top: 11px;
                font-size: 21px;
            }

            .stat-description {
                margin-top: 6px;
                font-size: 8px;
            }

            .section-grid {
                gap: 12px;
                margin-top: 12px;
            }

            .panel {
                border-radius: 11px;
            }

            .panel-header {
                padding: 13px 14px;
            }

            .panel-body {
                padding: 13px 14px;
            }

            .panel-title {
                font-size: 12px;
            }

            .panel-subtitle {
                font-size: 9px;
            }

            .assignment-item {
                gap: 8px;
                padding: 10px 0;
            }

            .assignment-title {
                font-size: 10px;
            }

            .assignment-meta {
                margin-top: 3px;
                font-size: 8px;
            }

            .assignment-badge {
                padding: 4px 6px;
                font-size: 8px;
            }

            .quick-actions {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));

                gap: 8px;
            }

            .quick-action {
                min-height: 52px;

                padding: 9px;

                gap: 7px;

                border-radius: 9px;
            }

            .quick-action i {
                font-size: 16px;
            }

            .quick-action span {
                font-size: 8px;
            }

            .empty-state {
                padding: 20px 7px;
                font-size: 9px;
            }

            .empty-state i {
                font-size: 23px;
            }

            .mobile-bottom-nav {
                height:
                    calc(
                        68px +
                        env(safe-area-inset-bottom)
                    );

                min-height: 68px;

                padding-left: 3px;
                padding-right: 3px;
            }

            .mobile-nav-item {
                font-size: 8px;

                margin: 0 1px;

                gap: 3px;
            }

            .mobile-nav-item i {
                font-size: 18px;
            }

            .mobile-more-menu {
                right: 8px;

                bottom:
                    calc(
                        76px +
                        env(safe-area-inset-bottom)
                    );

                width: 185px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Very Small Phones
        |--------------------------------------------------------------------------
        */

        @media (max-width: 360px) {

            body {
                padding-bottom:
                    calc(
                        66px +
                        env(safe-area-inset-bottom)
                    );
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .quick-action {
                min-height: 48px;
            }

            .mobile-bottom-nav {
                height:
                    calc(
                        66px +
                        env(safe-area-inset-bottom)
                    );

                min-height: 66px;
            }

            .mobile-nav-item {
                font-size: 7px;
            }

            .mobile-nav-item i {
                font-size: 17px;
            }

            .mobile-more-menu {
                right: 6px;

                bottom:
                    calc(
                        73px +
                        env(safe-area-inset-bottom)
                    );

                width: 175px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Safe Area
        |--------------------------------------------------------------------------
        */

        @supports (padding: env(safe-area-inset-bottom)) {

            .mobile-bottom-nav {
                padding-bottom:
                    max(
                        4px,
                        env(safe-area-inset-bottom)
                    );
            }
        }

    </style>

</head>

<body>

<!-- =========================================================
     DESKTOP SIDEBAR
     ========================================================= -->

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

        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="nav-link-custom active"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>

        <!-- Subjects -->

        <a
            href="subjects.php"
            class="nav-link-custom"
        >

            <i class="bi bi-book-fill"></i>

            <span>
                Subjects
            </span>

        </a>

        <!-- Classes -->

        <a
            href="classes.php"
            class="nav-link-custom"
        >

            <i class="bi bi-people-fill"></i>

            <span>
                Classes
            </span>

        </a>

        <!-- Result -->

        <a
            href="result.php"
            class="nav-link-custom"
        >

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Result
            </span>

        </a>

        <!-- Academic Section -->

        <div class="sidebar-label">
            Academic
        </div>

        <!-- Daily Attendance -->

        <a
            href="daily-attendance.php"
            class="nav-link-custom"
        >

            <i class="bi bi-calendar-check-fill"></i>

            <span>
                Daily Attendance
            </span>

        </a>

        <!-- Homework -->

        <a
            href="homework.php"
            class="nav-link-custom"
        >

            <i class="bi bi-journal-text"></i>

            <span>
                Homework
            </span>

        </a>

        <!-- Materials -->

        <a
            href="materials.php"
            class="nav-link-custom"
        >

            <i class="bi bi-folder-fill"></i>

            <span>
                Materials
            </span>

        </a>

        <!-- Announcements -->

        <a
            href="announcements.php"
            class="nav-link-custom"
        >

            <i class="bi bi-megaphone-fill"></i>

            <span>
                Announcement
            </span>

        </a>

        <!-- Roster -->

        <a
            href="roster.php"
            class="nav-link-custom"
        >

            <i class="bi bi-card-list"></i>

            <span>
                Roster
            </span>

        </a>

        <!-- Account -->

        <div class="sidebar-label">
            Account
        </div>

        <!-- Profile -->

        <a
            href="profile.php"
            class="nav-link-custom"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile
            </span>

        </a>

        <!-- Logout -->

        <a
            href="../auth/logout.php"
            class="nav-link-custom logout"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </nav>

    <!-- Sidebar Profile -->

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

<!-- =========================================================
     MAIN
     ========================================================= -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-heading">

            <h1>
                Teacher Dashboard
            </h1>

            <p>
                Manage your teaching activities
            </p>

        </div>

        <div class="topbar-right">

            <div class="date-pill">

                <i class="bi bi-calendar3"></i>

                <span>
                    <?= e($todayEthiopian) ?>
                </span>

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

    <!-- CONTENT -->

    <div class="content">

        <!-- Welcome -->

        <section class="welcome-card">

            <h2>
                Welcome back,
                <?= e($teacherName) ?>!
            </h2>

            <p>
                Here is an overview of your teaching assignments
                and classroom activities for the current academic year.
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

        <!-- Statistics -->

        <section class="stats-grid">

            <!-- Assigned Subjects -->

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Assigned Subjects
                    </div>

                    <div class="stat-icon blue">

                        <i class="bi bi-book-fill"></i>

                    </div>

                </div>

                <div class="stat-value">
                    <?= $assignedSubjectCount ?>
                </div>

                <div class="stat-description">
                    Subjects assigned to you
                </div>

            </div>

            <!-- Homeroom Classes -->

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Homeroom Classes
                    </div>

                    <div class="stat-icon green">

                        <i class="bi bi-house-door-fill"></i>

                    </div>

                </div>

                <div class="stat-value">
                    <?= $homeroomCount ?>
                </div>

                <div class="stat-description">
                    Classes under your supervision
                </div>

            </div>

            <!-- Students -->

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Students
                    </div>

                    <div class="stat-icon orange">

                        <i class="bi bi-people-fill"></i>

                    </div>

                </div>

                <div class="stat-value">
                    <?= $totalStudents ?>
                </div>

                <div class="stat-description">
                    Students in your assigned classes
                </div>

            </div>

            <!-- Today's Date -->

            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Today's Date
                    </div>

                    <div class="stat-icon purple">

                        <i class="bi bi-calendar-event-fill"></i>

                    </div>

                </div>

                <div
                    class="stat-value"
                    style="font-size: 16px;"
                >

                    <?= e($todayEthiopian) ?>

                </div>

                <div class="stat-description">
                    Ethiopian calendar
                </div>

            </div>

        </section>

        <!-- MAIN PANELS -->

        <section class="section-grid">

            <!-- Subject Assignments -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h3 class="panel-title">
                            My Subject Assignments
                        </h3>

                        <p class="panel-subtitle">
                            Subjects and classes assigned to you
                        </p>

                    </div>

                    <span class="assignment-badge">
                        <?= $assignedSubjectCount ?>
                    </span>

                </div>

                <div class="panel-body">

                    <?php if ($subjectAssignments): ?>

                        <div class="assignment-list">

                            <?php foreach ($subjectAssignments as $assignment): ?>

                                <div class="assignment-item">

                                    <div class="assignment-main">

                                        <div class="assignment-title">

                                            <?= e(
                                                (string) $assignment['subject_name']
                                            ) ?>

                                        </div>

                                        <div class="assignment-meta">

                                            Grade
                                            <?= e(
                                                (string) $assignment['grade']
                                            ) ?>

                                            ·

                                            Section
                                            <?= e(
                                                (string) $assignment['section']
                                            ) ?>

                                        </div>

                                    </div>

                                    <span class="assignment-badge">

                                        Grade
                                        <?= e(
                                            (string) $assignment['grade']
                                        ) ?>

                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-book"></i>

                            No subject assignments found.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- Quick Actions -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h3 class="panel-title">
                            Quick Actions
                        </h3>

                        <p class="panel-subtitle">
                            Quickly access your teaching tools
                        </p>

                    </div>

                    <i
                        class="bi bi-lightning-charge-fill"
                        style="
                            color: #f59e0b;
                            font-size: 19px;
                        "
                    ></i>

                </div>

                <div class="panel-body">

                    <div class="quick-actions">

                        <!-- Result -->

                        <a
                            href="result.php"
                            class="quick-action"
                        >

                            <i class="bi bi-bar-chart-fill"></i>

                            <span>
                                Enter Result
                            </span>

                        </a>

                        <!-- Daily Attendance -->

                        <?php if ($hasHomeroom): ?>

                            <a
                                href="daily-attendance.php"
                                class="quick-action"
                            >

                                <i class="bi bi-calendar-check-fill"></i>

                                <span>
                                    Daily Attendance
                                </span>

                            </a>

                        <?php endif; ?>

                        <!-- Homework -->

                        <a
                            href="homework.php"
                            class="quick-action"
                        >

                            <i class="bi bi-journal-text"></i>

                            <span>
                                Add Homework
                            </span>

                        </a>

                        <!-- Materials -->

                        <a
                            href="materials.php"
                            class="quick-action"
                        >

                            <i class="bi bi-folder-fill"></i>

                            <span>
                                Add Material
                            </span>

                        </a>

                        <!-- Roster -->

                        <a
                            href="roster.php"
                            class="quick-action"
                        >

                            <i class="bi bi-card-list"></i>

                            <span>
                                View Roster
                            </span>

                        </a>

                        <!-- Announcements -->

                        <a
                            href="announcements.php"
                            class="quick-action"
                        >

                            <i class="bi bi-megaphone-fill"></i>

                            <span>
                                Announcements
                            </span>

                        </a>

                    </div>

                </div>

            </div>

        </section>

        <!-- HOMEROOM -->

        <section class="panel mt-3">

            <div class="panel-header">

                <div>

                    <h3 class="panel-title">
                        My Homeroom Classes
                    </h3>

                    <p class="panel-subtitle">
                        Classes where you are the homeroom teacher
                    </p>

                </div>

                <span class="assignment-badge">
                    <?= $homeroomCount ?>
                </span>

            </div>

            <div class="panel-body">

                <?php if ($homeroomAssignments): ?>

                    <div class="row g-2">

                        <?php foreach ($homeroomAssignments as $homeroom): ?>

                            <div class="col-6 col-md-4 col-lg-3">

                                <div
                                    class="assignment-item"
                                    style="
                                        border: 1px solid #e5e7eb;
                                        border-radius: 10px;
                                        padding: 12px;
                                        height: 100%;
                                    "
                                >

                                    <div class="assignment-main">

                                        <div class="assignment-title">

                                            Grade
                                            <?= e(
                                                (string) $homeroom['grade']
                                            ) ?>

                                        </div>

                                        <div class="assignment-meta">

                                            Section
                                            <?= e(
                                                (string) $homeroom['section']
                                            ) ?>

                                        </div>

                                    </div>

                                    <i
                                        class="bi bi-house-door-fill"
                                        style="
                                            color: #16a34a;
                                            font-size: 19px;
                                        "
                                    ></i>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-house-door"></i>

                        You have no homeroom class assignments.

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</main>

<!-- =========================================================
     MOBILE MORE MENU
     ========================================================= -->

<div
    class="mobile-more-menu"
    id="mobileMoreMenu"
    aria-hidden="true"
>

    <!-- Roster -->

    <a
        href="roster.php"
        class="mobile-more-item"
    >

        <i class="bi bi-card-list"></i>

        <span>
            Roster
        </span>

    </a>

    <div class="mobile-more-divider"></div>

    <!-- Materials -->

    <a
        href="materials.php"
        class="mobile-more-item"
    >

        <i class="bi bi-folder-fill"></i>

        <span>
            Materials
        </span>

    </a>

    <div class="mobile-more-divider"></div>

    <!-- Profile -->

    <a
        href="profile.php"
        class="mobile-more-item"
    >

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>

    <div class="mobile-more-divider"></div>

    <!-- Logout -->

    <a
        href="../auth/logout.php"
        class="mobile-more-item logout"
    >

        <i class="bi bi-box-arrow-right"></i>

        <span>
            Logout
        </span>

    </a>

</div>

<!-- =========================================================
     MOBILE BOTTOM NAVIGATION
     ========================================================= -->

<nav
    class="mobile-bottom-nav"
    aria-label="Teacher mobile navigation"
>

    <!-- Daily Attendance -->

    <a
        href="daily-attendance.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-calendar-check-fill"></i>

        <span>
            Daily Attendance
        </span>

    </a>

    <!-- Homework -->

    <a
        href="homework.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-journal-text"></i>

        <span>
            Homework
        </span>

    </a>

    <!-- Result -->

    <a
        href="result.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Result
        </span>

    </a>

    <!-- Announcement -->

    <a
        href="announcements.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-megaphone-fill"></i>

        <span>
            Announcement
        </span>

    </a>

    <!-- More -->

    <button
        type="button"
        class="mobile-nav-item"
        id="mobileMoreButton"
        aria-expanded="false"
        aria-controls="mobileMoreMenu"
    >

        <i class="bi bi-three-dots"></i>

        <span>
            More
        </span>

    </button>

</nav>

<!-- =========================================================
     MOBILE MORE MENU SCRIPT
     ========================================================= -->

<script>

(function () {

    'use strict';

    const mobileMoreButton =
        document.getElementById(
            'mobileMoreButton'
        );

    const mobileMoreMenu =
        document.getElementById(
            'mobileMoreMenu'
        );

    if (
        !mobileMoreButton ||
        !mobileMoreMenu
    ) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Open More Menu
    |--------------------------------------------------------------------------
    */

    function openMobileMoreMenu() {

        mobileMoreMenu.classList.add(
            'show'
        );

        mobileMoreMenu.setAttribute(
            'aria-hidden',
            'false'
        );

        mobileMoreButton.setAttribute(
            'aria-expanded',
            'true'
        );

        mobileMoreButton.classList.add(
            'active'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Close More Menu
    |--------------------------------------------------------------------------
    */

    function closeMobileMoreMenu() {

        mobileMoreMenu.classList.remove(
            'show'
        );

        mobileMoreMenu.setAttribute(
            'aria-hidden',
            'true'
        );

        mobileMoreButton.setAttribute(
            'aria-expanded',
            'false'
        );

        mobileMoreButton.classList.remove(
            'active'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle More Menu
    |--------------------------------------------------------------------------
    */

    function toggleMobileMoreMenu(event) {

        if (event) {

            event.preventDefault();

            event.stopPropagation();
        }

        const isOpen =
            mobileMoreMenu.classList.contains(
                'show'
            );

        if (isOpen) {

            closeMobileMoreMenu();

        } else {

            openMobileMoreMenu();

        }
    }

    /*
    |--------------------------------------------------------------------------
    | More Button
    |--------------------------------------------------------------------------
    */

    mobileMoreButton.addEventListener(
        'click',
        toggleMobileMoreMenu,
        false
    );

    /*
    |--------------------------------------------------------------------------
    | Prevent Menu Click From Bubbling
    |--------------------------------------------------------------------------
    */

    mobileMoreMenu.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

        },
        false
    );

    /*
    |--------------------------------------------------------------------------
    | Close When Clicking Outside
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'click',
        function (event) {

            const target =
                event.target;

            if (
                target instanceof Node &&
                (
                    mobileMoreMenu.contains(target) ||
                    mobileMoreButton.contains(target)
                )
            ) {
                return;
            }

            closeMobileMoreMenu();

        },
        false
    );

    /*
    |--------------------------------------------------------------------------
    | Close After Selecting Menu Item
    |--------------------------------------------------------------------------
    */

    const moreLinks =
        mobileMoreMenu.querySelectorAll(
            '.mobile-more-item'
        );

    moreLinks.forEach(
        function (link) {

            link.addEventListener(
                'click',
                function () {

                    closeMobileMoreMenu();

                },
                false
            );

        }
    );

    /*
    |--------------------------------------------------------------------------
    | Escape Key
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Escape') {

                closeMobileMoreMenu();

            }

        },
        false
    );

})();

</script>

</body>

</html>