<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$calendarPath = __DIR__ . '/../../includes/EthiopianCalendar.php';

if (is_file($calendarPath)) {
    require_once $calendarPath;
}

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

function formatFileSize(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return 'Unknown size';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format(
            $bytes / 1024,
            1
        ) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format(
            $bytes / (1024 * 1024),
            1
        ) . ' MB';
    }

    return number_format(
        $bytes / (1024 * 1024 * 1024),
        1
    ) . ' GB';
}

function materialIcon(string $extension): string
{
    return match (strtolower($extension)) {

        'pdf' =>
            'bi-file-earmark-pdf-fill',

        'doc',
        'docx' =>
            'bi-file-earmark-word-fill',

        'ppt',
        'pptx' =>
            'bi-file-earmark-ppt-fill',

        'xls',
        'xlsx' =>
            'bi-file-earmark-excel-fill',

        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp' =>
            'bi-file-earmark-image-fill',

        'zip' =>
            'bi-file-earmark-zip-fill',

        default =>
            'bi-file-earmark-fill'
    };
}

function isPreviewable(string $extension): bool
{
    return in_array(
        strtolower($extension),
        [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp'
        ],
        true
    );
}

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

$teacherUserId = (int) $_SESSION['user_id'];

$teacherName = 'Teacher';

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$ethiopianToday = date('d M Y');

if (class_exists('EthiopianCalendar')) {

    try {

        $ethiopianToday =
            EthiopianCalendar::todayFormatted('en');

    } catch (Throwable $e) {

        $ethiopianToday = date('d M Y');
    }
}

/*
|--------------------------------------------------------------------------
| Get Teacher Name
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT full_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $teacherUserId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $teacherName =
            (string) $row['full_name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Material ID
|--------------------------------------------------------------------------
*/

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$materialId || $materialId < 1) {

    header('Location: ../materials.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get Material
|--------------------------------------------------------------------------
|
| A teacher can only view their own active material.
|
*/

$material = null;

$stmt = $conn->prepare("
    SELECT
        tm.id,
        tm.teacher_user_id,
        tm.grade_subject_id,
        tm.academic_year,
        tm.title,
        tm.description,
        tm.file_name,
        tm.file_path,
        tm.file_type,
        tm.file_size,
        tm.created_at,
        tm.updated_at,
        gs.grade,
        gs.subject_name
    FROM teacher_materials tm
    INNER JOIN grade_subjects gs
        ON gs.id = tm.grade_subject_id
    WHERE tm.id = ?
      AND tm.teacher_user_id = ?
      AND tm.is_active = 1
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'ii',
        $materialId,
        $teacherUserId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $material = $result->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Material Not Found
|--------------------------------------------------------------------------
*/

if (!$material) {

    http_response_code(404);

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
            Material Not Found | BKHS
        </title>
       <link
        rel="icon"
        type="image/webp"
        href="../teacher/public/image/logo.webp"
    >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet"
        >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
            rel="stylesheet"
        >

        <style>

            body {
                background: #f8fafc;
                font-family:
                    Inter,
                    system-ui,
                    sans-serif;
            }

            .error-card {
                max-width: 520px;
                margin: 100px auto;
                padding: 40px;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 18px;
                text-align: center;
                box-shadow:
                    0 10px 30px
                    rgba(15, 23, 42, .06);
            }

            .error-icon {
                width: 70px;
                height: 70px;
                margin: 0 auto 20px;
                border-radius: 50%;
                background: #fee2e2;
                color: #dc2626;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 30px;
            }

        </style>

    </head>

    <body>

        <div class="error-card">

            <div class="error-icon">

                <i class="bi bi-file-earmark-x"></i>

            </div>

            <h4 class="fw-bold mb-2">
                Material Not Found
            </h4>

            <p class="text-muted mb-4">

                The requested material does not exist
                or you do not have permission to view it.

            </p>

            <a
                href="../materials.php"
                class="btn btn-primary"
            >

                <i class="bi bi-arrow-left me-1"></i>

                Back to Materials

            </a>

        </div>

    </body>

    </html>

    <?php

    exit;
}

/*
|--------------------------------------------------------------------------
| File Information
|--------------------------------------------------------------------------
*/

$filePath = trim(
    (string) ($material['file_path'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Normalize Database Path
|--------------------------------------------------------------------------
|
| Database stores:
|
| uploads/materials/filename.pdf
|
*/

$normalizedRelativePath = str_replace(
    '\\',
    '/',
    $filePath
);

$normalizedRelativePath = preg_replace(
    '#/+#',
    '/',
    $normalizedRelativePath
);

$normalizedRelativePath = ltrim(
    (string) $normalizedRelativePath,
    '/'
);

/*
|--------------------------------------------------------------------------
| Security Check
|--------------------------------------------------------------------------
|
| Uploaded materials must always be inside:
|
| BKHS/uploads/materials/
|
*/

$isValidMaterialPath =
    str_starts_with(
        strtolower($normalizedRelativePath),
        'uploads/materials/'
    ) &&
    !str_contains(
        $normalizedRelativePath,
        '../'
    ) &&
    !str_contains(
        $normalizedRelativePath,
        '..\\'
    );

/*
|--------------------------------------------------------------------------
| Resolve Physical Server Path
|--------------------------------------------------------------------------
|
| Current file:
|
| BKHS/teacher/materials/view.php
|
| BKHS root:
|
| dirname(__DIR__, 2)
|
| Therefore:
|
| BKHS/uploads/materials/filename.pdf
|
*/

$physicalFilePath = '';

$physicalFileExists = false;

if ($isValidMaterialPath) {

    $physicalFilePath =
        dirname(__DIR__, 2) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $normalizedRelativePath
        );

    $physicalFileExists = is_file(
        $physicalFilePath
    );
}

/*
|--------------------------------------------------------------------------
| Browser URL
|--------------------------------------------------------------------------
|
| From:
|
| BKHS/teacher/materials/view.php
|
| To:
|
| BKHS/uploads/materials/filename.pdf
|
*/

$fileUrl =
    '../../' .
    $normalizedRelativePath;

/*
|--------------------------------------------------------------------------
| File Extension
|--------------------------------------------------------------------------
*/

$extension = strtolower(
    pathinfo(
        (string) $material['file_name'],
        PATHINFO_EXTENSION
    )
);

/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

$previewable = isPreviewable(
    $extension
);

/*
|--------------------------------------------------------------------------
| Ethiopian Created / Updated Dates
|--------------------------------------------------------------------------
*/

$createdAt = '';

$updatedAt = '';

/*
|--------------------------------------------------------------------------
| Created Date
|--------------------------------------------------------------------------
*/

if (!empty($material['created_at'])) {

    try {

        $createdDate =
            new DateTimeImmutable(
                (string) $material['created_at'],
                new DateTimeZone('Africa/Addis_Ababa')
            );

        if (class_exists('EthiopianCalendar')) {

            $createdEthiopian =
                EthiopianCalendar::fromGregorian(
                    $createdDate->format('Y-m-d')
                );

            $createdAt =
                EthiopianCalendar::format(
                    $createdEthiopian['year'],
                    $createdEthiopian['month'],
                    $createdEthiopian['day'],
                    'en'
                );

        } else {

            $createdAt =
                $createdDate->format('d M Y');
        }

    } catch (Throwable $e) {

        $createdAt = '';
    }
}

/*
|--------------------------------------------------------------------------
| Updated Date
|--------------------------------------------------------------------------
*/

if (!empty($material['updated_at'])) {

    try {

        $updatedDate =
            new DateTimeImmutable(
                (string) $material['updated_at'],
                new DateTimeZone('Africa/Addis_Ababa')
            );

        if (class_exists('EthiopianCalendar')) {

            $updatedEthiopian =
                EthiopianCalendar::fromGregorian(
                    $updatedDate->format('Y-m-d')
                );

            $updatedAt =
                EthiopianCalendar::format(
                    $updatedEthiopian['year'],
                    $updatedEthiopian['month'],
                    $updatedEthiopian['day'],
                    'en'
                );

        } else {

            $updatedAt =
                $updatedDate->format('d M Y');
        }

    } catch (Throwable $e) {

        $updatedAt = '';
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
        <?= e((string) $material['title']) ?>
        | BKHS Teacher Portal
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {

            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;

        }

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            background:
                var(--background);

            color:
                var(--text);

            font-family:
                'Inter',
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

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

            background:
                var(--sidebar);

            color: #fff;

            z-index: 1050;

            overflow-y: auto;

            transition:
                transform .25s ease;

        }

        .brand {

            height: 76px;

            display: flex;

            align-items: center;

            padding:
                0 22px;

            border-bottom:
                1px solid
                rgba(255,255,255,.08);

        }

        .brand-icon {

            width: 40px;
            height: 40px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                var(--primary);

            margin-right: 12px;

            font-size: 20px;

        }

        .brand-title {

            font-size: 16px;

            font-weight: 700;

            line-height: 1.2;

        }

        .brand-subtitle {

            font-size: 11px;

            color: #9ca3af;

            margin-top: 2px;

        }

        .sidebar-section {

            padding:
                20px 14px 8px;

            color: #6b7280;

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: .08em;

            font-weight: 700;

        }

        .sidebar-nav {

            padding:
                8px 12px 20px;

        }

        .sidebar-nav a {

            display: flex;

            align-items: center;

            gap: 12px;

            padding:
                11px 13px;

            margin-bottom: 4px;

            color: #d1d5db;

            text-decoration: none;

            border-radius: 9px;

            font-size: 13px;

            font-weight: 500;

            transition:
                .2s ease;

        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {

            color: #fff;

            background:
                var(--sidebar-hover);

        }

        .sidebar-nav a.active {

            background:
                var(--primary);

        }

        .sidebar-nav i {

            font-size: 17px;

            width: 20px;

            text-align: center;

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

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {

            height: 76px;

            background: #fff;

            border-bottom:
                1px solid
                var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 28px;

            position: sticky;

            top: 0;

            z-index: 1000;

        }

        .page-title {

            font-size: 18px;

            font-weight: 700;

            margin: 0;

        }

        .page-subtitle {

            color:
                var(--muted);

            font-size: 12px;

            margin-top: 3px;

        }

        .topbar-right {

            display: flex;

            align-items: center;

            gap: 16px;

        }

        .ethiopian-date {

            color:
                var(--muted);

            font-size: 12px;

        }

        .teacher-avatar {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #dbeafe;

            color:
                var(--primary);

            font-weight: 700;

        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {

            padding: 28px;

        }

        .back-link {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            text-decoration: none;

            color:
                var(--muted);

            font-size: 13px;

            margin-bottom: 18px;

        }

        .back-link:hover {

            color:
                var(--primary);

        }

        .card {

            border:
                1px solid
                var(--border);

            border-radius: 16px;

            box-shadow:
                0 4px 16px
                rgba(15, 23, 42, .04);

        }

        /*
        |--------------------------------------------------------------------------
        | Material Header
        |--------------------------------------------------------------------------
        */

        .material-header {

            background: #fff;

            padding: 24px;

            border-bottom:
                1px solid
                var(--border);

            border-radius:
                16px 16px 0 0;

        }

        .file-icon {

            width: 58px;
            height: 58px;

            border-radius: 14px;

            background: #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 28px;

            flex-shrink: 0;

        }

        .material-title {

            font-size: 20px;

            font-weight: 700;

            margin:
                0 0 6px;

            word-break: break-word;

        }

        .material-file-name {

            font-size: 12px;

            color:
                var(--muted);

            word-break: break-all;

        }

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        .meta-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            border-bottom:
                1px solid
                var(--border);

        }

        .meta-item {

            padding:
                18px 20px;

            border-right:
                1px solid
                var(--border);

        }

        .meta-item:last-child {

            border-right: 0;

        }

        .meta-label {

            font-size: 11px;

            color:
                var(--muted);

            margin-bottom: 5px;

        }

        .meta-value {

            font-size: 13px;

            font-weight: 600;

        }

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        .description {

            padding: 24px;

        }

        .description-title {

            font-size: 13px;

            font-weight: 700;

            margin-bottom: 9px;

        }

        .description-text {

            color: #4b5563;

            font-size: 13px;

            line-height: 1.7;

            white-space: pre-wrap;

        }

        /*
        |--------------------------------------------------------------------------
        | Preview
        |--------------------------------------------------------------------------
        */

        .preview-container {

            margin:
                24px 24px 24px;

            border:
                1px solid
                var(--border);

            border-radius: 14px;

            overflow: hidden;

            background: #f1f5f9;

        }

        .preview-label {

            background: #fff;

            border-bottom:
                1px solid
                var(--border);

            padding:
                13px 16px;

            font-size: 12px;

            font-weight: 700;

        }

        .pdf-preview {

            width: 100%;

            height: 720px;

            border: 0;

            display: block;

            background: #fff;

        }

        .image-preview {

            display: block;

            width: 100%;

            max-height: 720px;

            object-fit: contain;

            background: #f8fafc;

        }

        .no-preview {

            padding:
                70px 25px;

            text-align: center;

            color:
                var(--muted);

        }

        .no-preview-icon {

            font-size: 50px;

            margin-bottom: 15px;

            color: #94a3b8;

        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn-primary {

            background:
                var(--primary);

            border-color:
                var(--primary);

            border-radius: 10px;

            padding:
                10px 18px;

            font-size: 13px;

            font-weight: 600;

        }

        .btn-primary:hover {

            background:
                var(--primary-dark);

            border-color:
                var(--primary-dark);

        }

        .btn-light {

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            font-size: 13px;

            font-weight: 500;

        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Menu
        |--------------------------------------------------------------------------
        */

        .mobile-menu-button {

            display: none;

            border: 0;

            background: transparent;

            font-size: 24px;

            color:
                var(--text);

        }

        .sidebar-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(0,0,0,.45);

            z-index: 1040;

        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .bottom-nav {

            display: none;

        }

        /*
        |--------------------------------------------------------------------------
        | Tablet
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .sidebar {

                transform:
                    translateX(-100%);

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

                padding-bottom: 76px;

            }

            .mobile-menu-button {

                display: inline-block;

            }

            .topbar {

                padding:
                    0 16px;

            }

            .content {

                padding:
                    20px 16px;

            }

            .ethiopian-date {

                display: none;

            }

            .bottom-nav {

                position: fixed;

                display: grid;

                grid-template-columns:
                    repeat(5, 1fr);

                bottom: 0;

                left: 0;

                right: 0;

                height: 68px;

                background: #fff;

                border-top:
                    1px solid
                    var(--border);

                z-index: 1030;

                box-shadow:
                    0 -4px 15px
                    rgba(15,23,42,.06);

            }

            .bottom-nav a {

                display: flex;

                flex-direction: column;

                align-items: center;

                justify-content: center;

                gap: 3px;

                text-decoration: none;

                color: #6b7280;

                font-size: 10px;

                font-weight: 500;

            }

            .bottom-nav a i {

                font-size: 18px;

            }

            .bottom-nav a.active {

                color:
                    var(--primary);

            }

            .meta-grid {

                grid-template-columns:
                    repeat(2, 1fr);

            }

            .meta-item:nth-child(2) {

                border-right: 0;

            }

            .meta-item:nth-child(-n+2) {

                border-bottom:
                    1px solid
                    var(--border);

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 575.98px) {

            .topbar {

                height: 68px;

            }

            .page-title {

                font-size: 16px;

            }

            .page-subtitle {

                display: none;

            }

            .teacher-avatar {

                width: 36px;

                height: 36px;

            }

            .material-header {

                padding: 18px;

            }

            .material-title {

                font-size: 17px;

            }

            .file-icon {

                width: 48px;

                height: 48px;

                font-size: 22px;

            }

            .meta-grid {

                grid-template-columns: 1fr;

            }

            .meta-item {

                border-right: 0 !important;

                border-bottom:
                    1px solid
                    var(--border);

            }

            .meta-item:last-child {

                border-bottom: 0;

            }

            .description {

                padding: 18px;

            }

            .preview-container {

                margin:
                    18px 18px 18px;

            }

            .pdf-preview {

                height: 560px;

            }

            .image-preview {

                max-height: 500px;

            }

            .action-buttons {

                display: flex;

                flex-direction: column;

                gap: 8px !important;

            }

            .action-buttons .btn {

                width: 100%;

            }

        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeSidebar()"
></div>

<!-- Sidebar -->

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
                BKHS Teacher
            </div>

            <div class="brand-subtitle">
                Teacher Portal
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="../dashboard.php">

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>

        <a href="../subjects.php">

            <i class="bi bi-book-fill"></i>

            <span>
                Subjects
            </span>

        </a>

        <a href="../classes.php">

            <i class="bi bi-people-fill"></i>

            <span>
                Classes
            </span>

        </a>

        <a href="../result.php">

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Result
            </span>

        </a>

        <a href="../daily-attendance.php">

            <i class="bi bi-calendar-check-fill"></i>

            <span>
                Daily Attendance
            </span>

        </a>

        <a href="../homework.php">

            <i class="bi bi-journal-text"></i>

            <span>
                Homework
            </span>

        </a>

        <a
            href="../materials.php"
            class="active"
        >

            <i class="bi bi-folder-fill"></i>

            <span>
                Materials
            </span>

        </a>

        <a href="../announcement.php">

            <i class="bi bi-megaphone-fill"></i>

            <span>
                Announcement
            </span>

        </a>

        <a href="../roster.php">

            <i class="bi bi-card-list"></i>

            <span>
                Roster
            </span>

        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="../profile.php">

            <i class="bi bi-person-circle"></i>

            <span>
                Profile
            </span>

        </a>

        <a href="../../auth/logout.php">

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </nav>

</aside>

<!-- Main -->

<div class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-button"
                onclick="openSidebar()"
                aria-label="Open menu"
            >

                <i class="bi bi-list"></i>

            </button>

            <div>

                <h1 class="page-title">
                    Material Details
                </h1>

                <div class="page-subtitle">
                    View and access your uploaded material
                </div>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">

                <i class="bi bi-calendar3 me-1"></i>

                <?= e($ethiopianToday) ?>

            </div>

            <div
                class="teacher-avatar"
                title="<?= e($teacherName) ?>"
            >

                <?= e(
                    strtoupper(
                        mb_substr(
                            $teacherName,
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

        </div>

    </header>

    <!-- Content -->

    <main class="content">

        <a
            href="../materials.php"
            class="back-link"
        >

            <i class="bi bi-arrow-left"></i>

            Back to Materials

        </a>

        <div class="card">

            <!-- Material Header -->

            <div class="material-header">

                <div class="d-flex align-items-center gap-3">

                    <div class="file-icon">

                        <i
                            class="bi <?= e(
                                materialIcon($extension)
                            ) ?>"
                        ></i>

                    </div>

                    <div class="flex-grow-1">

                        <h1 class="material-title">

                            <?= e(
                                (string) $material['title']
                            ) ?>

                        </h1>

                        <div class="material-file-name">

                            <i
                                class="bi bi-paperclip me-1"
                            ></i>

                            <?= e(
                                (string) $material['file_name']
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Metadata -->

            <div class="meta-grid">

                <div class="meta-item">

                    <div class="meta-label">
                        Grade
                    </div>

                    <div class="meta-value">

                        Grade
                        <?= (int) $material['grade'] ?>

                    </div>

                </div>

                <div class="meta-item">

                    <div class="meta-label">
                        Subject
                    </div>

                    <div class="meta-value">

                        <?= e(
                            (string) $material['subject_name']
                        ) ?>

                    </div>

                </div>

                <div class="meta-item">

                    <div class="meta-label">
                        Academic Year
                    </div>

                    <div class="meta-value">

                        <?= e(
                            (string) $material['academic_year']
                        ) ?>

                    </div>

                </div>

                <div class="meta-item">

                    <div class="meta-label">
                        File Size
                    </div>

                    <div class="meta-value">

                        <?= e(
                            formatFileSize(
                                isset(
                                    $material['file_size']
                                )
                                    ? (int)
                                        $material['file_size']
                                    : null
                            )
                        ) ?>

                    </div>

                </div>

            </div>

            <!-- Description -->

            <?php if (
                trim(
                    (string)
                    $material['description']
                ) !== ''
            ): ?>

                <div class="description">

                    <div class="description-title">
                        Description
                    </div>

                    <div class="description-text">

                        <?= e(
                            (string)
                            $material['description']
                        ) ?>

                    </div>

                </div>

            <?php endif; ?>

            <!-- Preview -->

            <?php if (
                $physicalFileExists &&
                $previewable
            ): ?>

                <div class="preview-container">

                    <div class="preview-label">

                        <i class="bi bi-eye me-1"></i>

                        File Preview

                    </div>

                    <?php if ($extension === 'pdf'): ?>

                        <iframe
                            src="<?= e($fileUrl) ?>"
                            class="pdf-preview"
                            title="PDF Preview"
                        ></iframe>

                    <?php else: ?>

                        <img
                            src="<?= e($fileUrl) ?>"
                            alt="<?= e(
                                (string)
                                $material['title']
                            ) ?>"
                            class="image-preview"
                        >

                    <?php endif; ?>

                </div>

            <?php elseif ($physicalFileExists): ?>

                <div class="preview-container">

                    <div class="no-preview">

                        <div class="no-preview-icon">

                            <i
                                class="bi <?= e(
                                    materialIcon(
                                        $extension
                                    )
                                ) ?>"
                            ></i>

                        </div>

                        <h6 class="fw-bold">
                            Preview not available
                        </h6>

                        <p class="small mb-0">

                            This file type cannot be
                            previewed directly in the browser.

                        </p>

                    </div>

                </div>

            <?php endif; ?>

            <!-- Actions -->

            <div class="px-4 pb-4">

                <div
                    class="
                        d-flex
                        justify-content-between
                        align-items-center
                        gap-3
                        action-buttons
                    "
                >

                    <div class="small text-muted">

                        <?php if ($createdAt !== ''): ?>

                            <i
                                class="bi bi-calendar3 me-1"
                            ></i>

                            Uploaded:
                            <?= e($createdAt) ?>

                        <?php endif; ?>

                        <?php if (
                            $updatedAt !== '' &&
                            $updatedAt !== $createdAt
                        ): ?>

                            <span class="mx-2">
                                •
                            </span>

                            Updated:
                            <?= e($updatedAt) ?>

                        <?php endif; ?>

                    </div>

                    <div class="d-flex gap-2">

                        <a
                            href="../materials.php"
                            class="btn btn-light"
                        >

                            <i
                                class="bi bi-arrow-left me-1"
                            ></i>

                            Back

                        </a>

                        <?php if ($physicalFileExists): ?>

                            <a
                                href="<?= e($fileUrl) ?>"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-primary"
                            >

                                <i
                                    class="bi bi-download me-1"
                                ></i>

                                Download / Open

                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<!-- Mobile Bottom Navigation -->

<nav class="bottom-nav">

    <a href="../daily-attendance.php">

        <i
            class="bi bi-calendar-check-fill"
        ></i>

        <span>
            Attendance
        </span>

    </a>

    <a href="../homework.php">

        <i
            class="bi bi-journal-text"
        ></i>

        <span>
            Homework
        </span>

    </a>

    <a href="../result.php">

        <i
            class="bi bi-bar-chart-fill"
        ></i>

        <span>
            Result
        </span>

    </a>

    <a href="../announcement.php">

        <i
            class="bi bi-megaphone-fill"
        ></i>

        <span>
            Announce
        </span>

    </a>

    <a
        href="#"
        onclick="openSidebar(); return false;"
    >

        <i
            class="bi bi-three-dots"
        ></i>

        <span>
            More
        </span>

    </a>

</nav>

<script>

    function openSidebar() {

        document
            .getElementById('sidebar')
            .classList
            .add('show');

        document
            .getElementById('sidebarOverlay')
            .classList
            .add('show');

        document.body.style.overflow = 'hidden';

    }

    function closeSidebar() {

        document
            .getElementById('sidebar')
            .classList
            .remove('show');

        document
            .getElementById('sidebarOverlay')
            .classList
            .remove('show');

        document.body.style.overflow = '';

    }

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 991) {

                closeSidebar();

            }

        }
    );

</script>

</body>
</html>