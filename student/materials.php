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

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

$ethiopianCalendarFile = '../includes/EthiopianCalendar.php';

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
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
        return number_format($bytes / 1024, 1) . ' KB';
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
        in_array($extension, ['doc', 'docx'], true)
    ) {
        return 'bi-file-earmark-word';
    }

    if (
        str_contains($type, 'presentation') ||
        str_contains($type, 'powerpoint') ||
        in_array($extension, ['ppt', 'pptx'], true)
    ) {
        return 'bi-file-earmark-ppt';
    }

    if (
        str_contains($type, 'spreadsheet') ||
        str_contains($type, 'excel') ||
        in_array($extension, ['xls', 'xlsx'], true)
    ) {
        return 'bi-file-earmark-excel';
    }

    if (
        str_contains($type, 'image') ||
        in_array(
            $extension,
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
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
            new DateTimeZone('Africa/Addis_Ababa')
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
        $timestamp = strtotime($dateTime);

        return $timestamp !== false
            ? date('d M Y', $timestamp)
            : '—';
    }
}

function pageUrl(int $page): string
{
    $query = $_GET;

    $query['page'] = $page;

    return 'materials.php?' .
        http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Student User
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

if ($user) {
    $studentName =
        (string) (
            $user['full_name'] ??
            $studentName
        );
}

$userStmt->close();

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
    die('Student record not found.');
}

$studentId =
    (int) $student['id'];

/*
|--------------------------------------------------------------------------
| Current Student Registration
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
        sr.student_id,
        sr.academic_year_id,
        sr.grade_id,
        sr.section_id,
        sr.registration_type,
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

/*
|--------------------------------------------------------------------------
| Registration Values
|--------------------------------------------------------------------------
*/

$academicYear = '';
$studentGrade = 0;
$studentSection = '';

if ($registration) {
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
}

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
| Subject Filter
|--------------------------------------------------------------------------
*/

$selectedSubject =
    isset($_GET['subject'])
        ? (int) $_GET['subject']
        : 0;

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$currentPage =
    isset($_GET['page'])
        ? max(
            1,
            (int) $_GET['page']
        )
        : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Subjects For Current Grade
|--------------------------------------------------------------------------
*/

$subjects = [];

if ($studentGrade > 0) {
    $subjectStmt = $conn->prepare("
        SELECT
            id,
            subject_name
        FROM grade_subjects
        WHERE grade = ?
          AND is_active = 1
        ORDER BY subject_name ASC
    ");

    if ($subjectStmt) {
        $subjectStmt->bind_param(
            'i',
            $studentGrade
        );

        $subjectStmt->execute();

        $subjectResult =
            $subjectStmt->get_result();

        while (
            $row =
            $subjectResult->fetch_assoc()
        ) {
            $subjects[] = $row;
        }

        $subjectStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Validate Selected Subject
|--------------------------------------------------------------------------
|
| Prevent manually entering a subject ID that does not belong
| to the student's current grade.
|--------------------------------------------------------------------------
*/

if ($selectedSubject > 0) {
    $validSubject = false;

    foreach ($subjects as $subject) {
        if (
            (int) $subject['id'] ===
            $selectedSubject
        ) {
            $validSubject = true;
            break;
        }
    }

    if (!$validSubject) {
        $selectedSubject = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Count Materials
|--------------------------------------------------------------------------
*/

$totalMaterials = 0;

if (
    $academicYear !== '' &&
    $studentGrade > 0
) {
    $countSql = "
        SELECT
            COUNT(*) AS total
        FROM teacher_materials tm

        INNER JOIN grade_subjects gs
            ON gs.id = tm.grade_subject_id

        WHERE tm.academic_year = ?
          AND tm.is_active = 1
          AND gs.grade = ?
    ";

    $countTypes = 'si';

    $countParams = [
        $academicYear,
        $studentGrade
    ];

    if ($selectedSubject > 0) {
        $countSql .= "
            AND tm.grade_subject_id = ?
        ";

        $countTypes .= 'i';

        $countParams[] =
            $selectedSubject;
    }

    $countStmt =
        $conn->prepare($countSql);

    if ($countStmt) {
        $bindValues = [
            $countTypes
        ];

        foreach (
            $countParams as &$value
        ) {
            $bindValues[] =
                &$value;
        }

        $countStmt->bind_param(
            ...$bindValues
        );

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        $countRow =
            $countResult->fetch_assoc();

        $totalMaterials =
            (int) (
                $countRow['total'] ??
                0
            );

        $countStmt->close();

        unset($value);
    }
}

/*
|--------------------------------------------------------------------------
| Pagination Calculation
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalMaterials /
        $perPage
    )
);

if ($currentPage > $totalPages) {
    $currentPage =
        $totalPages;
}

$offset =
    ($currentPage - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Get Materials
|--------------------------------------------------------------------------
*/

$materials = [];

if (
    $academicYear !== '' &&
    $studentGrade > 0 &&
    $totalMaterials > 0
) {
    $materialSql = "
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

        WHERE tm.academic_year = ?
          AND tm.is_active = 1
          AND gs.grade = ?
    ";

    $materialTypes = 'si';

    $materialParams = [
        $academicYear,
        $studentGrade
    ];

    if ($selectedSubject > 0) {
        $materialSql .= "
            AND tm.grade_subject_id = ?
        ";

        $materialTypes .= 'i';

        $materialParams[] =
            $selectedSubject;
    }

    $materialSql .= "
        ORDER BY
            gs.subject_name ASC,
            tm.created_at DESC,
            tm.id DESC

        LIMIT ? OFFSET ?
    ";

    $materialTypes .= 'ii';

    $materialParams[] =
        $perPage;

    $materialParams[] =
        $offset;

    $materialStmt =
        $conn->prepare(
            $materialSql
        );

    if ($materialStmt) {
        $bindValues = [
            $materialTypes
        ];

        foreach (
            $materialParams as &$value
        ) {
            $bindValues[] =
                &$value;
        }

        $materialStmt->bind_param(
            ...$bindValues
        );

        $materialStmt->execute();

        $materialResult =
            $materialStmt->get_result();

        while (
            $row =
            $materialResult->fetch_assoc()
        ) {
            $materials[] =
                $row;
        }

        $materialStmt->close();

        unset($value);
    }
}

/*
|--------------------------------------------------------------------------
| Pagination Information
|--------------------------------------------------------------------------
*/

$startNumber =
    $totalMaterials > 0
        ? $offset + 1
        : 0;

$endNumber =
    min(
        $offset + $perPage,
        $totalMaterials
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

    <title>Materials | BKHS Student</title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
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
           Header
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

        .academic-info {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 13px;
            color: #374151;
            white-space: nowrap;
        }

        .academic-info i {
            color: var(--primary);
        }

        /* =====================================================
           Filter
        ===================================================== */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #4b5563;
            margin-bottom: 6px;
        }

        .form-select {
            min-height: 40px;
            border-color: #dbe0e7;
            font-size: 13px;
            border-radius: 8px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.1);
        }

        .filter-button {
            min-height: 40px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        /* =====================================================
           Table
        ===================================================== */

        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .table-header {
            padding: 15px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .table-header-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
        }

        .result-count {
            color: var(--muted);
            font-size: 12px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .materials-table {
            width: 100%;
            min-width: 700px;
            margin: 0;
        }

        .materials-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 11px 14px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .materials-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: middle;
            font-size: 12px;
        }

        .materials-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .materials-table tbody tr:hover {
            background: #fafcff;
        }

        .material-title {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 220px;
        }

        .file-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 8px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .material-name {
            color: #111827;
            font-weight: 600;
            font-size: 12px;
            line-height: 1.4;
        }

        .material-file {
            color: #94a3b8;
            font-size: 10px;
            margin-top: 2px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }

        .grade-text {
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
        }

        .file-size {
            color: #6b7280;
            white-space: nowrap;
        }

        .uploaded-date {
            color: #6b7280;
            white-space: nowrap;
            font-size: 11px;
        }

        .action-button {
            width: 32px;
            height: 32px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbe2ea;
            color: #475569;
            background: #fff;
            transition: all .2s ease;
        }

        .action-button:hover {
            color: var(--primary);
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        /* =====================================================
           Empty
        ===================================================== */

        .empty-state {
            padding: 48px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 12px;
            border-radius: 12px;
            background: #f1f5f9;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .empty-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .empty-text {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        /* =====================================================
           Pagination
        ===================================================== */

        .table-footer {
            padding: 13px 18px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 11px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: #475569;
            border-color: #e2e8f0;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        /* =====================================================
           Mobile Sidebar
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

            /*
             * Bottom navigation is visible on tablets and phones.
             */
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
                transition: all .2s ease;
            }

            .mobile-bottom-link i {
                font-size: 19px;
                line-height: 1;
            }

            .mobile-bottom-link.active {
                color: var(--primary);
            }

            .mobile-bottom-link:hover {
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
                margin-bottom: 15px;
            }

            .page-title {
                font-size: 19px;
            }

            .page-description {
                font-size: 11px;
            }

            .academic-info {
                display: none;
            }

            .filter-card {
                padding: 12px;
                margin-bottom: 12px;
            }

            .filter-row {
                row-gap: 10px;
            }

            .filter-button {
                width: 100%;
            }

            .table-card {
                border-radius: 10px;
            }

            .table-header {
                padding: 12px 13px;
            }

            .table-header-title {
                font-size: 13px;
            }

            .result-count {
                font-size: 10px;
            }

            .materials-table {
                min-width: 650px;
            }

            .materials-table thead th {
                padding: 10px 11px;
            }

            .materials-table tbody td {
                padding: 10px 11px;
            }

            .table-footer {
                padding: 11px 13px;
                justify-content: center;
            }

            .pagination-info {
                display: none;
            }

            .pagination .page-link {
                min-width: 30px;
                height: 30px;
            }

            .empty-state {
                padding: 38px 16px;
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
            src="../public/image/logo.webp"
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
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="subjects.php"
            class="sidebar-link"
        >
            <i class="bi bi-journal-bookmark"></i>
            <span>Subjects</span>
        </a>

        <a
            href="materials.php"
            class="sidebar-link active"
        >
            <i class="bi bi-folder2-open"></i>
            <span>Materials</span>
        </a>

        <a
            href="result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Result</span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check"></i>
            <span>Attendance</span>
        </a>

        <a
            href="homework.php"
            class="sidebar-link"
        >
            <i class="bi bi-pencil-square"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <div class="nav-section-title mt-3">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
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
                    Materials
                </h1>

                <div class="topbar-date">
                    <?= e($ethiopianToday) ?>
                </div>

            </div>

        </div>

        <div class="student-user">

            <div class="student-avatar">

                <?= e(
                    strtoupper(
                        substr(
                            $studentName,
                            0,
                            1
                        )
                    )
                ) ?>

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

    <!-- Content -->

    <main class="content">

        <div class="page-header">

            <div>

                <h2 class="page-title">
                    Learning Materials
                </h2>

                <p class="page-description">
                    Access learning materials for your subjects.
                </p>

            </div>

            <?php if ($registration): ?>

                <div class="academic-info">

                    <i class="bi bi-mortarboard"></i>

                    <strong>
                        Grade
                        <?= e(
                            (string) $studentGrade
                        ) ?>
                    </strong>

                    <span>•</span>

                    <span>
                        Section
                        <?= e($studentSection) ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

        <?php if (!$registration): ?>

            <div class="table-card">

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-person-x"></i>
                    </div>

                    <div class="empty-title">
                        No Active Registration
                    </div>

                    <p class="empty-text">
                        Your current academic registration could not be found.
                    </p>

                </div>

            </div>

        <?php else: ?>

            <!-- Subject Filter -->

            <div class="filter-card">

                <form
                    method="get"
                    action="materials.php"
                >

                    <div class="row align-items-end filter-row">

                        <div class="col-12 col-md-8">

                            <label
                                for="subject"
                                class="filter-label"
                            >
                                Subject
                            </label>

                            <select
                                name="subject"
                                id="subject"
                                class="form-select"
                            >

                                <option value="0">
                                    All Subjects
                                </option>

                                <?php foreach (
                                    $subjects as $subject
                                ): ?>

                                    <?php
                                    $subjectId =
                                        (int) $subject['id'];
                                    ?>

                                    <option
                                        value="<?= $subjectId ?>"
                                        <?= $selectedSubject === $subjectId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e(
                                            (string)
                                            $subject['subject_name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-12 col-md-4">

                            <button
                                type="submit"
                                class="btn btn-primary filter-button w-100"
                            >
                                <i class="bi bi-funnel me-1"></i>
                                Filter Materials
                            </button>

                        </div>

                    </div>

                </form>

            </div>

            <!-- Materials -->

            <div class="table-card">

                <div class="table-header">

                    <h3 class="table-header-title">
                        Materials
                    </h3>

                    <span class="result-count">

                        <?= number_format(
                            $totalMaterials
                        ) ?>

                        <?= $totalMaterials === 1
                            ? 'material'
                            : 'materials' ?>

                    </span>

                </div>

                <?php if (empty($materials)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-folder2-open"></i>
                        </div>

                        <div class="empty-title">
                            No Materials Found
                        </div>

                        <p class="empty-text">
                            There are no learning materials available
                            <?= $selectedSubject > 0
                                ? 'for this subject'
                                : 'for your grade' ?>
                            yet.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="materials-table">

                            <thead>

                                <tr>

                                    <th>
                                        Material
                                    </th>

                                    <th>
                                        Subject
                                    </th>

                                    <th>
                                        Grade
                                    </th>

                                    <th>
                                        File
                                    </th>

                                    <th>
                                        Uploaded
                                    </th>

                                    <th class="text-end">
                                        Action
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $materials as $material
                                ): ?>

                                    <?php

                                    $fileName =
                                        (string) (
                                            $material['file_name']
                                            ?? ''
                                        );

                                    $fileType =
                                        (string) (
                                            $material['file_type']
                                            ?? ''
                                        );

                                    $title =
                                        (string) (
                                            $material['title']
                                            ?? ''
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

                                    $createdAt =
                                        formatEthiopianDate(
                                            (string) (
                                                $material['created_at']
                                                ?? ''
                                            )
                                        );

                                    $icon =
                                        materialIcon(
                                            $fileType,
                                            $fileName
                                        );

                                    ?>

                                    <tr>

                                        <td>

                                            <div class="material-title">

                                                <div class="file-icon">

                                                    <i
                                                        class="bi <?= e($icon) ?>"
                                                    ></i>

                                                </div>

                                                <div>

                                                    <div class="material-name">
                                                        <?= e($title) ?>
                                                    </div>

                                                    <div class="material-file">
                                                        <?= e($fileName) ?>
                                                    </div>

                                                </div>

                                            </div>

                                        </td>

                                        <td>

                                            <span class="subject-badge">
                                                <?= e(
                                                    (string) (
                                                        $material[
                                                            'subject_name'
                                                        ] ?? ''
                                                    )
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span class="grade-text">

                                                Grade
                                                <?= e(
                                                    (string) (
                                                        $material['grade']
                                                        ?? ''
                                                    )
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="file-size">
                                                <?= e($fileSize) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span class="uploaded-date">
                                                <?= e($createdAt) ?>
                                            </span>

                                        </td>

                                        <td class="text-end">

                                            <a
                                                href="materials/view.php?id=<?= (int) $material['id'] ?>"
                                                class="action-button"
                                                title="View material"
                                                aria-label="View material"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <!-- Pagination -->

                    <?php if ($totalPages > 1): ?>

                        <div class="table-footer">

                            <div class="pagination-info">

                                Showing

                                <strong>
                                    <?= $startNumber ?>
                                </strong>

                                -

                                <strong>
                                    <?= $endNumber ?>
                                </strong>

                                of

                                <strong>
                                    <?= $totalMaterials ?>
                                </strong>

                            </div>

                            <nav
                                aria-label="Materials pagination"
                            >

                                <ul class="pagination pagination-sm">

                                    <!-- Previous -->

                                    <li
                                        class="page-item
                                        <?= $currentPage <= 1
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $currentPage > 1
                                        ): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl(
                                                        $currentPage - 1
                                                    )
                                                ) ?>"
                                                aria-label="Previous"
                                            >
                                                <i
                                                    class="bi bi-chevron-left"
                                                ></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i
                                                    class="bi bi-chevron-left"
                                                ></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>

                                    <?php

                                    $startPage =
                                        max(
                                            1,
                                            $currentPage - 2
                                        );

                                    $endPage =
                                        min(
                                            $totalPages,
                                            $currentPage + 2
                                        );

                                    ?>

                                    <?php if (
                                        $startPage > 1
                                    ): ?>

                                        <li class="page-item">

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl(1)
                                                ) ?>"
                                            >
                                                1
                                            </a>

                                        </li>

                                        <?php if (
                                            $startPage > 2
                                        ): ?>

                                            <li
                                                class="page-item disabled"
                                            >
                                                <span class="page-link">
                                                    …
                                                </span>
                                            </li>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                    <?php for (
                                        $page = $startPage;
                                        $page <= $endPage;
                                        $page++
                                    ): ?>

                                        <li
                                            class="page-item
                                            <?= $page === $currentPage
                                                ? 'active'
                                                : '' ?>"
                                        >

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl($page)
                                                ) ?>"
                                            >
                                                <?= $page ?>
                                            </a>

                                        </li>

                                    <?php endfor; ?>

                                    <?php if (
                                        $endPage < $totalPages
                                    ): ?>

                                        <?php if (
                                            $endPage <
                                            $totalPages - 1
                                        ): ?>

                                            <li
                                                class="page-item disabled"
                                            >
                                                <span class="page-link">
                                                    …
                                                </span>
                                            </li>

                                        <?php endif; ?>

                                        <li class="page-item">

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl(
                                                        $totalPages
                                                    )
                                                ) ?>"
                                            >
                                                <?= $totalPages ?>
                                            </a>

                                        </li>

                                    <?php endif; ?>

                                    <!-- Next -->

                                    <li
                                        class="page-item
                                        <?= $currentPage >= $totalPages
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $currentPage < $totalPages
                                        ): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl(
                                                        $currentPage + 1
                                                    )
                                                ) ?>"
                                                aria-label="Next"
                                            >
                                                <i
                                                    class="bi bi-chevron-right"
                                                ></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i
                                                    class="bi bi-chevron-right"
                                                ></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>

                                </ul>

                            </nav>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        <?php endif; ?>

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
        href="dashboard.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-grid-1x2"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="subjects.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-journal-bookmark"></i>
        <span>Subjects</span>
    </a>

    <a
        href="materials.php"
        class="mobile-bottom-link active"
        aria-current="page"
    >
        <i class="bi bi-folder2-open"></i>
        <span>Materials</span>
    </a>

    <a
        href="result.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Result</span>
    </a>

    <a
        href="attendance.php"
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

</ht