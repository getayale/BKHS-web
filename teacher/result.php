<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| AUTHORIZATION
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

require_once '../config/database.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectWithMessage(
    string $type,
    string $message,
    array $params = []
): never {
    $params['message_type'] = $type;
    $params['message'] = $message;

    header(
        'Location: result.php?' .
        http_build_query($params)
    );

    exit;
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
| VARIABLES
|--------------------------------------------------------------------------
*/

$error = '';
$success = '';

$teacher = null;

$teacherName = 'Teacher';
$teacherInitials = 'T';
$teacherPhoto = '';

$academicYear = null;
$academicYearId = 0;
$academicYearName = 'No Active Academic Year';

$semesters = [];
$activeSemester = null;
$selectedSemester = null;

$assignments = [];

$grades = [];
$sectionsByGrade = [];
$subjectsByClass = [];

$selectedSemesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

$selectedGrade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$selectedSection = isset($_GET['section'])
    ? strtoupper(trim((string) $_GET['section']))
    : '';

$selectedSubjectId = isset($_GET['subject_id'])
    ? (int) $_GET['subject_id']
    : 0;

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$students = [];

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$studentsPerPage = 10;

$currentPage = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['message_type'], $_GET['message']) &&
    $_GET['message'] !== ''
) {
    if ($_GET['message_type'] === 'success') {
        $success = (string) $_GET['message'];
    } else {
        $error = (string) $_GET['message'];
    }
}

/*
|--------------------------------------------------------------------------
| TEACHER PROFILE
|--------------------------------------------------------------------------
*/

$teacherStmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.photo_path
    FROM users u
    LEFT JOIN teachers t
        ON t.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0
    LIMIT 1
");

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

$teacherName = (string) (
    $teacher['full_name'] ?? 'Teacher'
);

$teacherInitials = getInitials($teacherName);

if (!empty($teacher['photo_path'])) {

    $photoPath = (string) $teacher['photo_path'];

    if (str_starts_with($photoPath, 'uploads/')) {

        $teacherPhoto = '../' . $photoPath;

    } elseif (str_starts_with($photoPath, '/')) {

        $teacherPhoto = $photoPath;

    } else {

        $teacherPhoto =
            '../uploads/teachers/' . $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| ACTIVE ACADEMIC YEAR
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$academicYearStmt) {

    die(
        'Unable to prepare academic year query.'
    );
}

$academicYearStmt->execute();

$academicYearResult =
    $academicYearStmt->get_result();

$academicYear =
    $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if ($academicYear) {

    $academicYearId =
        (int) $academicYear['id'];

    $academicYearName =
        (string) $academicYear['name'];

} else {

    $error =
        'There is no active academic year configured.';
}

/*
|--------------------------------------------------------------------------
| SEMESTERS
|--------------------------------------------------------------------------
*/

if ($academicYearId > 0) {

    $semesterStmt = $conn->prepare("
        SELECT
            id,
            name,
            order_number,
            max_mark,
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

    if ($semesterStmt) {

        $semesterStmt->bind_param(
            'i',
            $academicYearId
        );

        $semesterStmt->execute();

        $semesterResult =
            $semesterStmt->get_result();

        while (
            $row =
            $semesterResult->fetch_assoc()
        ) {

            $row['id'] =
                (int) $row['id'];

            $row['order_number'] =
                (int) $row['order_number'];

            $row['max_mark'] =
                (float) $row['max_mark'];

            $semesters[] = $row;

            if (
                (string) $row['status'] ===
                'Active'
            ) {
                $activeSemester = $row;
            }
        }

        $semesterStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| DEFAULT SEMESTER
|--------------------------------------------------------------------------
*/

if (
    $selectedSemesterId === 0 &&
    $activeSemester
) {

    $selectedSemesterId =
        (int) $activeSemester['id'];
}

/*
|--------------------------------------------------------------------------
| SELECTED SEMESTER
|--------------------------------------------------------------------------
*/

foreach ($semesters as $semester) {

    if (
        (int) $semester['id'] ===
        $selectedSemesterId
    ) {

        $selectedSemester =
            $semester;

        break;
    }
}

/*
|--------------------------------------------------------------------------
| TEACHER SUBJECT ASSIGNMENTS
|--------------------------------------------------------------------------
*/

if ($academicYearId > 0) {

    $assignmentStmt = $conn->prepare("
        SELECT
            sta.id,
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
            $teacherUserId,
            $academicYearName
        );

        $assignmentStmt->execute();

        $assignmentResult =
            $assignmentStmt->get_result();

        while (
            $row =
            $assignmentResult->fetch_assoc()
        ) {

            $row['id'] =
                (int) $row['id'];

            $row['grade'] =
                (int) $row['grade'];

            $row['grade_subject_id'] =
                (int) $row['grade_subject_id'];

            $assignments[] = $row;
        }

        $assignmentStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| BUILD GRADE / SECTION / SUBJECT LISTS
|--------------------------------------------------------------------------
*/

foreach ($assignments as $assignment) {

    $grade =
        (int) $assignment['grade'];

    $section =
        strtoupper(
            trim(
                (string) $assignment['section']
            )
        );

    $grades[$grade] = $grade;

    if (!isset($sectionsByGrade[$grade])) {
        $sectionsByGrade[$grade] = [];
    }

    if (
        !in_array(
            $section,
            $sectionsByGrade[$grade],
            true
        )
    ) {
        $sectionsByGrade[$grade][] =
            $section;
    }

    $classKey =
        $grade . '|' . $section;

    if (
        !isset(
            $subjectsByClass[$classKey]
        )
    ) {
        $subjectsByClass[$classKey] = [];
    }

    $subjectsByClass[$classKey][] = [
        'id' =>
            (int) $assignment['grade_subject_id'],

        'name' =>
            (string) $assignment['subject_name'],

        'code' => ''
    ];
}

ksort($grades);

foreach (
    $sectionsByGrade
    as &$sectionList
) {
    sort($sectionList);
}

unset($sectionList);

/*
|--------------------------------------------------------------------------
| VALIDATE SELECTED GRADE
|--------------------------------------------------------------------------
*/

if (
    $selectedGrade > 0 &&
    !isset($grades[$selectedGrade])
) {

    $selectedGrade = 0;
    $selectedSection = '';
    $selectedSubjectId = 0;
}

/*
|--------------------------------------------------------------------------
| VALIDATE SELECTED SECTION
|--------------------------------------------------------------------------
*/

if (
    $selectedGrade > 0 &&
    $selectedSection !== ''
) {

    $availableSections =
        $sectionsByGrade[
            $selectedGrade
        ] ?? [];

    if (
        !in_array(
            $selectedSection,
            $availableSections,
            true
        )
    ) {

        $selectedSection = '';
        $selectedSubjectId = 0;
    }
}

/*
|--------------------------------------------------------------------------
| AVAILABLE SUBJECTS
|--------------------------------------------------------------------------
*/

$selectedClassKey =
    $selectedGrade > 0 &&
    $selectedSection !== ''
        ? $selectedGrade .
          '|' .
          $selectedSection
        : '';

$availableSubjects =
    $subjectsByClass[
        $selectedClassKey
    ] ?? [];

/*
|--------------------------------------------------------------------------
| VALIDATE SELECTED SUBJECT
|--------------------------------------------------------------------------
*/

$validSubject = false;

foreach ($availableSubjects as $subject) {

    if (
        (int) $subject['id'] ===
        $selectedSubjectId
    ) {

        $validSubject = true;

        break;
    }
}

if (!$validSubject) {
    $selectedSubjectId = 0;
}

/*
|--------------------------------------------------------------------------
| SAVE RESULTS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'save_results'
) {

    $postSemesterId =
        isset($_POST['semester_id'])
            ? (int) $_POST['semester_id']
            : 0;

    $postGrade =
        isset($_POST['grade'])
            ? (int) $_POST['grade']
            : 0;

    $postSection =
        isset($_POST['section'])
            ? strtoupper(
                trim(
                    (string) $_POST['section']
                )
            )
            : '';

    $postSubjectId =
        isset($_POST['subject_id'])
            ? (int) $_POST['subject_id']
            : 0;

    $marks =
        isset($_POST['marks']) &&
        is_array($_POST['marks'])
            ? $_POST['marks']
            : [];

    $redirectParams = [
        'semester_id' =>
            $postSemesterId,

        'grade' =>
            $postGrade,

        'section' =>
            $postSection,

        'subject_id' =>
            $postSubjectId,

        'search' =>
            $search,

        'page' =>
            $currentPage
    ];

    /*
    |--------------------------------------------------------------------------
    | BASIC VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($academicYearId <= 0) {

        redirectWithMessage(
            'error',
            'No active academic year is available.',
            $redirectParams
        );
    }

    if ($postSemesterId <= 0) {

        redirectWithMessage(
            'error',
            'Please select a semester.',
            $redirectParams
        );
    }

    if ($postGrade <= 0) {

        redirectWithMessage(
            'error',
            'Please select a grade.',
            $redirectParams
        );
    }

    if ($postSection === '') {

        redirectWithMessage(
            'error',
            'Please select a section.',
            $redirectParams
        );
    }

    if ($postSubjectId <= 0) {

        redirectWithMessage(
            'error',
            'Please select a subject.',
            $redirectParams
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE SEMESTER
    |--------------------------------------------------------------------------
    */

    $semesterValidateStmt =
        $conn->prepare("
            SELECT
                id,
                name,
                max_mark,
                status,
                academic_year_id
            FROM semesters
            WHERE id = ?
              AND academic_year_id = ?
            LIMIT 1
        ");

    if (!$semesterValidateStmt) {

        redirectWithMessage(
            'error',
            'Unable to validate the selected semester.',
            $redirectParams
        );
    }

    $semesterValidateStmt->bind_param(
        'ii',
        $postSemesterId,
        $academicYearId
    );

    $semesterValidateStmt->execute();

    $semesterValidateResult =
        $semesterValidateStmt->get_result();

    $saveSemester =
        $semesterValidateResult->fetch_assoc();

    $semesterValidateStmt->close();

    if (!$saveSemester) {

        redirectWithMessage(
            'error',
            'The selected semester does not belong to the current academic year.',
            $redirectParams
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ONLY ACTIVE SEMESTER CAN BE EDITED
    |--------------------------------------------------------------------------
    */

    if (
        (string) $saveSemester['status'] !==
        'Active'
    ) {

        redirectWithMessage(
            'error',
            'Results can only be added or edited while the semester is Active.',
            $redirectParams
        );
    }

    $maxMark =
        (float) $saveSemester['max_mark'];

    /*
    |--------------------------------------------------------------------------
    | VALIDATE TEACHER ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    $assignmentValidateStmt =
        $conn->prepare("
            SELECT
                id
            FROM subject_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND grade = ?
              AND section = ?
              AND grade_subject_id = ?
              AND is_active = 1
            LIMIT 1
        ");

    if (!$assignmentValidateStmt) {

        redirectWithMessage(
            'error',
            'Unable to validate your subject assignment.',
            $redirectParams
        );
    }

    $assignmentValidateStmt->bind_param(
        'isisi',
        $teacherUserId,
        $academicYearName,
        $postGrade,
        $postSection,
        $postSubjectId
    );

    $assignmentValidateStmt->execute();

    $assignmentValidateResult =
        $assignmentValidateStmt->get_result();

    $teacherAssignment =
        $assignmentValidateResult->fetch_assoc();

    $assignmentValidateStmt->close();

    if (!$teacherAssignment) {

        redirectWithMessage(
            'error',
            'You are not assigned to this subject and class.',
            $redirectParams
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET STUDENT REGISTRATION IDS
    |--------------------------------------------------------------------------
    */

    $studentIds = [];

    $studentValidateStmt =
        $conn->prepare("
            SELECT
                sr.id
            FROM student_registrations sr
            INNER JOIN grades g
                ON g.id = sr.grade_id
            INNER JOIN sections sec
                ON sec.id = sr.section_id
            INNER JOIN students s
                ON s.id = sr.student_id
            WHERE sr.academic_year_id = ?
              AND g.grade_number = ?
              AND sec.code = ?
        ");

    if (!$studentValidateStmt) {

        redirectWithMessage(
            'error',
            'Unable to validate students.',
            $redirectParams
        );
    }

    $studentValidateStmt->bind_param(
        'iis',
        $academicYearId,
        $postGrade,
        $postSection
    );

    $studentValidateStmt->execute();

    $studentValidateResult =
        $studentValidateStmt->get_result();

    while (
        $studentRow =
        $studentValidateResult->fetch_assoc()
    ) {

        $studentIds[] =
            (int) $studentRow['id'];
    }

    $studentValidateStmt->close();

    if (count($studentIds) === 0) {

        redirectWithMessage(
            'error',
            'No students are registered in the selected class.',
            $redirectParams
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE RESULTS
    |--------------------------------------------------------------------------
    */

    $savedCount = 0;

    try {

        $conn->begin_transaction();

        $upsertStmt =
            $conn->prepare("
                INSERT INTO results (
                    student_registration_id,
                    grade_subject_id,
                    semester_id,
                    mark
                )
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    mark = VALUES(mark),
                    updated_at = CURRENT_TIMESTAMP
            ");

        if (!$upsertStmt) {

            throw new RuntimeException(
                'Unable to prepare result save query.'
            );
        }

        foreach (
            $marks as $registrationIdRaw =>
            $markRaw
        ) {

            $registrationId =
                (int) $registrationIdRaw;

            if (
                !in_array(
                    $registrationId,
                    $studentIds,
                    true
                )
            ) {
                continue;
            }

            if (
                $markRaw === null ||
                trim((string) $markRaw) === ''
            ) {
                continue;
            }

            if (!is_numeric($markRaw)) {

                throw new RuntimeException(
                    "Invalid mark entered for student registration {$registrationId}."
                );
            }

            $mark =
                (float) $markRaw;

            if ($mark < 0) {

                throw new RuntimeException(
                    'A mark cannot be negative.'
                );
            }

            if ($mark > $maxMark) {

                throw new RuntimeException(
                    "A mark cannot be greater than {$maxMark}."
                );
            }

            $upsertStmt->bind_param(
                'iiid',
                $registrationId,
                $postSubjectId,
                $postSemesterId,
                $mark
            );

            if (!$upsertStmt->execute()) {

                throw new RuntimeException(
                    'Failed to save one of the student results.'
                );
            }

            $savedCount++;
        }

        $upsertStmt->close();

        $conn->commit();

        redirectWithMessage(
            'success',
            $savedCount > 0
                ? "{$savedCount} result(s) saved successfully."
                : 'No marks were entered.',
            $redirectParams
        );

    } catch (Throwable $exception) {

        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // Ignore rollback errors.
        }

        redirectWithMessage(
            'error',
            $exception->getMessage(),
            $redirectParams
        );
    }
}

/*
|--------------------------------------------------------------------------
| LOAD STUDENTS AND RESULTS
|--------------------------------------------------------------------------
*/

if (
    $academicYearId > 0 &&
    $selectedSemester &&
    $selectedGrade > 0 &&
    $selectedSection !== '' &&
    $selectedSubjectId > 0
) {

    $studentStmt =
        $conn->prepare("
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
            ORDER BY
                s.full_name ASC
        ");

    if ($studentStmt) {

        $studentStmt->bind_param(
            'iiiss',
            $selectedSubjectId,
            $selectedSemesterId,
            $academicYearId,
            $selectedGrade,
            $selectedSection
        );

        $studentStmt->execute();

        $studentResult =
            $studentStmt->get_result();

        while (
            $row =
            $studentResult->fetch_assoc()
        ) {

            if ($search !== '') {

                $searchLower =
                    strtolower($search);

                $nameLower =
                    strtolower(
                        (string) $row['full_name']
                    );

                $codeLower =
                    strtolower(
                        (string) $row['student_code']
                    );

                if (
                    strpos(
                        $nameLower,
                        $searchLower
                    ) === false &&
                    strpos(
                        $codeLower,
                        $searchLower
                    ) === false
                ) {
                    continue;
                }
            }

            $students[] = $row;
        }

        $studentStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| SELECTED SUBJECT
|--------------------------------------------------------------------------
*/

$selectedSubject = null;

foreach (
    $availableSubjects as $subject
) {

    if (
        (int) $subject['id'] ===
        $selectedSubjectId
    ) {

        $selectedSubject =
            $subject;

        break;
    }
}

/*
|--------------------------------------------------------------------------
| TOTAL STUDENTS BEFORE PAGINATION
|--------------------------------------------------------------------------
*/

$totalStudents =
    count($students);

/*
|--------------------------------------------------------------------------
| PAGINATION CALCULATION
|--------------------------------------------------------------------------
*/

$totalPages =
    $totalStudents > 0
        ? (int) ceil(
            $totalStudents /
            $studentsPerPage
        )
        : 1;

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$paginationOffset =
    ($currentPage - 1) *
    $studentsPerPage;

$studentsOnCurrentPage =
    array_slice(
        $students,
        $paginationOffset,
        $studentsPerPage
    );

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$recordedResults = 0;

foreach ($students as $student) {

    if (
        $student['result_id'] !== null &&
        $student['mark'] !== null
    ) {

        $recordedResults++;
    }
}

$missingResults =
    max(
        0,
        $totalStudents -
        $recordedResults
    );

$isActiveSemester =
    $selectedSemester &&
    (string) $selectedSemester['status'] ===
    'Active';

$isCompletedSemester =
    $selectedSemester &&
    (string) $selectedSemester['status'] ===
    'Completed';

$isNotCompletedSemester =
    $selectedSemester &&
    (string) $selectedSemester['status'] ===
    'Not Completed';

$canEdit =
    $isActiveSemester;

/*
|--------------------------------------------------------------------------
| DISPLAY MAX MARK
|--------------------------------------------------------------------------
*/

$selectedMaxMark = 0;

if ($selectedSemester) {

    $selectedMaxMark =
        (float) $selectedSemester['max_mark'];
}

$formattedMaxMark =
    rtrim(
        rtrim(
            number_format(
                $selectedMaxMark,
                2,
                '.',
                ''
            ),
            '0'
        ),
        '.'
    );

/*
|--------------------------------------------------------------------------
| PAGINATION URL HELPER
|--------------------------------------------------------------------------
*/

$paginationParams = [
    'semester_id' =>
        $selectedSemesterId,

    'grade' =>
        $selectedGrade,

    'section' =>
        $selectedSection,

    'subject_id' =>
        $selectedSubjectId,

    'search' =>
        $search
];

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

    <!-- FAVICON -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        rel="shortcut icon"
        href="../public/image/logo.webp"
        type="image/webp"
    >

    <title>
        Results | Teacher Portal | BKHS
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
        | SIDEBAR
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
            transition: transform .25s ease;
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
        | MAIN
        |--------------------------------------------------------------------------
        */

        .main {
            min-height: 100vh;
            margin-left: 260px;
            width: calc(100% - 260px);
        }

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

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .menu-toggle {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: #111827;
            font-size: 20px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .page-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            font-size: 19px;
            flex-shrink: 0;
        }

        .page-heading {
            min-width: 0;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 19px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 3px 0 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        .academic-year {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #f9fafb;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .content {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
            padding: 25px 28px 40px;
        }

        /*
        |--------------------------------------------------------------------------
        | ALERTS
        |--------------------------------------------------------------------------
        */

        .alert-custom {
            border: 0;
            border-radius: 11px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 17px;
            font-size: 12px;
        }

        .alert-success-custom {
            background: #ecfdf3;
            color: #166534;
        }

        .alert-error-custom {
            background: #fef2f2;
            color: #991b1b;
        }

        /*
        |--------------------------------------------------------------------------
        | SEMESTERS
        |--------------------------------------------------------------------------
        */

        .semester-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .semester-card {
            min-width: 0;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            color: inherit;
            transition: .2s ease;
        }

        .semester-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 18px rgba(15, 23, 42, .06);
        }

        .semester-card.selected {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .10);
        }

        .semester-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 7px;
        }

        .semester-name {
            min-width: 0;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.35;
        }

        .semester-max {
            margin-top: 8px;
            color: var(--text-muted);
            font-size: 10px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-completed {
            background: #e0e7ff;
            color: #3730a3;
        }

        .status-not-completed {
            background: #fef3c7;
            color: #92400e;
        }

        /*
        |--------------------------------------------------------------------------
        | FILTER
        |--------------------------------------------------------------------------
        */

        .filter-card {
            padding: 19px;
            margin-bottom: 18px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
        }

        .filter-heading {
            margin-bottom: 17px;
        }

        .filter-heading h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .filter-heading p {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        .form-label {
            margin-bottom: 6px;
            color: #374151;
            font-size: 10px;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 43px;
            border-radius: 9px;
            border-color: #dbe0e7;
            font-size: 11px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
        }

        /*
        |--------------------------------------------------------------------------
        | SUMMARY
        |--------------------------------------------------------------------------
        */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            min-width: 0;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .summary-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            font-size: 16px;
            flex-shrink: 0;
        }

        .summary-card strong {
            display: block;
            font-size: 19px;
            line-height: 1;
        }

        .summary-card span {
            display: block;
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 9px;
        }

        /*
        |--------------------------------------------------------------------------
        | RESULT CARD
        |--------------------------------------------------------------------------
        */

        .result-card {
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
            overflow: hidden;
        }

        .result-header {
            padding: 17px 19px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .result-title {
            min-width: 0;
        }

        .result-title h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .result-title p {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        .btn-primary-custom {
            min-height: 40px;
            padding: 0 14px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .readonly-notice {
            margin: 14px 19px 0;
            padding: 10px 12px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 10px;
        }

        .readonly-completed {
            background: #eef2ff;
            color: #3730a3;
        }

        .readonly-not-completed {
            background: #fffbeb;
            color: #92400e;
        }

        /*
        |--------------------------------------------------------------------------
        | TABLE
        |--------------------------------------------------------------------------
        */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .result-table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
        }

        .result-table th {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            color: #64748b;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .result-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #eef0f3;
            font-size: 11px;
            vertical-align: middle;
        }

        .result-table tbody tr:hover {
            background: #fafcff;
        }

        .student-number {
            width: 45px;
            color: #64748b;
            font-weight: 700;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 180px;
        }

        .student-avatar {
            width: 33px;
            height: 33px;
            border-radius: 9px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .student-name {
            color: #1f2937;
            font-size: 11px;
            font-weight: 750;
        }

        .student-code {
            color: #64748b;
            font-size: 10px;
            font-weight: 600;
        }

        .mark-input {
            width: 100px;
            min-height: 42px;
            text-align: center;
            font-size: 13px !important;
            font-weight: 800;
        }

        .mark-display {
            min-width: 60px;
            min-height: 34px;
            padding: 5px 9px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .missing-mark {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 600;
        }

        .save-footer {
            padding: 15px 19px;
            border-top: 1px solid #eef0f3;
            display: flex;
            justify-content: flex-end;
        }

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            padding: 15px 19px;
            border-top: 1px solid #eef0f3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 600;
        }

        .pagination-custom {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0;
        }

        .pagination-custom .page-link {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            border: 1px solid var(--border);
            border-radius: 8px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #374151;
            background: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .pagination-custom .page-link:hover {
            background: #eff6ff;
            color: var(--primary);
            border-color: #bfdbfe;
        }

        .pagination-custom .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .pagination-custom .page-item.disabled .page-link {
            color: #cbd5e1;
            background: #f8fafc;
            cursor: not-allowed;
        }

        /*
        |--------------------------------------------------------------------------
        | EMPTY
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 50px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 12px;
            border-radius: 14px;
            background: #f1f5f9;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .empty-state h3 {
            margin-bottom: 5px;
            font-size: 14px;
            font-weight: 800;
        }

        .empty-state p {
            margin: 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        /*
        |--------------------------------------------------------------------------
        | MOBILE BOTTOM NAVIGATION
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | TABLET
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1199px) {

            .semester-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        /*
        |--------------------------------------------------------------------------
        | SMALL SCREEN
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .sidebar-overlay {
                display: none !important;
            }

            .main {
                width: 100%;
                margin-left: 0;
            }

            .menu-toggle {
                display: none !important;
            }

            .content {
                padding: 20px;
            }

            .topbar {
                padding: 11px 18px;
            }

            /*
            |----------------------------------------------------------------------
            | MOBILE BOTTOM NAV
            |----------------------------------------------------------------------
            */

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 1100;
                height: 66px;
                padding:
                    6px
                    6px
                    max(6px, env(safe-area-inset-bottom))
                    6px;
                background: rgba(255, 255, 255, .98);
                border-top: 1px solid var(--border);
                box-shadow:
                    0 -5px 20px rgba(15, 23, 42, .08);
                display: grid;
                grid-template-columns:
                    repeat(5, minmax(0, 1fr));
                gap: 2px;
            }

            .mobile-bottom-link {
                min-width: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                border-radius: 8px;
                color: #64748b;
                font-size: 8px;
                font-weight: 700;
                transition: .2s ease;
            }

            .mobile-bottom-link i {
                font-size: 18px;
                line-height: 1;
            }

            .mobile-bottom-link:hover,
            .mobile-bottom-link.active {
                background: #eff6ff;
                color: var(--primary);
            }

            .mobile-bottom-link.logout {
                color: #dc2626;
            }

            .mobile-bottom-link.logout:hover {
                background: #fef2f2;
                color: #b91c1c;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 767px) {

            .content {
                padding: 16px 16px 90px;
            }

            .semester-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 9px;
            }

            /*
            |----------------------------------------------------------------------
            | SUMMARY = ALWAYS THREE COLUMNS
            |----------------------------------------------------------------------
            */

            .summary-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
                gap: 8px;
            }

            .summary-card {
                padding: 12px 9px;
                gap: 7px;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
            }

            .summary-icon {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }

            .summary-card strong {
                font-size: 17px;
            }

            .summary-card span {
                font-size: 8px;
                line-height: 1.2;
            }

            /*
            |----------------------------------------------------------------------
            | FILTER
            |----------------------------------------------------------------------
            */

            .filter-card {
                padding: 15px;
            }

            /*
            | Grade / Section / Subject:
            | 3 equal columns on mobile.
            */

            .filter-card .filter-class-row {
                --bs-gutter-x: 7px;
                --bs-gutter-y: 0;
            }

            .filter-card .filter-class-field {
                width: 33.333333%;
                flex: 0 0 33.333333%;
                max-width: 33.333333%;
            }

            .filter-card .search-field {
                width: 100%;
                flex: 0 0 100%;
                max-width: 100%;
                margin-top: 10px;
            }

            .filter-card .form-label {
                margin-bottom: 4px;
                font-size: 8px;
            }

            .filter-card .form-select {
                min-height: 38px;
                height: 38px;
                padding: 6px 24px 6px 8px;
                border-radius: 8px;
                font-size: 9px;
                font-weight: 600;
            }

            .filter-card .form-control {
                min-height: 40px;
                height: 40px;
                padding: 7px 9px;
                border-radius: 8px;
                font-size: 9px;
            }

            .filter-card .input-group .btn {
                min-width: 42px;
                border-radius: 0 8px 8px 0;
            }

            .filter-heading {
                margin-bottom: 13px;
            }

            .filter-heading h2 {
                font-size: 12px;
            }

            .filter-heading p {
                font-size: 8px;
            }

            /*
            |----------------------------------------------------------------------
            | RESULT HEADER
            |----------------------------------------------------------------------
            */

            .result-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .result-actions {
                width: 100%;
            }

            .result-actions .btn-primary-custom {
                width: 100%;
            }

            .save-footer {
                justify-content: stretch;
            }

            .save-footer .btn-primary-custom {
                width: 100%;
            }

            .pagination-wrapper {
                padding: 12px;
                flex-direction: column;
                align-items: stretch;
            }

            .pagination-info {
                text-align: center;
            }

            .pagination-custom {
                justify-content: center;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | PHONE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 575px) {

            .topbar {
                min-height: 62px;
                padding: 9px 11px;
            }

            .topbar-left {
                gap: 8px;
            }

            .menu-toggle {
                display: none !important;
            }

            .page-icon {
                width: 36px;
                height: 36px;
                border-radius: 9px;
                font-size: 16px;
            }

            .page-heading h1 {
                font-size: 15px;
            }

            .page-heading p {
                display: none;
            }

            .academic-year {
                display: none;
            }

            .content {
                padding: 12px 12px 90px;
            }

            .semester-strip {
                gap: 7px;
                margin-bottom: 12px;
            }

            .semester-card {
                padding: 11px;
                border-radius: 10px;
            }

            .semester-name {
                font-size: 10px;
            }

            .semester-max {
                margin-top: 6px;
                font-size: 8px;
            }

            .status-badge {
                padding: 4px 6px;
                font-size: 7px;
            }

            /*
            |----------------------------------------------------------------------
            | FILTER CARD
            |----------------------------------------------------------------------
            */

            .filter-card {
                margin-bottom: 12px;
                padding: 12px;
                border-radius: 11px;
            }

            .filter-heading {
                margin-bottom: 12px;
            }

            .filter-heading h2 {
                font-size: 12px;
            }

            .filter-heading p {
                font-size: 8px;
            }

            /*
            | IMPORTANT:
            | Grade / Section / Subject remain on ONE ROW.
            */

            .filter-card .filter-class-row {
                --bs-gutter-x: 6px;
            }

            .filter-card .filter-class-field {
                width: 33.333333%;
                flex: 0 0 33.333333%;
                max-width: 33.333333%;
            }

            .filter-card .form-label {
                margin-bottom: 3px;
                font-size: 7.5px;
                white-space: nowrap;
            }

            .filter-card .form-select {
                min-height: 36px;
                height: 36px;
                padding:
                    5px
                    19px
                    5px
                    6px;
                border-radius: 7px;
                font-size: 8px;
                font-weight: 600;
                background-position:
                    right 5px center;
                background-size: 10px 8px;
            }

            /*
            |----------------------------------------------------------------------
            | SEARCH FULL WIDTH UNDER DROPDOWNS
            |----------------------------------------------------------------------
            */

            .filter-card .search-field {
                margin-top: 9px;
            }

            .filter-card .search-field .form-label {
                font-size: 8px;
            }

            .filter-card .form-control {
                min-height: 38px;
                height: 38px;
                padding: 6px 8px;
                font-size: 9px;
            }

            .filter-card .input-group .btn {
                min-width: 40px;
                padding-left: 10px;
                padding-right: 10px;
            }

            /*
            |----------------------------------------------------------------------
            | THREE SUMMARY CARDS IN ONE ROW
            |----------------------------------------------------------------------
            */

            .summary-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
                gap: 6px;
                margin-bottom: 12px;
            }

            .summary-card {
                min-height: 79px;
                padding: 9px 5px;
                gap: 5px;
                border-radius: 9px;
            }

            .summary-icon {
                width: 29px;
                height: 29px;
                border-radius: 8px;
                font-size: 13px;
            }

            .summary-card strong {
                font-size: 16px;
                line-height: 1;
            }

            .summary-card span {
                margin-top: 3px;
                font-size: 7px;
                line-height: 1.15;
                white-space: normal;
            }

            /*
            |----------------------------------------------------------------------
            | RESULT
            |----------------------------------------------------------------------
            */

            .result-card {
                border-radius: 11px;
            }

            .result-header {
                padding: 13px;
            }

            .result-title h2 {
                font-size: 12px;
            }

            .result-title p {
                font-size: 8px;
            }

            .readonly-notice {
                margin: 10px 13px 0;
                font-size: 8px;
            }

            .result-table {
                min-width: 680px;
            }

            .result-table th {
                padding: 10px;
                font-size: 8px;
            }

            .result-table td {
                padding: 9px 10px;
            }

            .student-info {
                min-width: 175px;
            }

            .mark-input {
                width: 85px;
                min-height: 40px;
            }

            .save-footer {
                padding: 12px;
            }

            .pagination-wrapper {
                padding: 12px 10px;
            }

            .pagination-custom {
                gap: 3px;
            }

            .pagination-custom .page-link {
                min-width: 31px;
                height: 31px;
                padding: 0 7px;
                font-size: 9px;
            }

            .empty-state {
                padding: 40px 15px;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | VERY SMALL PHONES
        |--------------------------------------------------------------------------
        */

        @media (max-width: 360px) {

            .semester-strip {
                grid-template-columns: 1fr;
            }

            .semester-card {
                padding: 10px;
            }

            .page-icon {
                display: none;
            }

            /*
            |----------------------------------------------------------------------
            | KEEP 3 FILTERS IN ONE ROW
            |---------------------------------------------------------------------- 
            */

            .filter-card {
                padding: 10px;
            }

            .filter-card .filter-class-row {
                --bs-gutter-x: 4px;
            }

            .filter-card .form-label {
                font-size: 7px;
            }

            .filter-card .form-select {
                min-height: 34px;
                height: 34px;
                padding:
                    4px
                    17px
                    4px
                    5px;
                font-size: 7.5px;
                background-size: 9px 7px;
            }

            .filter-card .search-field {
                margin-top: 8px;
            }

            .filter-card .search-field .form-label {
                font-size: 7.5px;
            }

            .filter-card .form-control {
                min-height: 36px;
                height: 36px;
                font-size: 8px;
            }

            /*
            |----------------------------------------------------------------------
            | SUMMARY
            |---------------------------------------------------------------------- 
            */

            .summary-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
                gap: 4px;
            }

            .summary-card {
                min-height: 72px;
                padding: 7px 3px;
                gap: 4px;
            }

            .summary-icon {
                width: 27px;
                height: 27px;
                font-size: 12px;
            }

            .summary-card strong {
                font-size: 14px;
            }

            .summary-card span {
                font-size: 6.5px;
            }

            .result-table {
                min-width: 650px;
            }

            .mobile-bottom-nav {
                height: 64px;
            }

            .mobile-bottom-link {
                font-size: 7px;
            }

            .mobile-bottom-link i {
                font-size: 17px;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | SAFE AREA
        |--------------------------------------------------------------------------
        */

        @supports (padding: env(safe-area-inset-top)) {

            .topbar {
                padding-top:
                    max(
                        9px,
                        env(safe-area-inset-top)
                    );
            }

            .content {
                padding-bottom:
                    max(
                        90px,
                        calc(
                            25px +
                            env(safe-area-inset-bottom)
                        )
                    );
            }

        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

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
            class="nav-link-custom active"
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
            href="subject-attendance.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Subject Attendance</span>
        </a>

        <a
            href="homework.php"
            class="nav-link-custom"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
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

<!--
|--------------------------------------------------------------------------
| MOBILE BOTTOM NAVIGATION
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a
        href="daily-attendance.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-calendar-check-fill"></i>
        <span>Daily Attendance</span>
    </a>

    <a
        href="homework.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>

    <a
        href="result.php"
        class="mobile-bottom-link active"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Result</span>
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
        class="mobile-bottom-link logout"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</nav>

<main class="main">

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Open menu"
                aria-controls="sidebar"
                aria-expanded="false"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-icon">
                <i class="bi bi-bar-chart-fill"></i>
            </div>

            <div class="page-heading">

                <h1>
                    Student Results
                </h1>

                <p>
                    Enter and manage student academic results
                </p>

            </div>

        </div>

        <div class="academic-year">

            <i class="bi bi-calendar3 me-1"></i>

            <?= e($academicYearName) ?>

        </div>

    </header>

    <div class="content">

        <?php if ($success !== ''): ?>

            <div class="alert-custom alert-success-custom">

                <i class="bi bi-check-circle-fill"></i>

                <span>
                    <?= e($success) ?>
                </span>

            </div>

        <?php endif; ?>

        <?php if ($error !== ''): ?>

            <div class="alert-custom alert-error-custom">

                <i class="bi bi-exclamation-circle-fill"></i>

                <span>
                    <?= e($error) ?>
                </span>

            </div>

        <?php endif; ?>

        <?php if (count($semesters) > 0): ?>

            <div class="semester-strip">

                <?php foreach (
                    $semesters as $semester
                ): ?>

                    <?php

                    $semesterId =
                        (int) $semester['id'];

                    $isSelected =
                        $semesterId ===
                        $selectedSemesterId;

                    $statusClass = match (
                        (string) $semester['status']
                    ) {
                        'Active' =>
                            'status-active',

                        'Completed' =>
                            'status-completed',

                        default =>
                            'status-not-completed'
                    };

                    $maxMark =
                        rtrim(
                            rtrim(
                                number_format(
                                    (float) $semester['max_mark'],
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        );

                    ?>

                    <a
                        href="?<?= http_build_query([
                            'semester_id' =>
                                $semesterId,

                            'grade' =>
                                $selectedGrade,

                            'section' =>
                                $selectedSection,

                            'subject_id' =>
                                $selectedSubjectId,

                            'search' =>
                                $search,

                            'page' =>
                                1
                        ]) ?>"
                        class="
                            semester-card
                            <?= $isSelected
                                ? 'selected'
                                : '' ?>
                        "
                    >

                        <div class="semester-card-top">

                            <div class="semester-name">

                                <?= e(
                                    (string)
                                    $semester['name']
                                ) ?>

                            </div>

                            <span
                                class="
                                    status-badge
                                    <?= $statusClass ?>
                                "
                            >

                                <?= e(
                                    (string)
                                    $semester['status']
                                ) ?>

                            </span>

                        </div>

                        <div class="semester-max">

                            Maximum mark:
                            <?= e($maxMark) ?>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <div class="filter-card">

            <div class="filter-heading">

                <h2>
                    Select Class & Subject
                </h2>

                <p>
                    Only classes and subjects assigned to you are available.
                </p>

            </div>

            <form
                method="GET"
                action="result.php"
            >

                <input
                    type="hidden"
                    name="semester_id"
                    value="<?= $selectedSemesterId ?>"
                >

                <!--
                |--------------------------------------------------------------------------
                | GRADE / SECTION / SUBJECT ROW
                |--------------------------------------------------------------------------
                -->

                <div class="row filter-class-row">

                    <div
                        class="
                            filter-class-field
                            col-xl-3
                            col-md-6
                        "
                    >

                        <label class="form-label">
                            Grade
                        </label>

                        <select
                            name="grade"
                            class="form-select"
                            onchange="this.form.submit()"
                        >

                            <option value="">
                                Grade
                            </option>

                            <?php foreach (
                                $grades as $grade
                            ): ?>

                                <option
                                    value="<?= (int) $grade ?>"
                                    <?= $selectedGrade ===
                                        (int) $grade
                                            ? 'selected'
                                            : '' ?>
                                >
                                    Grade
                                    <?= (int) $grade ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div
                        class="
                            filter-class-field
                            col-xl-3
                            col-md-6
                        "
                    >

                        <label class="form-label">
                            Section
                        </label>

                        <select
                            name="section"
                            class="form-select"
                            onchange="this.form.submit()"
                            <?= $selectedGrade <= 0
                                ? 'disabled'
                                : '' ?>
                        >

                            <option value="">
                                Section
                            </option>

                            <?php

                            $availableSections =
                                $sectionsByGrade[
                                    $selectedGrade
                                ] ?? [];

                            ?>

                            <?php foreach (
                                $availableSections
                                as $section
                            ): ?>

                                <option
                                    value="<?= e($section) ?>"
                                    <?= $selectedSection ===
                                        $section
                                            ? 'selected'
                                            : '' ?>
                                >

                                    <?= e($section) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div
                        class="
                            filter-class-field
                            col-xl-3
                            col-md-6
                        "
                    >

                        <label class="form-label">
                            Subject
                        </label>

                        <select
                            name="subject_id"
                            class="form-select"
                            onchange="this.form.submit()"
                            <?= $selectedGrade <= 0 ||
                                $selectedSection === ''
                                    ? 'disabled'
                                    : '' ?>
                        >

                            <option value="">
                                Subject
                            </option>

                            <?php foreach (
                                $availableSubjects
                                as $subject
                            ): ?>

                                <option
                                    value="<?= (int) $subject['id'] ?>"
                                    <?= $selectedSubjectId ===
                                        (int) $subject['id']
                                            ? 'selected'
                                            : '' ?>
                                >

                                    <?= e(
                                        $subject['name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!--
                    |--------------------------------------------------------------------------
                    | SEARCH
                    |--------------------------------------------------------------------------
                    -->

                    <div
                        class="
                            search-field
                            col-xl-3
                            col-md-6
                        "
                    >

                        <label class="form-label">
                            Search Student
                        </label>

                        <div class="input-group">

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                value="<?= e($search) ?>"
                                placeholder="Name or student code"
                            >

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-search"></i>
                            </button>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <?php if (
            $selectedSemester &&
            $selectedGrade > 0 &&
            $selectedSection !== '' &&
            $selectedSubjectId > 0
        ): ?>

            <!--
            |--------------------------------------------------------------------------
            | SUMMARY
            |--------------------------------------------------------------------------
            -->

            <div class="summary-grid">

                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div>

                        <strong>
                            <?= $totalStudents ?>
                        </strong>

                        <span>
                            Students
                        </span>

                    </div>

                </div>

                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <div>

                        <strong>
                            <?= $recordedResults ?>
                        </strong>

                        <span>
                            Results Recorded
                        </span>

                    </div>

                </div>

                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                    <div>

                        <strong>
                            <?= $missingResults ?>
                        </strong>

                        <span>
                            Missing Results
                        </span>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div class="result-card">

            <?php if (
                !$selectedSemester ||
                $selectedGrade <= 0 ||
                $selectedSection === '' ||
                $selectedSubjectId <= 0
            ): ?>

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-bar-chart"></i>
                    </div>

                    <h3>
                        Select a class and subject
                    </h3>

                    <p>
                        Choose the grade, section and subject to view student results.
                    </p>

                </div>

            <?php else: ?>

                <div class="result-header">

                    <div class="result-title">

                        <h2>

                            Grade
                            <?= $selectedGrade ?>

                            —

                            Section
                            <?= e($selectedSection) ?>

                            —

                            <?= $selectedSubject
                                ? e(
                                    $selectedSubject['name']
                                )
                                : 'Subject' ?>

                        </h2>

                        <p>

                            <?= e(
                                (string)
                                $selectedSemester['name']
                            ) ?>

                            ·

                            Maximum mark:
                            <?= e($formattedMaxMark) ?>

                        </p>

                    </div>

                    <?php if (
                        $canEdit &&
                        $totalStudents > 0
                    ): ?>

                        <div class="result-actions">

                            <button
                                type="submit"
                                form="resultForm"
                                class="btn-primary-custom"
                            >

                                <i class="bi bi-save me-1"></i>

                                Save Results

                            </button>

                        </div>

                    <?php endif; ?>

                </div>

                <?php if (
                    $isCompletedSemester
                ): ?>

                    <div
                        class="
                            readonly-notice
                            readonly-completed
                        "
                    >

                        <i class="bi bi-lock-fill"></i>

                        <span>
                            This semester is completed.
                            Results are read-only.
                        </span>

                    </div>

                <?php elseif (
                    $isNotCompletedSemester
                ): ?>

                    <div
                        class="
                            readonly-notice
                            readonly-not-completed
                        "
                    >

                        <i class="bi bi-hourglass-split"></i>

                        <span>
                            This semester is not active yet.
                            Result entry is currently unavailable.
                        </span>

                    </div>

                <?php elseif (
                    $isActiveSemester
                ): ?>

                    <div
                        class="
                            readonly-notice
                            readonly-completed
                        "
                    >

                        <i class="bi bi-pencil-square"></i>

                        <span>
                            This semester is active.
                            You can add and edit results.
                        </span>

                    </div>

                <?php endif; ?>

                <?php if (
                    $totalStudents > 0
                ): ?>

                    <form
                        method="POST"
                        action="result.php"
                        id="resultForm"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="save_results"
                        >

                        <input
                            type="hidden"
                            name="semester_id"
                            value="<?= $selectedSemesterId ?>"
                        >

                        <input
                            type="hidden"
                            name="grade"
                            value="<?= $selectedGrade ?>"
                        >

                        <input
                            type="hidden"
                            name="section"
                            value="<?= e($selectedSection) ?>"
                        >

                        <input
                            type="hidden"
                            name="subject_id"
                            value="<?= $selectedSubjectId ?>"
                        >

                        <div class="table-wrapper">

                            <table class="result-table">

                                <thead>

                                    <tr>

                                        <th>
                                            #
                                        </th>

                                        <th>
                                            Student
                                        </th>

                                        <th>
                                            Student Code
                                        </th>

                                        <th>
                                            Mark /
                                            <?= e(
                                                $formattedMaxMark
                                            ) ?>
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $studentsOnCurrentPage
                                        as $index =>
                                        $student
                                    ): ?>

                                        <?php

                                        $hasResult =
                                            $student['result_id']
                                            !== null &&
                                            $student['mark']
                                            !== null;

                                        $markValue =
                                            $hasResult
                                                ? (string)
                                                    $student['mark']
                                                : '';

                                        $fullName =
                                            (string)
                                            $student['full_name'];

                                        $initial =
                                            strtoupper(
                                                substr(
                                                    $fullName,
                                                    0,
                                                    1
                                                )
                                            );

                                        $studentNumber =
                                            $paginationOffset +
                                            $index +
                                            1;

                                        ?>

                                        <tr>

                                            <td
                                                class="student-number"
                                            >
                                                <?= $studentNumber ?>
                                            </td>

                                            <td>

                                                <div
                                                    class="student-info"
                                                >

                                                    <div
                                                        class="student-avatar"
                                                    >

                                                        <?= e(
                                                            $initial
                                                        ) ?>

                                                    </div>

                                                    <div>

                                                        <div
                                                            class="student-name"
                                                        >

                                                            <?= e(
                                                                $fullName
                                                            ) ?>

                                                        </div>

                                                    </div>

                                                </div>

                                            </td>

                                            <td>

                                                <span
                                                    class="student-code"
                                                >

                                                    <?= e(
                                                        (string)
                                                        $student[
                                                            'student_code'
                                                        ]
                                                    ) ?>

                                                </span>

                                            </td>

                                            <td>

                                                <?php if (
                                                    $canEdit
                                                ): ?>

                                                    <input
                                                        type="number"
                                                        name="marks[<?= (int) $student['registration_id'] ?>]"
                                                        class="form-control mark-input"
                                                        value="<?= e($markValue) ?>"
                                                        min="0"
                                                        max="<?= e(
                                                            (string)
                                                            $selectedMaxMark
                                                        ) ?>"
                                                        step="0.01"
                                                        inputmode="decimal"
                                                        placeholder="—"
                                                    >

                                                <?php else: ?>

                                                    <?php if (
                                                        $hasResult
                                                    ): ?>

                                                        <span
                                                            class="mark-display"
                                                        >

                                                            <?= rtrim(
                                                                rtrim(
                                                                    number_format(
                                                                        (float)
                                                                        $student['mark'],
                                                                        2,
                                                                        '.',
                                                                        ''
                                                                    ),
                                                                    '0'
                                                                ),
                                                                '.'
                                                            ) ?>

                                                        </span>

                                                    <?php else: ?>

                                                        <span
                                                            class="missing-mark"
                                                        >
                                                            Not Recorded
                                                        </span>

                                                    <?php endif; ?>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?php if (
                                                    $hasResult
                                                ): ?>

                                                    <span
                                                        class="
                                                            status-badge
                                                            status-completed
                                                        "
                                                    >
                                                        Recorded
                                                    </span>

                                                <?php else: ?>

                                                    <span
                                                        class="
                                                            status-badge
                                                            status-not-completed
                                                        "
                                                    >
                                                        Missing
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <?php if ($totalPages > 1): ?>

                            <?php

                            $startStudent =
                                $paginationOffset + 1;

                            $endStudent =
                                min(
                                    $paginationOffset +
                                    $studentsPerPage,
                                    $totalStudents
                                );

                            ?>

                            <div class="pagination-wrapper">

                                <div class="pagination-info">

                                    Showing
                                    <?= $startStudent ?>
                                    –
                                    <?= $endStudent ?>
                                    of
                                    <?= $totalStudents ?>
                                    students

                                </div>

                                <nav
                                    aria-label="Student pagination"
                                >

                                    <ul
                                        class="
                                            pagination
                                            pagination-custom
                                        "
                                    >

                                        <?php

                                        $previousParams =
                                            $paginationParams;

                                        $previousParams['page'] =
                                            max(
                                                1,
                                                $currentPage - 1
                                            );

                                        ?>

                                        <li
                                            class="
                                                page-item
                                                <?= $currentPage <= 1
                                                    ? 'disabled'
                                                    : '' ?>
                                            "
                                        >

                                            <?php if (
                                                $currentPage <= 1
                                            ): ?>

                                                <span
                                                    class="page-link"
                                                >
                                                    <i class="bi bi-chevron-left"></i>
                                                </span>

                                            <?php else: ?>

                                                <a
                                                    class="page-link"
                                                    href="?<?= http_build_query($previousParams) ?>"
                                                    aria-label="Previous"
                                                >
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>

                                            <?php endif; ?>

                                        </li>

                                        <?php

                                        $pageStart =
                                            max(
                                                1,
                                                $currentPage - 2
                                            );

                                        $pageEnd =
                                            min(
                                                $totalPages,
                                                $currentPage + 2
                                            );

                                        ?>

                                        <?php for (
                                            $page =
                                                $pageStart;
                                            $page <=
                                                $pageEnd;
                                            $page++
                                        ): ?>

                                            <?php

                                            $pageParams =
                                                $paginationParams;

                                            $pageParams['page'] =
                                                $page;

                                            ?>

                                            <li
                                                class="
                                                    page-item
                                                    <?= $page ===
                                                        $currentPage
                                                            ? 'active'
                                                            : '' ?>
                                                "
                                            >

                                                <a
                                                    class="page-link"
                                                    href="?<?= http_build_query($pageParams) ?>"
                                                >
                                                    <?= $page ?>
                                                </a>

                                            </li>

                                        <?php endfor; ?>

                                        <?php

                                        $nextParams =
                                            $paginationParams;

                                        $nextParams['page'] =
                                            min(
                                                $totalPages,
                                                $currentPage + 1
                                            );

                                        ?>

                                        <li
                                            class="
                                                page-item
                                                <?= $currentPage >=
                                                    $totalPages
                                                        ? 'disabled'
                                                        : '' ?>
                                            "
                                        >

                                            <?php if (
                                                $currentPage >=
                                                $totalPages
                                            ): ?>

                                                <span
                                                    class="page-link"
                                                >
                                                    <i class="bi bi-chevron-right"></i>
                                                </span>

                                            <?php else: ?>

                                                <a
                                                    class="page-link"
                                                    href="?<?= http_build_query($nextParams) ?>"
                                                    aria-label="Next"
                                                >
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>

                                            <?php endif; ?>

                                        </li>

                                    </ul>

                                </nav>

                            </div>

                        <?php endif; ?>

                        <?php if ($canEdit): ?>

                            <div class="save-footer">

                                <button
                                    type="submit"
                                    class="btn-primary-custom"
                                >

                                    <i class="bi bi-save me-1"></i>

                                    Save Results

                                </button>

                            </div>

                        <?php endif; ?>

                    </form>

                <?php else: ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <h3>
                            No students found
                        </h3>

                        <p>

                            No registered students were found for

                            Grade
                            <?= $selectedGrade ?>

                            ,

                            Section
                            <?= e($selectedSection) ?>

                            in

                            <?= e($academicYearName) ?>.

                        </p>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</main>

<script>

/*
|--------------------------------------------------------------------------
| MOBILE SIDEBAR
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const menuToggle =
    document.getElementById('menuToggle');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

function openSidebar() {

    if (!sidebar || !menuToggle) {
        return;
    }

    sidebar.classList.add('show');

    sidebarOverlay?.classList.add('show');

    menuToggle.setAttribute(
        'aria-expanded',
        'true'
    );

    document.body.style.overflow =
        'hidden';
}

function closeSidebar() {

    if (!sidebar) {
        return;
    }

    sidebar.classList.remove('show');

    sidebarOverlay?.classList.remove(
        'show'
    );

    menuToggle?.setAttribute(
        'aria-expanded',
        'false'
    );

    document.body.style.overflow = '';
}

menuToggle?.addEventListener(
    'click',
    function () {

        if (
            sidebar.classList.contains(
                'show'
            )
        ) {

            closeSidebar();

        } else {

            openSidebar();
        }
    }
);

sidebarOverlay?.addEventListener(
    'click',
    closeSidebar
);

/*
|--------------------------------------------------------------------------
| CLOSE SIDEBAR AFTER NAVIGATION
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        '.nav-link-custom'
    )
    .forEach(
        function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <=
                        991
                    ) {

                        closeSidebar();
                    }
                }
            );
        }
    );

/*
|--------------------------------------------------------------------------
| CLOSE SIDEBAR WHEN SCREEN BECOMES DESKTOP
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'resize',
    function () {

        if (
            window.innerWidth > 991
        ) {

            closeSidebar();
        }
    }
);

/*
|--------------------------------------------------------------------------
| RESULT MARK VALIDATION
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const form =
            document.getElementById(
                'resultForm'
            );

        if (!form) {
            return;
        }

        form.addEventListener(
            'submit',
            function (event) {

                const inputs =
                    form.querySelectorAll(
                        '.mark-input'
                    );

                for (
                    const input of inputs
                ) {

                    if (
                        input.value.trim() ===
                        ''
                    ) {
                        continue;
                    }

                    const value =
                        parseFloat(
                            input.value
                        );

                    const max =
                        parseFloat(
                            input.max
                        );

                    if (
                        Number.isNaN(value) ||
                        value < 0 ||
                        value > max
                    ) {

                        event.preventDefault();

                        input.focus();

                        alert(
                            'Please enter a valid mark between 0 and ' +
                            max +
                            '.'
                        );

                        return;
                    }
                }

            }
        );

    }
);

</script>

</body>

</html>