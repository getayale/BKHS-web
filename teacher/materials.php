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
    header('Location: ../auth/login.php');
    exit;
}

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

$ethiopianCalendarFile = __DIR__ . '/../includes/EthiopianCalendar.php';

if (file_exists($ethiopianCalendarFile)) {
    require_once $ethiopianCalendarFile;
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

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(?string $dateTime): string
{
    if (
        empty($dateTime) ||
        !class_exists('EthiopianCalendar')
    ) {
        return '—';
    }

    try {

        $timestamp = strtotime($dateTime);

        if ($timestamp === false) {
            return '—';
        }

        $gregorianDate = date(
            'Y-m-d',
            $timestamp
        );

        $ethiopian = EthiopianCalendar::fromGregorian(
            $gregorianDate
        );

        return (string) (
            $ethiopian['formatted']
            ?? '—'
        );

    } catch (Throwable $e) {
        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

function getTodayEthiopianDate(): string
{
    if (!class_exists('EthiopianCalendar')) {
        return '—';
    }

    try {
        return EthiopianCalendar::todayFormatted('en');
    } catch (Throwable $e) {
        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Material Icon
|--------------------------------------------------------------------------
*/

function materialIcon(
    string $fileType,
    string $fileName
): string {

    $type = strtolower(
        trim($fileType)
    );

    $extension = strtolower(
        pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );

    if (
        str_contains($type, 'pdf') ||
        $extension === 'pdf'
    ) {
        return 'bi-file-earmark-pdf-fill';
    }

    if (
        str_contains($type, 'word') ||
        in_array(
            $extension,
            ['doc', 'docx'],
            true
        )
    ) {
        return 'bi-file-earmark-word-fill';
    }

    if (
        str_contains($type, 'powerpoint') ||
        str_contains($type, 'presentation') ||
        in_array(
            $extension,
            ['ppt', 'pptx'],
            true
        )
    ) {
        return 'bi-file-earmark-slides-fill';
    }

    if (
        str_contains($type, 'excel') ||
        in_array(
            $extension,
            ['xls', 'xlsx', 'csv'],
            true
        )
    ) {
        return 'bi-file-earmark-spreadsheet-fill';
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
        return 'bi-file-earmark-image-fill';
    }

    if (
        str_contains($type, 'video') ||
        in_array(
            $extension,
            [
                'mp4',
                'webm',
                'avi',
                'mov'
            ],
            true
        )
    ) {
        return 'bi-file-earmark-play-fill';
    }

    if (
        str_contains($type, 'audio') ||
        in_array(
            $extension,
            [
                'mp3',
                'wav',
                'ogg'
            ],
            true
        )
    ) {
        return 'bi-file-earmark-music-fill';
    }

    return 'bi-file-earmark-fill';
}

/*
|--------------------------------------------------------------------------
| File Size
|--------------------------------------------------------------------------
*/

function formatFileSize(?int $bytes): string
{
    $bytes = (int) $bytes;

    if ($bytes <= 0) {
        return 'Unknown';
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB'
    ];

    $index = 0;
    $size = (float) $bytes;

    while (
        $size >= 1024 &&
        $index < count($units) - 1
    ) {
        $size /= 1024;
        $index++;
    }

    return number_format(
        $size,
        $index === 0 ? 0 : 1
    ) . ' ' . $units[$index];
}

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage = basename(
    $_SERVER['PHP_SELF']
);

$morePages = [
    'materials.php',
    'roster.php',
    'profile.php'
];

$isMorePage = in_array(
    $currentPage,
    $morePages,
    true
);

/*
|--------------------------------------------------------------------------
| Teacher
|--------------------------------------------------------------------------
*/

$teacherId = (int) $_SESSION['user_id'];

$teacherName = 'Teacher';

/*
|--------------------------------------------------------------------------
| Load Teacher
|--------------------------------------------------------------------------
*/

$teacherStmt = $conn->prepare("
    SELECT
        id,
        full_name
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'teacher'
      AND (
          is_deleted = 0
          OR is_deleted IS NULL
      )
    LIMIT 1
");

if ($teacherStmt) {

    $teacherStmt->bind_param(
        'i',
        $teacherId
    );

    $teacherStmt->execute();

    $teacherResult =
        $teacherStmt->get_result();

    if (
        $teacherRow =
        $teacherResult->fetch_assoc()
    ) {

        $teacherName = trim(
            (string) (
                $teacherRow['full_name']
                ?? 'Teacher'
            )
        );

        if ($teacherName === '') {
            $teacherName = 'Teacher';
        }
    }

    $teacherStmt->close();
}

/*
|--------------------------------------------------------------------------
| Teacher Initial
|--------------------------------------------------------------------------
*/

$teacherInitial = strtoupper(
    substr(
        $teacherName,
        0,
        1
    )
);

if ($teacherInitial === '') {
    $teacherInitial = 'T';
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = '';

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($academicYearStmt) {

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    if (
        $academicYearRow =
        $academicYearResult->fetch_assoc()
    ) {

        $activeAcademicYear =
            (string) $academicYearRow['name'];
    }

    $academicYearStmt->close();
}

/*
|--------------------------------------------------------------------------
| Teacher Subject Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

if ($activeAcademicYear !== '') {

    $assignmentStmt = $conn->prepare("
        SELECT
            sta.id AS assignment_id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
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

    if ($assignmentStmt) {

        $assignmentStmt->bind_param(
            'is',
            $teacherId,
            $activeAcademicYear
        );

        $assignmentStmt->execute();

        $assignmentResult =
            $assignmentStmt->get_result();

        while (
            $row =
            $assignmentResult->fetch_assoc()
        ) {
            $assignments[] = $row;
        }

        $assignmentStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$selectedGrade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$selectedSection = isset($_GET['section'])
    ? trim((string) $_GET['section'])
    : '';

$selectedSubject = isset($_GET['subject'])
    ? (int) $_GET['subject']
    : 0;

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPageOptions = [
    10,
    20,
    30,
    50
];

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 10;

if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}

$currentPageNumber = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($currentPageNumber < 1) {
    $currentPageNumber = 1;
}

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$grades = [];

$sections = [];

$subjects = [];

foreach ($assignments as $assignment) {

    $grade = (int) $assignment['grade'];

    $section =
        (string) $assignment['section'];

    $subjectId =
        (int) $assignment['grade_subject_id'];

    $subjectName =
        (string) $assignment['subject_name'];

    if (
        !in_array(
            $grade,
            $grades,
            true
        )
    ) {
        $grades[] = $grade;
    }

    if (
        !in_array(
            $section,
            $sections,
            true
        )
    ) {
        $sections[] = $section;
    }

    if (
        !isset(
            $subjects[$subjectId]
        )
    ) {
        $subjects[$subjectId] =
            $subjectName;
    }
}

sort(
    $grades,
    SORT_NUMERIC
);

sort(
    $sections,
    SORT_NATURAL | SORT_FLAG_CASE
);

asort(
    $subjects,
    SORT_NATURAL | SORT_FLAG_CASE
);

/*
|--------------------------------------------------------------------------
| Build WHERE Conditions
|--------------------------------------------------------------------------
*/

$where = [
    "tm.teacher_user_id = ?",
    "tm.academic_year = ?",
    "tm.is_active = 1"
];

$params = [
    $teacherId,
    $activeAcademicYear
];

$types = 'is';

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($selectedGrade > 0) {

    $where[] = "
        EXISTS (
            SELECT 1
            FROM subject_teacher_assignments filter_sta
            WHERE filter_sta.teacher_user_id = tm.teacher_user_id
              AND filter_sta.academic_year = tm.academic_year
              AND filter_sta.grade_subject_id = tm.grade_subject_id
              AND filter_sta.grade = ?
              AND filter_sta.is_active = 1
        )
    ";

    $types .= 'i';

    $params[] =
        $selectedGrade;
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($selectedSection !== '') {

    $where[] = "
        EXISTS (
            SELECT 1
            FROM subject_teacher_assignments filter_sta
            WHERE filter_sta.teacher_user_id = tm.teacher_user_id
              AND filter_sta.academic_year = tm.academic_year
              AND filter_sta.grade_subject_id = tm.grade_subject_id
              AND filter_sta.section = ?
              AND filter_sta.is_active = 1
        )
    ";

    $types .= 's';

    $params[] =
        $selectedSection;
}

/*
|--------------------------------------------------------------------------
| Subject Filter
|--------------------------------------------------------------------------
*/

if ($selectedSubject > 0) {

    $where[] =
        "tm.grade_subject_id = ?";

    $types .= 'i';

    $params[] =
        $selectedSubject;
}

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "
        (
            tm.title LIKE ?
            OR tm.file_name LIKE ?
            OR tm.description LIKE ?
            OR gs.subject_name LIKE ?
        )
    ";

    $searchValue =
        '%' . $search . '%';

    $types .= 'ssss';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Total Materials Count
|--------------------------------------------------------------------------
*/

$totalMaterials = 0;

if ($activeAcademicYear !== '') {

    $countSql = "
        SELECT COUNT(*) AS total
        FROM teacher_materials tm
        INNER JOIN grade_subjects gs
            ON gs.id = tm.grade_subject_id
        WHERE {$whereSql}
    ";

    $countStmt =
        $conn->prepare($countSql);

    if ($countStmt) {

        $countStmt->bind_param(
            $types,
            ...$params
        );

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        if (
            $countRow =
            $countResult->fetch_assoc()
        ) {
            $totalMaterials =
                (int) $countRow['total'];
        }

        $countStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Pagination Calculations
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalMaterials / $perPage
    )
);

if (
    $currentPageNumber >
    $totalPages
) {
    $currentPageNumber =
        $totalPages;
}

$offset =
    ($currentPageNumber - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Load Materials
|--------------------------------------------------------------------------
*/

$materials = [];

if (
    $activeAcademicYear !== '' &&
    $totalMaterials > 0
) {

    /*
    |--------------------------------------------------------------------------
    | Important:
    | GROUP_CONCAT prevents duplicate materials when the teacher
    | teaches the same subject to multiple sections.
    |--------------------------------------------------------------------------
    */

    $materialSql = "
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
            gs.subject_name,

            GROUP_CONCAT(
                DISTINCT sta.section
                ORDER BY sta.section ASC
                SEPARATOR ', '
            ) AS sections

        FROM teacher_materials tm

        INNER JOIN grade_subjects gs
            ON gs.id = tm.grade_subject_id

        LEFT JOIN subject_teacher_assignments sta
            ON sta.grade_subject_id = tm.grade_subject_id
           AND sta.teacher_user_id = tm.teacher_user_id
           AND sta.academic_year = tm.academic_year
           AND sta.is_active = 1
           AND sta.grade = gs.grade

        WHERE {$whereSql}

        GROUP BY
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

        ORDER BY
            tm.created_at DESC

        LIMIT ? OFFSET ?
    ";

    $materialTypes =
        $types . 'ii';

    $materialParams =
        $params;

    $materialParams[] =
        $perPage;

    $materialParams[] =
        $offset;

    $materialStmt =
        $conn->prepare($materialSql);

    if ($materialStmt) {

        $materialStmt->bind_param(
            $materialTypes,
            ...$materialParams
        );

        $materialStmt->execute();

        $materialResult =
            $materialStmt->get_result();

        while (
            $row =
            $materialResult->fetch_assoc()
        ) {
            $materials[] = $row;
        }

        $materialStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$assignmentCount =
    count($assignments);

$subjectCount =
    count($subjects);

/*
|--------------------------------------------------------------------------
| Pagination URL Helper
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $page,
    int $perPage,
    int $grade,
    string $section,
    int $subject,
    string $search
): string {

    $query = [
        'page' => $page,
        'per_page' => $perPage
    ];

    if ($grade > 0) {
        $query['grade'] = $grade;
    }

    if ($section !== '') {
        $query['section'] = $section;
    }

    if ($subject > 0) {
        $query['subject'] = $subject;
    }

    if ($search !== '') {
        $query['search'] = $search;
    }

    return 'materials.php?' .
        http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Pagination Range
|--------------------------------------------------------------------------
*/

$paginationStart =
    max(
        1,
        $currentPageNumber - 2
    );

$paginationEnd =
    min(
        $totalPages,
        $currentPageNumber + 2
    );

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$ethiopianDate =
    getTodayEthiopianDate();

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
        Materials | BKHS Teacher Portal
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
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

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #9ca3af;
            --body-bg: #f5f7fb;
            --card-bg: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --danger: #dc2626;
            --sidebar-width: 260px;
            --topbar-height: 76px;
            --radius: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--body-bg);
            color: var(--text);
            font-family:
                'Inter',
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
        }

        /* =========================================================
           SIDEBAR
        ========================================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: var(--sidebar);
            z-index: 1040;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .sidebar-brand {
            min-height: var(--topbar-height);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37,99,235,.18);
            color: #60a5fa;
            font-size: 20px;
        }

        .brand-title {
            color: #ffffff;
            font-size: 15px;
            font-weight: 800;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 10px;
            font-weight: 700;
            padding: 10px 12px 7px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 10px;
            color: var(--sidebar-text);
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .nav-link-custom i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .nav-link-custom:hover {
            color: #ffffff;
            background: var(--sidebar-hover);
        }

        .nav-link-custom.active {
            color: #ffffff;
            background: var(--primary);
            box-shadow: 0 8px 20px rgba(37,99,235,.22);
        }

        .sidebar-profile {
            padding: 14px;
            border-top: 1px solid rgba(255,255,255,.06);
        }

        .sidebar-profile-inner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px;
            border-radius: 12px;
            background: rgba(255,255,255,.04);
        }

        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            flex-shrink: 0;
        }

        .sidebar-profile-name {
            color: #ffffff;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-profile-role {
            color: #9ca3af;
            font-size: 10px;
            margin-top: 2px;
        }

        /* =========================================================
           MAIN
        ========================================================== */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: var(--topbar-height);
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .page-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .date-badge {
            padding: 8px 12px;
            border-radius: 10px;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 12px;
            font-weight: 500;
        }

        .content {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
            padding: 28px;
        }

        /* =========================================================
           PAGE HEADER
        ========================================================== */

        .page-header-card {
            background: linear-gradient(
                135deg,
                #1d4ed8,
                #2563eb
            );
            border-radius: 20px;
            padding: 24px;
            color: #ffffff;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .page-header-card::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            right: -70px;
            top: -100px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }

        .page-header-content {
            position: relative;
            z-index: 1;
        }

        .page-header-title {
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .page-header-text {
            font-size: 12px;
            color: rgba(255,255,255,.82);
            margin: 0;
            max-width: 700px;
        }

        .academic-year-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 13px;
            padding: 7px 11px;
            border-radius: 9px;
            background: rgba(255,255,255,.14);
            font-size: 11px;
            font-weight: 600;
        }

        .header-action {
            position: absolute;
            right: 24px;
            bottom: 24px;
            z-index: 2;
        }

        .btn-add {
            border: 0;
            border-radius: 10px;
            background: #ffffff;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            padding: 10px 15px;
        }

        .btn-add:hover {
            background: #f8fafc;
            color: var(--primary-dark);
        }

        /* =========================================================
           STATISTICS
        ========================================================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 17px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            font-size: 19px;
            flex-shrink: 0;
        }

        .stat-number {
            font-size: 20px;
            font-weight: 800;
            line-height: 1.1;
        }

        .stat-label {
            color: var(--muted);
            font-size: 10px;
            margin-top: 4px;
        }

        /* =========================================================
           FILTER
        ========================================================== */

        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 17px;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .form-label-custom {
            font-size: 10px;
            font-weight: 600;
            color: #4b5563;
            margin-bottom: 5px;
        }

        .form-control,
        .form-select {
            border-color: #dfe3e8;
            border-radius: 9px;
            font-size: 11px;
            min-height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.08);
        }

        .btn-filter {
            min-height: 40px;
            border-radius: 9px;
            padding: 0 15px;
            font-size: 11px;
            font-weight: 600;
        }

        .btn-reset {
            min-height: 40px;
            width: 40px;
            border-radius: 9px;
            font-size: 13px;
        }

        /* =========================================================
           TABLE PANEL
        ========================================================== */

        .section-panel {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .panel-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .panel-title {
            font-size: 13px;
            font-weight: 700;
            margin: 0;
        }

        .panel-count {
            background: #eff6ff;
            color: var(--primary);
            padding: 5px 9px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 700;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .materials-table {
            width: 100%;
            min-width: 900px;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .materials-table thead th {
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            color: #6b7280;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 12px 13px;
            white-space: nowrap;
        }

        .materials-table tbody td {
            border-bottom: 1px solid #f0f1f3;
            padding: 12px 13px;
            vertical-align: middle;
            font-size: 11px;
            color: #374151;
        }

        .materials-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .materials-table tbody tr {
            transition: background .15s ease;
        }

        .materials-table tbody tr:hover {
            background: #fafcff;
        }

        .material-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 220px;
        }

        .material-icon-small {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .material-name {
            font-size: 11px;
            font-weight: 700;
            color: #111827;
            line-height: 1.4;
            max-width: 260px;
            word-break: break-word;
        }

        .material-file {
            color: #9ca3af;
            font-size: 9px;
            margin-top: 3px;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            background: #eef2ff;
            color: #4f46e5;
            border-radius: 7px;
            font-size: 9px;
            font-weight: 600;
            white-space: nowrap;
        }

        .grade-badge,
        .section-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            background: #f3f4f6;
            color: #4b5563;
            border-radius: 7px;
            font-size: 9px;
            font-weight: 600;
            margin: 2px;
        }

        .sections-cell {
            max-width: 150px;
        }

        .file-size {
            color: #6b7280;
            font-size: 10px;
            white-space: nowrap;
        }

        .date-cell {
            color: #6b7280;
            font-size: 9px;
            line-height: 1.5;
            white-space: nowrap;
        }

        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
            white-space: nowrap;
        }

        .icon-btn {
            width: 31px;
            height: 31px;
            border: 1px solid var(--border);
            background: #ffffff;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            transition: all .15s ease;
        }

        .icon-btn:hover {
            color: var(--primary);
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .icon-btn.delete:hover {
            color: var(--danger);
            border-color: #fecaca;
            background: #fef2f2;
        }

        /* =========================================================
           PAGINATION
        ========================================================== */

        .table-footer {
            padding: 13px 17px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 10px;
        }

        .pagination {
            margin: 0;
            gap: 4px;
        }

        .page-link {
            min-width: 31px;
            height: 31px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px !important;
            border: 1px solid var(--border);
            color: #4b5563;
            font-size: 10px;
            padding: 0 8px;
        }

        .page-link:hover {
            color: var(--primary);
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }

        .page-item.disabled .page-link {
            color: #c4c8ce;
            background: #f9fafb;
        }

        .per-page-select {
            width: auto;
            min-width: 70px;
            min-height: 31px;
            font-size: 10px;
            padding-top: 3px;
            padding-bottom: 3px;
        }

        /* =========================================================
           EMPTY
        ========================================================== */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 15px;
            border-radius: 17px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 27px;
        }

        .empty-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .empty-text {
            max-width: 450px;
            margin: 0 auto 17px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

        .btn-empty-add {
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #ffffff;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-empty-add:hover {
            background: var(--primary-dark);
            color: #ffffff;
        }

        /* =========================================================
           MOBILE MORE
        ========================================================== */

        .mobile-more-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.42);
            z-index: 1090;
            opacity: 0;
            visibility: hidden;
            transition: all .2s ease;
        }

        .mobile-more-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .mobile-more-menu {
            position: fixed;
            left: 14px;
            right: 14px;
            bottom: 82px;
            z-index: 1100;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 8px;
            box-shadow: 0 20px 50px rgba(15,23,42,.18);
            transform: translateY(15px);
            opacity: 0;
            visibility: hidden;
            transition: all .2s ease;
        }

        .mobile-more-menu.show {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
        }

        .mobile-more-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #374151;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .mobile-more-item:hover {
            background: #f3f4f6;
            color: var(--primary);
        }

        .mobile-more-item i {
            width: 22px;
            text-align: center;
            font-size: 17px;
        }

        .mobile-more-divider {
            height: 1px;
            background: var(--border);
            margin: 5px 8px;
        }

        .mobile-bottom-nav {
            display: none;
        }

        /* =========================================================
           TABLET
        ========================================================== */

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 20px 20px 100px;
            }

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .header-action {
                position: static;
                margin-top: 16px;
            }

            .btn-add {
                display: inline-block;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                background: rgba(255,255,255,.97);
                backdrop-filter: blur(12px);
                border-top: 1px solid var(--border);
                z-index: 1080;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                padding-bottom: env(safe-area-inset-bottom);
            }

            .mobile-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #6b7280;
                font-size: 9px;
                font-weight: 600;
                border: 0;
                background: transparent;
            }

            .mobile-nav-item i {
                font-size: 19px;
            }

            .mobile-nav-item.active {
                color: var(--primary);
            }
        }

        /* =========================================================
           SMALL SCREEN
        ========================================================== */

        @media (max-width: 575px) {

            .topbar {
                height: 68px;
                padding: 0 14px;
            }

            .page-title {
                font-size: 16px;
            }

            .page-subtitle {
                font-size: 9px;
            }

            .topbar-right .date-badge {
                display: none;
            }

            .topbar-right .avatar-placeholder {
                width: 36px;
                height: 36px;
            }

            .content {
                padding: 14px 10px 92px;
            }

            .page-header-card {
                padding: 18px;
                border-radius: 15px;
            }

            .page-header-title {
                font-size: 18px;
            }

            .page-header-text {
                font-size: 10px;
                line-height: 1.6;
            }

            .academic-year-badge {
                font-size: 9px;
            }

            .header-action {
                margin-top: 14px;
            }

            .btn-add {
                width: 100%;
                display: block;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 13px;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
            }

            .filter-card {
                padding: 13px;
                border-radius: 13px;
            }

            .filter-title {
                margin-bottom: 12px;
            }

            .section-panel {
                border-radius: 13px;
            }

            .panel-header {
                padding: 13px;
            }

            .table-footer {
                padding: 12px;
                align-items: flex-start;
                flex-direction: column;
            }

            .pagination {
                width: 100%;
                justify-content: center;
            }

            .pagination-info {
                width: 100%;
                text-align: center;
            }

            .per-page-wrapper {
                width: 100%;
                display: flex;
                justify-content: center;
            }
        }

        @media (max-width: 360px) {

            .mobile-nav-item {
                font-size: 8px;
            }

            .mobile-nav-item i {
                font-size: 17px;
            }

            .content {
                padding-left: 8px;
                padding-right: 8px;
            }

            .page-header-card {
                padding: 16px;
            }
        }

    </style>

</head>

<body>

<!-- =============================================================
     DESKTOP SIDEBAR
============================================================== -->

<aside class="sidebar">

    <div class="sidebar-brand">

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

    </div>


    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>


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
            class="nav-link-custom"
        >
            <i class="bi bi-people-fill"></i>
            <span>Classes</span>
        </a>


        <div class="nav-section-title">
            Academic
        </div>


        <a
            href="result.php"
            class="nav-link-custom"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>


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
            href="materials.php"
            class="nav-link-custom active"
        >
            <i class="bi bi-folder-fill"></i>
            <span>Materials</span>
        </a>


        <a
            href="announcement.php"
            class="nav-link-custom"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>


        <div class="nav-section-title">
            Management
        </div>


        <a
            href="roster.php"
            class="nav-link-custom"
        >
            <i class="bi bi-card-list"></i>
            <span>Roster</span>
        </a>


        <a
            href="profile.php"
            class="nav-link-custom"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>


        <a
            href="../auth/logout.php"
            class="nav-link-custom"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>


    <div class="sidebar-profile">

        <div class="sidebar-profile-inner">

            <div class="avatar-placeholder">
                <?= e($teacherInitial) ?>
            </div>

            <div class="flex-grow-1 min-width-0">

                <div class="sidebar-profile-name">
                    <?= e($teacherName) ?>
                </div>

                <div class="sidebar-profile-role">
                    Teacher
                </div>

            </div>

        </div>

    </div>

</aside>


<!-- =============================================================
     MAIN
============================================================== -->

<main class="main">

    <header class="topbar">

        <div>

            <h1 class="page-title">
                Materials
            </h1>

            <div class="page-subtitle">
                Teaching and learning resources
            </div>

        </div>


        <div class="topbar-right">

            <div class="date-badge">

                <i class="bi bi-calendar3 me-1"></i>

                <?= e($ethiopianDate) ?>

            </div>


            <div class="avatar-placeholder">
                <?= e($teacherInitial) ?>
            </div>

        </div>

    </header>


    <div class="content">

        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <section class="page-header-card">

            <div class="page-header-content">

                <div class="page-header-title">
                    Teaching Materials
                </div>

                <p class="page-header-text">
                    Manage learning resources for the subjects and
                    classes assigned to you.
                </p>


                <?php if ($activeAcademicYear !== ''): ?>

                    <div class="academic-year-badge">

                        <i class="bi bi-calendar2-check"></i>

                        Academic Year:
                        <?= e($activeAcademicYear) ?>

                    </div>

                <?php else: ?>

                    <div class="academic-year-badge">

                        <i class="bi bi-exclamation-circle"></i>

                        No active academic year

                    </div>

                <?php endif; ?>


                <div class="header-action">

                    <a
                        href="materials/create.php"
                        class="btn-add"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Add Material
                    </a>

                </div>

            </div>

        </section>


        <!-- =====================================================
             STATISTICS
        ====================================================== -->

        <section class="stats-grid">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-file-earmark-text-fill"></i>
                </div>

                <div>

                    <div class="stat-number">
                        <?= $totalMaterials ?>
                    </div>

                    <div class="stat-label">
                        Materials
                    </div>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-book-fill"></i>
                </div>

                <div>

                    <div class="stat-number">
                        <?= $assignmentCount ?>
                    </div>

                    <div class="stat-label">
                        Assigned Classes & Subjects
                    </div>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-layers-fill"></i>
                </div>

                <div>

                    <div class="stat-number">
                        <?= $subjectCount ?>
                    </div>

                    <div class="stat-label">
                        Assigned Subjects
                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             FILTER
        ====================================================== -->

        <section class="filter-card">

            <div class="filter-title">

                <i class="bi bi-funnel me-1"></i>

                Filter Materials

            </div>


            <form
                method="GET"
                action="materials.php"
            >

                <div class="row g-2 align-items-end">

                    <!-- Search -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <label class="form-label-custom">
                            Search
                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Title, file or subject..."
                        >

                    </div>


                    <!-- Grade -->

                    <div class="col-6 col-md-3 col-lg-2">

                        <label class="form-label-custom">
                            Grade
                        </label>

                        <select
                            name="grade"
                            class="form-select"
                        >

                            <option value="">
                                All Grades
                            </option>

                            <?php foreach (
                                $grades
                                as $grade
                            ): ?>

                                <option
                                    value="<?= $grade ?>"
                                    <?= $selectedGrade === $grade
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Grade <?= $grade ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Section -->

                    <div class="col-6 col-md-3 col-lg-2">

                        <label class="form-label-custom">
                            Section
                        </label>

                        <select
                            name="section"
                            class="form-select"
                        >

                            <option value="">
                                All Sections
                            </option>

                            <?php foreach (
                                $sections
                                as $section
                            ): ?>

                                <option
                                    value="<?= e($section) ?>"
                                    <?= $selectedSection === $section
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Section <?= e($section) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Subject -->

                    <div class="col-12 col-md-6 col-lg-3">

                        <label class="form-label-custom">
                            Subject
                        </label>

                        <select
                            name="subject"
                            class="form-select"
                        >

                            <option value="">
                                All Subjects
                            </option>

                            <?php foreach (
                                $subjects
                                as $subjectId => $subjectName
                            ): ?>

                                <option
                                    value="<?= (int) $subjectId ?>"
                                    <?= $selectedSubject === (int) $subjectId
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($subjectName) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Buttons -->

                    <div class="col-12 col-md-6 col-lg-2">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary btn-filter flex-grow-1"
                            >
                                <i class="bi bi-funnel-fill me-1"></i>
                                Filter
                            </button>


                            <a
                                href="materials.php"
                                class="btn btn-light border btn-reset d-flex align-items-center justify-content-center"
                                title="Reset filters"
                            >
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </section>


        <!-- =====================================================
             MATERIAL TABLE
        ====================================================== -->

        <section class="section-panel">

            <div class="panel-header">

                <h2 class="panel-title">
                    My Materials
                </h2>

                <span class="panel-count">

                    <?= $totalMaterials ?>

                    <?= $totalMaterials === 1
                        ? 'Material'
                        : 'Materials'
                    ?>

                </span>

            </div>


            <?php if (count($materials) > 0): ?>

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
                                    Sections
                                </th>

                                <th>
                                    File Size
                                </th>

                                <th>
                                    Uploaded
                                </th>

                                <th class="text-end">
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $materials
                                as $material
                            ): ?>

                                <?php

                                $materialId =
                                    (int) $material['id'];

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
                                    trim(
                                        (string) (
                                            $material['title']
                                            ?? ''
                                        )
                                    );

                                if ($title === '') {

                                    $title =
                                        $fileName !== ''
                                            ? $fileName
                                            : 'Untitled Material';
                                }

                                $subjectName =
                                    (string) (
                                        $material['subject_name']
                                        ?? 'Subject'
                                    );

                                $grade =
                                    (int) (
                                        $material['grade']
                                        ?? 0
                                    );

                                $sectionsText =
                                    trim(
                                        (string) (
                                            $material['sections']
                                            ?? ''
                                        )
                                    );

                                $size =
                                    formatFileSize(
                                        isset(
                                            $material['file_size']
                                        )
                                            ? (int) $material['file_size']
                                            : 0
                                    );

                                $createdAt =
                                    formatEthiopianDate(
                                        isset(
                                            $material['created_at']
                                        )
                                            ? (string) $material['created_at']
                                            : null
                                    );

                                $icon =
                                    materialIcon(
                                        $fileType,
                                        $fileName
                                    );

                                $sectionList =
                                    $sectionsText !== ''
                                        ? array_filter(
                                            array_map(
                                                'trim',
                                                explode(
                                                    ',',
                                                    $sectionsText
                                                )
                                            )
                                        )
                                        : [];

                                ?>

                                <tr>

                                    <!-- Material -->

                                    <td>

                                        <div class="material-name-cell">

                                            <div class="material-icon-small">

                                                <i
                                                    class="bi <?= e($icon) ?>"
                                                ></i>

                                            </div>


                                            <div>

                                                <div class="material-name">

                                                    <?= e($title) ?>

                                                </div>


                                                <div class="material-file">

                                                    <?= e(
                                                        $fileName !== ''
                                                            ? $fileName
                                                            : 'File'
                                                    ) ?>

                                                </div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- Subject -->

                                    <td>

                                        <span class="subject-badge">

                                            <i class="bi bi-book"></i>

                                            <?= e($subjectName) ?>

                                        </span>

                                    </td>


                                    <!-- Grade -->

                                    <td>

                                        <?php if ($grade > 0): ?>

                                            <span class="grade-badge">

                                                Grade
                                                <?= $grade ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- Sections -->

                                    <td class="sections-cell">

                                        <?php if (
                                            count($sectionList) > 0
                                        ): ?>

                                            <?php foreach (
                                                $sectionList
                                                as $section
                                            ): ?>

                                                <span class="section-badge">

                                                    <?= e($section) ?>

                                                </span>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- Size -->

                                    <td>

                                        <span class="file-size">

                                            <?= e($size) ?>

                                        </span>

                                    </td>


                                    <!-- Date -->

                                    <td>

                                        <div class="date-cell">

                                            <i
                                                class="bi bi-calendar3 me-1"
                                            ></i>

                                            <?= e($createdAt) ?>

                                        </div>

                                    </td>


                                    <!-- Actions -->

                                    <td>

                                        <div class="action-buttons">

                                            <a
                                                href="materials/view.php?id=<?= $materialId ?>"
                                                class="icon-btn"
                                                title="View material"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>


                                            <a
                                                href="materials/edit.php?id=<?= $materialId ?>"
                                                class="icon-btn"
                                                title="Edit material"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>


                                            <a
                                                href="materials/delete.php?id=<?= $materialId ?>"
                                                class="icon-btn delete"
                                                title="Delete material"
                                                onclick="return confirm('Are you sure you want to delete this material?');"
                                            >
                                                <i class="bi bi-trash3"></i>
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- =================================================
                     TABLE FOOTER / PAGINATION
                ================================================== -->

                <div class="table-footer">

                    <?php

                    $startRecord =
                        $totalMaterials > 0
                            ? $offset + 1
                            : 0;

                    $endRecord =
                        min(
                            $offset + $perPage,
                            $totalMaterials
                        );

                    ?>

                    <div class="pagination-info">

                        Showing
                        <strong>
                            <?= $startRecord ?>
                        </strong>
                        –
                        <strong>
                            <?= $endRecord ?>
                        </strong>
                        of
                        <strong>
                            <?= $totalMaterials ?>
                        </strong>
                        materials

                    </div>


                    <div class="d-flex align-items-center gap-3">

                        <!-- Per page -->

                        <div class="per-page-wrapper d-flex align-items-center gap-2">

                            <span
                                class="pagination-info"
                            >
                                Show
                            </span>

                            <select
                                class="form-select per-page-select"
                                onchange="changePerPage(this.value)"
                            >

                                <?php foreach (
                                    $perPageOptions
                                    as $option
                                ): ?>

                                    <option
                                        value="<?= $option ?>"
                                        <?= $perPage === $option
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= $option ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Pagination -->

                        <?php if ($totalPages > 1): ?>

                            <nav
                                aria-label="Materials pagination"
                            >

                                <ul class="pagination">

                                    <!-- Previous -->

                                    <li
                                        class="page-item <?= $currentPageNumber <= 1
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $currentPageNumber > 1
                                        ): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $currentPageNumber - 1,
                                                        $perPage,
                                                        $selectedGrade,
                                                        $selectedSection,
                                                        $selectedSubject,
                                                        $search
                                                    )
                                                ) ?>"
                                                aria-label="Previous"
                                            >
                                                <i class="bi bi-chevron-left"></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i class="bi bi-chevron-left"></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>


                                    <!-- First Page -->

                                    <?php if (
                                        $paginationStart > 1
                                    ): ?>

                                        <li class="page-item">

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        1,
                                                        $perPage,
                                                        $selectedGrade,
                                                        $selectedSection,
                                                        $selectedSubject,
                                                        $search
                                                    )
                                                ) ?>"
                                            >
                                                1
                                            </a>

                                        </li>


                                        <?php if (
                                            $paginationStart > 2
                                        ): ?>

                                            <li class="page-item disabled">

                                                <span class="page-link">
                                                    …
                                                </span>

                                            </li>

                                        <?php endif; ?>

                                    <?php endif; ?>


                                    <!-- Page Numbers -->

                                    <?php for (
                                        $pageNumber = $paginationStart;
                                        $pageNumber <= $paginationEnd;
                                        $pageNumber++
                                    ): ?>

                                        <li
                                            class="page-item <?= $pageNumber === $currentPageNumber
                                                ? 'active'
                                                : '' ?>"
                                        >

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $pageNumber,
                                                        $perPage,
                                                        $selectedGrade,
                                                        $selectedSection,
                                                        $selectedSubject,
                                                        $search
                                                    )
                                                ) ?>"
                                            >
                                                <?= $pageNumber ?>
                                            </a>

                                        </li>

                                    <?php endfor; ?>


                                    <!-- Last Page -->

                                    <?php if (
                                        $paginationEnd < $totalPages
                                    ): ?>

                                        <?php if (
                                            $paginationEnd < $totalPages - 1
                                        ): ?>

                                            <li class="page-item disabled">

                                                <span class="page-link">
                                                    …
                                                </span>

                                            </li>

                                        <?php endif; ?>


                                        <li class="page-item">

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $totalPages,
                                                        $perPage,
                                                        $selectedGrade,
                                                        $selectedSection,
                                                        $selectedSubject,
                                                        $search
                                                    )
                                                ) ?>"
                                            >
                                                <?= $totalPages ?>
                                            </a>

                                        </li>

                                    <?php endif; ?>


                                    <!-- Next -->

                                    <li
                                        class="page-item <?= $currentPageNumber >= $totalPages
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $currentPageNumber < $totalPages
                                        ): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    paginationUrl(
                                                        $currentPageNumber + 1,
                                                        $perPage,
                                                        $selectedGrade,
                                                        $selectedSection,
                                                        $selectedSubject,
                                                        $search
                                                    )
                                                ) ?>"
                                                aria-label="Next"
                                            >
                                                <i class="bi bi-chevron-right"></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i class="bi bi-chevron-right"></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>

                                </ul>

                            </nav>

                        <?php endif; ?>

                    </div>

                </div>


            <?php else: ?>

                <!-- =================================================
                     EMPTY STATE
                ================================================== -->

                <div class="empty-state">

                    <div class="empty-icon">

                        <i class="bi bi-folder2-open"></i>

                    </div>


                    <div class="empty-title">

                        No materials found

                    </div>


                    <p class="empty-text">

                        <?php if (
                            $search !== '' ||
                            $selectedGrade > 0 ||
                            $selectedSection !== '' ||
                            $selectedSubject > 0
                        ): ?>

                            No materials match your selected filters.
                            Try changing the filters or reset them.

                        <?php else: ?>

                            You haven't added any teaching materials
                            for the selected academic year yet.

                        <?php endif; ?>

                    </p>


                    <?php if (
                        $search !== '' ||
                        $selectedGrade > 0 ||
                        $selectedSection !== '' ||
                        $selectedSubject > 0
                    ): ?>

                        <a
                            href="materials.php"
                            class="btn-empty-add me-1"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Reset Filters
                        </a>

                    <?php endif; ?>


                    <a
                        href="materials/create.php"
                        class="btn-empty-add"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Add Material
                    </a>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>


<!-- =============================================================
     MOBILE MORE OVERLAY
============================================================== -->

<div
    class="mobile-more-overlay"
    id="mobileMoreOverlay"
></div>


<!-- =============================================================
     MOBILE MORE MENU
============================================================== -->

<div
    class="mobile-more-menu"
    id="mobileMoreMenu"
>

    <a
        href="materials.php"
        class="mobile-more-item"
    >
        <i class="bi bi-folder-fill"></i>
        <span>Materials</span>
    </a>


    <a
        href="roster.php"
        class="mobile-more-item"
    >
        <i class="bi bi-card-list"></i>
        <span>Roster</span>
    </a>


    <div class="mobile-more-divider"></div>


    <a
        href="profile.php"
        class="mobile-more-item"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>


    <a
        href="../auth/logout.php"
        class="mobile-more-item"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</div>


<!-- =============================================================
     MOBILE BOTTOM NAVIGATION
============================================================== -->

<nav class="mobile-bottom-nav">

    <a
        href="daily-attendance.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-calendar-check-fill"></i>
        <span>Attendance</span>
    </a>


    <a
        href="homework.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>


    <a
        href="result.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Result</span>
    </a>


    <a
        href="announcement.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-megaphone-fill"></i>
        <span>Announcement</span>
    </a>


    <button
        type="button"
        class="mobile-nav-item more <?= $isMorePage ? 'active' : '' ?>"
        id="mobileMoreButton"
        data-page-active="<?= $isMorePage ? 'true' : 'false' ?>"
        aria-label="More"
    >
        <i class="bi bi-three-dots"></i>
        <span>More</span>
    </button>

</nav>


<script>

/*
|--------------------------------------------------------------------------
| Mobile More Menu
|--------------------------------------------------------------------------
*/

const mobileMoreButton =
    document.getElementById(
        'mobileMoreButton'
    );

const mobileMoreMenu =
    document.getElementById(
        'mobileMoreMenu'
    );

const mobileMoreOverlay =
    document.getElementById(
        'mobileMoreOverlay'
    );

const morePageIsActive =
    mobileMoreButton.dataset.pageActive === 'true';


function openMobileMoreMenu() {

    mobileMoreMenu.classList.add(
        'show'
    );

    mobileMoreOverlay.classList.add(
        'show'
    );

    mobileMoreButton.classList.add(
        'active'
    );

    document.body.style.overflow =
        'hidden';
}


function closeMobileMoreMenu() {

    mobileMoreMenu.classList.remove(
        'show'
    );

    mobileMoreOverlay.classList.remove(
        'show'
    );

    if (!morePageIsActive) {

        mobileMoreButton.classList.remove(
            'active'
        );
    }

    document.body.style.overflow =
        '';
}


mobileMoreButton.addEventListener(
    'click',
    function () {

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
);


mobileMoreOverlay.addEventListener(
    'click',
    closeMobileMoreMenu
);


document.addEventListener(
    'keydown',
    function (event) {

        if (event.key === 'Escape') {

            closeMobileMoreMenu();

        }

    }
);


document
    .querySelectorAll(
        '.mobile-more-item'
    )
    .forEach(
        function (item) {

            item.addEventListener(
                'click',
                function () {

                    closeMobileMoreMenu();

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| Change Items Per Page
|--------------------------------------------------------------------------
*/

function changePerPage(value) {

    const url =
        new URL(
            window.location.href
        );

    url.searchParams.set(
        'per_page',
        value
    );

    url.searchParams.set(
        'page',
        '1'
    );

    window.location.href =
        url.toString();
}

</script>

</body>
</html>