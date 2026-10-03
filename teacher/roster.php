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
require_once '../registrar/roster-data.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

$teacher = null;
$academicYear = null;
$homeroom = null;

$rosterResult = [
    'students' => [],
    'subjects' => []
];

$allRoster = [];
$subjects = [];
$displayRoster = [];

$selectedRosterType = $_GET['roster_type'] ?? 'first';

$allowedRosterTypes = [
    'first',
    'second',
    'annual'
];

if (!in_array($selectedRosterType, $allowedRosterTypes, true)) {
    $selectedRosterType = 'first';
}

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 20;

$allowedPerPage = [
    10,
    20,
    30,
    50,
    100
];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$page = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($page < 1) {
    $page = 1;
}

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
        return strtoupper(
            substr($name, 0, 1)
        );
    }

    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(
            substr($part, 0, 1)
        );
    }

    return $initials ?: 'T';
}

/*
|--------------------------------------------------------------------------
| Resolve Teacher Photo
|--------------------------------------------------------------------------
*/

function resolveTeacherPhoto(
    ?string $storedPath
): string {

    $storedPath = trim(
        (string) $storedPath
    );

    if ($storedPath === '') {
        return '';
    }

    if (
        str_starts_with(
            $storedPath,
            'http://'
        ) ||
        str_starts_with(
            $storedPath,
            'https://'
        )
    ) {
        return $storedPath;
    }

    if (
        str_starts_with(
            $storedPath,
            '/'
        )
    ) {
        return $storedPath;
    }

    if (
        str_starts_with(
            $storedPath,
            'public/'
        )
    ) {
        return '../' . $storedPath;
    }

    if (
        str_starts_with(
            $storedPath,
            'uploads/'
        )
    ) {
        return '../' . $storedPath;
    }

    if (
        str_contains(
            $storedPath,
            '/'
        )
    ) {
        return '../' .
            ltrim(
                $storedPath,
                './'
            );
    }

    $profileFile =
        __DIR__ .
        '/../public/uploads/profiles/' .
        $storedPath;

    if (is_file($profileFile)) {

        return
            '../public/uploads/profiles/' .
            $storedPath;
    }

    $oldTeacherFile =
        __DIR__ .
        '/../uploads/teachers/' .
        $storedPath;

    if (is_file($oldTeacherFile)) {

        return
            '../uploads/teachers/' .
            $storedPath;
    }

    $publicImageFile =
        __DIR__ .
        '/../public/image/' .
        $storedPath;

    if (is_file($publicImageFile)) {

        return
            '../public/image/' .
            $storedPath;
    }

    return
        '../public/uploads/profiles/' .
        $storedPath;
}

/*
|--------------------------------------------------------------------------
| Normalize Subject
|--------------------------------------------------------------------------
*/

function normalizeSubjectName(
    mixed $subject
): string {

    if (is_array($subject)) {

        foreach (
            [
                'subject_name',
                'name',
                'subject'
            ] as $key
        ) {

            if (
                isset($subject[$key]) &&
                trim(
                    (string) $subject[$key]
                ) !== ''
            ) {
                return trim(
                    (string) $subject[$key]
                );
            }
        }
    }

    if (
        is_string($subject) ||
        is_numeric($subject)
    ) {
        return trim(
            (string) $subject
        );
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Normalize Marks
|--------------------------------------------------------------------------
*/

function normalizeMarksForTeacherRoster(
    mixed $marks
): array {

    if (!is_array($marks)) {
        return [];
    }

    $normalized = [];

    foreach ($marks as $key => $value) {

        $subjectName = '';

        if (is_array($value)) {

            $subjectName =
                normalizeSubjectName(
                    $value
                );

        } else {

            $subjectName =
                is_string($key)
                    ? trim($key)
                    : '';
        }

        if ($subjectName === '') {

            if (
                is_string($key) &&
                !is_numeric($key)
            ) {
                $subjectName = trim($key);
            }
        }

        if ($subjectName === '') {
            continue;
        }

        $mark = null;

        if (is_array($value)) {

            foreach (
                [
                    'mark',
                    'score',
                    'total',
                    'average',
                    'value'
                ] as $markKey
            ) {

                if (
                    array_key_exists(
                        $markKey,
                        $value
                    )
                ) {

                    $mark =
                        $value[$markKey];

                    break;
                }
            }

        } else {

            $mark = $value;
        }

        $normalized[$subjectName] =
            $mark;
    }

    return $normalized;
}

/*
|--------------------------------------------------------------------------
| Normalize Student Row
|--------------------------------------------------------------------------
*/

function normalizeTeacherRosterStudent(
    array $student,
    int $index
): array {

    $studentName =
        $student['student_name']
        ?? $student['full_name']
        ?? $student['name']
        ?? '';

    $studentCode =
        $student['student_code']
        ?? $student['code']
        ?? '';

    if (
        isset($student['subjects']) &&
        is_array($student['subjects'])
    ) {

        $student['subjects'] =
            normalizeMarksForTeacherRoster(
                $student['subjects']
            );

    } elseif (
        isset($student['marks']) &&
        is_array($student['marks'])
    ) {

        $student['subjects'] =
            normalizeMarksForTeacherRoster(
                $student['marks']
            );

    } else {

        $student['subjects'] = [];
    }

    $student['student_name'] =
        (string) $studentName;

    $student['student_code'] =
        (string) $studentCode;

    $student['row_number'] =
        $index + 1;

    return $student;
}

/*
|--------------------------------------------------------------------------
| Page URL
|--------------------------------------------------------------------------
*/

function rosterPageUrl(
    int $page,
    string $rosterType,
    int $perPage
): string {

    return 'roster.php?' .
        http_build_query([
            'roster_type' => $rosterType,
            'page'        => $page,
            'per_page'    => $perPage
        ]);
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

$teacherStmt =
    $conn->prepare(
        $teacherSql
    );

if ($teacherStmt) {

    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult =
        $teacherStmt->get_result();

    $teacher =
        $teacherResult->fetch_assoc();

    $teacherStmt->close();
}

if (!$teacher) {

    session_destroy();

    header(
        'Location: ../auth/login.php'
    );

    exit;
}

$teacherName =
    $teacher['full_name']
    ?? 'Teacher';

$teacherInitials =
    getInitials(
        (string) $teacherName
    );

$teacherPhoto =
    resolveTeacherPhoto(
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

$academicYearResult =
    $conn->query(
        $academicYearSql
    );

if ($academicYearResult) {

    $academicYear =
        $academicYearResult->fetch_assoc();
}

$academicYearId =
    (int) (
        $academicYear['id'] ?? 0
    );

$academicYearName =
    (string) (
        $academicYear['name'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| Load Teacher's Active Homeroom
|--------------------------------------------------------------------------
|
| We match:
|
| homeroom_teacher_assignments.academic_year
|       ->
| academic_years.name
|
| grade number
|       ->
| grades.grade_number
|
| section code
|       ->
| sections.code
|
*/

if (
    $academicYearId > 0 &&
    $academicYearName !== ''
) {

    $homeroomSql = "
        SELECT
            hta.id AS homeroom_assignment_id,

            hta.grade AS assigned_grade,
            hta.section AS assigned_section,

            ay.id AS academic_year_id,
            ay.name AS academic_year_name,

            g.id AS grade_id,
            g.name AS grade_name,
            g.grade_number,

            sec.id AS section_id,
            sec.name AS section_name,
            sec.code AS section_code

        FROM homeroom_teacher_assignments hta

        INNER JOIN academic_years ay
            ON ay.name = hta.academic_year

        INNER JOIN grades g
            ON g.grade_number = hta.grade

        INNER JOIN sections sec
            ON sec.code = hta.section

        WHERE hta.teacher_user_id = ?
          AND hta.academic_year = ?
          AND hta.is_active = 1
          AND ay.id = ?

        ORDER BY
            hta.id ASC

        LIMIT 1
    ";

    $homeroomStmt =
        $conn->prepare(
            $homeroomSql
        );

    if ($homeroomStmt) {

        $homeroomStmt->bind_param(
            'isi',
            $teacherUserId,
            $academicYearName,
            $academicYearId
        );

        $homeroomStmt->execute();

        $homeroomResult =
            $homeroomStmt->get_result();

        $homeroom =
            $homeroomResult->fetch_assoc();

        $homeroomStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Homeroom Information
|--------------------------------------------------------------------------
*/

$hasHomeroom =
    is_array($homeroom);

$selectedAcademicYearId =
    $hasHomeroom
        ? (int) (
            $homeroom['academic_year_id']
            ?? 0
        )
        : 0;

$selectedGradeId =
    $hasHomeroom
        ? (int) (
            $homeroom['grade_id']
            ?? 0
        )
        : 0;

$selectedSectionId =
    $hasHomeroom
        ? (int) (
            $homeroom['section_id']
            ?? 0
        )
        : 0;

$assignedGrade =
    $hasHomeroom
        ? (string) (
            $homeroom['assigned_grade']
            ?? ''
        )
        : '';

$assignedSection =
    $hasHomeroom
        ? (string) (
            $homeroom['assigned_section']
            ?? ''
        )
        : '';

$gradeName =
    $hasHomeroom
        ? (string) (
            $homeroom['grade_name']
            ?? ('Grade ' . $assignedGrade)
        )
        : '';

$sectionName =
    $hasHomeroom
        ? (string) (
            $homeroom['section_name']
            ?? $assignedSection
        )
        : '';

$sectionCode =
    $hasHomeroom
        ? (string) (
            $homeroom['section_code']
            ?? $assignedSection
        )
        : '';

/*
|--------------------------------------------------------------------------
| Load Roster
|--------------------------------------------------------------------------
*/

if (
    $hasHomeroom &&
    $selectedAcademicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0
) {

    $rosterResult = getRoster(
        $conn,
        $selectedAcademicYearId,
        $selectedGradeId,
        $selectedSectionId,
        $selectedRosterType
    );

    $allRoster =
        $rosterResult['students']
        ?? [];

    $subjects =
        $rosterResult['subjects']
        ?? [];

    if (!is_array($allRoster)) {
        $allRoster = [];
    }

    if (!is_array($subjects)) {
        $subjects = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Subjects
    |--------------------------------------------------------------------------
    */

    $normalizedSubjects = [];

    foreach ($subjects as $subject) {

        $subjectName =
            normalizeSubjectName(
                $subject
            );

        if ($subjectName === '') {
            continue;
        }

        if (
            !in_array(
                $subjectName,
                $normalizedSubjects,
                true
            )
        ) {
            $normalizedSubjects[] =
                $subjectName;
        }
    }

    $subjects =
        $normalizedSubjects;

    /*
    |--------------------------------------------------------------------------
    | Normalize Students
    |--------------------------------------------------------------------------
    */

    $normalizedRoster = [];

    foreach (
        $allRoster as $index => $student
    ) {

        if (!is_array($student)) {
            continue;
        }

        $normalizedRoster[] =
            normalizeTeacherRosterStudent(
                $student,
                count($normalizedRoster)
            );
    }

    $allRoster =
        $normalizedRoster;

    /*
    |--------------------------------------------------------------------------
    | Annual Roster
    |--------------------------------------------------------------------------
    */

    if (
        $selectedRosterType === 'annual'
    ) {

        foreach (
            $allRoster as &$student
        ) {

            $student['first_subjects'] =
                normalizeMarksForTeacherRoster(
                    $student['first_subjects']
                    ?? $student['first']['marks']
                    ?? []
                );

            $student['second_subjects'] =
                normalizeMarksForTeacherRoster(
                    $student['second_subjects']
                    ?? $student['second']['marks']
                    ?? []
                );

            $student['annual_subjects'] =
                normalizeMarksForTeacherRoster(
                    $student['annual_subjects']
                    ?? $student['annual']['marks']
                    ?? []
                );
        }

        unset($student);
    }
}

/*
|--------------------------------------------------------------------------
| Annual Availability
|--------------------------------------------------------------------------
*/

$annualReady = false;

if ($selectedAcademicYearId > 0) {

    $annualReady =
        isAnnualRosterReady(
            $conn,
            $selectedAcademicYearId
        );
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalStudents =
    count($allRoster);

$totalPages =
    $totalStudents > 0
        ? (int) ceil(
            $totalStudents / $perPage
        )
        : 1;

if ($page > $totalPages) {
    $page = $totalPages;
}

$pageStart =
    ($page - 1) * $perPage;

$displayRoster =
    array_slice(
        $allRoster,
        $pageStart,
        $perPage
    );

/*
|--------------------------------------------------------------------------
| Roster Labels
|--------------------------------------------------------------------------
*/

$rosterTypeLabels = [
    'first' =>
        'First Semester Roster',

    'second' =>
        'Second Semester Roster',

    'annual' =>
        'Annual Roster'
];

$rosterTypeLabel =
    $rosterTypeLabels[
        $selectedRosterType
    ];

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
|
| Export page will be built later.
|
*/

$canExport =
    $hasHomeroom &&
    $selectedAcademicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0 &&
    !empty($allRoster);

$exportUrl =
    'roster-export.php?' .
    http_build_query([
        'academic_year_id' =>
            $selectedAcademicYearId,

        'grade_id' =>
            $selectedGradeId,

        'section_id' =>
            $selectedSectionId,

        'roster_type' =>
            $selectedRosterType
    ]);

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

<title>
    Teacher Roster | BKHS
</title>

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
    gap: 9px;
    flex-shrink: 0;
}

.dashboard-button {
    min-height: 37px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border: 1px solid #dbeafe;
    border-radius: 9px;
    color: #1d4ed8;
    background: #eff6ff;
    font-size: 11px;
    font-weight: 700;
    transition: .2s ease;
}

.dashboard-button:hover {
    border-color: #93c5fd;
    color: #fff;
    background: var(--primary);
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
    max-width: 1800px;
    margin: 0 auto;
    padding: 25px 28px 35px;
}

/*
|--------------------------------------------------------------------------
| Page Header
|--------------------------------------------------------------------------
*/

.roster-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
}

.roster-heading {
    min-width: 0;
}

.roster-heading h2 {
    margin: 0;
    font-size: 23px;
    font-weight: 800;
}

.roster-heading p {
    margin: 6px 0 0;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.6;
}

.roster-badges {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.info-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-radius: 8px;
    color: #1e40af;
    background: #dbeafe;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

/*
|--------------------------------------------------------------------------
| Filter / Class Information Card
|--------------------------------------------------------------------------
*/

.filter-card {
    padding: 18px;
    margin-bottom: 18px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
}

.filter-grid {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr)
        minmax(0, 1fr)
        minmax(0, 1.1fr);
    gap: 14px;
}

.filter-item {
    min-width: 0;
}

.filter-label {
    display: block;
    margin-bottom: 6px;
    color: #374151;
    font-size: 10px;
    font-weight: 800;
}

.filter-display {
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 9px;
    color: #111827;
    background: #f9fafb;
    font-size: 11px;
    font-weight: 700;
}

.filter-display i {
    color: var(--primary);
    font-size: 15px;
}

.form-select {
    min-height: 42px;
    border-color: var(--border);
    border-radius: 9px;
    font-size: 11px;
    font-weight: 600;
    box-shadow: none !important;
}

.form-select:focus {
    border-color: #93c5fd;
}

/*
|--------------------------------------------------------------------------
| Annual Status
|--------------------------------------------------------------------------
*/

.status-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 16px;
    margin-bottom: 18px;
    border: 1px solid #dbeafe;
    border-radius: 11px;
    background: #eff6ff;
}

.status-main {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.status-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #1d4ed8;
    background: #dbeafe;
}

.status-title {
    color: #1e3a8a;
    font-size: 11px;
    font-weight: 800;
}

.status-text {
    margin-top: 2px;
    color: #4b5563;
    font-size: 9px;
}

.status-badge {
    flex-shrink: 0;
    padding: 6px 9px;
    border-radius: 7px;
    font-size: 9px;
    font-weight: 800;
}

.status-badge.ready {
    color: #166534;
    background: #dcfce7;
}

.status-badge.pending {
    color: #92400e;
    background: #fef3c7;
}

/*
|--------------------------------------------------------------------------
| Results Card
|--------------------------------------------------------------------------
*/

.results-card {
    border: 1px solid var(--border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
    overflow: hidden;
}

.results-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 17px 19px;
    border-bottom: 1px solid var(--border);
}

.results-title {
    min-width: 0;
}

.results-title h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
}

.results-title p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 10px;
}

.results-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.result-count {
    padding: 7px 9px;
    border-radius: 7px;
    color: #1d4ed8;
    background: #dbeafe;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}

.export-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 34px;
    padding: 7px 10px;
    border: 0;
    border-radius: 8px;
    color: #fff;
    background: var(--primary);
    font-size: 10px;
    font-weight: 700;
}

.export-button:hover {
    color: #fff;
    background: var(--primary-dark);
}

.export-button.disabled {
    opacity: .55;
    pointer-events: none;
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 17px;
    border-bottom: 1px solid var(--border);
    background: #fafafa;
}

.pagination-info {
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 600;
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 4px;
}

.page-link-custom {
    min-width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 7px;
    color: #374151;
    background: #fff;
    font-size: 9px;
    font-weight: 700;
}

.page-link-custom:hover,
.page-link-custom.active {
    border-color: var(--primary);
    color: #fff;
    background: var(--primary);
}

.page-link-custom.disabled {
    color: #9ca3af;
    background: #f3f4f6;
    pointer-events: none;
}

/*
|--------------------------------------------------------------------------
| Table
|--------------------------------------------------------------------------
*/

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.roster-table {
    width: 100%;
    min-width: 950px;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 10px;
}

.roster-table thead th {
    padding: 11px 9px;
    border-bottom: 1px solid #1e3a8a;
    color: #fff;
    background: #1e3a8a;
    font-size: 9px;
    font-weight: 800;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
}

.roster-table thead th:first-child {
    border-top-left-radius: 0;
}

.roster-table thead th:last-child {
    border-top-right-radius: 0;
}

.roster-table tbody td {
    padding: 10px 9px;
    border-bottom: 1px solid #eef2f7;
    color: #374151;
    background: #fff;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
}

.roster-table tbody tr:hover td {
    background: #f8fafc;
}

.roster-table tbody tr:last-child td {
    border-bottom: 0;
}

.student-number {
    width: 48px;
    color: #6b7280;
    font-weight: 700;
}

.student-code {
    color: #1d4ed8 !important;
    font-weight: 700;
}

.student-name {
    min-width: 180px;
    color: #111827 !important;
    font-weight: 700;
    text-align: left !important;
}

.summary-cell {
    color: #111827 !important;
    font-weight: 800;
}

.rank-cell {
    color: #7c3aed !important;
    font-weight: 800;
}

.semester-cell {
    color: #1d4ed8 !important;
    background: #eff6ff !important;
    font-size: 9px;
    font-weight: 800;
}

.annual-first-row td {
    border-top: 1px solid #dbeafe;
}

.annual-secondary-row td {
    background: #fafafa !important;
}

.annual-average-row td {
    background: #eff6ff !important;
    border-bottom: 1px solid #bfdbfe !important;
    font-weight: 800;
}

.annual-average-row .summary-cell {
    color: #1e40af !important;
}

/*
|--------------------------------------------------------------------------
| Empty State
|--------------------------------------------------------------------------
*/

.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-muted);
}

.empty-state i {
    display: block;
    margin-bottom: 12px;
    color: #9ca3af;
    font-size: 42px;
}

.empty-state h4 {
    margin: 0;
    color: #374151;
    font-size: 14px;
    font-weight: 800;
}

.empty-state p {
    max-width: 500px;
    margin: 7px auto 0;
    font-size: 10px;
    line-height: 1.6;
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

    .filter-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

/*
|--------------------------------------------------------------------------
| Mobile
|--------------------------------------------------------------------------
*/

@media (max-width: 991px) {

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
        padding: 20px 18px 105px;
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

        display: grid;
        grid-template-columns:
            repeat(5, 1fr);

        min-height: 68px;

        padding:
            7px
            6px
            max(7px, env(safe-area-inset-bottom));

        background: rgba(255, 255, 255, .98);

        border-top: 1px solid var(--border);

        box-shadow:
            0 -5px 25px
            rgba(15, 23, 42, .10);

        backdrop-filter: blur(12px);
    }

    .mobile-nav-item {
        position: relative;

        min-width: 0;

        border: 0;

        background: transparent;

        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;

        gap: 4px;

        padding: 5px 2px;

        color: #6b7280;

        font-family: inherit;

        font-size: 9px;
        font-weight: 700;
        line-height: 1.1;

        cursor: pointer;

        border-radius: 10px;

        transition:
            background .18s ease,
            color .18s ease;

        appearance: none;
        -webkit-appearance: none;

        touch-action: manipulation;
    }

    .mobile-nav-item i {
        font-size: 19px;
        line-height: 1;
        pointer-events: none;
    }

    .mobile-nav-item span {
        pointer-events: none;
    }

    .mobile-nav-item.active {
        color: var(--primary);
    }

    .mobile-nav-item.active::before {
        content: '';

        position: absolute;

        top: -7px;
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
        background: #eff6ff;
    }

    /*
    |--------------------------------------------------------------------------
    | More Menu
    |--------------------------------------------------------------------------
    */

    .mobile-more-menu {
        position: fixed;

        right: 10px;

        bottom: calc(
            78px + env(safe-area-inset-bottom)
        );

        z-index: 1300;

        width: 190px;

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

        background: var(--border);
    }

    /*
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    */

    .roster-header {
        flex-direction: column;
    }

    .roster-badges {
        justify-content: flex-start;
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
        padding: 10px 12px;
        gap: 8px;
    }

    .page-heading h1 {
        font-size: 16px;
    }

    .page-heading p {
        display: none;
    }

    .topbar-right {
        gap: 5px;
    }

    .dashboard-button {
        min-height: 34px;
        padding: 7px 9px;
        font-size: 9px;
    }

    .dashboard-button span {
        display: none;
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
            105px;
    }

    .roster-heading h2 {
        font-size: 18px;
    }

    .roster-heading p {
        font-size: 9px;
    }

    .roster-badges {
        width: 100%;
    }

    .info-badge {
        padding: 6px 8px;
        font-size: 8px;
    }

    .filter-card {
        padding: 13px;
        border-radius: 11px;
    }

    .filter-grid {
        grid-template-columns: 1fr;
        gap: 9px;
    }

    .filter-label {
        font-size: 9px;
    }

    .filter-display,
    .form-select {
        min-height: 39px;
        font-size: 9px;
    }

    .status-card {
        align-items: flex-start;
        padding: 11px;
    }

    .status-title {
        font-size: 9px;
    }

    .status-text {
        font-size: 8px;
    }

    .status-badge {
        font-size: 8px;
    }

    .results-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 13px 14px;
    }

    .results-actions {
        width: 100%;
        justify-content: space-between;
    }

    .pagination-bar {
        align-items: flex-start;
        flex-direction: column;
        padding: 10px 12px;
    }

    .pagination-controls {
        width: 100%;
        overflow-x: auto;
    }

    .page-link-custom {
        flex-shrink: 0;
    }

    .empty-state {
        padding: 45px 15px;
    }

    .empty-state i {
        font-size: 35px;
    }

    .empty-state h4 {
        font-size: 12px;
    }

    .empty-state p {
        font-size: 9px;
    }

    .mobile-bottom-nav {
        min-height: 67px;
        padding-left: 4px;
        padding-right: 4px;
    }

    .mobile-nav-item {
        font-size: 8px;
        gap: 4px;
    }

    .mobile-nav-item i {
        font-size: 18px;
    }

    .mobile-more-menu {
        right: 8px;
        bottom: calc(
            76px + env(safe-area-inset-bottom)
        );
        width: 175px;
    }
}

/*
|--------------------------------------------------------------------------
| Very Small Phones
|--------------------------------------------------------------------------
*/

@media (max-width: 360px) {

    .dashboard-button {
        padding: 7px;
    }

    .mobile-nav-item {
        font-size: 7px;
    }

    .mobile-nav-item i {
        font-size: 17px;
    }

    .mobile-more-menu {
        right: 6px;
        width: 170px;
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
                7px,
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
            href="homework.php"
            class="nav-link-custom"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="roster.php"
            class="nav-link-custom active"
        >
            <i class="bi bi-card-list"></i>
            <span>Roster</span>
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
                Teacher Roster
            </h1>

            <p>
                View the roster and academic results of your homeroom class
            </p>

        </div>

        <div class="topbar-right">

            <a
                href="dashboard.php"
                class="dashboard-button"
            >
                <i class="bi bi-grid-1x2-fill"></i>

                <span>
                    Dashboard
                </span>
            </a>

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

        <!-- PAGE HEADER -->

        <section class="roster-header">

            <div class="roster-heading">

                <h2>
                    <?= e($rosterTypeLabel) ?>
                </h2>

              

            </div>

            <div class="roster-badges">

                <?php if ($academicYearName !== ''): ?>

                    <span class="info-badge">

                        <i class="bi bi-calendar2-week"></i>

                        Academic Year:
                        <?= e($academicYearName) ?>

                    </span>

                <?php endif; ?>

                <?php if ($hasHomeroom): ?>

                    <span class="info-badge">

                        <i class="bi bi-people-fill"></i>

                        <?= e($gradeName) ?>

                        ·

                        Section
                        <?= e($sectionCode) ?>

                    </span>

                <?php endif; ?>

            </div>

        </section>

        <!-- CLASS INFORMATION -->

        <section class="filter-card">

            <div class="filter-grid">

                <div class="filter-item">

                    <label class="filter-label">
                        Academic Year
                    </label>

                    <div class="filter-display">

                        <i class="bi bi-calendar2-week"></i>

                        <?php if ($academicYearName !== ''): ?>

                            <?= e($academicYearName) ?>

                        <?php else: ?>

                            No Active Academic Year

                        <?php endif; ?>

                    </div>

                </div>

                <div class="filter-item">

                    <label class="filter-label">
                        Homeroom Grade
                    </label>

                    <div class="filter-display">

                        <i class="bi bi-mortarboard-fill"></i>

                        <?php if ($hasHomeroom): ?>

                            <?= e($gradeName) ?>

                        <?php else: ?>

                            No Homeroom Assignment

                        <?php endif; ?>

                    </div>

                </div>

                <div class="filter-item">

                    <label class="filter-label">
                        Homeroom Section
                    </label>

                    <div class="filter-display">

                        <i class="bi bi-people-fill"></i>

                        <?php if ($hasHomeroom): ?>

                            <?= e($sectionName) ?>

                            <?php if (
                                $sectionName !== $sectionCode
                            ): ?>

                                (<?= e($sectionCode) ?>)

                            <?php endif; ?>

                        <?php else: ?>

                            No Homeroom Assignment

                        <?php endif; ?>

                    </div>

                </div>

                <div class="filter-item">

                    <label
                        class="filter-label"
                        for="rosterType"
                    >
                        Roster Type
                    </label>

                    <select
                        id="rosterType"
                        class="form-select"
                        onchange="changeRosterType(this.value)"
                    >

                        <option
                            value="first"
                            <?= $selectedRosterType === 'first'
                                ? 'selected'
                                : '' ?>
                        >
                            First Semester Roster
                        </option>

                        <option
                            value="second"
                            <?= $selectedRosterType === 'second'
                                ? 'selected'
                                : '' ?>
                        >
                            Second Semester Roster
                        </option>

                        <option
                            value="annual"
                            <?= $selectedRosterType === 'annual'
                                ? 'selected'
                                : '' ?>
                        >
                            Annual Roster
                        </option>

                    </select>

                </div>

            </div>

        </section>

        <!-- ANNUAL STATUS -->

        <?php if ($selectedRosterType === 'annual'): ?>

            <section class="status-card">

                <div class="status-main">

                    <div class="status-icon">

                        <i class="bi bi-calculator-fill"></i>

                    </div>

                    <div>

                        <div class="status-title">
                            Annual Roster Status
                        </div>

                        <div class="status-text">

                            <?php if ($annualReady): ?>

                                First and Second Semester
                                results are available for annual calculation.

                            <?php else: ?>

                                Annual roster is not ready because the required
                                semester results have not been completed.

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <span
                    class="status-badge
                    <?= $annualReady
                        ? 'ready'
                        : 'pending' ?>"
                >

                    <?= $annualReady
                        ? 'Ready'
                        : 'Pending' ?>

                </span>

            </section>

        <?php endif; ?>

        <!-- RESULTS -->

        <section class="results-card">

            <div class="results-header">

                <div class="results-title">

                    <h3>
                        <?= e($rosterTypeLabel) ?>
                    </h3>

                    <p>

                        <?php if ($hasHomeroom): ?>

                            <?= e($gradeName) ?>

                            ·

                            Section
                            <?= e($sectionCode) ?>

                            ·

                            Academic Year
                            <?= e($academicYearName) ?>

                        <?php else: ?>

                            No active homeroom assignment found.

                        <?php endif; ?>

                    </p>

                </div>

                <div class="results-actions">

                    <span class="result-count">

                        <?= $totalStudents ?>

                        Student<?= $totalStudents === 1
                            ? ''
                            : 's' ?>

                    </span>

                    <?php if ($canExport): ?>

                        <a
                            href="<?= e($exportUrl) ?>"
                            class="export-button"
                            target="_blank"
                        >

                            <i class="bi bi-file-earmark-word-fill"></i>

                            Export

                        </a>

                    <?php else: ?>

                        <span
                            class="export-button disabled"
                        >

                            <i class="bi bi-file-earmark-word-fill"></i>

                            Export

                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <?php if ($totalStudents > 0): ?>

                <!-- TOP PAGINATION -->

                <div class="pagination-bar">

                    <div class="pagination-info">

                        Showing

                        <?= $pageStart + 1 ?>

                        –

                        <?= min(
                            $pageStart + $perPage,
                            $totalStudents
                        ) ?>

                        of

                        <?= $totalStudents ?>

                        students

                    </div>

                    <div class="pagination-controls">

                        <?php if ($page > 1): ?>

                            <a
                                href="<?= e(
                                    rosterPageUrl(
                                        $page - 1,
                                        $selectedRosterType,
                                        $perPage
                                    )
                                ) ?>"
                                class="page-link-custom"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>

                        <?php else: ?>

                            <span
                                class="page-link-custom disabled"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </span>

                        <?php endif; ?>

                        <?php

                        $startPage =
                            max(1, $page - 2);

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        for (
                            $p = $startPage;
                            $p <= $endPage;
                            $p++
                        ):

                        ?>

                            <a
                                href="<?= e(
                                    rosterPageUrl(
                                        $p,
                                        $selectedRosterType,
                                        $perPage
                                    )
                                ) ?>"
                                class="
                                    page-link-custom
                                    <?= $p === $page
                                        ? 'active'
                                        : '' ?>
                                "
                            >
                                <?= $p ?>
                            </a>

                        <?php endfor; ?>

                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= e(
                                    rosterPageUrl(
                                        $page + 1,
                                        $selectedRosterType,
                                        $perPage
                                    )
                                ) ?>"
                                class="page-link-custom"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>

                        <?php else: ?>

                            <span
                                class="page-link-custom disabled"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <!-- TABLE -->

                <div class="table-wrapper">

                    <table class="roster-table">

                        <thead>

                            <tr>

                                <th>
                                    No
                                </th>

                                <th>
                                    Student Code
                                </th>

                                <th>
                                    Student Name
                                </th>

                                <?php foreach (
                                    $subjects as $subject
                                ): ?>

                                    <th>
                                        <?= e(
                                            $subject
                                        ) ?>
                                    </th>

                                <?php endforeach; ?>

                                <th>
                                    Sum
                                </th>

                                <th>
                                    Average
                                </th>

                                <th>
                                    Rank
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if (
                                $selectedRosterType !== 'annual'
                            ): ?>

                                <?php foreach (
                                    $displayRoster
                                    as $student
                                ): ?>

                                    <tr>

                                        <td class="student-number">

                                            <?= (int) (
                                                $student['row_number']
                                                ?? 0
                                            ) ?>

                                        </td>

                                        <td class="student-code">

                                            <?= e(
                                                (string) (
                                                    $student['student_code']
                                                    ?? ''
                                                )
                                            ) ?>

                                        </td>

                                        <td class="student-name">

                                            <?= e(
                                                (string) (
                                                    $student['student_name']
                                                    ?? ''
                                                )
                                            ) ?>

                                        </td>

                                        <?php

                                        $studentSubjects =
                                            is_array(
                                                $student['subjects']
                                                ?? null
                                            )
                                                ? $student['subjects']
                                                : [];

                                        ?>

                                        <?php foreach (
                                            $subjects as $subject
                                        ): ?>

                                            <td>

                                                <?php

                                                $mark =
                                                    $studentSubjects[
                                                        $subject
                                                    ] ?? null;

                                                echo e(
                                                    formatRosterMark(
                                                        $mark
                                                    )
                                                );

                                                ?>

                                            </td>

                                        <?php endforeach; ?>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['sum']
                                                    ?? $student['total']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['average']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="rank-cell">

                                            <?= e(
                                                formatRosterRank(
                                                    $student['rank']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <?php foreach (
                                    $displayRoster
                                    as $student
                                ): ?>

                                    <?php

                                    $firstSubjects =
                                        is_array(
                                            $student['first_subjects']
                                            ?? null
                                        )
                                            ? $student['first_subjects']
                                            : [];

                                    $secondSubjects =
                                        is_array(
                                            $student['second_subjects']
                                            ?? null
                                        )
                                            ? $student['second_subjects']
                                            : [];

                                    $annualSubjects =
                                        is_array(
                                            $student['annual_subjects']
                                            ?? null
                                        )
                                            ? $student['annual_subjects']
                                            : [];

                                    ?>

                                    <!-- FIRST SEMESTER -->

                                    <tr class="annual-first-row">

                                        <td
                                            class="student-number"
                                            rowspan="3"
                                        >

                                            <?= (int) (
                                                $student['row_number']
                                                ?? 0
                                            ) ?>

                                        </td>

                                        <td
                                            class="student-code"
                                            rowspan="3"
                                        >

                                            <?= e(
                                                (string) (
                                                    $student['student_code']
                                                    ?? ''
                                                )
                                            ) ?>

                                        </td>

                                        <td
                                            class="student-name"
                                            rowspan="3"
                                        >

                                            <?= e(
                                                (string) (
                                                    $student['student_name']
                                                    ?? ''
                                                )
                                            ) ?>

                                        </td>

                                        <td class="semester-cell">

                                            First Semester

                                        </td>

                                        <?php foreach (
                                            $subjects as $subject
                                        ): ?>

                                            <td>

                                                <?= e(
                                                    formatRosterMark(
                                                        $firstSubjects[
                                                            $subject
                                                        ] ?? null
                                                    )
                                                ) ?>

                                            </td>

                                        <?php endforeach; ?>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['first_sum']
                                                    ?? $student['first']['sum']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['first_average']
                                                    ?? $student['first']['average']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="rank-cell">

                                            <?= e(
                                                formatRosterRank(
                                                    $student['first_rank']
                                                    ?? $student['first']['rank']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                    </tr>

                                    <!-- SECOND SEMESTER -->

                                    <tr class="annual-secondary-row">

                                        <td class="semester-cell">

                                            Second Semester

                                        </td>

                                        <?php foreach (
                                            $subjects as $subject
                                        ): ?>

                                            <td>

                                                <?= e(
                                                    formatRosterMark(
                                                        $secondSubjects[
                                                            $subject
                                                        ] ?? null
                                                    )
                                                ) ?>

                                            </td>

                                        <?php endforeach; ?>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['second_sum']
                                                    ?? $student['second']['sum']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['second_average']
                                                    ?? $student['second']['average']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="rank-cell">

                                            <?= e(
                                                formatRosterRank(
                                                    $student['second_rank']
                                                    ?? $student['second']['rank']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                    </tr>

                                    <!-- ANNUAL AVERAGE -->

                                    <tr class="annual-average-row">

                                        <td class="semester-cell">

                                            Annual Average

                                        </td>

                                        <?php foreach (
                                            $subjects as $subject
                                        ): ?>

                                            <td>

                                                <?= e(
                                                    formatRosterMark(
                                                        $annualSubjects[
                                                            $subject
                                                        ] ?? null
                                                    )
                                                ) ?>

                                            </td>

                                        <?php endforeach; ?>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['annual_sum']
                                                    ?? $student['annual']['sum']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="summary-cell">

                                            <?= e(
                                                formatRosterNumber(
                                                    $student['annual_average']
                                                    ?? $student['annual']['average']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                        <td class="rank-cell">

                                            <?= e(
                                                formatRosterRank(
                                                    $student['annual_rank']
                                                    ?? $student['annual']['rank']
                                                    ?? null
                                                )
                                            ) ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <!-- BOTTOM PAGINATION -->

                <div class="pagination-bar">

                    <div class="pagination-info">

                        Page
                        <?= $page ?>
                        of
                        <?= $totalPages ?>

                    </div>

                    <div class="pagination-controls">

                        <?php if ($page > 1): ?>

                            <a
                                href="<?= e(
                                    rosterPageUrl(
                                        $page - 1,
                                        $selectedRosterType,
                                        $perPage
                                    )
                                ) ?>"
                                class="page-link-custom"
                            >

                                <i class="bi bi-chevron-left"></i>

                            </a>

                        <?php endif; ?>

                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= e(
                                    rosterPageUrl(
                                        $page + 1,
                                        $selectedRosterType,
                                        $perPage
                                    )
                                ) ?>"
                                class="page-link-custom"
                            >

                                <i class="bi bi-chevron-right"></i>

                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            <?php elseif (
                !$hasHomeroom
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-house-door"></i>

                    <h4>
                        No Homeroom Assignment
                    </h4>

                    <p>
                        You do not have an active homeroom assignment
                        for the current academic year.
                    </p>

                </div>

            <?php elseif (
                $selectedRosterType === 'annual' &&
                !$annualReady
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-hourglass-split"></i>

                    <h4>
                        Annual Roster Not Ready
                    </h4>

                    <p>
                        The Annual Roster will be available after
                        the required semester results are completed.
                    </p>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <i class="bi bi-card-list"></i>

                    <h4>
                        No Student Result Records Found
                    </h4>

                    <p>
                        No student result records were found for your
                        assigned homeroom class and selected roster type.
                    </p>

                </div>

            <?php endif; ?>

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

    <a
        href="daily-attendance.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-calendar-check-fill"></i>

        <span>
            Daily Attendance
        </span>

    </a>

    <a
        href="homework.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-journal-text"></i>

        <span>
            Homework
        </span>

    </a>

    <a
        href="result.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Result
        </span>

    </a>

    <a
        href="announcements.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-megaphone-fill"></i>

        <span>
            Announcement
        </span>

    </a>

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

<script>

/*
|--------------------------------------------------------------------------
| Change Roster Type
|--------------------------------------------------------------------------
*/

function changeRosterType(type) {

    const url =
        new URL(
            window.location.href
        );

    url.searchParams.set(
        'roster_type',
        type
    );

    url.searchParams.set(
        'page',
        '1'
    );

    window.location.href =
        url.toString();
}

/*
|--------------------------------------------------------------------------
| Mobile More Menu
|--------------------------------------------------------------------------
*/

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

    mobileMoreButton.addEventListener(
        'click',
        function (event) {

            event.preventDefault();
            event.stopPropagation();

            const isOpen =
                mobileMoreMenu.classList.contains(
                    'show'
                );

            if (isOpen) {

                closeMobileMoreMenu();

            } else {

                openMobileMoreMenu();

            }

        },
        false
    );

    mobileMoreMenu.addEventListener(
        'click',
        function (event) {
            event.stopPropagation();
        },
        false
    );

    document.addEventListener(
        'click',
        function (event) {

            const target =
                event.target;

            if (
                target instanceof Node &&
                (
                    mobileMoreMenu.contains(
                        target
                    ) ||
                    mobileMoreButton.contains(
                        target
                    )
                )
            ) {
                return;
            }

            closeMobileMoreMenu();

        },
        false
    );

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

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
            ) {
                closeMobileMoreMenu();
            }

        },
        false
    );

})();

</script>

</body>

</html>