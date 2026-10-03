<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Principal Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'P';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return strtoupper(substr($name, 0, 1));
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 1));
    }

    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}

function formatMark(mixed $mark): string
{
    if ($mark === null || $mark === '') {
        return 'Not Recorded';
    }

    if (!is_numeric($mark)) {
        return e((string) $mark);
    }

    $number = (float) $mark;

    if (floor($number) === $number) {
        return number_format($number, 0);
    }

    return number_format($number, 2);
}

function formatEthiopianDate(
    mixed $year,
    mixed $month,
    mixed $day
): string {
    if (
        $year === null ||
        $month === null ||
        $day === null ||
        !is_numeric($year) ||
        !is_numeric($month) ||
        !is_numeric($day)
    ) {
        return '—';
    }

    $months = [
        1  => 'Meskerem',
        2  => 'Tikimt',
        3  => 'Hidar',
        4  => 'Tahsas',
        5  => 'Tir',
        6  => 'Yekatit',
        7  => 'Megabit',
        8  => 'Miazia',
        9  => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    $monthNumber = (int) $month;

    if (!isset($months[$monthNumber])) {
        return '—';
    }

    return sprintf(
        '%d %s %d',
        (int) $day,
        $months[$monthNumber],
        (int) $year
    );
}

/*
|--------------------------------------------------------------------------
| Principal Information
|--------------------------------------------------------------------------
*/

$principalName = (string) ($_SESSION['full_name'] ?? 'Principal');
$principalEmail = '';
$principalPhone = '';

$principalId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        full_name,
        email,
        phone
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $principalId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $principalName = (string) ($row['full_name'] ?? $principalName);
        $principalEmail = (string) ($row['email'] ?? '');
        $principalPhone = (string) ($row['phone'] ?? '');
    }

    $stmt->close();
}

$principalInitials = getInitials($principalName);

/*
|--------------------------------------------------------------------------
| Request Filters
|--------------------------------------------------------------------------
*/

$selectedAcademicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$selectedSemesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

$selectedGrade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$selectedSection = trim((string) ($_GET['section'] ?? ''));

$selectedSubjectId = isset($_GET['subject_id'])
    ? (int) $_GET['subject_id']
    : 0;

$search = trim((string) ($_GET['search'] ?? ''));

$currentPage = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$studentsPerPage = 15;

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/

$academicYears = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    ORDER BY id DESC
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $academicYears[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Default Academic Year
|--------------------------------------------------------------------------
*/

$selectedAcademicYear = null;

if (!empty($academicYears)) {

    foreach ($academicYears as $academicYear) {
        if ((int) $academicYear['id'] === $selectedAcademicYearId) {
            $selectedAcademicYear = $academicYear;
            break;
        }
    }

    if ($selectedAcademicYear === null) {
        foreach ($academicYears as $academicYear) {
            if (
                strtolower(trim((string) $academicYear['status'])) === 'active'
            ) {
                $selectedAcademicYear = $academicYear;
                break;
            }
        }
    }

    if ($selectedAcademicYear === null) {
        $selectedAcademicYear = $academicYears[0];
    }

    $selectedAcademicYearId = (int) $selectedAcademicYear['id'];
}

/*
|--------------------------------------------------------------------------
| Semesters
|--------------------------------------------------------------------------
|
| All four semesters are included:
| - Mid Semester
| - First Semester
| - Quarter Semester
| - Second Semester
|--------------------------------------------------------------------------
*/

$semesters = [];

if ($selectedAcademicYearId > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            order_number,
            status,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day
        FROM semesters
        WHERE academic_year_id = ?
        ORDER BY order_number ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedAcademicYearId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $semesters[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Validate / Select Semester
|--------------------------------------------------------------------------
*/

$selectedSemester = null;

foreach ($semesters as $semester) {

    if ((int) $semester['id'] === $selectedSemesterId) {
        $selectedSemester = $semester;
        break;
    }
}

if ($selectedSemester === null && !empty($semesters)) {

    foreach ($semesters as $semester) {

        if (
            strtolower(trim((string) $semester['status'])) === 'active'
        ) {
            $selectedSemester = $semester;
            break;
        }
    }
}

if ($selectedSemester === null && !empty($semesters)) {
    $selectedSemester = $semesters[0];
}

if ($selectedSemester !== null) {
    $selectedSemesterId = (int) $selectedSemester['id'];
}

/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

$grades = [];

$stmt = $conn->prepare("
    SELECT
        id,
        grade_number,
        name
    FROM grades
    ORDER BY grade_number ASC
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate Grade
|--------------------------------------------------------------------------
*/

$validGradeNumbers = [];

foreach ($grades as $grade) {
    $validGradeNumbers[] = (int) $grade['grade_number'];
}

if (
    $selectedGrade > 0 &&
    !in_array($selectedGrade, $validGradeNumbers, true)
) {
    $selectedGrade = 0;
}

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

$sections = [];

if (
    $selectedAcademicYearId > 0 &&
    $selectedGrade > 0
) {

    $stmt = $conn->prepare("
        SELECT DISTINCT
            sec.id,
            sec.code,
            sec.name
        FROM sections sec
        INNER JOIN student_registrations sr
            ON sr.section_id = sec.id
        INNER JOIN grades g
            ON g.id = sr.grade_id
        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
        ORDER BY sec.code ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'ii',
            $selectedAcademicYearId,
            $selectedGrade
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Validate Section
|--------------------------------------------------------------------------
*/

$validSections = [];

foreach ($sections as $section) {
    $validSections[] = (string) $section['code'];
}

if (
    $selectedSection !== '' &&
    !in_array($selectedSection, $validSections, true)
) {
    $selectedSection = '';
}

/*
|--------------------------------------------------------------------------
| Subjects
|--------------------------------------------------------------------------
|
| Subjects come from:
| 1. Teacher assignments
| 2. Existing results
|--------------------------------------------------------------------------
*/

$subjects = [];
$subjectMap = [];

/*
|--------------------------------------------------------------------------
| Subjects From Teacher Assignments
|--------------------------------------------------------------------------
*/

if (
    $selectedAcademicYearId > 0 &&
    $selectedGrade > 0 &&
    $selectedSection !== '' &&
    $selectedAcademicYear !== null
) {

    /*
     * IMPORTANT:
     * bind_param() requires variables passed by reference.
     * Do NOT use an assignment expression directly inside bind_param().
     */

    $academicYearNameForSubject =
        (string) ($selectedAcademicYear['name'] ?? '');

    $stmt = $conn->prepare("
        SELECT DISTINCT
            gs.id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.academic_year = ?
          AND sta.grade = ?
          AND sta.section = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY gs.subject_name ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'sis',
            $academicYearNameForSubject,
            $selectedGrade,
            $selectedSection
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $subjectId = (int) $row['id'];

            $subjectMap[$subjectId] = [
                'id' => $subjectId,
                'subject_name' => (string) $row['subject_name'],
            ];
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Subjects From Existing Results
|--------------------------------------------------------------------------
*/

if (
    $selectedAcademicYearId > 0 &&
    $selectedGrade > 0 &&
    $selectedSection !== ''
) {

    $stmt = $conn->prepare("
        SELECT DISTINCT
            gs.id,
            gs.subject_name
        FROM results r
        INNER JOIN grade_subjects gs
            ON gs.id = r.grade_subject_id
        INNER JOIN student_registrations sr
            ON sr.id = r.student_registration_id
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
          AND gs.is_active = 1
        ORDER BY gs.subject_name ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'iis',
            $selectedAcademicYearId,
            $selectedGrade,
            $selectedSection
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $subjectId = (int) $row['id'];

            if (!isset($subjectMap[$subjectId])) {
                $subjectMap[$subjectId] = [
                    'id' => $subjectId,
                    'subject_name' => (string) $row['subject_name'],
                ];
            }
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Convert Subject Map To Array
|--------------------------------------------------------------------------
*/

$subjects = array_values($subjectMap);

usort(
    $subjects,
    static function (array $a, array $b): int {
        return strcasecmp(
            (string) $a['subject_name'],
            (string) $b['subject_name']
        );
    }
);

/*
|--------------------------------------------------------------------------
| Validate Subject
|--------------------------------------------------------------------------
*/

$validSubjectIds = [];

foreach ($subjects as $subject) {
    $validSubjectIds[] = (int) $subject['id'];
}

if (
    $selectedSubjectId > 0 &&
    !in_array($selectedSubjectId, $validSubjectIds, true)
) {
    $selectedSubjectId = 0;
}

/*
|--------------------------------------------------------------------------
| Search Pattern
|--------------------------------------------------------------------------
*/

$searchPattern = '%' . $search . '%';

/*
|--------------------------------------------------------------------------
| Results Summary
|--------------------------------------------------------------------------
*/

$totalStudents = 0;
$recordedResults = 0;
$notRecordedResults = 0;

if (
    $selectedAcademicYearId > 0 &&
    $selectedSemesterId > 0 &&
    $selectedGrade > 0 &&
    $selectedSection !== '' &&
    $selectedSubjectId > 0
) {

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_students,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.id IS NOT NULL
                         AND r.mark IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS recorded_results

        FROM student_registrations sr

        INNER JOIN students s
            ON s.id = sr.student_id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        LEFT JOIN results r
            ON r.student_registration_id = sr.id
           AND r.grade_subject_id = ?
           AND r.semester_id = ?

        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
          AND s.full_name LIKE ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            'iiiiss',
            $selectedSubjectId,
            $selectedSemesterId,
            $selectedAcademicYearId,
            $selectedGrade,
            $selectedSection,
            $searchPattern
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {

            $totalStudents = (int) ($row['total_students'] ?? 0);

            $recordedResults = (int) ($row['recorded_results'] ?? 0);
        }

        $stmt->close();
    }

    $notRecordedResults =
        max(0, $totalStudents - $recordedResults);
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil($totalStudents / $studentsPerPage)
);

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $studentsPerPage;

/*
|--------------------------------------------------------------------------
| Students + Results
|--------------------------------------------------------------------------
*/

$students = [];

if (
    $selectedAcademicYearId > 0 &&
    $selectedSemesterId > 0 &&
    $selectedGrade > 0 &&
    $selectedSection !== '' &&
    $selectedSubjectId > 0
) {

    $stmt = $conn->prepare("
        SELECT
            sr.id AS registration_id,
            s.id AS student_id,
            s.student_code,
            s.full_name,

            r.id AS result_id,
            r.mark

        FROM student_registrations sr

        INNER JOIN students s
            ON s.id = sr.student_id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        LEFT JOIN results r
            ON r.student_registration_id = sr.id
           AND r.grade_subject_id = ?
           AND r.semester_id = ?

        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
          AND s.full_name LIKE ?

        ORDER BY
            s.full_name ASC,
            sr.id ASC

        LIMIT ? OFFSET ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            'iiiissii',
            $selectedSubjectId,
            $selectedSemesterId,
            $selectedAcademicYearId,
            $selectedGrade,
            $selectedSection,
            $searchPattern,
            $studentsPerPage,
            $offset
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Pagination URL Helper
|--------------------------------------------------------------------------
*/

function buildPageUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
}

/*
|--------------------------------------------------------------------------
| Export URL
|--------------------------------------------------------------------------
*/

$exportParams = [
    'academic_year_id' => $selectedAcademicYearId,
    'semester_id' => $selectedSemesterId,
    'grade' => $selectedGrade,
    'section' => $selectedSection,
    'subject_id' => $selectedSubjectId,
    'search' => $search,
];

$exportUrl = 'export-result.php?' . http_build_query($exportParams);

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
        content="Principal Results - Bole Kale Hiwot School Management System"
    >

    <title>Results | Principal | BKHS</title>

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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #4f46e5;
            --primary-dark: #312e81;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;
            --white: #ffffff;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        a {
            text-decoration: none;
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
            background: var(--sidebar);
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .brand-title {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.5px;
            color: #fff;
        }

        .brand-subtitle {
            margin-top: 2px;
            font-size: 11px;
            color: #9ca3af;
            font-weight: 500;
        }

        .sidebar-menu {
            padding: 20px 14px;
        }

        .menu-label {
            padding: 0 12px 8px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            font-weight: 500;
            transition: .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            font-size: 17px;
        }

        .sidebar-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .sidebar-link.active {
            color: #fff;
            background: rgba(79, 70, 229, .22);
        }

        .sidebar-link.active i {
            color: #818cf8;
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
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            backdrop-filter: blur(8px);
        }

        .page-heading h1 {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .principal-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
        }

        .principal-details {
            line-height: 1.25;
        }

        .principal-details strong {
            display: block;
            font-size: 13px;
        }

        .principal-details span {
            color: var(--muted);
            font-size: 11px;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .card-box {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 7px rgba(15, 23, 42, .03);
        }

        /*
        |--------------------------------------------------------------------------
        | Read Only Notice
        |--------------------------------------------------------------------------
        */

        .readonly-notice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 17px;
            border-radius: 12px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            margin-bottom: 20px;
        }

        .readonly-notice i {
            font-size: 20px;
            margin-top: 1px;
        }

        .readonly-notice strong {
            display: block;
            margin-bottom: 2px;
            font-size: 13px;
        }

        .readonly-notice span {
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        .filter-card {
            padding: 20px;
            margin-bottom: 20px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .section-title i {
            color: var(--primary);
            font-size: 18px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(79, 70, 229, .1);
        }

        .btn {
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            min-height: 42px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        /*
        |--------------------------------------------------------------------------
        | Semester Cards
        |--------------------------------------------------------------------------
        */

        .semester-card {
            height: 100%;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            cursor: pointer;
            transition: .2s ease;
        }

        .semester-card:hover {
            border-color: #a5b4fc;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, .06);
        }

        .semester-card.active {
            border-color: var(--primary);
            background: #eef2ff;
            box-shadow: 0 0 0 1px var(--primary);
        }

        .semester-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 13px;
        }

        .semester-card.active .semester-icon {
            background: var(--primary);
            color: #fff;
        }

        .semester-name {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 7px;
        }

        .semester-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            background: #f3f4f6;
            color: #4b5563;
            margin-bottom: 12px;
        }

        .semester-status.active-status {
            background: #dcfce7;
            color: #166534;
        }

        .semester-dates {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.7;
        }

        /*
        |--------------------------------------------------------------------------
        | Summary Cards
        |--------------------------------------------------------------------------
        */

        .summary-card {
            padding: 18px;
            height: 100%;
        }

        .summary-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: var(--primary);
            font-size: 19px;
            margin-bottom: 14px;
        }

        .summary-number {
            font-size: 25px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 5px;
        }

        .summary-label {
            color: var(--muted);
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Results Card
        |--------------------------------------------------------------------------
        */

        .results-card {
            overflow: hidden;
        }

        .results-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .results-header-title {
            font-size: 15px;
            font-weight: 700;
        }

        .results-header-subtitle {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .results-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .results-table {
            width: 100%;
            min-width: 700px;
            margin: 0;
        }

        .results-table th {
            background: #f8fafc;
            color: #6b7280;
            border-bottom: 1px solid var(--border);
            padding: 13px 16px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .results-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 13px;
        }

        .results-table tbody tr:hover {
            background: #fafafa;
        }

        .student-name {
            font-weight: 600;
        }

        .student-code {
            font-family: monospace;
            font-size: 12px;
            color: #4b5563;
            background: #f3f4f6;
            border-radius: 6px;
            padding: 4px 7px;
        }

        .mark-badge {
            display: inline-flex;
            min-width: 54px;
            justify-content: center;
            padding: 5px 9px;
            border-radius: 7px;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 700;
            font-size: 12px;
        }

        .not-recorded {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 7px;
            background: #fff7ed;
            color: #c2410c;
            font-weight: 600;
            font-size: 11px;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            font-size: 38px;
            margin-bottom: 12px;
            color: #9ca3af;
        }

        .empty-state strong {
            display: block;
            color: #374151;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .empty-state span {
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            padding: 16px 20px;
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
            border-radius: 7px !important;
            margin: 0 2px;
            border-color: var(--border);
            color: #374151;
            font-size: 12px;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px 16px 95px;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                background: #fff;
                border-top: 1px solid var(--border);
                display: flex;
                align-items: stretch;
                justify-content: space-around;
                z-index: 1100;
                box-shadow: 0 -4px 15px rgba(15, 23, 42, .06);
            }

            .mobile-bottom-link {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                color: #6b7280;
                font-size: 9px;
                font-weight: 600;
            }

            .mobile-bottom-link i {
                font-size: 18px;
            }

            .mobile-bottom-link.active {
                color: var(--primary);
            }

            .principal-details {
                display: none;
            }
        }

        @media (max-width: 575.98px) {

            .topbar {
                height: 68px;
            }

            .page-heading h1 {
                font-size: 18px;
            }

            .page-heading p {
                display: none;
            }

            .content {
                padding: 16px 12px 90px;
            }

            .filter-card {
                padding: 15px;
            }

            .results-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .results-header .btn {
                width: 100%;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: stretch;
            }

            .pagination {
                justify-content: center;
            }

            .readonly-notice {
                padding: 13px;
            }
        }

    </style>

</head>

<body>

<!-- ===================================================================== -->
<!-- Sidebar -->
<!-- ===================================================================== -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <div>
            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                School Management
            </div>
        </div>

    </div>

    <nav class="sidebar-menu">

        <div class="menu-label">
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
            href="students.php"
            class="sidebar-link"
        >
            <i class="bi bi-people"></i>
            <span>Students</span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check"></i>
            <span>Attendance</span>
        </a>

        <a
            href="results.php"
            class="sidebar-link active"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Results</span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcements</span>
        </a>

        <div class="menu-label mt-4">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
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

<!-- ===================================================================== -->
<!-- Main -->
<!-- ===================================================================== -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="page-heading">

            <h1>
                Results
            </h1>

            <p>
                View student academic results
            </p>

        </div>

        <div class="principal-profile">

            <div class="principal-details">

                <strong>
                    <?= e($principalName) ?>
                </strong>

                <span>
                    Principal
                </span>

            </div>

            <div class="principal-avatar">
                <?= e($principalInitials) ?>
            </div>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <!-- Read-only notice -->

        <div class="readonly-notice">

            <i class="bi bi-eye"></i>

            <div>

                <strong>
                    Read-only Results
                </strong>

                <span>
                    As principal, you can view and export student results.
                    Result marks cannot be edited from this page.
                </span>

            </div>

        </div>

        <!-- ============================================================= -->
        <!-- Filters -->
        <!-- ============================================================= -->

        <div class="card-box filter-card">

            <div class="section-title">

                <i class="bi bi-funnel"></i>

                <span>
                    Result Filters
                </span>

            </div>

            <form
                method="GET"
                action=""
            >

                <div class="row g-3">

                    <!-- Academic Year -->

                    <div class="col-lg-3 col-md-6">

                        <label
                            class="form-label"
                            for="academic_year_id"
                        >
                            Academic Year
                        </label>

                        <select
                            class="form-select"
                            id="academic_year_id"
                            name="academic_year_id"
                            onchange="this.form.submit()"
                        >

                            <?php foreach ($academicYears as $academicYear): ?>

                                <option
                                    value="<?= (int) $academicYear['id'] ?>"
                                    <?= (int) $academicYear['id'] === $selectedAcademicYearId ? 'selected' : '' ?>
                                >
                                    <?= e((string) $academicYear['name']) ?>

                                    <?php if (
                                        strtolower(trim((string) $academicYear['status'])) === 'active'
                                    ): ?>
                                        — Active
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Semester -->

                    <div class="col-lg-3 col-md-6">

                        <label
                            class="form-label"
                            for="semester_id"
                        >
                            Semester
                        </label>

                        <select
                            class="form-select"
                            id="semester_id"
                            name="semester_id"
                            onchange="this.form.submit()"
                        >

                            <?php if (!empty($semesters)): ?>

                                <?php foreach ($semesters as $semester): ?>

                                    <option
                                        value="<?= (int) $semester['id'] ?>"
                                        <?= (int) $semester['id'] === $selectedSemesterId ? 'selected' : '' ?>
                                    >
                                        <?= e((string) $semester['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <option value="">
                                    No semesters available
                                </option>

                            <?php endif; ?>

                        </select>

                    </div>

                    <!-- Grade -->

                    <div class="col-lg-2 col-md-6">

                        <label
                            class="form-label"
                            for="grade"
                        >
                            Grade
                        </label>

                        <select
                            class="form-select"
                            id="grade"
                            name="grade"
                            onchange="this.form.submit()"
                        >

                            <option value="0">
                                Select Grade
                            </option>

                            <?php foreach ($grades as $grade): ?>

                                <option
                                    value="<?= (int) $grade['grade_number'] ?>"
                                    <?= (int) $grade['grade_number'] === $selectedGrade ? 'selected' : '' ?>
                                >
                                    <?= e((string) $grade['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Section -->

                    <div class="col-lg-2 col-md-6">

                        <label
                            class="form-label"
                            for="section"
                        >
                            Section
                        </label>

                        <select
                            class="form-select"
                            id="section"
                            name="section"
                            onchange="this.form.submit()"
                            <?= $selectedGrade <= 0 ? 'disabled' : '' ?>
                        >

                            <option value="">
                                Select Section
                            </option>

                            <?php foreach ($sections as $section): ?>

                                <option
                                    value="<?= e((string) $section['code']) ?>"
                                    <?= (string) $section['code'] === $selectedSection ? 'selected' : '' ?>
                                >
                                    <?= e((string) $section['code']) ?>

                                    <?php if (
                                        trim((string) $section['name']) !== ''
                                    ): ?>
                                        — <?= e((string) $section['name']) ?>
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Subject -->

                    <div class="col-lg-2 col-md-6">

                        <label
                            class="form-label"
                            for="subject_id"
                        >
                            Subject
                        </label>

                        <select
                            class="form-select"
                            id="subject_id"
                            name="subject_id"
                            onchange="this.form.submit()"
                            <?= $selectedSection === '' ? 'disabled' : '' ?>
                        >

                            <option value="0">
                                Select Subject
                            </option>

                            <?php foreach ($subjects as $subject): ?>

                                <option
                                    value="<?= (int) $subject['id'] ?>"
                                    <?= (int) $subject['id'] === $selectedSubjectId ? 'selected' : '' ?>
                                >
                                    <?= e((string) $subject['subject_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Search -->

                    <div class="col-lg-8 col-md-8">

                        <label
                            class="form-label"
                            for="search"
                        >
                            Search Student
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="search"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Search by student name..."
                        >

                    </div>

                    <!-- Filter Button -->

                    <div class="col-lg-2 col-md-4 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >
                            <i class="bi bi-search me-1"></i>
                            View Results
                        </button>

                    </div>

                    <!-- Clear -->

                    <div class="col-lg-2 col-md-4 d-flex align-items-end">

                        <a
                            href="results.php"
                            class="btn btn-light border w-100"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Clear
                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- ============================================================= -->
        <!-- Semester Cards -->
        <!-- ============================================================= -->

        <?php if (!empty($semesters)): ?>

            <div class="row g-3 mb-4">

                <?php foreach ($semesters as $semester): ?>

                    <?php

                    $isActiveSemester =
                        (int) $semester['id'] === $selectedSemesterId;

                    $statusText =
                        (string) ($semester['status'] ?? '');

                    $isSemesterActive =
                        strtolower(trim($statusText)) === 'active';

                    ?>

                    <div class="col-xl-3 col-lg-3 col-md-6">

                        <a
                            href="?<?= http_build_query([
                                'academic_year_id' => $selectedAcademicYearId,
                                'semester_id' => (int) $semester['id'],
                                'grade' => $selectedGrade,
                                'section' => $selectedSection,
                                'subject_id' => $selectedSubjectId,
                                'search' => $search,
                            ]) ?>"
                            class="text-decoration-none text-dark"
                        >

                            <div
                                class="semester-card <?= $isActiveSemester ? 'active' : '' ?>"
                            >

                                <div class="semester-icon">
                                    <i class="bi bi-calendar3"></i>
                                </div>

                                <div class="semester-name">
                                    <?= e((string) $semester['name']) ?>
                                </div>

                                <div
                                    class="semester-status <?= $isSemesterActive ? 'active-status' : '' ?>"
                                >
                                    <?= e($statusText !== '' ? $statusText : 'Not Set') ?>
                                </div>

                                <div class="semester-dates">

                                    <div>
                                        <strong>Start:</strong>
                                        <?= e(
                                            formatEthiopianDate(
                                                $semester['start_year'] ?? null,
                                                $semester['start_month'] ?? null,
                                                $semester['start_day'] ?? null
                                            )
                                        ) ?>
                                    </div>

                                    <div>
                                        <strong>End:</strong>
                                        <?= e(
                                            formatEthiopianDate(
                                                $semester['end_year'] ?? null,
                                                $semester['end_month'] ?? null,
                                                $semester['end_day'] ?? null
                                            )
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <!-- ============================================================= -->
        <!-- Summary -->
        <!-- ============================================================= -->

        <?php if (
            $selectedGrade > 0 &&
            $selectedSection !== '' &&
            $selectedSubjectId > 0 &&
            $selectedSemesterId > 0
        ): ?>

            <div class="row g-3 mb-4">

                <!-- Students -->

                <div class="col-lg-4 col-md-4">

                    <div class="card-box summary-card">

                        <div class="summary-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div class="summary-number">
                            <?= number_format($totalStudents) ?>
                        </div>

                        <div class="summary-label">
                            Students
                        </div>

                    </div>

                </div>

                <!-- Recorded -->

                <div class="col-lg-4 col-md-4">

                    <div class="card-box summary-card">

                        <div class="summary-icon">
                            <i class="bi bi-check2-circle"></i>
                        </div>

                        <div class="summary-number">
                            <?= number_format($recordedResults) ?>
                        </div>

                        <div class="summary-label">
                            Results Recorded
                        </div>

                    </div>

                </div>

                <!-- Not Recorded -->

                <div class="col-lg-4 col-md-4">

                    <div class="card-box summary-card">

                        <div class="summary-icon">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>

                        <div class="summary-number">
                            <?= number_format($notRecordedResults) ?>
                        </div>

                        <div class="summary-label">
                            Not Recorded
                        </div>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <!-- ============================================================= -->
        <!-- Results -->
        <!-- ============================================================= -->

        <div class="card-box results-card">

            <div class="results-header">

                <div>

                    <div class="results-header-title">
                        Student Results
                    </div>

                    <div class="results-header-subtitle">

                        <?php if (
                            $selectedAcademicYear !== null &&
                            $selectedSemester !== null
                        ): ?>

                            <?= e((string) $selectedAcademicYear['name']) ?>

                            •
                            <?= e((string) $selectedSemester['name']) ?>

                            <?php if ($selectedGrade > 0): ?>
                                • Grade <?= $selectedGrade ?>
                            <?php endif; ?>

                            <?php if ($selectedSection !== ''): ?>
                                • Section <?= e($selectedSection) ?>
                            <?php endif; ?>

                        <?php else: ?>

                            Select filters to view results.

                        <?php endif; ?>

                    </div>

                </div>

                <!-- Export -->

                <?php if (
                    $selectedAcademicYearId > 0 &&
                    $selectedSemesterId > 0 &&
                    $selectedGrade > 0 &&
                    $selectedSection !== '' &&
                    $selectedSubjectId > 0
                ): ?>

                    <a
                        href="<?= e($exportUrl) ?>"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-download me-1"></i>
                        Export Results
                    </a>

                <?php endif; ?>

            </div>

            <?php if (
                $selectedAcademicYearId <= 0 ||
                $selectedSemesterId <= 0 ||
                $selectedGrade <= 0 ||
                $selectedSection === '' ||
                $selectedSubjectId <= 0
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-funnel"></i>

                    <strong>
                        Select Result Filters
                    </strong>

                    <span>
                        Choose an academic year, semester, grade,
                        section and subject to view student results.
                    </span>

                </div>

            <?php elseif (empty($students)): ?>

                <div class="empty-state">

                    <i class="bi bi-clipboard-x"></i>

                    <strong>
                        No Students Found
                    </strong>

                    <span>
                        No students match the selected filters.
                    </span>

                </div>

            <?php else: ?>

                <div class="results-table-wrapper">

                    <table class="table results-table">

                        <thead>

                            <tr>

                                <th style="width: 70px;">
                                    No.
                                </th>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Student Code
                                </th>

                                <th style="width: 150px;">
                                    Mark
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($students as $index => $student): ?>

                                <?php
                                $rowNumber =
                                    $offset + $index + 1;

                                $hasMark =
                                    $student['mark'] !== null &&
                                    $student['mark'] !== '';

                                ?>

                                <tr>

                                    <td>
                                        <?= $rowNumber ?>
                                    </td>

                                    <td>

                                        <div class="student-name">
                                            <?= e(
                                                (string) $student['full_name']
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <span class="student-code">
                                            <?= e(
                                                (string) $student['student_code']
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <?php if ($hasMark): ?>

                                            <span class="mark-badge">
                                                <?= e(
                                                    formatMark(
                                                        $student['mark']
                                                    )
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="not-recorded">
                                                Not Recorded
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- Pagination -->

                <?php if ($totalPages > 1): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing
                            <strong>
                                <?= $offset + 1 ?>
                            </strong>

                            -
                            <strong>
                                <?= min(
                                    $offset + $studentsPerPage,
                                    $totalStudents
                                ) ?>
                            </strong>

                            of
                            <strong>
                                <?= number_format($totalStudents) ?>
                            </strong>
                            students

                        </div>

                        <nav aria-label="Results pagination">

                            <ul class="pagination">

                                <!-- Previous -->

                                <li
                                    class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $currentPage > 1 ? e(buildPageUrl($currentPage - 1)) : '#' ?>"
                                        aria-label="Previous"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

                                <?php

                                $startPage = max(
                                    1,
                                    $currentPage - 2
                                );

                                $endPage = min(
                                    $totalPages,
                                    $currentPage + 2
                                );

                                ?>

                                <?php if ($startPage > 1): ?>

                                    <li class="page-item">

                                        <a
                                            class="page-link"
                                            href="<?= e(buildPageUrl(1)) ?>"
                                        >
                                            1
                                        </a>

                                    </li>

                                    <?php if ($startPage > 2): ?>

                                        <li class="page-item disabled">

                                            <span class="page-link">
                                                ...
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
                                        class="page-item <?= $page === $currentPage ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(buildPageUrl($page)) ?>"
                                        >
                                            <?= $page ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>

                                    <?php if ($endPage < $totalPages - 1): ?>

                                        <li class="page-item disabled">

                                            <span class="page-link">
                                                ...
                                            </span>

                                        </li>

                                    <?php endif; ?>

                                    <li class="page-item">

                                        <a
                                            class="page-link"
                                            href="<?= e(buildPageUrl($totalPages)) ?>"
                                        >
                                            <?= $totalPages ?>
                                        </a>

                                    </li>

                                <?php endif; ?>

                                <!-- Next -->

                                <li
                                    class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $currentPage < $totalPages ? e(buildPageUrl($currentPage + 1)) : '#' ?>"
                                        aria-label="Next"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</main>

<!-- ===================================================================== -->
<!-- Mobile Bottom Navigation -->
<!-- ===================================================================== -->

<nav class="mobile-bottom-nav">

    <a
        href="dashboard.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-grid-1x2"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="attendance.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-calendar-check"></i>
        <span>Attendance</span>
    </a>

    <a
        href="results.php"
        class="mobile-bottom-link active"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Results</span>
    </a>

    <a
        href="profile.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../auth/logout.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</nav>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>