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
    header('Location: ../../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

$ethiopianCalendarFile = '../../includes/EthiopianCalendar.php';

if (is_file($ethiopianCalendarFile)) {
    require_once $ethiopianCalendarFile;
}

/*
|--------------------------------------------------------------------------
| Helper Functions
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
        return '—';
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

function materialIcon(
    ?string $fileType,
    ?string $fileName = null
): string {
    $type = strtolower((string) $fileType);
    $name = strtolower((string) $fileName);

    $extension = '';

    if ($name !== '' && str_contains($name, '.')) {
        $extension = strtolower(
            (string) pathinfo(
                $name,
                PATHINFO_EXTENSION
            )
        );
    }

    if (
        str_contains($type, 'pdf') ||
        $extension === 'pdf'
    ) {
        return 'bi-file-earmark-pdf';
    }

    if (
        str_contains($type, 'word') ||
        in_array(
            $extension,
            ['doc', 'docx'],
            true
        )
    ) {
        return 'bi-file-earmark-word';
    }

    if (
        str_contains($type, 'presentation') ||
        str_contains($type, 'powerpoint') ||
        in_array(
            $extension,
            ['ppt', 'pptx'],
            true
        )
    ) {
        return 'bi-file-earmark-ppt';
    }

    if (
        str_contains($type, 'spreadsheet') ||
        str_contains($type, 'excel') ||
        in_array(
            $extension,
            ['xls', 'xlsx'],
            true
        )
    ) {
        return 'bi-file-earmark-excel';
    }

    if (
        str_contains($type, 'image') ||
        in_array(
            $extension,
            [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp'
            ],
            true
        )
    ) {
        return 'bi-file-earmark-image';
    }

    if ($extension === 'zip') {
        return 'bi-file-earmark-zip';
    }

    return 'bi-file-earmark';
}

function formatEthiopianDate(
    ?string $dateTime
): string {
    if (!$dateTime) {
        return '—';
    }

    try {
        $date = new DateTimeImmutable(
            $dateTime,
            new DateTimeZone(
                'Africa/Addis_Ababa'
            )
        );

        if (
            class_exists('EthiopianCalendar') &&
            method_exists(
                'EthiopianCalendar',
                'fromGregorian'
            ) &&
            method_exists(
                'EthiopianCalendar',
                'format'
            )
        ) {
            $ethiopian =
                EthiopianCalendar::fromGregorian(
                    $date->format('Y-m-d')
                );

            return EthiopianCalendar::format(
                (int) $ethiopian['year'],
                (int) $ethiopian['month'],
                (int) $ethiopian['day'],
                'en'
            );
        }

        return $date->format('d M Y');

    } catch (Throwable $e) {

        $timestamp =
            strtotime($dateTime);

        return $timestamp !== false
            ? date('d M Y', $timestamp)
            : '—';
    }
}

/*
|--------------------------------------------------------------------------
| Material ID
|--------------------------------------------------------------------------
*/

$materialId =
    isset($_GET['id'])
        ? (int) $_GET['id']
        : 0;

if ($materialId <= 0) {
    header('Location: ../materials.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Student Account
|--------------------------------------------------------------------------
*/

$studentName =
    (string) (
        $_SESSION['full_name'] ??
        'Student'
    );

$userStmt = $conn->prepare("
    SELECT
        id,
        full_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$userStmt) {
    die('Unable to load student account.');
}

$userStmt->bind_param(
    'i',
    $userId
);

$userStmt->execute();

$userResult =
    $userStmt->get_result();

$user =
    $userResult->fetch_assoc();

$userStmt->close();

if ($user) {
    $studentName =
        (string) (
            $user['full_name'] ??
            $studentName
        );
}

/*
|--------------------------------------------------------------------------
| Find Student Record
|--------------------------------------------------------------------------
*/

$studentStmt = $conn->prepare("
    SELECT
        id
    FROM students
    WHERE user_id = ?
    LIMIT 1
");

if (!$studentStmt) {
    die('Unable to load student information.');
}

$studentStmt->bind_param(
    'i',
    $userId
);

$studentStmt->execute();

$studentResult =
    $studentStmt->get_result();

$student =
    $studentResult->fetch_assoc();

$studentStmt->close();

if (!$student) {
    header('Location: ../materials.php');
    exit;
}

$studentId =
    (int) $student['id'];

/*
|--------------------------------------------------------------------------
| Current Active Registration
|--------------------------------------------------------------------------
|
| Correct columns:
| grades.grade_number
| sections.name
|--------------------------------------------------------------------------
*/

$registrationStmt = $conn->prepare("
    SELECT
        sr.id,
        sr.academic_year_id,
        sr.grade_id,
        sr.section_id,
        ay.name AS academic_year,
        g.grade_number AS grade,
        sec.name AS section
    FROM student_registrations sr

    INNER JOIN academic_years ay
        ON ay.id = sr.academic_year_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    WHERE sr.student_id = ?
      AND ay.status = 'Active'

    ORDER BY sr.id DESC

    LIMIT 1
");

if (!$registrationStmt) {
    die('Unable to load student registration.');
}

$registrationStmt->bind_param(
    'i',
    $studentId
);

$registrationStmt->execute();

$registrationResult =
    $registrationStmt->get_result();

$registration =
    $registrationResult->fetch_assoc();

$registrationStmt->close();

if (!$registration) {
    header('Location: ../materials.php');
    exit;
}

$academicYear =
    (string) (
        $registration['academic_year'] ??
        ''
    );

$studentGrade =
    (int) (
        $registration['grade'] ??
        0
    );

$studentSection =
    (string) (
        $registration['section'] ??
        ''
    );

/*
|--------------------------------------------------------------------------
| Load Material
|--------------------------------------------------------------------------
|
| The material must:
|
| 1. Be active
| 2. Belong to the current academic year
| 3. Belong to the student's current grade
|
|--------------------------------------------------------------------------
*/

$materialStmt = $conn->prepare("
    SELECT
        tm.id,
        tm.title,
        tm.description,
        tm.file_name,
        tm.file_path,
        tm.file_type,
        tm.file_size,
        tm.created_at,
        tm.updated_at,

        gs.id AS grade_subject_id,
        gs.subject_name,
        gs.grade

    FROM teacher_materials tm

    INNER JOIN grade_subjects gs
        ON gs.id = tm.grade_subject_id

    WHERE tm.id = ?
      AND tm.academic_year = ?
      AND tm.is_active = 1
      AND gs.grade = ?

    LIMIT 1
");

if (!$materialStmt) {
    die('Unable to load learning material.');
}

$materialStmt->bind_param(
    'isi',
    $materialId,
    $academicYear,
    $studentGrade
);

$materialStmt->execute();

$materialResult =
    $materialStmt->get_result();

$material =
    $materialResult->fetch_assoc();

$materialStmt->close();

if (!$material) {
    header('Location: ../materials.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Material Values
|--------------------------------------------------------------------------
*/

$title =
    (string) (
        $material['title'] ??
        'Learning Material'
    );

$description =
    trim(
        (string) (
            $material['description'] ??
            ''
        )
    );

$fileName =
    (string) (
        $material['file_name'] ??
        ''
    );

$filePath =
    (string) (
        $material['file_path'] ??
        ''
    );

$fileType =
    (string) (
        $material['file_type'] ??
        ''
    );

$fileSize =
    formatFileSize(
        isset($material['file_size'])
            ? (int) $material['file_size']
            : null
    );

$subjectName =
    (string) (
        $material['subject_name'] ??
        ''
    );

$materialGrade =
    (int) (
        $material['grade'] ??
        $studentGrade
    );

$uploadedDate =
    formatEthiopianDate(
        (string) (
            $material['created_at'] ??
            ''
        )
    );

$updatedDate =
    formatEthiopianDate(
        (string) (
            $material['updated_at'] ??
            ''
        )
    );

$icon =
    materialIcon(
        $fileType,
        $fileName
    );

/*
|--------------------------------------------------------------------------
| File URL
|--------------------------------------------------------------------------
|
| teacher_materials.file_path normally contains:
|
| uploads/materials/example.pdf
|
| From:
| student/materials/view.php
|
| ../../uploads/materials/example.pdf
|
|--------------------------------------------------------------------------
*/

$normalizedPath =
    str_replace(
        '\\',
        '/',
        trim($filePath)
    );

$normalizedPath =
    ltrim(
        $normalizedPath,
        '/'
    );

/*
|--------------------------------------------------------------------------
| Prevent unsafe path traversal
|--------------------------------------------------------------------------
*/

$normalizedPath =
    preg_replace(
        '#\.\.+/#',
        '',
        $normalizedPath
    );

$projectRoot =
    realpath(
        dirname(__DIR__, 2)
    );

$fileAbsolutePath = false;

if (
    $projectRoot !== false &&
    $normalizedPath !== ''
) {
    $candidate =
        $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $normalizedPath
        );

    $realCandidate =
        realpath($candidate);

    if (
        $realCandidate !== false &&
        is_file($realCandidate)
    ) {
        $rootWithSeparator =
            rtrim(
                $projectRoot,
                DIRECTORY_SEPARATOR
            ) .
            DIRECTORY_SEPARATOR;

        if (
            str_starts_with(
                $realCandidate,
                $rootWithSeparator
            )
        ) {
            $fileAbsolutePath =
                $realCandidate;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Browser File URL
|--------------------------------------------------------------------------
*/

$fileUrl = '';

if ($normalizedPath !== '') {
    $fileUrl =
        '../../' .
        $normalizedPath;
}

/*
|--------------------------------------------------------------------------
| Detect Preview Type
|--------------------------------------------------------------------------
*/

$extension =
    strtolower(
        (string) pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );

$isPdf =
    $extension === 'pdf' ||
    str_contains(
        strtolower($fileType),
        'pdf'
    );

$isImage =
    in_array(
        $extension,
        [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp'
        ],
        true
    ) ||
    str_contains(
        strtolower($fileType),
        'image'
    );

/*
|--------------------------------------------------------------------------
| Ethiopian Today
|--------------------------------------------------------------------------
*/

$ethiopianToday =
    date('d M Y');

if (class_exists('EthiopianCalendar')) {

    try {

        $ethiopianToday =
            EthiopianCalendar::todayFormatted(
                'en'
            );

    } catch (Throwable $e) {

        $ethiopianToday =
            date('d M Y');
    }
}

/*
|--------------------------------------------------------------------------
| Avatar Initial
|--------------------------------------------------------------------------
*/

$avatarInitial =
    strtoupper(
        substr(
            trim($studentName),
            0,
            1
        )
    );

if ($avatarInitial === '') {
    $avatarInitial = 'S';
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
        <?= e($title) ?> | BKHS Student
    </title>

    <!-- Favicon -->

    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >

    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Inter -->

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --page-bg: #f8fafc;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --mobile-nav-height: 68px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           Sidebar
        ===================================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 72px;
            padding: 0 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,.07);
        }

        .sidebar-logo {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            object-fit: cover;
            background: #fff;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 700;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            overflow-y: auto;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 8px 12px;
            margin-bottom: 5px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            padding: 11px 13px;
            border-radius: 9px;
            margin-bottom: 3px;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            font-size: 17px;
            text-align: center;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        /* =====================================================
           Main
        ===================================================== */

        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: 72px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .topbar-date {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .student-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .student-user-name {
            font-size: 13px;
            font-weight: 600;
        }

        .student-user-role {
            font-size: 11px;
            color: var(--muted);
        }

        .content {
            padding: 28px;
        }

        /* =====================================================
           Page Header
        ===================================================== */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }

        .page-description {
            color: var(--muted);
            margin: 6px 0 0;
            font-size: 13px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 13px;
            border: 1px solid #dbe2ea;
            border-radius: 8px;
            background: #fff;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .back-button:hover {
            color: var(--primary);
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        /* =====================================================
           Material Card
        ===================================================== */

        .material-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .material-header {
            padding: 22px;
            border-bottom: 1px solid var(--border);
        }

        .material-header-top {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .large-file-icon {
            width: 52px;
            height: 52px;
            flex: 0 0 52px;
            border-radius: 12px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .material-main-title {
            font-size: 19px;
            font-weight: 700;
            line-height: 1.4;
            margin: 0;
            word-break: break-word;
        }

        .material-subject {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .material-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 7px;
            color: #64748b;
            font-size: 10px;
            font-weight: 500;
        }

        .meta-item i {
            color: var(--primary);
        }

        /* =====================================================
           Description
        ===================================================== */

        .description-section {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .section-label {
            color: #374151;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .description-text {
            color: #64748b;
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
            white-space: pre-line;
        }

        .no-description {
            color: #94a3b8;
            font-size: 12px;
            font-style: italic;
            margin: 0;
        }

        /* =====================================================
           File Section
        ===================================================== */

        .file-section {
            padding: 20px 22px;
        }

        .file-information {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            background: #fafcff;
            margin-bottom: 18px;
        }

        .small-file-icon {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 8px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .file-name {
            color: #374151;
            font-size: 12px;
            font-weight: 600;
            word-break: break-word;
        }

        .file-details {
            color: #94a3b8;
            font-size: 10px;
            margin-top: 3px;
        }

        .file-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 20px;
        }

        .file-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .preview-button {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
        }

        .preview-button:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .download-button {
            background: #fff;
            border: 1px solid #dbe2ea;
            color: #475569;
        }

        .download-button:hover {
            background: #f8fafc;
            color: var(--primary);
            border-color: #bfdbfe;
        }

        /* =====================================================
           Preview
        ===================================================== */

        .preview-container {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: #f1f5f9;
        }

        .preview-title {
            padding: 11px 14px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            color: #374151;
            font-size: 12px;
            font-weight: 700;
        }

        .pdf-preview {
            width: 100%;
            height: 700px;
            display: block;
            border: 0;
            background: #fff;
        }

        .image-preview-wrapper {
            padding: 20px;
            text-align: center;
        }

        .image-preview {
            max-width: 100%;
            max-height: 700px;
            object-fit: contain;
            border-radius: 6px;
        }

        .unsupported-preview {
            padding: 45px 20px;
            text-align: center;
            color: #64748b;
        }

        .unsupported-preview i {
            font-size: 40px;
            color: #94a3b8;
            display: block;
            margin-bottom: 10px;
        }

        .unsupported-preview strong {
            display: block;
            color: #374151;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .unsupported-preview span {
            font-size: 11px;
        }

        /* =====================================================
           Mobile Menu
        ===================================================== */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1030;
        }

        .mobile-menu-button {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            color: #374151;
            padding: 0;
        }

        /* =====================================================
           Mobile Bottom Navigation
        ===================================================== */

        .mobile-bottom-nav {
            display: none;
        }

        /* =====================================================
           Tablet
        ===================================================== */

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

            .main-wrapper {
                margin-left: 0;
            }

            .mobile-menu-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                height: 64px;
                padding: 0 18px;
            }

            .topbar-title {
                font-size: 16px;
            }

            .student-user-name,
            .student-user-role {
                display: none;
            }

            .content {
                padding: 20px;
            }

            .page-title {
                font-size: 21px;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                left: 0;
                right: 0;
                bottom: 0;
                height: var(--mobile-nav-height);
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1020;
                padding: 7px 8px;
                padding-bottom: calc(
                    7px + env(safe-area-inset-bottom)
                );
                box-shadow:
                    0 -4px 16px rgba(15,23,42,.06);
            }

            .mobile-bottom-link {
                flex: 1;
                min-width: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #64748b;
                border-radius: 8px;
                font-size: 9px;
                font-weight: 600;
            }

            .mobile-bottom-link i {
                font-size: 19px;
                line-height: 1;
            }

            .mobile-bottom-link.active {
                color: var(--primary);
            }

        }

        /* =====================================================
           Small Screen
        ===================================================== */

        @media (max-width: 575.98px) {

            body {
                padding-bottom: var(--mobile-nav-height);
            }

            .content {
                padding: 14px;
            }

            .topbar {
                padding: 0 14px;
            }

            .page-header {
                margin-bottom: 14px;
            }

            .page-title {
                font-size: 19px;
            }

            .page-description {
                font-size: 11px;
            }

            .back-button {
                min-height: 34px;
                padding: 0 10px;
                font-size: 11px;
            }

            .material-header {
                padding: 16px;
            }

            .material-header-top {
                gap: 11px;
            }

            .large-file-icon {
                width: 44px;
                height: 44px;
                flex-basis: 44px;
                border-radius: 9px;
                font-size: 20px;
            }

            .material-main-title {
                font-size: 16px;
            }

            .material-subject {
                font-size: 10px;
            }

            .material-meta {
                margin-top: 13px;
                gap: 6px;
            }

            .meta-item {
                font-size: 9px;
                padding: 5px 7px;
            }

            .description-section,
            .file-section {
                padding: 16px;
            }

            .description-text {
                font-size: 12px;
            }

            .file-information {
                padding: 10px;
            }

            .file-actions {
                margin-bottom: 15px;
            }

            .file-action {
                flex: 1;
                min-height: 36px;
                padding: 0 10px;
                font-size: 11px;
            }

            .pdf-preview {
                height: 520px;
            }

            .image-preview-wrapper {
                padding: 12px;
            }

            .mobile-bottom-link {
                font-size: 8px;
            }

            .mobile-bottom-link i {
                font-size: 18px;
            }

        }

    </style>

</head>

<body>

<!-- ============================================================
     Sidebar
============================================================ -->

<aside
    class="sidebar"
    id="studentSidebar"
>

    <div class="sidebar-brand">

        <img
            src="../../public/image/logo.webp"
            alt="BKHS"
            class="sidebar-logo"
        >

        <div>

            <div class="brand-title">
                BKHS Student
            </div>

            <div class="brand-subtitle">
                Student Portal
            </div>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main Menu
        </div>

        <a
            href="../dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="../subjects.php"
            class="sidebar-link"
        >
            <i class="bi bi-journal-bookmark"></i>
            <span>Subjects</span>
        </a>

        <a
            href="../materials.php"
            class="sidebar-link active"
        >
            <i class="bi bi-folder2-open"></i>
            <span>Materials</span>
        </a>

        <a
            href="../result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Result</span>
        </a>

        <a
            href="../attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check"></i>
            <span>Attendance</span>
        </a>

        <a
            href="../homework.php"
            class="sidebar-link"
        >
            <i class="bi bi-pencil-square"></i>
            <span>Homework</span>
        </a>

        <a
            href="../announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <div class="nav-section-title mt-3">
            Account
        </div>

        <a
            href="../profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person"></i>
            <span>Profile</span>
        </a>

        <a
            href="../../auth/logout.php"
            class="sidebar-link"
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

<!-- ============================================================
     Main
============================================================ -->

<div class="main-wrapper">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="topbar-title">
                    Material
                </h1>

                <div class="topbar-date">
                    <?= e($ethiopianToday) ?>
                </div>

            </div>

        </div>

        <div class="student-user">

            <div class="student-avatar">
                <?= e($avatarInitial) ?>
            </div>

            <div>

                <div class="student-user-name">
                    <?= e($studentName) ?>
                </div>

                <div class="student-user-role">
                    Student
                </div>

            </div>

        </div>

    </header>

    <!-- ========================================================
         Content
    ========================================================= -->

    <main class="content">

        <div class="page-header">

            <div>

                <h2 class="page-title">
                    Learning Material
                </h2>

                <p class="page-description">
                    View and access your learning material.
                </p>

            </div>

            <a
                href="../materials.php"
                class="back-button"
            >
                <i class="bi bi-arrow-left"></i>
                <span>Back to Materials</span>
            </a>

        </div>

        <!-- ====================================================
             Material
        ==================================================== -->

        <div class="material-card">

            <!-- Material Header -->

            <div class="material-header">

                <div class="material-header-top">

                    <div class="large-file-icon">

                        <i
                            class="bi <?= e($icon) ?>"
                        ></i>

                    </div>

                    <div class="flex-grow-1">

                        <h3 class="material-main-title">
                            <?= e($title) ?>
                        </h3>

                        <div class="material-subject">

                            <i class="bi bi-journal-bookmark me-1"></i>

                            <?= e($subjectName) ?>

                        </div>

                    </div>

                </div>

                <div class="material-meta">

                    <span class="meta-item">

                        <i class="bi bi-mortarboard"></i>

                        Grade
                        <?= e(
                            (string) $materialGrade
                        ) ?>

                    </span>

                    <span class="meta-item">

                        <i class="bi bi-people"></i>

                        Section
                        <?= e($studentSection) ?>

                    </span>

                    <span class="meta-item">

                        <i class="bi bi-calendar3"></i>

                        Academic Year
                        <?= e($academicYear) ?>

                    </span>

                    <span class="meta-item">

                        <i class="bi bi-clock"></i>

                        <?= e($uploadedDate) ?>

                    </span>

                </div>

            </div>

            <!-- Description -->

            <div class="description-section">

                <div class="section-label">
                    Description
                </div>

                <?php if ($description !== ''): ?>

                    <p class="description-text">
                        <?= e($description) ?>
                    </p>

                <?php else: ?>

                    <p class="no-description">
                        No description was provided for this material.
                    </p>

                <?php endif; ?>

            </div>

            <!-- File -->

            <div class="file-section">

                <div class="section-label">
                    File
                </div>

                <div class="file-information">

                    <div class="small-file-icon">

                        <i
                            class="bi <?= e($icon) ?>"
                        ></i>

                    </div>

                    <div class="flex-grow-1">

                        <div class="file-name">
                            <?= e($fileName) ?>
                        </div>

                        <div class="file-details">

                            <?= e($fileSize) ?>

                            <?php if ($fileType !== ''): ?>

                                <span class="mx-1">•</span>

                                <?= e($fileType) ?>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <?php if (
                    $fileAbsolutePath !== false &&
                    $fileUrl !== ''
                ): ?>

                    <div class="file-actions">

                        <?php if (
                            $isPdf ||
                            $isImage
                        ): ?>

                            <a
                                href="<?= e($fileUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="file-action preview-button"
                            >
                                <i class="bi bi-eye"></i>
                                Preview
                            </a>

                        <?php endif; ?>

                        <a
                            href="<?= e($fileUrl) ?>"
                            download="<?= e($fileName) ?>"
                            class="file-action download-button"
                        >
                            <i class="bi bi-download"></i>
                            Download
                        </a>

                    </div>

                    <!-- Preview -->

                    <?php if ($isPdf): ?>

                        <div class="preview-container">

                            <div class="preview-title">

                                <i class="bi bi-file-earmark-pdf me-1"></i>

                                PDF Preview

                            </div>

                            <iframe
                                src="<?= e($fileUrl) ?>"
                                class="pdf-preview"
                                title="<?= e($title) ?>"
                            ></iframe>

                        </div>

                    <?php elseif ($isImage): ?>

                        <div class="preview-container">

                            <div class="preview-title">

                                <i class="bi bi-image me-1"></i>

                                Image Preview

                            </div>

                            <div class="image-preview-wrapper">

                                <img
                                    src="<?= e($fileUrl) ?>"
                                    alt="<?= e($title) ?>"
                                    class="image-preview"
                                >

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="preview-container">

                            <div class="unsupported-preview">

                                <i class="bi bi-file-earmark"></i>

                                <strong>
                                    Preview is not available
                                </strong>

                                <span>
                                    Download the file to open it on your device.
                                </span>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="preview-container">

                        <div class="unsupported-preview">

                            <i class="bi bi-file-earmark-x"></i>

                            <strong>
                                File is not available
                            </strong>

                            <span>
                                The material file could not be found.
                            </span>

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

<!-- ============================================================
     Mobile Bottom Navigation
============================================================ -->

<nav
    class="mobile-bottom-nav"
    aria-label="Student mobile navigation"
>

    <a
        href="../dashboard.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-grid-1x2"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="../subjects.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-journal-bookmark"></i>
        <span>Subjects</span>
    </a>

    <a
        href="../materials.php"
        class="mobile-bottom-link active"
        aria-current="page"
    >
        <i class="bi bi-folder2-open"></i>
        <span>Materials</span>
    </a>

    <a
        href="../result.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Result</span>
    </a>

    <a
        href="../attendance.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-calendar-check"></i>
        <span>Attendance</span>
    </a>

</nav>

<!-- ============================================================
     Mobile Sidebar JavaScript
============================================================ -->

<script>

    const sidebar =
        document.getElementById(
            'studentSidebar'
        );

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    const mobileMenuButton =
        document.getElementById(
            'mobileMenuButton'
        );

    function openSidebar() {

        if (sidebar) {
            sidebar.classList.add('show');
        }

        if (overlay) {
            overlay.classList.add('show');
        }

        document.body.style.overflow =
            'hidden';
    }

    function closeSidebar() {

        if (sidebar) {
            sidebar.classList.remove('show');
        }

        if (overlay) {
            overlay.classList.remove('show');
        }

        document.body.style.overflow =
            '';
    }

    if (mobileMenuButton) {

        mobileMenuButton.addEventListener(
            'click',
            openSidebar
        );

    }

    if (overlay) {

        overlay.addEventListener(
            'click',
            closeSidebar
        );

    }

    document
        .querySelectorAll('.sidebar-link')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <=
                        991.98
                    ) {
                        closeSidebar();
                    }

                }
            );

        });

    window.addEventListener(
        'resize',
        function () {

            if (
                window.innerWidth >
                991.98
            ) {
                closeSidebar();
            }

        }
    );

</script>

</body>

</html>

