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

$calendarPath =
    __DIR__ .
    '/../../includes/EthiopianCalendar.php';

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

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

$teacherUserId =
    (int) $_SESSION['user_id'];

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

        $ethiopianToday =
            date('d M Y');
    }
}

/*
|--------------------------------------------------------------------------
| Get Teacher Name
|--------------------------------------------------------------------------
*/

$teacherName = 'Teacher';

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

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $teacherName =
            (string) $row['full_name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Material ID
|--------------------------------------------------------------------------
*/

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    !$materialId ||
    $materialId < 1
) {

    header(
        'Location: ../materials.php'
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Material
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The material must belong to the logged-in teacher.
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

    $result =
        $stmt->get_result();

    $material =
        $result->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Material Not Found
|--------------------------------------------------------------------------
*/

if (!$material) {

    header(
        'Location: ../materials.php?error=' .
        urlencode(
            'Material not found or you do not have permission to delete it.'
        )
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Generate Delete Form Token
|--------------------------------------------------------------------------
*/

if (
    !isset(
        $_SESSION['material_delete_token']
    )
) {

    $_SESSION['material_delete_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$formToken =
    (string)
    $_SESSION['material_delete_token'];

/*
|--------------------------------------------------------------------------
| Handle Delete
|--------------------------------------------------------------------------
*/

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Validate Form Token
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string) (
            $_POST['form_token'] ?? ''
        );

    if (
        !hash_equals(
            (string)
            $_SESSION['material_delete_token'],
            $submittedToken
        )
    ) {

        $errors[] =
            'Invalid form submission. Please try again.';
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Delete
    |--------------------------------------------------------------------------
    */

    $confirmation =
        (string) (
            $_POST['confirm_delete'] ?? ''
        );

    if (
        $confirmation !== '1'
    ) {

        $errors[] =
            'Please confirm that you want to delete this material.';
    }

    /*
    |--------------------------------------------------------------------------
    | Continue Delete
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        /*
        |--------------------------------------------------------------------------
        | Normalize File Path
        |--------------------------------------------------------------------------
        */

        $filePath =
            trim(
                (string) (
                    $material['file_path'] ?? ''
                )
            );

        $normalizedRelativePath =
            str_replace(
                '\\',
                '/',
                $filePath
            );

        $normalizedRelativePath =
            preg_replace(
                '#/+#',
                '/',
                $normalizedRelativePath
            );

        $normalizedRelativePath =
            ltrim(
                (string)
                $normalizedRelativePath,
                '/'
            );

        /*
        |--------------------------------------------------------------------------
        | Security Check
        |--------------------------------------------------------------------------
        |
        | Only files inside:
        |
        | BKHS/uploads/materials/
        |
        | can be deleted.
        |
        */

        $isSafePath =
            str_starts_with(
                strtolower(
                    $normalizedRelativePath
                ),
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

        if (!$isSafePath) {

            $errors[] =
                'The material file path is invalid. The database record was not deleted.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Database Record
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        /*
        |--------------------------------------------------------------------------
        | Soft Delete
        |--------------------------------------------------------------------------
        |
        | We keep the database record but make it inactive.
        |
        */

        $stmt = $conn->prepare("
            UPDATE teacher_materials
            SET
                is_active = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND teacher_user_id = ?
              AND is_active = 1
            LIMIT 1
        ");

        if (!$stmt) {

            $errors[] =
                'The material could not be deleted. Please try again.';

        } else {

            $stmt->bind_param(
                'ii',
                $materialId,
                $teacherUserId
            );

            $deleted =
                $stmt->execute();

            $affectedRows =
                $stmt->affected_rows;

            $stmt->close();

            if (
                !$deleted ||
                $affectedRows < 1
            ) {

                $errors[] =
                    'The material could not be deleted. It may have already been deleted.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Physical File
    |--------------------------------------------------------------------------
    |
    | Only remove the physical file after the database
    | record has successfully been marked inactive.
    |
    */

    if (
        empty($errors)
    ) {

        $physicalFilePath =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $normalizedRelativePath
            );

        /*
        |--------------------------------------------------------------------------
        | Resolve Real Paths For Security
        |--------------------------------------------------------------------------
        */

        $realFilePath =
            realpath(
                $physicalFilePath
            );

        $materialsDirectory =
            realpath(
                dirname(__DIR__, 2) .
                DIRECTORY_SEPARATOR .
                'uploads' .
                DIRECTORY_SEPARATOR .
                'materials'
            );

        /*
        |--------------------------------------------------------------------------
        | Delete Only If Inside Materials Directory
        |--------------------------------------------------------------------------
        */

        if (
            $realFilePath !== false &&
            $materialsDirectory !== false &&
            str_starts_with(
                strtolower(
                    $realFilePath
                ),
                strtolower(
                    $materialsDirectory .
                    DIRECTORY_SEPARATOR
                )
            )
        ) {

            if (
                is_file(
                    $realFilePath
                )
            ) {

                @unlink(
                    $realFilePath
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Regenerate Token
        |--------------------------------------------------------------------------
        */

        $_SESSION['material_delete_token'] =
            bin2hex(
                random_bytes(32)
            );

        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */

        header(
            'Location: ../materials.php?success=' .
            urlencode(
                'Material deleted successfully.'
            )
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Regenerate Token After Error
    |--------------------------------------------------------------------------
    */

    $_SESSION['material_delete_token'] =
        bin2hex(
            random_bytes(32)
        );

    $formToken =
        (string)
        $_SESSION['material_delete_token'];
}

/*
|--------------------------------------------------------------------------
| File Information
|--------------------------------------------------------------------------
*/

$fileExtension =
    strtolower(
        pathinfo(
            (string)
            $material['file_name'],
            PATHINFO_EXTENSION
        )
    );

$fileSize =
    formatFileSize(
        isset(
            $material['file_size']
        )
            ? (int)
                $material['file_size']
            : null
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

    <title>
        Delete Material | BKHS Teacher Portal
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
        href="https://fonts.googleapis.com"
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
            --danger: #dc2626;
            --danger-dark: #b91c1c;
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

            max-width: 900px;

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

        /*
        |--------------------------------------------------------------------------
        | Delete Card
        |--------------------------------------------------------------------------
        */

        .delete-card {

            background: #fff;

            border:
                1px solid
                var(--border);

            border-radius: 16px;

            box-shadow:
                0 4px 18px
                rgba(15,23,42,.05);

            overflow: hidden;

        }

        .delete-header {

            padding: 26px;

            border-bottom:
                1px solid
                var(--border);

            text-align: center;

        }

        .delete-icon {

            width: 64px;
            height: 64px;

            margin:
                0 auto 15px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                #fee2e2;

            color:
                var(--danger);

            font-size: 27px;

        }

        .delete-header h2 {

            font-size: 19px;

            font-weight: 700;

            margin-bottom: 7px;

        }

        .delete-header p {

            margin: 0;

            color:
                var(--muted);

            font-size: 13px;

            line-height: 1.6;

        }

        .delete-body {

            padding: 26px;

        }

        /*
        |--------------------------------------------------------------------------
        | Material Information
        |--------------------------------------------------------------------------
        */

        .material-box {

            border:
                1px solid
                var(--border);

            background:
                #f8fafc;

            border-radius: 13px;

            padding: 17px;

            margin-bottom: 22px;

        }

        .material-file {

            display: flex;

            align-items: center;

            gap: 13px;

            margin-bottom: 17px;

        }

        .material-file-icon {

            width: 48px;
            height: 48px;

            border-radius: 11px;

            background:
                #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 22px;

            flex-shrink: 0;

        }

        .material-file-name {

            font-size: 13px;

            font-weight: 600;

            word-break: break-all;

        }

        .material-file-size {

            color:
                var(--muted);

            font-size: 11px;

            margin-top: 3px;

        }

        .material-details {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 12px;

        }

        .detail {

            background: #fff;

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            padding: 12px;

        }

        .detail-label {

            font-size: 10px;

            color:
                var(--muted);

            margin-bottom: 4px;

            text-transform: uppercase;

            letter-spacing: .04em;

            font-weight: 600;

        }

        .detail-value {

            font-size: 12px;

            font-weight: 600;

            color:
                var(--text);

        }

        /*
        |--------------------------------------------------------------------------
        | Warning
        |--------------------------------------------------------------------------
        */

        .warning-box {

            display: flex;

            align-items: flex-start;

            gap: 11px;

            background:
                #fff7ed;

            border:
                1px solid
                #fed7aa;

            color:
                #9a3412;

            border-radius: 11px;

            padding: 14px;

            font-size: 12px;

            line-height: 1.6;

            margin-bottom: 22px;

        }

        .warning-box i {

            font-size: 17px;

            margin-top: 1px;

        }

        /*
        |--------------------------------------------------------------------------
        | Alert
        |--------------------------------------------------------------------------
        */

        .alert {

            border-radius: 11px;

            font-size: 12px;

        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .action-buttons {

            display: flex;

            justify-content: flex-end;

            gap: 9px;

        }

        .btn {

            border-radius: 10px;

            font-size: 13px;

            font-weight: 600;

            padding:
                10px 17px;

        }

        .btn-light {

            background: #fff;

            border:
                1px solid
                var(--border);

        }

        .btn-light:hover {

            background:
                #f8fafc;

        }

        .btn-danger {

            background:
                var(--danger);

            border-color:
                var(--danger);

        }

        .btn-danger:hover {

            background:
                var(--danger-dark);

            border-color:
                var(--danger-dark);

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

                color:
                    #6b7280;

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

            .delete-header {

                padding:
                    22px 18px;

            }

            .delete-body {

                padding:
                    18px;

            }

            .material-details {

                grid-template-columns:
                    1fr;

            }

            .action-buttons {

                flex-direction: column-reverse;

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

            <i
                class="bi bi-box-arrow-right"
            ></i>

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
                    Delete Material
                </h1>

                <div class="page-subtitle">
                    Remove an uploaded teaching material
                </div>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">

                <i
                    class="bi bi-calendar3 me-1"
                ></i>

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
            href="view.php?id=<?= (int) $materialId ?>"
            class="back-link"
        >

            <i class="bi bi-arrow-left"></i>

            Back to Material

        </a>

        <div class="delete-card">

            <!-- Header -->

            <div class="delete-header">

                <div class="delete-icon">

                    <i
                        class="bi bi-trash3-fill"
                    ></i>

                </div>

                <h2>
                    Delete this material?
                </h2>

                <p>

                    You are about to remove this
                    teaching material from your
                    Materials list.

                </p>

            </div>

            <!-- Body -->

            <div class="delete-body">

                <?php if (!empty($errors)): ?>

                    <div
                        class="
                            alert
                            alert-danger
                            d-flex
                            align-items-start
                            gap-2
                        "
                    >

                        <i
                            class="
                                bi
                                bi-exclamation-triangle-fill
                                mt-1
                            "
                        ></i>

                        <div>

                            <?php foreach (
                                $errors as $error
                            ): ?>

                                <div>
                                    <?= e($error) ?>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endif; ?>

                <!-- Material -->

                <div class="material-box">

                    <div class="material-file">

                        <div
                            class="material-file-icon"
                        >

                            <i
                                class="
                                    bi
                                    <?= e(
                                        materialIcon(
                                            $fileExtension
                                        )
                                    ) ?>
                                "
                            ></i>

                        </div>

                        <div>

                            <div
                                class="material-file-name"
                            >

                                <?= e(
                                    (string)
                                    $material['file_name']
                                ) ?>

                            </div>

                            <div
                                class="material-file-size"
                            >

                                <?= e($fileSize) ?>

                                <?php if (
                                    $fileExtension !== ''
                                ): ?>

                                    <span class="mx-1">
                                        •
                                    </span>

                                    <?= e(
                                        strtoupper(
                                            $fileExtension
                                        )
                                    ) ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                    <div class="material-details">

                        <div class="detail">

                            <div
                                class="detail-label"
                            >
                                Title
                            </div>

                            <div
                                class="detail-value"
                            >

                                <?= e(
                                    (string)
                                    $material['title']
                                ) ?>

                            </div>

                        </div>

                        <div class="detail">

                            <div
                                class="detail-label"
                            >
                                Grade
                            </div>

                            <div
                                class="detail-value"
                            >

                                Grade
                                <?= (int)
                                    $material['grade'] ?>

                            </div>

                        </div>

                        <div class="detail">

                            <div
                                class="detail-label"
                            >
                                Subject
                            </div>

                            <div
                                class="detail-value"
                            >

                                <?= e(
                                    (string)
                                    $material['subject_name']
                                ) ?>

                            </div>

                        </div>

                        <div class="detail">

                            <div
                                class="detail-label"
                            >
                                Academic Year
                            </div>

                            <div
                                class="detail-value"
                            >

                                <?= e(
                                    (string)
                                    $material['academic_year']
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Warning -->

                <div class="warning-box">

                    <i
                        class="
                            bi
                            bi-exclamation-triangle-fill
                        "
                    ></i>

                    <div>

                        <strong>
                            Please confirm carefully.
                        </strong>

                        <br>

                        The material will be removed
                        from your active Materials list
                        and its uploaded file will be
                        removed from the server.

                    </div>

                </div>

                <!-- Delete Form -->

                <form
                    method="POST"
                    id="deleteForm"
                >

                    <input
                        type="hidden"
                        name="form_token"
                        value="<?= e($formToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="confirm_delete"
                        value="1"
                    >

                    <div
                        class="
                            action-buttons
                        "
                    >

                        <a
                            href="view.php?id=<?= (int) $materialId ?>"
                            class="btn btn-light"
                        >

                            <i
                                class="
                                    bi
                                    bi-x-lg
                                    me-1
                                "
                            ></i>

                            Cancel

                        </a>

                        <button
                            type="submit"
                            class="btn btn-danger"
                            id="deleteButton"
                        >

                            <i
                                class="
                                    bi
                                    bi-trash3
                                    me-1
                                "
                            ></i>

                            Delete Material

                        </button>

                    </div>

                </form>

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

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    function openSidebar() {

        document
            .getElementById('sidebar')
            .classList
            .add('show');

        document
            .getElementById('sidebarOverlay')
            .classList
            .add('show');

        document.body.style.overflow =
            'hidden';
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

        document.body.style.overflow =
            '';
    }

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 991) {

                closeSidebar();

            }

        }
    );

    /*
    |--------------------------------------------------------------------------
    | Delete Confirmation
    |--------------------------------------------------------------------------
    */

    const deleteForm =
        document.getElementById(
            'deleteForm'
        );

    const deleteButton =
        document.getElementById(
            'deleteButton'
        );

    if (
        deleteForm &&
        deleteButton
    ) {

        deleteForm.addEventListener(
            'submit',
            function (event) {

                const confirmed =
                    window.confirm(
                        'Are you sure you want to delete this material?'
                    );

                if (!confirmed) {

                    event.preventDefault();

                    return;
                }

                deleteButton.disabled =
                    true;

                deleteButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
                    'Deleting...';

            }
        );

    }

</script>

</body>

</html>