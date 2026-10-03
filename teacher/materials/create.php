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
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Session / Basic Data
|--------------------------------------------------------------------------
*/

$teacherUserId = (int) $_SESSION['user_id'];

$teacherName = 'Teacher';
$activeAcademicYear = '';
$assignments = [];
$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$ethiopianToday = date('d M Y');

if (class_exists('EthiopianCalendar')) {
    try {
        $ethiopianToday = EthiopianCalendar::todayFormatted('en');
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
    $stmt->bind_param('i', $teacherUserId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $teacherName = (string) $row['full_name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $activeAcademicYear = (string) $row['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Teacher Assignments
|--------------------------------------------------------------------------
*/

if ($activeAcademicYear !== '') {

    $stmt = $conn->prepare("
        SELECT
            sta.id AS assignment_id,
            sta.grade,
            sta.section,
            sta.academic_year,
            gs.id AS grade_subject_id,
            gs.subject_name
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
    ");

    if ($stmt) {
        $stmt->bind_param(
            'is',
            $teacherUserId,
            $activeAcademicYear
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Handle Upload
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $assignmentId = filter_input(
        INPUT_POST,
        'assignment_id',
        FILTER_VALIDATE_INT
    );

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | Validate Basic Fields
    |--------------------------------------------------------------------------
    */

    if (!$activeAcademicYear) {
        $error = 'There is no active academic year.';
    } elseif (!$assignmentId || $assignmentId < 1) {
        $error = 'Please select a class and subject.';
    } elseif ($title === '') {
        $error = 'Please enter the material title.';
    } elseif (mb_strlen($title) > 255) {
        $error = 'The material title is too long.';
    } elseif (
        !isset($_FILES['material_file']) ||
        !is_array($_FILES['material_file'])
    ) {
        $error = 'Please select a file.';
    } else {

        $uploadedFile = $_FILES['material_file'];

        /*
        |--------------------------------------------------------------------------
        | Upload Error
        |--------------------------------------------------------------------------
        */

        if (
            !isset($uploadedFile['error']) ||
            (int) $uploadedFile['error'] !== UPLOAD_ERR_OK
        ) {
            $uploadErrorCode = (int) ($uploadedFile['error'] ?? -1);

            switch ($uploadErrorCode) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error = 'The selected file is too large.';
                    break;

                case UPLOAD_ERR_PARTIAL:
                    $error = 'The file upload was incomplete.';
                    break;

                case UPLOAD_ERR_NO_FILE:
                    $error = 'Please select a file.';
                    break;

                default:
                    $error = 'The file could not be uploaded.';
                    break;
            }
        } else {

            /*
            |--------------------------------------------------------------------------
            | Validate Temporary File
            |--------------------------------------------------------------------------
            */

            $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
            $originalName = (string) ($uploadedFile['name'] ?? '');
            $fileSize = (int) ($uploadedFile['size'] ?? 0);

            if (
                $tmpName === '' ||
                !is_uploaded_file($tmpName)
            ) {
                $error = 'Invalid uploaded file.';
            } else {

                /*
                |--------------------------------------------------------------------------
                | File Extension
                |--------------------------------------------------------------------------
                */

                $extension = strtolower(
                    pathinfo($originalName, PATHINFO_EXTENSION)
                );

                $allowedExtensions = [
                    'pdf',
                    'doc',
                    'docx',
                    'ppt',
                    'pptx',
                    'xls',
                    'xlsx',
                    'jpg',
                    'jpeg',
                    'png',
                    'gif',
                    'webp',
                    'zip'
                ];

                if (!in_array($extension, $allowedExtensions, true)) {
                    $error =
                        'File type not allowed. Allowed types: PDF, Word, PowerPoint, Excel, images and ZIP.';
                }

                /*
                |--------------------------------------------------------------------------
                | Maximum File Size
                |--------------------------------------------------------------------------
                */

                $maxFileSize = 20 * 1024 * 1024; // 20 MB

                if (
                    $error === '' &&
                    $fileSize > $maxFileSize
                ) {
                    $error = 'The maximum allowed file size is 20 MB.';
                }

                /*
                |--------------------------------------------------------------------------
                | Verify Assignment Belongs To Teacher
                |--------------------------------------------------------------------------
                */

                $assignment = null;

                if ($error === '') {

                    $stmt = $conn->prepare("
                        SELECT
                            sta.id AS assignment_id,
                            sta.grade,
                            sta.section,
                            sta.academic_year,
                            gs.id AS grade_subject_id,
                            gs.subject_name
                        FROM subject_teacher_assignments sta
                        INNER JOIN grade_subjects gs
                            ON gs.id = sta.grade_subject_id
                        WHERE sta.id = ?
                          AND sta.teacher_user_id = ?
                          AND sta.academic_year = ?
                          AND sta.is_active = 1
                          AND gs.is_active = 1
                        LIMIT 1
                    ");

                    if (!$stmt) {
                        $error = 'Unable to verify the selected class and subject.';
                    } else {

                        $stmt->bind_param(
                            'iis',
                            $assignmentId,
                            $teacherUserId,
                            $activeAcademicYear
                        );

                        $stmt->execute();

                        $result = $stmt->get_result();

                        $assignment = $result->fetch_assoc();

                        $stmt->close();

                        if (!$assignment) {
                            $error =
                                'The selected class and subject assignment is not valid.';
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Save File
                |--------------------------------------------------------------------------
                */

                if ($error === '' && $assignment) {

                    /*
                    |--------------------------------------------------------------------------
                    | IMPORTANT:
                    | Physical upload directory:
                    |
                    | C:\xampp\htdocs\BKHS\uploads\materials\
                    |--------------------------------------------------------------------------
                    */

                    $uploadDirectory =
                        dirname(__DIR__, 2) .
                        DIRECTORY_SEPARATOR .
                        'uploads' .
                        DIRECTORY_SEPARATOR .
                        'materials';

                    /*
                    |--------------------------------------------------------------------------
                    | Create Directory If Needed
                    |--------------------------------------------------------------------------
                    */

                    if (!is_dir($uploadDirectory)) {

                        if (!mkdir($uploadDirectory, 0755, true)) {
                            $error =
                                'The materials upload directory could not be created.';
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Verify Directory Is Writable
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $error === '' &&
                        !is_writable($uploadDirectory)
                    ) {
                        $error =
                            'The materials upload directory is not writable.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Generate Safe Unique Filename
                    |--------------------------------------------------------------------------
                    */

                    if ($error === '') {

                        $safeFileName =
                            date('YmdHis') .
                            '_' .
                            $teacherUserId .
                            '_' .
                            bin2hex(random_bytes(12)) .
                            '.' .
                            $extension;

                        /*
                        |--------------------------------------------------------------------------
                        | Physical File Path
                        |--------------------------------------------------------------------------
                        */

                        $destinationPath =
                            $uploadDirectory .
                            DIRECTORY_SEPARATOR .
                            $safeFileName;

                        /*
                        |--------------------------------------------------------------------------
                        | Database Relative Path
                        |--------------------------------------------------------------------------
                        */

                        $databaseFilePath =
                            'uploads/materials/' .
                            $safeFileName;

                        /*
                        |--------------------------------------------------------------------------
                        | Detect MIME Type
                        |--------------------------------------------------------------------------
                        */

                        $fileType = '';

                        if (function_exists('finfo_open')) {

                            $finfo = finfo_open(FILEINFO_MIME_TYPE);

                            if ($finfo) {
                                $fileType =
                                    (string) finfo_file(
                                        $finfo,
                                        $tmpName
                                    );

                                finfo_close($finfo);
                            }
                        }

                        if ($fileType === '') {
                            $fileType =
                                (string) ($uploadedFile['type'] ?? '');
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Move Uploaded File
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !move_uploaded_file(
                                $tmpName,
                                $destinationPath
                            )
                        ) {

                            $error =
                                'The file could not be saved to the materials folder.';
                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | Verify File Was Actually Saved
                            |--------------------------------------------------------------------------
                            */

                            if (!is_file($destinationPath)) {

                                $error =
                                    'The file upload completed, but the server could not verify the saved file.';

                                @unlink($destinationPath);
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Insert Database Record
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $error === '' &&
                            is_file($destinationPath)
                        ) {

                            $stmt = $conn->prepare("
                                INSERT INTO teacher_materials (
                                    teacher_user_id,
                                    grade_subject_id,
                                    academic_year,
                                    title,
                                    description,
                                    file_name,
                                    file_path,
                                    file_type,
                                    file_size,
                                    is_active
                                )
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                            ");

                            if (!$stmt) {

                                @unlink($destinationPath);

                                $error =
                                    'The material could not be saved to the database.';
                            } else {

                                $gradeSubjectId =
                                    (int) $assignment['grade_subject_id'];

                                $stmt->bind_param(
                                    'iissssssi',
                                    $teacherUserId,
                                    $gradeSubjectId,
                                    $activeAcademicYear,
                                    $title,
                                    $description,
                                    $originalName,
                                    $databaseFilePath,
                                    $fileType,
                                    $fileSize
                                );

                                if ($stmt->execute()) {

                                    $stmt->close();

                                    /*
                                    |--------------------------------------------------------------------------
                                    | Success
                                    |--------------------------------------------------------------------------
                                    */

                                    header(
                                        'Location: ../materials.php?success=' .
                                        rawurlencode(
                                            'Material uploaded successfully.'
                                        )
                                    );

                                    exit;

                                } else {

                                    @unlink($destinationPath);

                                    $error =
                                        'The material could not be saved to the database.';

                                    $stmt->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success Message From Redirect
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['success']) &&
    trim((string) $_GET['success']) !== ''
) {
    $success = trim((string) $_GET['success']);
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

    <title>Upload Material | BKHS Teacher Portal</title>
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
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
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
            padding: 20px 14px 8px;
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 700;
        }

        .sidebar-nav {
            padding: 8px 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            color: #d1d5db;
            text-decoration: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: .2s ease;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .sidebar-nav a.active {
            background: var(--primary);
        }

        .sidebar-nav i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
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
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .ethiopian-date {
            color: var(--muted);
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
            color: var(--primary);
            font-weight: 700;
        }

        .content {
            padding: 28px;
            max-width: 1100px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 18px;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 20px 24px;
            border-radius: 16px 16px 0 0 !important;
        }

        .card-body {
            padding: 24px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-color: #dbe0e6;
            border-radius: 10px;
            font-size: 13px;
        }

        textarea.form-control {
            min-height: 120px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
        }

        .file-help {
            font-size: 11px;
            color: var(--muted);
            margin-top: 7px;
        }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 14px 16px;
            color: #1e40af;
            font-size: 12px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 10px;
            padding: 11px 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-light {
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
        }

        .mobile-menu-button {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
            color: var(--text);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        .bottom-nav {
            display: none;
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
                padding-bottom: 76px;
            }

            .mobile-menu-button {
                display: inline-block;
            }

            .topbar {
                padding: 0 16px;
            }

            .content {
                padding: 20px 16px;
            }

            .ethiopian-date {
                display: none;
            }

            .bottom-nav {
                position: fixed;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                bottom: 0;
                left: 0;
                right: 0;
                height: 68px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1030;
                box-shadow: 0 -4px 15px rgba(15,23,42,.06);
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
                color: var(--primary);
            }
        }

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

            .card-header,
            .card-body {
                padding: 18px;
            }

            .action-buttons {
                display: flex;
                flex-direction: column-reverse;
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
<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>
            <div class="brand-title">BKHS Teacher</div>
            <div class="brand-subtitle">Teacher Portal</div>
        </div>
    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="../dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="../subjects.php">
            <i class="bi bi-book-fill"></i>
            <span>Subjects</span>
        </a>

        <a href="../classes.php">
            <i class="bi bi-people-fill"></i>
            <span>Classes</span>
        </a>

        <a href="../result.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <a href="../daily-attendance.php">
            <i class="bi bi-calendar-check-fill"></i>
            <span>Daily Attendance</span>
        </a>

        <a href="../homework.php">
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a href="../materials.php" class="active">
            <i class="bi bi-folder-fill"></i>
            <span>Materials</span>
        </a>

        <a href="../announcement.php">
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <a href="../roster.php">
            <i class="bi bi-card-list"></i>
            <span>Roster</span>
        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="../profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
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
                    Upload Material
                </h1>

                <div class="page-subtitle">
                    Share learning materials with your students
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
                        mb_substr($teacherName, 0, 1)
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

        <?php if ($error !== ''): ?>

            <div
                class="alert alert-danger d-flex align-items-start gap-2"
                role="alert"
            >
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>

                <div>
                    <?= e($error) ?>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($success !== ''): ?>

            <div
                class="alert alert-success d-flex align-items-start gap-2"
                role="alert"
            >
                <i class="bi bi-check-circle-fill mt-1"></i>

                <div>
                    <?= e($success) ?>
                </div>
            </div>

        <?php endif; ?>

        <div class="card">

            <div class="card-header">

                <div class="d-flex align-items-center gap-3">

                    <div
                        class="rounded-3 d-flex align-items-center justify-content-center"
                        style="
                            width:46px;
                            height:46px;
                            background:#dbeafe;
                            color:#2563eb;
                        "
                    >
                        <i class="bi bi-cloud-arrow-up-fill fs-5"></i>
                    </div>

                    <div>
                        <h5 class="mb-1 fw-bold">
                            Upload Learning Material
                        </h5>

                        <div class="text-muted small">
                            Academic Year:
                            <strong>
                                <?= e(
                                    $activeAcademicYear !== ''
                                        ? $activeAcademicYear
                                        : 'Not available'
                                ) ?>
                            </strong>
                        </div>
                    </div>

                </div>

            </div>

            <div class="card-body">

                <?php if ($activeAcademicYear === ''): ?>

                    <div class="info-box">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        There is currently no active academic year.
                        Please contact the administrator.
                    </div>

                <?php elseif (count($assignments) === 0): ?>

                    <div class="info-box">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        You do not have any active subject assignments
                        for the current academic year.
                    </div>

                <?php else: ?>

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <div class="row g-4">

                            <!-- Assignment -->
                            <div class="col-12">

                                <label
                                    for="assignment_id"
                                    class="form-label"
                                >
                                    Class & Subject
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="assignment_id"
                                    id="assignment_id"
                                    class="form-select"
                                    required
                                >
                                    <option value="">
                                        Select class and subject
                                    </option>

                                    <?php foreach ($assignments as $assignment): ?>

                                        <option
                                            value="<?= (int) $assignment['assignment_id'] ?>"
                                            <?= (
                                                isset($_POST['assignment_id']) &&
                                                (int) $_POST['assignment_id'] ===
                                                (int) $assignment['assignment_id']
                                            )
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            Grade <?= (int) $assignment['grade'] ?>
                                            -
                                            Section <?= e((string) $assignment['section']) ?>
                                            —
                                            <?= e((string) $assignment['subject_name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- Title -->
                            <div class="col-12">

                                <label
                                    for="title"
                                    class="form-label"
                                >
                                    Material Title
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    id="title"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="e.g. Chapter 1 Mathematics Notes"
                                    value="<?= e(
                                        (string) ($_POST['title'] ?? '')
                                    ) ?>"
                                    required
                                >

                            </div>

                            <!-- Description -->
                            <div class="col-12">

                                <label
                                    for="description"
                                    class="form-label"
                                >
                                    Description
                                    <span class="text-muted fw-normal">
                                        (Optional)
                                    </span>
                                </label>

                                <textarea
                                    name="description"
                                    id="description"
                                    class="form-control"
                                    placeholder="Briefly describe this material..."
                                ><?= e(
                                    (string) ($_POST['description'] ?? '')
                                ) ?></textarea>

                            </div>

                            <!-- File -->
                            <div class="col-12">

                                <label
                                    for="material_file"
                                    class="form-label"
                                >
                                    File
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="file"
                                    name="material_file"
                                    id="material_file"
                                    class="form-control"
                                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.zip"
                                    required
                                >

                                <div class="file-help">
                                    Maximum size: <strong>20 MB</strong>.
                                    Allowed:
                                    PDF, Word, PowerPoint, Excel,
                                    JPG, PNG, GIF, WEBP and ZIP.
                                </div>

                            </div>

                            <!-- Info -->
                            <div class="col-12">

                                <div class="info-box">

                                    <i class="bi bi-shield-check me-1"></i>

                                    Your material will be stored securely
                                    in the BKHS materials folder and linked
                                    to the selected class and subject.

                                </div>

                            </div>

                        </div>

                        <div
                            class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top action-buttons"
                        >

                            <a
                                href="../materials.php"
                                class="btn btn-light px-4"
                            >
                                Cancel
                            </a>

                            <button
                                type="submit"
                                class="btn btn-primary px-4"
                            >
                                <i class="bi bi-cloud-arrow-up me-1"></i>
                                Upload Material
                            </button>

                        </div>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

<!-- Mobile Bottom Navigation -->
<nav class="bottom-nav">

    <a href="../daily-attendance.php">
        <i class="bi bi-calendar-check-fill"></i>
        <span>Attendance</span>
    </a>

    <a href="../homework.php">
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>

    <a href="../result.php">
        <i class="bi bi-bar-chart-fill"></i>
        <span>Result</span>
    </a>

    <a href="../announcement.php">
        <i class="bi bi-megaphone-fill"></i>
        <span>Announce</span>
    </a>

    <a
        href="#"
        onclick="openSidebar(); return false;"
    >
        <i class="bi bi-three-dots"></i>
        <span>More</span>
    </a>

</nav>

<script>
    function openSidebar() {
        document.getElementById('sidebar').classList.add('show');
        document.getElementById('sidebarOverlay').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('show');
        document.getElementById('sidebarOverlay').classList.remove('show');
        document.body.style.overflow = '';
    }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 991) {
            closeSidebar();
        }
    });
</script>

</body>
</html>