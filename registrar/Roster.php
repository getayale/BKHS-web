<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once 'roster-data.php';

$userId = (int) $_SESSION['user_id'];

$registrar = null;
$academicYears = [];
$grades = [];
$sections = [];

$selectedAcademicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$selectedGradeId = isset($_GET['grade_id'])
    ? (int) $_GET['grade_id']
    : 0;

$selectedSectionId = isset($_GET['section_id'])
    ? (int) $_GET['section_id']
    : 0;

$selectedRosterType = isset($_GET['roster_type'])
    ? trim((string) $_GET['roster_type'])
    : 'first';

$allowedRosterTypes = [
    'first',
    'second',
    'annual'
];

if (!in_array($selectedRosterType, $allowedRosterTypes, true)) {
    $selectedRosterType = 'first';
}


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$allowedPerPage = [
    10,
    20,
    30,
    50,
    100
];

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 20;

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$currentPage = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}


$roster = [];
$allRoster = [];
$subjects = [];

$totalStudents = 0;
$totalPages = 0;
$pageStart = 0;
$pageEnd = 0;

$errorMessage = '';
$successMessage = '';


try {

    /*
    |--------------------------------------------------------------------------
    | Registrar
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            r.photo
        FROM users u
        LEFT JOIN registrars r
            ON r.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'registrar'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare registrar query.'
        );
    }

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $registrar = $result->fetch_assoc();

    $stmt->close();

    if (!$registrar) {
        session_destroy();

        header('Location: ../auth/login.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Selection Data
    |--------------------------------------------------------------------------
    */

    $academicYears = getRosterAcademicYears($conn);

    $grades = getRosterGrades($conn);

    $sections = getRosterSections($conn);


    /*
    |--------------------------------------------------------------------------
    | Load Roster
    |--------------------------------------------------------------------------
    */

    if (
        $selectedAcademicYearId > 0 &&
        $selectedGradeId > 0 &&
        $selectedSectionId > 0
    ) {

        if (
            $selectedRosterType === 'annual' &&
            !isAnnualRosterReady(
                $conn,
                $selectedAcademicYearId
            )
        ) {

            $errorMessage =
                'The Annual Roster cannot be generated yet. '
                . 'Both First Semester and Second Semester '
                . 'must be completed first.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Get Complete Roster
            |--------------------------------------------------------------------------
            |
            | Important:
            | getRoster() loads the complete selected class.
            | Pagination is applied AFTER the complete roster
            | is built so ranks remain calculated across
            | the entire Grade + Section.
            |
            */

            $rosterResult = getRoster(
                $conn,
                $selectedAcademicYearId,
                $selectedGradeId,
                $selectedSectionId,
                $selectedRosterType
            );

            $allRoster = $rosterResult['students'] ?? [];

            $subjects = $rosterResult['subjects'] ?? [];


            /*
            |--------------------------------------------------------------------------
            | Normalize Subjects
            |--------------------------------------------------------------------------
            */

            $normalizedSubjects = [];

            foreach ($subjects as $subject) {

                if (is_array($subject)) {

                    if (isset($subject['subject_name'])) {

                        $normalizedSubjects[] =
                            (string) $subject['subject_name'];

                    } elseif (isset($subject['name'])) {

                        $normalizedSubjects[] =
                            (string) $subject['name'];
                    }

                } elseif (
                    is_string($subject) ||
                    is_numeric($subject)
                ) {

                    $normalizedSubjects[] =
                        (string) $subject;
                }
            }

            $subjects = array_values(
                array_unique($normalizedSubjects)
            );


            /*
            |--------------------------------------------------------------------------
            | Normalize Roster
            |--------------------------------------------------------------------------
            */

            foreach ($allRoster as $index => &$student) {

                if (!is_array($student)) {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | Student Name
                |--------------------------------------------------------------------------
                */

                if (
                    !isset($student['student_name']) ||
                    is_array($student['student_name'])
                ) {

                    if (
                        isset($student['full_name']) &&
                        !is_array($student['full_name'])
                    ) {

                        $student['student_name'] =
                            (string) $student['full_name'];

                    } else {

                        $student['student_name'] = '';
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | Student Code
                |--------------------------------------------------------------------------
                */

                if (
                    !isset($student['student_code']) ||
                    is_array($student['student_code'])
                ) {

                    $student['student_code'] = '';
                }


                /*
                |--------------------------------------------------------------------------
                | Global Row Number
                |--------------------------------------------------------------------------
                |
                | This number is assigned BEFORE pagination.
                | Therefore page 2 continues from page 1.
                |
                */

                $student['row_number'] = $index + 1;


                /*
                |--------------------------------------------------------------------------
                | Semester Roster
                |--------------------------------------------------------------------------
                */

                if ($selectedRosterType !== 'annual') {

                    if (
                        !isset($student['subjects']) ||
                        !is_array($student['subjects'])
                    ) {

                        if (
                            isset($student['marks']) &&
                            is_array($student['marks'])
                        ) {

                            $student['subjects'] =
                                normalizeRosterMarks(
                                    $student['marks']
                                );

                        } else {

                            $student['subjects'] = [];
                        }
                    }


                    foreach (
                        $student['subjects']
                        as $subjectName => $mark
                    ) {

                        if (is_array($mark)) {

                            if (
                                array_key_exists(
                                    'mark',
                                    $mark
                                )
                            ) {

                                $student['subjects'][$subjectName] =
                                    $mark['mark'];

                            } elseif (
                                array_key_exists(
                                    'value',
                                    $mark
                                )
                            ) {

                                $student['subjects'][$subjectName] =
                                    $mark['value'];

                            } else {

                                $student['subjects'][$subjectName] =
                                    null;
                            }
                        }
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | Annual Roster
                |--------------------------------------------------------------------------
                */

                if ($selectedRosterType === 'annual') {

                    /*
                    | First semester
                    */

                    if (
                        !isset($student['first_subjects']) ||
                        !is_array($student['first_subjects'])
                    ) {

                        if (
                            isset($student['first']['marks']) &&
                            is_array($student['first']['marks'])
                        ) {

                            $student['first_subjects'] =
                                normalizeRosterMarks(
                                    $student['first']['marks']
                                );

                        } else {

                            $student['first_subjects'] = [];
                        }
                    }


                    /*
                    | Second semester
                    */

                    if (
                        !isset($student['second_subjects']) ||
                        !is_array($student['second_subjects'])
                    ) {

                        if (
                            isset($student['second']['marks']) &&
                            is_array($student['second']['marks'])
                        ) {

                            $student['second_subjects'] =
                                normalizeRosterMarks(
                                    $student['second']['marks']
                                );

                        } else {

                            $student['second_subjects'] = [];
                        }
                    }


                    /*
                    | Annual subject averages
                    */

                    if (
                        !isset($student['annual_subjects']) ||
                        !is_array($student['annual_subjects'])
                    ) {

                        if (
                            isset($student['annual']['marks']) &&
                            is_array($student['annual']['marks'])
                        ) {

                            $student['annual_subjects'] =
                                normalizeRosterMarks(
                                    $student['annual']['marks']
                                );

                        } else {

                            $student['annual_subjects'] = [];
                        }
                    }


                    $student['first_subjects'] =
                        normalizeRosterMarks(
                            $student['first_subjects']
                        );

                    $student['second_subjects'] =
                        normalizeRosterMarks(
                            $student['second_subjects']
                        );

                    $student['annual_subjects'] =
                        normalizeRosterMarks(
                            $student['annual_subjects']
                        );
                }
            }

            unset($student);


            /*
            |--------------------------------------------------------------------------
            | Complete Student Count
            |--------------------------------------------------------------------------
            */

            $totalStudents = count($allRoster);


            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            if ($totalStudents > 0) {

                $totalPages = (int) ceil(
                    $totalStudents / $perPage
                );

                if ($currentPage > $totalPages) {
                    $currentPage = $totalPages;
                }

                $pageStart =
                    (($currentPage - 1) * $perPage);

                $pageEnd = min(
                    $pageStart + $perPage,
                    $totalStudents
                );


                /*
                | Slice by STUDENT.
                |
                | This is especially important for Annual Roster
                | because every student has three rows.
                |
                */

                $roster = array_slice(
                    $allRoster,
                    $pageStart,
                    $perPage
                );

            } else {

                $currentPage = 1;
                $totalPages = 0;
                $pageStart = 0;
                $pageEnd = 0;
                $roster = [];
            }


            if (empty($allRoster)) {

                $errorMessage =
                    'No student result records were found '
                    . 'for the selected criteria.';
            }
        }
    }

} catch (Throwable $e) {

    $errorMessage = $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$registrarName = htmlspecialchars(
    $registrar['full_name'] ?? 'Registrar',
    ENT_QUOTES,
    'UTF-8'
);

$registrarEmail = htmlspecialchars(
    $registrar['email'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);


/*
|--------------------------------------------------------------------------
| Profile Photo
|--------------------------------------------------------------------------
*/

$photoPath = '../public/images/default-avatar.png';

if (!empty($registrar['photo'])) {

    $candidate = '../' . ltrim(
        (string) $registrar['photo'],
        '/'
    );

    if (file_exists($candidate)) {
        $photoPath = $candidate;
    }
}

$photoPath = htmlspecialchars(
    $photoPath,
    ENT_QUOTES,
    'UTF-8'
);


/*
|--------------------------------------------------------------------------
| Selected Academic Year
|--------------------------------------------------------------------------
*/

$selectedAcademicYear = null;

foreach ($academicYears as $academicYear) {

    if (
        (int) $academicYear['id'] ===
        $selectedAcademicYearId
    ) {

        $selectedAcademicYear = $academicYear;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Selected Grade
|--------------------------------------------------------------------------
*/

$selectedGrade = null;

foreach ($grades as $grade) {

    if (
        (int) $grade['id'] ===
        $selectedGradeId
    ) {

        $selectedGrade = $grade;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Selected Section
|--------------------------------------------------------------------------
*/

$selectedSection = null;

foreach ($sections as $section) {

    if (
        (int) $section['id'] ===
        $selectedSectionId
    ) {

        $selectedSection = $section;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Roster Type Label
|--------------------------------------------------------------------------
*/

$rosterTypeLabels = [
    'first'  => 'First Semester Roster',
    'second' => 'Second Semester Roster',
    'annual' => 'Annual Roster'
];

$rosterTypeLabel =
    $rosterTypeLabels[$selectedRosterType];


/*
|--------------------------------------------------------------------------
| Section Display
|--------------------------------------------------------------------------
*/

$sectionDisplay = '';

if ($selectedSection) {

    $sectionDisplay = !empty($selectedSection['code'])
        ? (string) $selectedSection['code']
        : (string) $selectedSection['name'];
}


/*
|--------------------------------------------------------------------------
| Export Availability
|--------------------------------------------------------------------------
|
| Export uses roster-export.php and therefore exports
| the COMPLETE roster, not just the current page.
|
*/

$canExport =
    $selectedAcademicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0 &&
    !empty($allRoster);

if ($selectedRosterType === 'annual') {

    $canExport =
        $canExport &&
        isAnnualRosterReady(
            $conn,
            $selectedAcademicYearId
        );
}


/*
|--------------------------------------------------------------------------
| Pagination URL Helper
|--------------------------------------------------------------------------
*/

function rosterPageUrl(
    int $page,
    int $academicYearId,
    int $gradeId,
    int $sectionId,
    string $rosterType,
    int $perPage
): string {

    return 'Roster.php?' . http_build_query([
        'academic_year_id' => $academicYearId,
        'grade_id'         => $gradeId,
        'section_id'      => $sectionId,
        'roster_type'     => $rosterType,
        'page'            => $page,
        'per_page'        => $perPage
    ]);
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
        Academic Roster | Registrar
    </title>
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <style>

        /* =========================================================
           ROOT
        ========================================================== */

        :root {

            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;

            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #d1d5db;
            --sidebar-muted: #9ca3af;

            --background: #f5f7fb;
            --card: #ffffff;

            --text: #111827;
            --muted: #6b7280;

            --border: #e5e7eb;

            --success: #16a34a;
            --success-light: #f0fdf4;

            --warning: #d97706;
            --warning-light: #fffbeb;

            --danger: #dc2626;
            --danger-light: #fef2f2;

            --shadow-sm:
                0 1px 2px rgba(15, 23, 42, 0.05);

            --shadow:
                0 8px 25px rgba(15, 23, 42, 0.06);

            --radius: 14px;
        }


        /* =========================================================
           GLOBAL
        ========================================================== */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
            font-size: 14px;
        }

        a {
            text-decoration: none;
        }


        /* =========================================================
           APP
        ========================================================== */

        .app {
            min-height: 100vh;
        }


        /* =========================================================
           SIDEBAR
        ========================================================== */

        .sidebar {

            position: fixed;
            top: 0;
            left: 0;

            width: 260px;
            height: 100vh;

            background: var(--sidebar);
            color: white;

            z-index: 1100;

            overflow-y: auto;
        }

        .brand {

            height: 82px;

            padding: 0 20px;

            display: flex;
            align-items: center;

            gap: 12px;

            border-bottom:
                1px solid rgba(255,255,255,0.07);
        }

        .brand-icon {

            width: 42px;
            height: 42px;

            border-radius: 11px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: var(--primary);

            color: white;

            font-size: 20px;

            flex-shrink: 0;
        }

        .brand-title {

            font-size: 14px;
            font-weight: 700;

            color: #fff;

            line-height: 1.3;
        }

        .brand-subtitle {

            margin-top: 2px;

            font-size: 11px;

            color: var(--sidebar-muted);
        }

        .sidebar-menu {
            padding: 20px 13px;
        }

        .menu-label {

            padding:
                0 10px 9px;

            color: #6b7280;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .08em;
        }

        .nav-link {

            display: flex;
            align-items: center;

            gap: 12px;

            min-height: 44px;

            margin-bottom: 4px;

            padding: 10px 12px;

            border-radius: 9px;

            color: var(--sidebar-text);

            font-size: 13px;

            font-weight: 500;

            transition: all .2s ease;
        }

        .nav-link i {

            width: 20px;

            text-align: center;

            font-size: 16px;
        }

        .nav-link:hover {

            color: #fff;

            background: var(--sidebar-hover);
        }

        .nav-link.active {

            color: #fff;

            background: var(--primary);
        }

        .nav-link.logout-link {

            color: #fca5a5;
        }

        .nav-link.logout-link:hover {

            color: #fff;

            background:
                rgba(220, 38, 38, .18);
        }


        /* =========================================================
           SIDEBAR OVERLAY
        ========================================================== */

        .sidebar-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(15, 23, 42, .55);

            z-index: 1050;
        }

        .sidebar-overlay.show {
            display: block;
        }


        /* =========================================================
           MAIN
        ========================================================== */

        .main {

            margin-left: 260px;

            min-height: 100vh;
        }


        /* =========================================================
           TOPBAR
        ========================================================== */

        .topbar {

            position: sticky;

            top: 0;

            z-index: 1000;

            height: 78px;

            padding: 0 30px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            background:
                rgba(255,255,255,.97);

            border-bottom:
                1px solid var(--border);

            backdrop-filter: blur(10px);
        }

        .topbar-left {

            display: flex;
            align-items: center;

            gap: 15px;
        }

        .mobile-menu {

            display: none;

            width: 40px;
            height: 40px;

            border:
                1px solid var(--border);

            border-radius: 9px;

            background: #fff;

            color: var(--text);

            font-size: 21px;
        }

        .page-title {

            margin: 0;

            font-size: 20px;

            font-weight: 700;

            color: var(--text);
        }

        .page-subtitle {

            margin-top: 3px;

            color: var(--muted);

            font-size: 12px;
        }

        .top-profile {

            display: flex;
            align-items: center;

            gap: 10px;
        }

        .top-profile img {

            width: 40px;
            height: 40px;

            object-fit: cover;

            border-radius: 50%;

            border:
                2px solid #e5e7eb;
        }

        .top-profile-name {

            font-size: 13px;

            font-weight: 700;

            color: var(--text);
        }

        .top-profile-role {

            margin-top: 1px;

            color: var(--muted);

            font-size: 11px;
        }


        /* =========================================================
           CONTENT
        ========================================================== */

        .content {

            padding: 30px;

            max-width: 1800px;

            margin: 0 auto;
        }


        /* =========================================================
           ALERT
        ========================================================== */

        .error-alert {

            margin-bottom: 20px;

            border:
                1px solid #fde68a;

            border-radius: 12px;

            background:
                var(--warning-light);

            color: #92400e;

            padding: 13px 16px;
        }


        /* =========================================================
           PAGE HEADER
        ========================================================== */

        .roster-page-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            padding: 25px 27px;

            margin-bottom: 22px;

            border:
                1px solid var(--border);

            border-radius: var(--radius);

            background: var(--card);

            box-shadow: var(--shadow-sm);
        }

        .roster-page-header h2 {

            margin: 0;

            font-size: 22px;

            font-weight: 700;

            color: var(--text);
        }

        .roster-page-header p {

            margin: 7px 0 0;

            color: var(--muted);

            font-size: 13px;
        }

        .roster-page-icon {

            width: 56px;
            height: 56px;

            flex-shrink: 0;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 14px;

            background:
                var(--primary-light);

            color: var(--primary);

            font-size: 25px;
        }


        /* =========================================================
           CARD
        ========================================================== */

        .roster-card,
        .roster-result-card {

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: var(--radius);

            box-shadow: var(--shadow-sm);

            overflow: hidden;
        }

        .roster-card {

            margin-bottom: 22px;
        }

        .roster-card-header {

            padding: 18px 22px;

            border-bottom:
                1px solid var(--border);
        }

        .roster-card-title {

            display: flex;

            align-items: center;

            gap: 12px;
        }

        .roster-card-icon {

            width: 40px;
            height: 40px;

            border-radius: 10px;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                var(--primary-light);

            color: var(--primary);

            font-size: 17px;
        }

        .roster-card-title h3 {

            margin: 0;

            font-size: 15px;

            font-weight: 700;
        }

        .roster-card-title p {

            margin: 3px 0 0;

            color: var(--muted);

            font-size: 11px;
        }

        .roster-card-body {
            padding: 22px;
        }


        /* =========================================================
           FORM
        ========================================================== */

        .form-label {

            margin-bottom: 7px;

            color: #374151;

            font-size: 12px;

            font-weight: 600;
        }

        .form-select {

            min-height: 44px;

            border-color: #d1d5db;

            border-radius: 9px;

            font-size: 13px;

            box-shadow: none;
        }

        .form-select:focus {

            border-color: var(--primary);

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, .10);
        }

        .filter-actions {

            display: flex;

            align-items: center;

            gap: 9px;

            margin-top: 20px;
        }

        .btn {

            min-height: 42px;

            border-radius: 8px;

            padding: 9px 15px;

            font-size: 12px;

            font-weight: 600;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 7px;
        }

        .btn-primary {

            background: var(--primary);

            border-color: var(--primary);
        }

        .btn-primary:hover {

            background: var(--primary-dark);

            border-color: var(--primary-dark);
        }

        .btn-light {

            background: #fff;

            border:
                1px solid var(--border);

            color: #374151;
        }

        .btn-light:hover {

            background: #f9fafb;

            border-color: #d1d5db;
        }

        .btn-success {

            background: var(--success);

            border-color: var(--success);
        }

        .btn-success:hover {

            background: #15803d;

            border-color: #15803d;
        }


        /* =========================================================
           ANNUAL STATUS
        ========================================================== */

        .annual-status {

            display: flex;

            align-items: flex-start;

            gap: 13px;

            margin-bottom: 22px;

            padding: 15px 17px;

            border-radius: 12px;

            border: 1px solid;
        }

        .annual-ready {

            background:
                var(--success-light);

            border-color:
                #bbf7d0;

            color: #166534;
        }

        .annual-warning {

            background:
                var(--warning-light);

            border-color:
                #fde68a;

            color: #92400e;
        }

        .annual-status-icon {

            font-size: 20px;

            line-height: 1;
        }

        .annual-status strong {

            font-size: 13px;
        }

        .annual-status p {

            margin: 3px 0 0;

            font-size: 11px;

            line-height: 1.5;
        }


        /* =========================================================
           RESULT HEADER
        ========================================================== */

        .roster-result-card {

            margin-bottom: 30px;
        }

        .roster-result-header {

            padding: 20px 22px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            border-bottom:
                1px solid var(--border);

            background: #fff;
        }

        .result-title {

            display: flex;

            align-items: center;

            gap: 9px;

            color: var(--text);

            font-size: 15px;

            font-weight: 700;
        }

        .result-title i {

            color: var(--primary);

            font-size: 18px;
        }

        .result-details {

            display: flex;

            flex-wrap: wrap;

            gap: 8px 20px;

            margin-top: 9px;

            color: var(--muted);

            font-size: 11px;
        }

        .result-details strong {

            color: #374151;
        }

        .result-actions {
            flex-shrink: 0;
        }


        /* =========================================================
           PAGINATION SUMMARY
        ========================================================== */

        .roster-pagination-bar {

            padding: 14px 20px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            border-bottom:
                1px solid var(--border);

            background: #f8fafc;
        }

        .pagination-summary {

            color: #64748b;

            font-size: 11px;

            font-weight: 500;
        }

        .pagination-summary strong {

            color: #334155;

            font-weight: 700;
        }

        .per-page-form {

            display: flex;

            align-items: center;

            gap: 8px;
        }

        .per-page-label {

            color: #64748b;

            font-size: 11px;

            font-weight: 500;
        }

        .per-page-select {

            width: 78px;

            min-height: 34px;

            padding: 5px 28px 5px 9px;

            border:
                1px solid #d1d5db;

            border-radius: 7px;

            background: #fff;

            color: #374151;

            font-size: 11px;

            outline: none;
        }

        .per-page-select:focus {

            border-color: var(--primary);

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, .08);
        }


        /* =========================================================
           TABLE
        ========================================================== */

        .table-wrapper {
            width: 100%;
        }

        .table-responsive {

            overflow-x: auto;

            max-width: 100%;
        }

        .roster-table {

            margin: 0;

            width: max-content;

            min-width: 100%;

            border-collapse:
                separate;

            border-spacing: 0;

            font-size: 12px;
        }


        /* TABLE HEADER */

        .roster-table thead th {

            position: sticky;

            top: 0;

            z-index: 5;

            padding: 13px 13px;

            background:
                #1e3a8a;

            color: #fff;

            border-bottom:
                1px solid #172554;

            border-right:
                1px solid rgba(255,255,255,.15);

            text-align: center;

            vertical-align: middle;

            white-space: nowrap;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .035em;
        }

        .roster-table thead th:first-child {

            border-top-left-radius: 0;
        }

        .roster-table thead th:last-child {

            border-right: 0;

            border-top-right-radius: 0;
        }


        /* TABLE BODY */

        .roster-table tbody td {

            padding: 11px 13px;

            border-bottom:
                1px solid var(--border);

            border-right:
                1px solid #eef0f3;

            text-align: center;

            vertical-align: middle;

            white-space: nowrap;

            background: #fff;
        }

        .roster-table tbody tr:hover td {

            background: #f8fafc;
        }

        .roster-table tbody tr:last-child td {

            border-bottom: 0;
        }


        /* NUMBER */

        .student-number {

            width: 52px;

            min-width: 52px;

            text-align: center !important;

            color: #64748b;

            font-weight: 700;

            background: #f8fafc !important;
        }


        /* STUDENT CODE */

        .student-code {

            min-width: 145px;

            text-align: left !important;

            font-family: 'Inter', sans-serif;

            font-size: 11px;

            font-weight: 600;

            color: #334155;
        }


        /* STUDENT NAME */

        .student-name {

            min-width: 190px;

            text-align: left !important;

            font-weight: 600;

            color: #111827;
        }


        /* SUBJECT */

        .roster-table th:not(:first-child) {

            min-width: 75px;
        }


        /* SUMMARY */

        .summary-cell {

            min-width: 75px;

            font-weight: 700;

            background:
                #f8fafc !important;
        }


        /* RANK */

        .rank-cell {

            min-width: 60px;

            font-weight: 800;

            color: var(--primary);

            background:
                #f8fafc !important;
        }


        /* SEMESTER */

        .semester-cell {

            min-width: 125px;

            text-align: center !important;

            font-weight: 700;

            color: #374151;
        }


        /* =========================================================
           ANNUAL ROSTER
        ========================================================== */

        .annual-first-row td {

            border-top:
                1px solid #cbd5e1;
        }

        .annual-first-row td.student-number,
        .annual-first-row td.student-code,
        .annual-first-row td.student-name {

            background:
                #f8fafc !important;

            vertical-align: middle;
        }

        .annual-secondary-row td {

            background: #fff;
        }

        .annual-average-row td {

            background:
                #eff6ff !important;

            border-bottom:
                1px solid #bfdbfe;

            font-weight: 700;
        }

        .annual-average-row .semester-cell {

            color:
                var(--primary-dark);
        }

        .annual-average-row .annual-value {

            color: #1e40af;
        }


        /* =========================================================
           PAGINATION
        ========================================================== */

        .pagination-container {

            padding: 16px 20px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            border-top:
                1px solid var(--border);

            background: #fff;
        }

        .pagination-info {

            color: #64748b;

            font-size: 11px;

            white-space: nowrap;
        }

        .pagination {

            margin: 0;

            display: flex;

            align-items: center;

            gap: 4px;
        }

        .pagination .page-item {

            margin: 0;
        }

        .pagination .page-link {

            min-width: 34px;

            height: 34px;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 5px 9px;

            border:
                1px solid #e2e8f0;

            border-radius: 7px !important;

            background: #fff;

            color: #475569;

            font-size: 11px;

            font-weight: 600;
        }

        .pagination .page-link:hover {

            background:
                var(--primary-light);

            border-color:
                #bfdbfe;

            color:
                var(--primary);
        }

        .pagination .page-item.active .page-link {

            background:
                var(--primary);

            border-color:
                var(--primary);

            color: #fff;
        }

        .pagination .page-item.disabled .page-link {

            background:
                #f8fafc;

            color:
                #cbd5e1;

            border-color:
                #e2e8f0;

            cursor: not-allowed;
        }

        .pagination-ellipsis {

            min-width: 30px;

            text-align: center;

            color: #94a3b8;

            font-size: 12px;
        }


        /* =========================================================
           EMPTY STATE
        ========================================================== */

        .empty-state {

            padding: 60px 20px;

            text-align: center;

            background: #fff;

            border:
                1px solid var(--border);

            border-radius: var(--radius);

            box-shadow: var(--shadow-sm);
        }

        .empty-icon {

            width: 62px;
            height: 62px;

            margin: 0 auto 16px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background: #f3f4f6;

            color: #9ca3af;

            font-size: 26px;
        }

        .empty-state h3 {

            margin: 0;

            font-size: 16px;

            font-weight: 700;
        }

        .empty-state p {

            margin: 7px 0 0;

            color: var(--muted);

            font-size: 12px;
        }


        /* =========================================================
           SCROLLBAR
        ========================================================== */

        .table-responsive::-webkit-scrollbar {

            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {

            background: #f1f5f9;
        }

        .table-responsive::-webkit-scrollbar-thumb {

            background: #cbd5e1;

            border-radius: 10px;
        }


        /* =========================================================
           RESPONSIVE
        ========================================================== */

        @media (max-width: 1100px) {

            .content {
                padding: 24px;
            }

            .topbar {
                padding: 0 24px;
            }

            .roster-page-header {
                padding: 22px;
            }
        }


        @media (max-width: 900px) {

            .sidebar {

                transform:
                    translateX(-100%);

                transition:
                    transform .25s ease;
            }

            .sidebar.show {

                transform:
                    translateX(0);
            }

            .main {

                margin-left: 0;
            }

            .mobile-menu {

                display: inline-flex;

                align-items: center;

                justify-content: center;
            }

            .topbar {

                height: 70px;
            }

            .content {

                padding: 20px;
            }
        }


        @media (max-width: 700px) {

            .content {

                padding: 15px;
            }

            .topbar {

                padding: 0 15px;
            }

            .page-title {

                font-size: 17px;
            }

            .page-subtitle {

                display: none;
            }

            .top-profile div {

                display: none;
            }

            .top-profile img {

                width: 38px;
                height: 38px;
            }

            .roster-page-header {

                padding: 19px;
            }

            .roster-page-header h2 {

                font-size: 18px;
            }

            .roster-page-header p {

                font-size: 11px;
            }

            .roster-page-icon {

                width: 45px;
                height: 45px;

                font-size: 20px;
            }

            .roster-card-body {

                padding: 17px;
            }

            .roster-result-header {

                align-items: flex-start;

                flex-direction: column;

                padding: 17px;
            }

            .result-actions {

                width: 100%;
            }

            .result-actions .btn {

                width: 100%;
            }

            .result-details {

                flex-direction: column;

                gap: 5px;
            }

            .filter-actions {

                flex-direction: column;

                align-items: stretch;
            }

            .filter-actions .btn {

                width: 100%;
            }

            .roster-pagination-bar {

                align-items: flex-start;

                flex-direction: column;
            }

            .pagination-container {

                align-items: center;

                flex-direction: column;
            }

            .pagination-info {

                order: 2;
            }

            .pagination {

                order: 1;

                flex-wrap: wrap;

                justify-content: center;
            }
        }


        @media (max-width: 480px) {

            .roster-page-header {

                align-items: flex-start;
            }

            .roster-page-icon {

                display: none;
            }

            .roster-card-header {

                padding: 15px;
            }

            .roster-card-body {

                padding: 15px;
            }

            .roster-table {

                font-size: 11px;
            }

            .roster-table thead th,
            .roster-table tbody td {

                padding:
                    9px 10px;
            }

            .student-name {

                min-width: 160px;
            }

            .pagination .page-link {

                min-width: 31px;

                height: 31px;

                font-size: 10px;
            }
        }

    </style>

</head>


<body>

<div class="app">


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

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
                    School Management
                </div>

                <div class="brand-subtitle">
                    Registrar Portal
                </div>

            </div>

        </div>


        <nav class="sidebar-menu">

            <div class="menu-label">
                Main Menu
            </div>


            <a
                href="dashboard.php"
                class="nav-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>


            <a
                href="register.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>


            <a
                href="delete-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete Student</span>
            </a>


            <a
                href="student-record.php"
                class="nav-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Student Record</span>
            </a>


            <a
                href="update-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Student</span>
            </a>


            <a
                href="certificate.php"
                class="nav-link"
            >
                <i class="bi bi-award-fill"></i>
                <span>Certificate</span>
            </a>


            <a
                href="Roster.php"
                class="nav-link active"
            >
                <i class="bi bi-table"></i>
                <span>Roster</span>
            </a>


            <a
                href="Transcript.php"
                class="nav-link"
            >
                <i class="bi bi-file-earmark-text-fill"></i>
                <span>Transcript</span>
            </a>


            <a
                href="profile.php"
                class="nav-link"
            >
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>


            <div style="height: 15px;"></div>


            <a
                href="../auth/logout.php"
                class="nav-link logout-link"
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


    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main">


        <!-- =====================================================
             TOPBAR
        ====================================================== -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu"
                    id="mobileMenu"
                    aria-label="Open menu"
                >
                    <i class="bi bi-list"></i>
                </button>


                <div>

                    <h1 class="page-title">
                        Academic Roster
                    </h1>

                    <div class="page-subtitle">
                        Generate and export student academic results
                    </div>

                </div>

            </div>


            <div class="top-profile">

                <img
                    src="<?= $photoPath ?>"
                    alt="Registrar"
                >


                <div>

                    <div class="top-profile-name">
                        <?= $registrarName ?>
                    </div>

                    <div class="top-profile-role">
                        Registrar
                    </div>

                </div>

            </div>

        </header>


        <!-- =====================================================
             CONTENT
        ====================================================== -->

        <div class="content">


            <?php if ($errorMessage !== ''): ?>

                <div
                    class="alert alert-warning error-alert"
                    role="alert"
                >

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= htmlspecialchars(
                        $errorMessage,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div class="roster-page-header">

                <div>

                    <h2>
                        Academic Result Roster
                    </h2>

                    <p>
                        Select an academic year, grade, section,
                        and roster type to view student results.
                    </p>

                </div>


                <div class="roster-page-icon">

                    <i class="bi bi-file-earmark-spreadsheet-fill"></i>

                </div>

            </div>


            <!-- =================================================
                 FILTER CARD
            ================================================== -->

            <div class="roster-card">

                <div class="roster-card-header">

                    <div class="roster-card-title">

                        <div class="roster-card-icon">

                            <i class="bi bi-funnel-fill"></i>

                        </div>


                        <div>

                            <h3>
                                Roster Selection
                            </h3>

                            <p>
                                Choose the academic period and class.
                            </p>

                        </div>

                    </div>

                </div>


                <div class="roster-card-body">

                    <form
                        method="GET"
                        action="Roster.php"
                    >

                        <div class="row g-3">


                            <!-- Academic Year -->

                            <div class="col-lg-3 col-md-6">

                                <label
                                    for="academic_year_id"
                                    class="form-label"
                                >
                                    Academic Year
                                </label>


                                <select
                                    name="academic_year_id"
                                    id="academic_year_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Academic Year
                                    </option>


                                    <?php foreach (
                                        $academicYears
                                        as $year
                                    ): ?>

                                        <option
                                            value="<?= (int) $year['id'] ?>"
                                            <?= $selectedAcademicYearId ===
                                                (int) $year['id']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= htmlspecialchars(
                                                (string) $year['name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- Grade -->

                            <div class="col-lg-3 col-md-6">

                                <label
                                    for="grade_id"
                                    class="form-label"
                                >
                                    Grade
                                </label>


                                <select
                                    name="grade_id"
                                    id="grade_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Grade
                                    </option>


                                    <?php foreach (
                                        $grades
                                        as $grade
                                    ): ?>

                                        <option
                                            value="<?= (int) $grade['id'] ?>"
                                            <?= $selectedGradeId ===
                                                (int) $grade['id']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= htmlspecialchars(
                                                (string) $grade['name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- Section -->

                            <div class="col-lg-3 col-md-6">

                                <label
                                    for="section_id"
                                    class="form-label"
                                >
                                    Section
                                </label>


                                <select
                                    name="section_id"
                                    id="section_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Section
                                    </option>


                                    <?php foreach (
                                        $sections
                                        as $section
                                    ): ?>

                                        <option
                                            value="<?= (int) $section['id'] ?>"
                                            <?= $selectedSectionId ===
                                                (int) $section['id']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= htmlspecialchars(
                                                !empty($section['code'])
                                                    ? (string) $section['code']
                                                    : (string) $section['name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- Roster Type -->

                            <div class="col-lg-3 col-md-6">

                                <label
                                    for="roster_type"
                                    class="form-label"
                                >
                                    Roster Type
                                </label>


                                <select
                                    name="roster_type"
                                    id="roster_type"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value="first"
                                        <?= $selectedRosterType === 'first'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        First Semester
                                    </option>


                                    <option
                                        value="second"
                                        <?= $selectedRosterType === 'second'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Second Semester
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


                        <div class="filter-actions">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >

                                <i class="bi bi-search"></i>

                                Load Roster

                            </button>


                            <a
                                href="Roster.php"
                                class="btn btn-light"
                            >

                                <i class="bi bi-arrow-counterclockwise"></i>

                                Reset

                            </a>

                        </div>

                    </form>

                </div>

            </div>


            <!-- =================================================
                 ANNUAL STATUS
            ================================================== -->

            <?php if (
                $selectedRosterType === 'annual' &&
                $selectedAcademicYearId > 0
            ): ?>

                <?php

                $annualReady = isAnnualRosterReady(
                    $conn,
                    $selectedAcademicYearId
                );

                ?>


                <div
                    class="annual-status <?= $annualReady
                        ? 'annual-ready'
                        : 'annual-warning' ?>"
                >

                    <div class="annual-status-icon">

                        <i class="bi <?= $annualReady
                            ? 'bi-check-circle-fill'
                            : 'bi-exclamation-circle-fill' ?>"></i>

                    </div>


                    <div>

                        <?php if ($annualReady): ?>

                            <strong>
                                Annual roster is ready.
                            </strong>

                            <p>
                                Both First Semester and Second Semester
                                are completed for this academic year.
                            </p>

                        <?php else: ?>

                            <strong>
                                Annual roster is not ready.
                            </strong>

                            <p>
                                Both First Semester and Second Semester
                                must be completed before the Annual Roster
                                can be exported.
                            </p>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 RESULTS
            ================================================== -->

            <?php if (!empty($roster)): ?>

                <div class="roster-result-card">


                    <!-- Result Header -->

                    <div class="roster-result-header">

                        <div>

                            <div class="result-title">

                                <i class="bi bi-file-earmark-spreadsheet-fill"></i>

                                <?= htmlspecialchars(
                                    $rosterTypeLabel,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>


                            <div class="result-details">

                                <span>

                                    <strong>
                                        Academic Year:
                                    </strong>

                                    <?= htmlspecialchars(
                                        (string) (
                                            $selectedAcademicYear['name']
                                            ?? ''
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>


                                <span>

                                    <strong>
                                        Grade:
                                    </strong>

                                    <?= htmlspecialchars(
                                        (string) (
                                            $selectedGrade['name']
                                            ?? ''
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>


                                <span>

                                    <strong>
                                        Section:
                                    </strong>

                                    <?= htmlspecialchars(
                                        $sectionDisplay,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>


                                <span>

                                    <strong>
                                        Students:
                                    </strong>

                                    <?= $totalStudents ?>

                                </span>

                            </div>

                        </div>


                        <?php if ($canExport): ?>

                            <div class="result-actions">

                                <a
                                    href="roster-export.php?academic_year_id=<?= $selectedAcademicYearId ?>&grade_id=<?= $selectedGradeId ?>&section_id=<?= $selectedSectionId ?>&roster_type=<?= urlencode($selectedRosterType) ?>"
                                    class="btn btn-success"
                                >

                                    <i class="bi bi-file-earmark-excel-fill"></i>

                                    Export Excel

                                </a>

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- =================================================
                         PAGINATION TOP BAR
                    ================================================== -->

                    <?php if ($totalPages > 0): ?>

                        <div class="roster-pagination-bar">

                            <div class="pagination-summary">

                                Showing

                                <strong>
                                    <?= $pageStart + 1 ?>
                                </strong>

                                to

                                <strong>
                                    <?= $pageEnd ?>
                                </strong>

                                of

                                <strong>
                                    <?= $totalStudents ?>
                                </strong>

                                students

                            </div>


                            <form
                                method="GET"
                                action="Roster.php"
                                class="per-page-form"
                            >

                                <input
                                    type="hidden"
                                    name="academic_year_id"
                                    value="<?= $selectedAcademicYearId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="grade_id"
                                    value="<?= $selectedGradeId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="section_id"
                                    value="<?= $selectedSectionId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="roster_type"
                                    value="<?= htmlspecialchars(
                                        $selectedRosterType,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="page"
                                    value="1"
                                >


                                <label
                                    for="per_page"
                                    class="per-page-label"
                                >
                                    Students per page
                                </label>


                                <select
                                    name="per_page"
                                    id="per_page"
                                    class="per-page-select"
                                    onchange="this.form.submit()"
                                >

                                    <?php foreach (
                                        $allowedPerPage
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

                            </form>

                        </div>

                    <?php endif; ?>


                    <!-- =================================================
                         TABLE
                    ================================================== -->

                    <div class="table-wrapper">

                        <div class="table-responsive">

                            <table class="table roster-table">

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


                                    <?php if (
                                        $selectedRosterType === 'annual'
                                    ): ?>

                                        <th>
                                            Semester
                                        </th>

                                    <?php endif; ?>


                                    <?php foreach (
                                        $subjects
                                        as $subject
                                    ): ?>

                                        <th>

                                            <?= htmlspecialchars(
                                                (string) $subject,
                                                ENT_QUOTES,
                                                'UTF-8'
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


                                <?php foreach (
                                    $roster
                                    as $index => $student
                                ): ?>

                                    <?php

                                    if (!is_array($student)) {
                                        continue;
                                    }


                                    /*
                                    | row_number is global.
                                    */

                                    $rowNumber =
                                        is_numeric(
                                            $student['row_number']
                                                ?? null
                                        )
                                            ? (int) (
                                                $student['row_number']
                                            )
                                            : (
                                                $pageStart +
                                                $index +
                                                1
                                            );


                                    $studentCode =
                                        is_scalar(
                                            $student['student_code']
                                                ?? ''
                                        )
                                            ? (string) (
                                                $student['student_code']
                                                ?? ''
                                            )
                                            : '';


                                    $studentName =
                                        is_scalar(
                                            $student['student_name']
                                                ?? ''
                                        )
                                            ? (string) (
                                                $student['student_name']
                                                ?? ''
                                            )
                                            : '';

                                    ?>


                                    <?php if (
                                        $selectedRosterType === 'annual'
                                    ): ?>


                                        <!-- =================================
                                             FIRST SEMESTER
                                        ================================== -->

                                        <tr class="annual-first-row">


                                            <!-- No -->

                                            <td
                                                class="student-number"
                                                rowspan="3"
                                            >

                                                <?= htmlspecialchars(
                                                    (string) $rowNumber,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <!-- Student Code -->

                                            <td
                                                class="student-code"
                                                rowspan="3"
                                            >

                                                <?= htmlspecialchars(
                                                    $studentCode,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <!-- Student Name -->

                                            <td
                                                class="student-name"
                                                rowspan="3"
                                            >

                                                <?= htmlspecialchars(
                                                    $studentName,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <!-- Semester -->

                                            <td class="semester-cell">

                                                1st Semester

                                            </td>


                                            <?php foreach (
                                                $subjects
                                                as $subject
                                            ): ?>

                                                <?php

                                                $firstMark =
                                                    $student[
                                                        'first_subjects'
                                                    ][$subject]
                                                        ?? null;

                                                ?>


                                                <td>

                                                    <?= formatRosterMark(
                                                        is_scalar($firstMark)
                                                            ? $firstMark
                                                            : null
                                                    ) ?>

                                                </td>

                                            <?php endforeach; ?>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['first_sum']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['first_average']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="rank-cell">

                                                <?= formatRosterRank(
                                                    $student['first_rank']
                                                        ?? null
                                                ) ?>

                                            </td>

                                        </tr>


                                        <!-- =================================
                                             SECOND SEMESTER
                                        ================================== -->

                                        <tr class="annual-secondary-row">


                                            <td class="semester-cell">

                                                2nd Semester

                                            </td>


                                            <?php foreach (
                                                $subjects
                                                as $subject
                                            ): ?>

                                                <?php

                                                $secondMark =
                                                    $student[
                                                        'second_subjects'
                                                    ][$subject]
                                                        ?? null;

                                                ?>


                                                <td>

                                                    <?= formatRosterMark(
                                                        is_scalar($secondMark)
                                                            ? $secondMark
                                                            : null
                                                    ) ?>

                                                </td>

                                            <?php endforeach; ?>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['second_sum']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['second_average']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="rank-cell">

                                                <?= formatRosterRank(
                                                    $student['second_rank']
                                                        ?? null
                                                ) ?>

                                            </td>

                                        </tr>


                                        <!-- =================================
                                             ANNUAL AVERAGE
                                        ================================== -->

                                        <tr class="annual-average-row">


                                            <td class="semester-cell">

                                                Annual Average

                                            </td>


                                            <?php foreach (
                                                $subjects
                                                as $subject
                                            ): ?>

                                                <?php

                                                $annualMark =
                                                    $student[
                                                        'annual_subjects'
                                                    ][$subject]
                                                        ?? null;

                                                ?>


                                                <td class="annual-value">

                                                    <?= formatRosterNumber(
                                                        is_scalar($annualMark)
                                                            ? $annualMark
                                                            : null
                                                    ) ?>

                                                </td>

                                            <?php endforeach; ?>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['annual_sum']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['annual_average']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="rank-cell">

                                                <?= formatRosterRank(
                                                    $student['annual_rank']
                                                        ?? null
                                                ) ?>

                                            </td>

                                        </tr>


                                    <?php else: ?>


                                        <!-- =================================
                                             FIRST / SECOND SEMESTER
                                        ================================== -->

                                        <tr>


                                            <!-- No -->

                                            <td class="student-number">

                                                <?= htmlspecialchars(
                                                    (string) $rowNumber,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <!-- Student Code -->

                                            <td class="student-code">

                                                <?= htmlspecialchars(
                                                    $studentCode,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <!-- Student Name -->

                                            <td class="student-name">

                                                <?= htmlspecialchars(
                                                    $studentName,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </td>


                                            <?php foreach (
                                                $subjects
                                                as $subject
                                            ): ?>

                                                <?php

                                                $mark =
                                                    $student[
                                                        'subjects'
                                                    ][$subject]
                                                        ?? null;

                                                ?>


                                                <td>

                                                    <?= formatRosterMark(
                                                        is_scalar($mark)
                                                            ? $mark
                                                            : null
                                                    ) ?>

                                                </td>

                                            <?php endforeach; ?>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['sum']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="summary-cell">

                                                <?= formatRosterNumber(
                                                    $student['average']
                                                        ?? null
                                                ) ?>

                                            </td>


                                            <td class="rank-cell">

                                                <?= formatRosterRank(
                                                    $student['rank']
                                                        ?? null
                                                ) ?>

                                            </td>

                                        </tr>


                                    <?php endif; ?>


                                <?php endforeach; ?>


                                </tbody>

                            </table>

                        </div>

                    </div>


                    <!-- =================================================
                         BOTTOM PAGINATION
                    ================================================== -->

                    <?php if ($totalPages > 1): ?>

                        <div class="pagination-container">


                            <div class="pagination-info">

                                Page
                                <strong>
                                    <?= $currentPage ?>
                                </strong>
                                of
                                <strong>
                                    <?= $totalPages ?>
                                </strong>

                            </div>


                            <nav
                                aria-label="Roster pagination"
                            >

                                <ul class="pagination">


                                    <!-- Previous -->

                                    <?php

                                    $previousDisabled =
                                        $currentPage <= 1;

                                    ?>

                                    <li
                                        class="page-item <?= $previousDisabled
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $previousDisabled
                                        ): ?>

                                            <span class="page-link">

                                                <i class="bi bi-chevron-left"></i>

                                            </span>

                                        <?php else: ?>

                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(
                                                    rosterPageUrl(
                                                        $currentPage - 1,
                                                        $selectedAcademicYearId,
                                                        $selectedGradeId,
                                                        $selectedSectionId,
                                                        $selectedRosterType,
                                                        $perPage
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                                <i class="bi bi-chevron-left"></i>

                                            </a>

                                        <?php endif; ?>

                                    </li>


                                    <?php

                                    /*
                                    |--------------------------------------------------------------------------
                                    | Page Number Window
                                    |--------------------------------------------------------------------------
                                    */

                                    $paginationPages = [];

                                    if ($totalPages <= 7) {

                                        for (
                                            $p = 1;
                                            $p <= $totalPages;
                                            $p++
                                        ) {

                                            $paginationPages[] = $p;
                                        }

                                    } else {

                                        $paginationPages[] = 1;

                                        if ($currentPage > 4) {
                                            $paginationPages[] = '...';
                                        }

                                        $windowStart =
                                            max(
                                                2,
                                                $currentPage - 1
                                            );

                                        $windowEnd =
                                            min(
                                                $totalPages - 1,
                                                $currentPage + 1
                                            );

                                        for (
                                            $p = $windowStart;
                                            $p <= $windowEnd;
                                            $p++
                                        ) {

                                            $paginationPages[] = $p;
                                        }

                                        if (
                                            $currentPage <
                                            ($totalPages - 3)
                                        ) {

                                            $paginationPages[] = '...';
                                        }

                                        $paginationPages[] =
                                            $totalPages;
                                    }

                                    ?>


                                    <?php foreach (
                                        $paginationPages
                                        as $paginationPage
                                    ): ?>


                                        <?php if (
                                            $paginationPage === '...'
                                        ): ?>

                                            <li
                                                class="pagination-ellipsis"
                                            >
                                                ...
                                            </li>

                                        <?php else: ?>

                                            <li
                                                class="page-item <?= $currentPage ===
                                                    (int) $paginationPage
                                                    ? 'active'
                                                    : '' ?>"
                                            >

                                                <?php if (
                                                    $currentPage ===
                                                    (int) $paginationPage
                                                ): ?>

                                                    <span
                                                        class="page-link"
                                                    >
                                                        <?= (int) $paginationPage ?>
                                                    </span>

                                                <?php else: ?>

                                                    <a
                                                        class="page-link"
                                                        href="<?= htmlspecialchars(
                                                            rosterPageUrl(
                                                                (int) $paginationPage,
                                                                $selectedAcademicYearId,
                                                                $selectedGradeId,
                                                                $selectedSectionId,
                                                                $selectedRosterType,
                                                                $perPage
                                                            ),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>"
                                                    >
                                                        <?= (int) $paginationPage ?>
                                                    </a>

                                                <?php endif; ?>

                                            </li>

                                        <?php endif; ?>


                                    <?php endforeach; ?>


                                    <!-- Next -->

                                    <?php

                                    $nextDisabled =
                                        $currentPage >= $totalPages;

                                    ?>

                                    <li
                                        class="page-item <?= $nextDisabled
                                            ? 'disabled'
                                            : '' ?>"
                                    >

                                        <?php if (
                                            $nextDisabled
                                        ): ?>

                                            <span class="page-link">

                                                <i class="bi bi-chevron-right"></i>

                                            </span>

                                        <?php else: ?>

                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(
                                                    rosterPageUrl(
                                                        $currentPage + 1,
                                                        $selectedAcademicYearId,
                                                        $selectedGradeId,
                                                        $selectedSectionId,
                                                        $selectedRosterType,
                                                        $perPage
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                                <i class="bi bi-chevron-right"></i>

                                            </a>

                                        <?php endif; ?>

                                    </li>

                                </ul>

                            </nav>

                        </div>

                    <?php endif; ?>


                </div>


            <?php elseif (
                $selectedAcademicYearId > 0 &&
                $selectedGradeId > 0 &&
                $selectedSectionId > 0 &&
                $errorMessage === ''
            ): ?>


                <div class="empty-state">

                    <div class="empty-icon">

                        <i class="bi bi-clipboard-x"></i>

                    </div>


                    <h3>
                        No Roster Data
                    </h3>


                    <p>
                        There are no results available for
                        the selected criteria.
                    </p>

                </div>


            <?php endif; ?>


        </div>

    </main>

</div>


<script>

    /*
    |--------------------------------------------------------------------------
    | Mobile Sidebar
    |--------------------------------------------------------------------------
    */

    const mobileMenu =
        document.getElementById('mobileMenu');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');


    if (mobileMenu) {

        mobileMenu.addEventListener(
            'click',
            function () {

                sidebar.classList.toggle('show');

                sidebarOverlay.classList.toggle('show');

            }
        );
    }


    if (sidebarOverlay) {

        sidebarOverlay.addEventListener(
            'click',
            function () {

                sidebar.classList.remove('show');

                sidebarOverlay.classList.remove('show');

            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Close Mobile Sidebar When Link Is Clicked
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll(
        '.sidebar .nav-link'
    ).forEach(function (link) {

        link.addEventListener(
            'click',
            function () {

                if (window.innerWidth <= 900) {

                    sidebar.classList.remove('show');

                    sidebarOverlay.classList.remove('show');

                }

            }
        );

    });

</script>


</body>

</html>