<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['homeroom_assignment_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: homeroom-assignment.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['principal_homeroom_assignment_csrf'])) {
    $_SESSION['principal_homeroom_assignment_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['principal_homeroom_assignment_csrf'];

/*
|--------------------------------------------------------------------------
| Principal Information
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        p.photo
    FROM users u
    LEFT JOIN principals p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'principal'
      AND u.is_deleted = 0
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$principal = $result->fetch_assoc();

$stmt->close();

if (!$principal) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$principalName = $principal['full_name'];

$principalPhoto = $principal['photo'] ?? '';

$principalPhotoUrl = '';

if (!empty($principalPhoto)) {
    $principalPhotoUrl = '../' . ltrim($principalPhoto, '/');
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$currentAcademicYear = '';

$academicYearStmt = $conn->prepare("
    SELECT name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$academicYearStmt->execute();

$academicYearResult = $academicYearStmt->get_result();

if ($academicYearRow = $academicYearResult->fetch_assoc()) {
    $currentAcademicYear = trim($academicYearRow['name']);
}

$academicYearStmt->close();

$currentAcademicYearError = '';

if ($currentAcademicYear === '') {
    $currentAcademicYearError =
        'No active academic year is configured. Please activate an academic year before creating a new homeroom assignment.';
}

/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['homeroom_assignment_message'] ?? null;

unset($_SESSION['homeroom_assignment_message']);

/*
|--------------------------------------------------------------------------
| Form Variables
|--------------------------------------------------------------------------
*/

$formId = 0;
$formAcademicYear = $currentAcademicYear;
$formGrade = '';
$formSection = '';
$formTeacherUserId = '';

$editing = false;

$errors = [];

/*
|--------------------------------------------------------------------------
| Delete Assignment
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_assignment'])
) {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['principal_homeroom_assignment_csrf'],
            $postedToken
        )
    ) {
        redirectWithMessage(
            'danger',
            'Security verification failed. Please try again.'
        );
    }

    $deleteId = filter_var(
        $_POST['assignment_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    if (!$deleteId || $deleteId < 1) {
        redirectWithMessage(
            'danger',
            'Invalid homeroom assignment.'
        );
    }

    $deleteStmt = $conn->prepare("
        DELETE FROM homeroom_teacher_assignments
        WHERE id = ?
        LIMIT 1
    ");

    $deleteStmt->bind_param('i', $deleteId);

    if ($deleteStmt->execute()) {

        if ($deleteStmt->affected_rows > 0) {

            $deleteStmt->close();

            redirectWithMessage(
                'success',
                'Homeroom teacher assignment deleted successfully.'
            );
        }

        $deleteStmt->close();

        redirectWithMessage(
            'danger',
            'Homeroom assignment was not found.'
        );
    }

    $deleteError = $deleteStmt->error;

    $deleteStmt->close();

    redirectWithMessage(
        'danger',
        'Unable to delete the assignment. ' . $deleteError
    );
}

/*
|--------------------------------------------------------------------------
| Save Assignment
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_assignment'])
) {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['principal_homeroom_assignment_csrf'],
            $postedToken
        )
    ) {
        $errors[] =
            'Security verification failed. Please refresh the page and try again.';
    }

    $formId = (int) ($_POST['assignment_id'] ?? 0);

    $formGrade = trim($_POST['grade'] ?? '');

    $formSection = strtoupper(
        trim($_POST['section'] ?? '')
    );

    $formTeacherUserId = (int) (
        $_POST['teacher_user_id'] ?? 0
    );

    $editing = $formId > 0;

    /*
    |--------------------------------------------------------------------------
    | Determine Academic Year
    |--------------------------------------------------------------------------
    |
    | New assignment:
    |   Always use the active academic year.
    |
    | Existing assignment:
    |   Preserve its original academic year.
    |--------------------------------------------------------------------------
    */

    if ($editing) {

        $existingStmt = $conn->prepare("
            SELECT
                id,
                academic_year,
                grade,
                section,
                teacher_user_id
            FROM homeroom_teacher_assignments
            WHERE id = ?
            LIMIT 1
        ");

        $existingStmt->bind_param(
            'i',
            $formId
        );

        $existingStmt->execute();

        $existingResult = $existingStmt->get_result();

        $existingAssignment =
            $existingResult->fetch_assoc();

        $existingStmt->close();

        if (!$existingAssignment) {

            $errors[] =
                'The homeroom assignment you are trying to edit does not exist.';

        } else {

            $formAcademicYear =
                $existingAssignment['academic_year'];
        }

    } else {

        if ($currentAcademicYear === '') {

            $errors[] =
                $currentAcademicYearError;

        } else {

            $formAcademicYear =
                $currentAcademicYear;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
        $formGrade === '' ||
        !ctype_digit($formGrade) ||
        (int) $formGrade < 1 ||
        (int) $formGrade > 12
    ) {
        $errors[] =
            'Please select a valid grade from Grade 1 to Grade 12.';
    }

    $validSections = ['A', 'B', 'C', 'D', 'E'];

    if (!in_array($formSection, $validSections, true)) {
        $errors[] =
            'Please select a valid section.';
    }

    if ($formTeacherUserId < 1) {
        $errors[] =
            'Please select a teacher.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Teacher
    |--------------------------------------------------------------------------
    */

    if ($formTeacherUserId > 0) {

        $teacherCheckStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND LOWER(role) = 'teacher'
              AND is_deleted = 0
            LIMIT 1
        ");

        $teacherCheckStmt->bind_param(
            'i',
            $formTeacherUserId
        );

        $teacherCheckStmt->execute();

        $teacherCheckResult =
            $teacherCheckStmt->get_result();

        $validTeacher =
            $teacherCheckResult->fetch_assoc();

        $teacherCheckStmt->close();

        if (!$validTeacher) {

            $errors[] =
                'The selected teacher is not a valid active teacher.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Check Grade + Section Duplicate
    |--------------------------------------------------------------------------
    |
    | One homeroom assignment is allowed for:
    |
    | Academic Year + Grade + Section
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $formAcademicYear !== '' &&
        $formGrade !== '' &&
        $formSection !== ''
    ) {

        $gradeInt = (int) $formGrade;

        if ($editing) {

            $duplicateStmt = $conn->prepare("
                SELECT id
                FROM homeroom_teacher_assignments
                WHERE academic_year = ?
                  AND grade = ?
                  AND section = ?
                  AND id <> ?
                LIMIT 1
            ");

            $duplicateStmt->bind_param(
                'sisi',
                $formAcademicYear,
                $gradeInt,
                $formSection,
                $formId
            );

        } else {

            $duplicateStmt = $conn->prepare("
                SELECT id
                FROM homeroom_teacher_assignments
                WHERE academic_year = ?
                  AND grade = ?
                  AND section = ?
                LIMIT 1
            ");

            $duplicateStmt->bind_param(
                'sis',
                $formAcademicYear,
                $gradeInt,
                $formSection
            );
        }

        $duplicateStmt->execute();

        $duplicateResult =
            $duplicateStmt->get_result();

        $duplicate =
            $duplicateResult->fetch_assoc();

        $duplicateStmt->close();

        if ($duplicate) {

            $errors[] =
                'A homeroom teacher is already assigned to Grade ' .
                $gradeInt .
                ' Section ' .
                $formSection .
                ' for academic year ' .
                $formAcademicYear .
                '.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NEW RULE:
    | One Teacher = One Homeroom Section Per Academic Year
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | Teacher A -> Grade 5 Section A
    |
    | Teacher A cannot also be:
    |
    | Grade 5 Section B
    | Grade 6 Section A
    | Grade 7 Section C
    |
    | during the same academic year.
    |
    | Historical academic years are not affected.
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $formAcademicYear !== '' &&
        $formTeacherUserId > 0
    ) {

        if ($editing) {

            $teacherAssignmentStmt = $conn->prepare("
                SELECT
                    hta.id,
                    hta.grade,
                    hta.section,
                    hta.academic_year
                FROM homeroom_teacher_assignments hta
                WHERE hta.academic_year = ?
                  AND hta.teacher_user_id = ?
                  AND hta.id <> ?
                LIMIT 1
            ");

            $teacherAssignmentStmt->bind_param(
                'sii',
                $formAcademicYear,
                $formTeacherUserId,
                $formId
            );

        } else {

            $teacherAssignmentStmt = $conn->prepare("
                SELECT
                    hta.id,
                    hta.grade,
                    hta.section,
                    hta.academic_year
                FROM homeroom_teacher_assignments hta
                WHERE hta.academic_year = ?
                  AND hta.teacher_user_id = ?
                LIMIT 1
            ");

            $teacherAssignmentStmt->bind_param(
                'si',
                $formAcademicYear,
                $formTeacherUserId
            );
        }

        $teacherAssignmentStmt->execute();

        $teacherAssignmentResult =
            $teacherAssignmentStmt->get_result();

        $existingTeacherAssignment =
            $teacherAssignmentResult->fetch_assoc();

        $teacherAssignmentStmt->close();

        if ($existingTeacherAssignment) {

            $existingTeacherGrade =
                (int) $existingTeacherAssignment['grade'];

            $existingTeacherSection =
                strtoupper(
                    $existingTeacherAssignment['section']
                );

            $errors[] =
                'This teacher is already the homeroom teacher for Grade ' .
                $existingTeacherGrade .
                ' Section ' .
                $existingTeacherSection .
                ' for academic year ' .
                $formAcademicYear .
                '. One teacher cannot be the homeroom teacher for more than one section in the same academic year.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert / Update
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $gradeInt = (int) $formGrade;

        if ($editing) {

            /*
            |------------------------------------------------------------------
            | UPDATE
            |------------------------------------------------------------------
            |
            | Academic year is intentionally NOT updated.
            | Historical academic year remains unchanged.
            |------------------------------------------------------------------
            */

            $updateStmt = $conn->prepare("
                UPDATE homeroom_teacher_assignments
                SET
                    grade = ?,
                    section = ?,
                    teacher_user_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                LIMIT 1
            ");

            $updateStmt->bind_param(
                'isii',
                $gradeInt,
                $formSection,
                $formTeacherUserId,
                $formId
            );

            if ($updateStmt->execute()) {

                $updateStmt->close();

                redirectWithMessage(
                    'success',
                    'Homeroom teacher assignment updated successfully.'
                );
            }

            $dbError = $updateStmt->error;
            $dbErrno = $updateStmt->errno;

            $updateStmt->close();

            if ($dbErrno === 1062) {

                redirectWithMessage(
                    'danger',
                    'This teacher or grade/section already has a homeroom assignment for the selected academic year.'
                );
            }

            redirectWithMessage(
                'danger',
                'Unable to update the homeroom assignment. ' .
                $dbError
            );

        } else {

            /*
            |------------------------------------------------------------------
            | INSERT
            |------------------------------------------------------------------
            */

            $insertStmt = $conn->prepare("
                INSERT INTO homeroom_teacher_assignments (
                    academic_year,
                    grade,
                    section,
                    teacher_user_id,
                    is_active
                )
                VALUES (?, ?, ?, ?, 1)
            ");

            $insertStmt->bind_param(
                'sisi',
                $formAcademicYear,
                $gradeInt,
                $formSection,
                $formTeacherUserId
            );

            if ($insertStmt->execute()) {

                $insertStmt->close();

                redirectWithMessage(
                    'success',
                    'Homeroom teacher assigned successfully.'
                );
            }

            $dbError = $insertStmt->error;
            $dbErrno = $insertStmt->errno;

            $insertStmt->close();

            if ($dbErrno === 1062) {

                redirectWithMessage(
                    'danger',
                    'This teacher or grade/section already has a homeroom assignment for the active academic year.'
                );
            }

            redirectWithMessage(
                'danger',
                'Unable to create the homeroom assignment. ' .
                $dbError
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Assignment For Editing
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['edit'])
) {

    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    );

    if ($editId && $editId > 0) {

        $editStmt = $conn->prepare("
            SELECT
                id,
                academic_year,
                grade,
                section,
                teacher_user_id
            FROM homeroom_teacher_assignments
            WHERE id = ?
            LIMIT 1
        ");

        $editStmt->bind_param(
            'i',
            $editId
        );

        $editStmt->execute();

        $editResult =
            $editStmt->get_result();

        $editAssignment =
            $editResult->fetch_assoc();

        $editStmt->close();

        if ($editAssignment) {

            $editing = true;

            $formId =
                (int) $editAssignment['id'];

            $formAcademicYear =
                $editAssignment['academic_year'];

            $formGrade =
                (string) $editAssignment['grade'];

            $formSection =
                strtoupper(
                    $editAssignment['section']
                );

            $formTeacherUserId =
                (int) $editAssignment['teacher_user_id'];

        } else {

            $flash = [
                'type' => 'danger',
                'message' =>
                    'The homeroom assignment could not be found.'
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Teachers
|--------------------------------------------------------------------------
*/

$teachers = [];

$teachersStmt = $conn->prepare("
    SELECT
        id,
        full_name
    FROM users
    WHERE LOWER(role) = 'teacher'
      AND is_deleted = 0
    ORDER BY full_name ASC
");

$teachersStmt->execute();

$teachersResult =
    $teachersStmt->get_result();

while ($teacher = $teachersResult->fetch_assoc()) {
    $teachers[] = $teacher;
}

$teachersStmt->close();

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filterAcademicYear =
    trim($_GET['academic_year'] ?? '');

$filterGrade =
    trim($_GET['grade'] ?? '');

$filterSection =
    strtoupper(
        trim($_GET['section'] ?? '')
    );

$filterTeacher =
    (int) ($_GET['teacher'] ?? 0);

/*
|--------------------------------------------------------------------------
| Load Homeroom Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

$sql = "
    SELECT
        hta.id,
        hta.academic_year,
        hta.grade,
        hta.section,
        hta.is_active,
        hta.created_at,
        hta.updated_at,
        u.full_name AS teacher_name
    FROM homeroom_teacher_assignments hta
    INNER JOIN users u
        ON u.id = hta.teacher_user_id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($filterAcademicYear !== '') {

    $sql .= "
        AND hta.academic_year LIKE ?
    ";

    $params[] =
        '%' . $filterAcademicYear . '%';

    $types .= 's';
}

if (
    $filterGrade !== '' &&
    ctype_digit($filterGrade)
) {

    $sql .= "
        AND hta.grade = ?
    ";

    $params[] =
        (int) $filterGrade;

    $types .= 'i';
}

if (
    $filterSection !== '' &&
    in_array(
        $filterSection,
        ['A', 'B', 'C', 'D', 'E'],
        true
    )
) {

    $sql .= "
        AND hta.section = ?
    ";

    $params[] =
        $filterSection;

    $types .= 's';
}

if ($filterTeacher > 0) {

    $sql .= "
        AND hta.teacher_user_id = ?
    ";

    $params[] =
        $filterTeacher;

    $types .= 'i';
}

$sql .= "
    ORDER BY
        hta.academic_year DESC,
        hta.grade ASC,
        hta.section ASC
";

$assignmentStmt =
    $conn->prepare($sql);

if (!empty($params)) {

    $assignmentStmt->bind_param(
        $types,
        ...$params
    );
}

$assignmentStmt->execute();

$assignmentResult =
    $assignmentStmt->get_result();

while (
    $assignment =
        $assignmentResult->fetch_assoc()
) {
    $assignments[] = $assignment;
}

$assignmentStmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Homeroom Assignment | BKHS</title>
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

        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --success: #059669;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .app {
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: var(--sidebar);
            color: #fff;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(79,70,229,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: #a5b4fc;
            font-size: 20px;
        }

        .brand-text {
            line-height: 1.2;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-menu {
            padding: 22px 14px;
            flex: 1;
            overflow-y: auto;
        }

        .menu-label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0 12px;
            margin-bottom: 10px;
        }

        .nav-link {
            color: #cbd5e1;
            padding: 11px 12px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .nav-link i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .nav-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .nav-link.active {
            color: #fff;
            background: var(--primary);
            box-shadow: 0 5px 15px rgba(79,70,229,0.25);
        }

        .logout-link {
            color: #fca5a5;
        }

        .logout-link:hover {
            color: #fecaca;
            background: rgba(239,68,68,0.12);
        }

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .page-title h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-title p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .topbar-date {
            color: var(--muted);
            font-size: 12px;
            display: none;
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text);
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .principal-info {
            line-height: 1.2;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
            color: var(--text);
        }

        .content {
            padding: 30px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            background: #fff;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
        }

        .card-subtitle {
            color: var(--muted);
            font-size: 11px;
            margin-top: 5px;
        }

        .card-body {
            padding: 24px;
        }

        .alert {
            border-radius: 12px;
            border: 0;
            font-size: 12px;
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 13px;
            padding: 10px 13px;
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }

        .current-year-box {
            min-height: 45px;
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 10px;
            display: flex;
            align-items: center;
            padding: 10px 13px;
            font-size: 13px;
            font-weight: 700;
        }

        .current-year-box i {
            font-size: 16px;
        }

        .field-help {
            color: #9ca3af;
            font-size: 10px;
            margin-top: 6px;
        }

        .btn-primary-custom {
            min-height: 45px;
            background: var(--primary);
            border: 0;
            color: #fff;
            border-radius: 10px;
            padding: 0 18px;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-secondary-custom {
            min-height: 45px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #374151;
            border-radius: 10px;
            padding: 0 18px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-secondary-custom:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .year-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #047857;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 8px;
        }

        .year-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
        }

        .filter-card {
            margin-top: 24px;
        }

        .filter-label {
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .filter-button {
            min-height: 42px;
            border: 0;
            background: var(--primary);
            color: #fff;
            border-radius: 9px;
            padding: 0 16px;
            font-size: 11px;
            font-weight: 700;
        }

        .filter-button:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .clear-filter {
            min-height: 42px;
            border: 1px solid var(--border);
            background: #fff;
            color: #6b7280;
            border-radius: 9px;
            padding: 0 15px;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .clear-filter:hover {
            background: #f9fafb;
            color: #111827;
        }

        .assignment-table-wrapper {
            overflow-x: auto;
        }

        .assignment-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 780px;
        }

        .assignment-table thead th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .assignment-table tbody td {
            padding: 15px 16px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 12px;
            vertical-align: middle;
        }

        .assignment-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .assignment-table tbody tr:hover {
            background: #fafbff;
        }

        .row-number {
            color: #9ca3af;
            font-size: 11px;
            font-weight: 600;
        }

        .year-text {
            font-weight: 700;
            color: #374151;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 7px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 800;
        }

        .section-badge {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #f3f4f6;
            color: #374151;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
        }

        .teacher-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .teacher-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #ecfdf5;
            color: #059669;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .teacher-name {
            font-size: 12px;
            font-weight: 700;
            color: #1f2937;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-active {
            background: #ecfdf5;
            color: #047857;
        }

        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .action-edit {
            color: var(--primary);
        }

        .action-edit:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
        }

        .action-delete {
            color: var(--danger);
        }

        .action-delete:hover {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .delete-form {
            margin: 0;
        }

        .empty-state {
            padding: 55px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .empty-icon {
            width: 54px;
            height: 54px;
            border-radius: 15px;
            background: #f3f4f6;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
            margin-bottom: 13px;
        }

        .empty-state h6 {
            font-size: 13px;
            font-weight: 700;
            color: #4b5563;
            margin-bottom: 5px;
        }

        .empty-state p {
            margin: 0;
            color: #9ca3af;
            font-size: 11px;
        }

        .count-badge {
            min-width: 26px;
            height: 26px;
            padding: 0 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.45);
            z-index: 999;
        }

        @media (min-width: 1200px) {

            .topbar-date {
                display: block;
            }

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
                width: 100%;
            }

            .mobile-menu {
                display: inline-block;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 20px;
            }

        }

        @media (max-width: 575.98px) {

            .topbar {
                height: 68px;
                padding: 0 15px;
            }

            .page-title h1 {
                font-size: 17px;
            }

            .page-title p {
                display: none;
            }

            .principal-info {
                display: none;
            }

            .avatar {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 15px;
            }

            .card-body {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

<div class="app">

    <!-- SIDEBAR -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="brand">

            <div class="brand-icon">
                <i class="bi bi-building"></i>
            </div>

            <div class="brand-text">

                <div class="brand-title">
                    BKHS
                </div>

                <div class="brand-subtitle">
                    School Management
                </div>

            </div>

        </div>

        <div class="sidebar-menu">

            <div class="menu-label">
                Principal
            </div>

            <a
                href="dashboard.php"
                class="nav-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="announcements.php"
                class="nav-link"
            >
                <i class="bi bi-megaphone-fill"></i>
                <span>Announcement</span>
            </a>

            <a
                href="subject-assignment.php"
                class="nav-link"
            >
                <i class="bi bi-book-half"></i>
                <span>Subject Assignment</span>
            </a>

            <a
                href="homeroom-assignment.php"
                class="nav-link active"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Homeroom Assignment</span>
            </a>

            <a
                href="student-assignment.php"
                class="nav-link"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>Student Assignment</span>
            </a>

            <a
                href="attendance.php"
                class="nav-link"
            >
                <i class="bi bi-calendar-check-fill"></i>
                <span>Attendance</span>
            </a>

            <a
                href="roster.php"
                class="nav-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Roster</span>
            </a>

            <a
                href="certificate.php"
                class="nav-link"
            >
                <i class="bi bi-award-fill"></i>
                <span>Certificate</span>
            </a>

            <a
                href="result.php"
                class="nav-link"
            >
                <i class="bi bi-bar-chart-fill"></i>
                <span>Result</span>
            </a>

            <a
                href="profile.php"
                class="nav-link"
            >
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>

            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">

                <a
                    href="../auth/logout.php"
                    class="nav-link logout-link"
                >
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>

            </div>

        </div>

    </aside>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <!-- MAIN -->

    <main class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="d-flex align-items-center gap-3">

                <button
                    type="button"
                    class="mobile-menu"
                    id="mobileMenu"
                    aria-label="Open menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div class="page-title">

                    <h1>
                        Homeroom Teacher Assignment
                    </h1>

                    <p>
                        Assign teachers to grade sections
                    </p>

                </div>

            </div>

            <div class="topbar-right">

                <div class="topbar-date">

                    <i class="bi bi-calendar3 me-1"></i>

                    <?= date('F j, Y') ?>

                </div>

                <a
                    href="profile.php"
                    class="principal-profile"
                >

                    <div class="avatar">

                        <?php if (!empty($principalPhotoUrl)): ?>

                            <img
                                src="<?= e($principalPhotoUrl) ?>"
                                alt="Principal Photo"
                            >

                        <?php else: ?>

                            <i class="bi bi-person-fill"></i>

                        <?php endif; ?>

                    </div>

                    <div class="principal-info">

                        <div class="principal-name">
                            <?= e($principalName) ?>
                        </div>

                        <div class="principal-role">
                            Principal
                        </div>

                    </div>

                </a>

            </div>

        </header>

        <!-- CONTENT -->

        <div class="content">

            <!-- FLASH MESSAGE -->

            <?php if ($flash): ?>

                <div
                    class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"
                    role="alert"
                >

                    <i
                        class="bi
                        <?= $flash['type'] === 'success'
                            ? 'bi-check-circle-fill'
                            : 'bi-exclamation-triangle-fill'
                        ?>
                        me-2"
                    ></i>

                    <?= e($flash['message']) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Close"
                    ></button>

                </div>

            <?php endif; ?>

            <!-- VALIDATION ERRORS -->

            <?php if (!empty($errors)): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <div class="fw-bold mb-2">

                        <i class="bi bi-exclamation-triangle-fill me-2"></i>

                        Please correct the following:

                    </div>

                    <ul class="mb-0 ps-4">

                        <?php foreach ($errors as $error): ?>

                            <li>
                                <?= e($error) ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <!-- ASSIGNMENT FORM -->

            <div class="card">

                <div class="card-header">

                    <div class="d-flex align-items-center justify-content-between gap-3">

                        <div>

                            <h5 class="card-title">

                                <?= $editing
                                    ? 'Edit Homeroom Teacher Assignment'
                                    : 'Assign Homeroom Teacher'
                                ?>

                            </h5>

                            <div class="card-subtitle">

                                One teacher can be assigned to only one
                                grade section per academic year.

                            </div>

                        </div>

                        <div>

                            <span class="year-status">

                                <span class="year-status-dot"></span>

                                Academic Year Controlled by School

                            </span>

                        </div>

                    </div>

                </div>

                <div class="card-body">

                    <?php if ($currentAcademicYearError && !$editing): ?>

                        <div class="alert alert-warning">

                            <i class="bi bi-exclamation-triangle-fill me-2"></i>

                            <?= e($currentAcademicYearError) ?>

                        </div>

                    <?php endif; ?>

                    <form
                        method="POST"
                        action="homeroom-assignment.php<?= $editing ? '?edit=' . $formId : '' ?>"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="assignment_id"
                            value="<?= $formId ?>"
                        >

                        <div class="row g-3">

                            <!-- ACADEMIC YEAR -->

                            <div class="col-12 col-lg-3">

                                <label class="form-label">
                                    Academic Year
                                </label>

                                <div class="current-year-box">

                                    <i class="bi bi-calendar3 me-2"></i>

                                    <span>
                                        <?= e(
                                            $formAcademicYear ?: 'Not configured'
                                        ) ?>
                                    </span>

                                </div>

                                <div class="field-help">

                                    <?= $editing
                                        ? 'Academic year is preserved for this assignment.'
                                        : 'Current academic year is controlled by the school database.'
                                    ?>

                                </div>

                            </div>

                            <!-- GRADE -->

                            <div class="col-12 col-md-4 col-lg-2">

                                <label
                                    for="grade"
                                    class="form-label"
                                >
                                    Grade
                                </label>

                                <select
                                    name="grade"
                                    id="grade"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Grade
                                    </option>

                                    <?php for (
                                        $grade = 1;
                                        $grade <= 12;
                                        $grade++
                                    ): ?>

                                        <option
                                            value="<?= $grade ?>"
                                            <?= (string) $grade === (string) $formGrade
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            Grade <?= $grade ?>
                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <!-- SECTION -->

                            <div class="col-12 col-md-4 col-lg-2">

                                <label
                                    for="section"
                                    class="form-label"
                                >
                                    Section
                                </label>

                                <select
                                    name="section"
                                    id="section"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Section
                                    </option>

                                    <?php foreach (
                                        ['A', 'B', 'C', 'D', 'E']
                                        as $section
                                    ): ?>

                                        <option
                                            value="<?= $section ?>"
                                            <?= $section === $formSection
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            Section <?= $section ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- TEACHER -->

                            <div class="col-12 col-md-4 col-lg-3">

                                <label
                                    for="teacher_user_id"
                                    class="form-label"
                                >
                                    Homeroom Teacher
                                </label>

                                <select
                                    name="teacher_user_id"
                                    id="teacher_user_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Teacher
                                    </option>

                                    <?php foreach ($teachers as $teacher): ?>

                                        <option
                                            value="<?= (int) $teacher['id'] ?>"
                                            <?= (int) $teacher['id'] ===
                                                (int) $formTeacherUserId
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= e($teacher['full_name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <div class="field-help">
                                    A teacher can have only one homeroom section in this academic year.
                                </div>

                            </div>

                            <!-- BUTTONS -->

                            <div class="col-12 col-lg-2 d-flex align-items-end gap-2">

                                <button
                                    type="submit"
                                    name="save_assignment"
                                    class="btn-primary-custom flex-grow-1"
                                    <?= (!$editing &&
                                        $currentAcademicYear === '')
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >

                                    <i
                                        class="bi
                                        <?= $editing
                                            ? 'bi-check-lg'
                                            : 'bi-plus-lg'
                                        ?>
                                        me-1"
                                    ></i>

                                    <?= $editing
                                        ? 'Update'
                                        : 'Assign'
                                    ?>

                                </button>

                                <?php if ($editing): ?>

                                    <a
                                        href="homeroom-assignment.php"
                                        class="btn-secondary-custom"
                                        title="Cancel"
                                    >
                                        <i class="bi bi-x-lg"></i>
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- FILTERS -->

            <div class="card filter-card">

                <div class="card-header">

                    <h5 class="card-title">
                        Filter Assignments
                    </h5>

                    <div class="card-subtitle">
                        Search current and historical homeroom assignments.
                    </div>

                </div>

                <div class="card-body">

                    <form
                        method="GET"
                        action="homeroom-assignment.php"
                    >

                        <div class="row g-3 align-items-end">

                            <div class="col-12 col-md-6 col-xl-3">

                                <label class="filter-label">
                                    Academic Year
                                </label>

                                <input
                                    type="text"
                                    name="academic_year"
                                    class="form-control"
                                    placeholder="e.g. 2019"
                                    value="<?= e($filterAcademicYear) ?>"
                                >

                            </div>

                            <div class="col-12 col-md-6 col-xl-2">

                                <label class="filter-label">
                                    Grade
                                </label>

                                <select
                                    name="grade"
                                    class="form-select"
                                >

                                    <option value="">
                                        All Grades
                                    </option>

                                    <?php for (
                                        $grade = 1;
                                        $grade <= 12;
                                        $grade++
                                    ): ?>

                                        <option
                                            value="<?= $grade ?>"
                                            <?= (string) $grade ===
                                                (string) $filterGrade
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            Grade <?= $grade ?>
                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <div class="col-12 col-md-6 col-xl-2">

                                <label class="filter-label">
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
                                        ['A', 'B', 'C', 'D', 'E']
                                        as $section
                                    ): ?>

                                        <option
                                            value="<?= $section ?>"
                                            <?= $section === $filterSection
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            Section <?= $section ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-12 col-md-6 col-xl-3">

                                <label class="filter-label">
                                    Teacher
                                </label>

                                <select
                                    name="teacher"
                                    class="form-select"
                                >

                                    <option value="">
                                        All Teachers
                                    </option>

                                    <?php foreach ($teachers as $teacher): ?>

                                        <option
                                            value="<?= (int) $teacher['id'] ?>"
                                            <?= (int) $teacher['id'] ===
                                                $filterTeacher
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= e($teacher['full_name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-12 col-xl-2">

                                <div class="d-flex gap-2">

                                    <button
                                        type="submit"
                                        class="filter-button flex-grow-1"
                                    >
                                        <i class="bi bi-funnel-fill me-1"></i>
                                        Filter
                                    </button>

                                    <a
                                        href="homeroom-assignment.php"
                                        class="clear-filter"
                                        title="Clear Filters"
                                    >
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>

                                </div>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- ASSIGNMENT LIST -->

            <div class="card mt-4">

                <div class="card-header">

                    <div class="d-flex align-items-center justify-content-between gap-3">

                        <div>

                            <h5 class="card-title">
                                Homeroom Teacher Assignments
                            </h5>

                            <div class="card-subtitle">
                                Current and historical homeroom teacher assignments.
                            </div>

                        </div>

                        <span class="count-badge">

                            <?= number_format(count($assignments)) ?>

                        </span>

                    </div>

                </div>

                <?php if (!empty($assignments)): ?>

                    <div class="assignment-table-wrapper">

                        <table class="assignment-table">

                            <thead>

                                <tr>

                                    <th>
                                        #
                                    </th>

                                    <th>
                                        Academic Year
                                    </th>

                                    <th>
                                        Grade
                                    </th>

                                    <th>
                                        Section
                                    </th>

                                    <th>
                                        Homeroom Teacher
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Actions
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $assignments
                                    as $index => $assignment
                                ): ?>

                                    <tr>

                                        <td>

                                            <span class="row-number">
                                                <?= $index + 1 ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span class="year-text">

                                                <?= e(
                                                    $assignment['academic_year']
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="grade-badge">

                                                Grade
                                                <?= (int) $assignment['grade'] ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="section-badge">

                                                <?= e(
                                                    $assignment['section']
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <div class="teacher-cell">

                                                <div class="teacher-avatar">

                                                    <i class="bi bi-person-workspace"></i>

                                                </div>

                                                <div class="teacher-name">

                                                    <?= e(
                                                        $assignment['teacher_name']
                                                    ) ?>

                                                </div>

                                            </div>

                                        </td>

                                        <td>

                                            <?php if (
                                                (int) $assignment['is_active'] === 1
                                            ): ?>

                                                <span class="status-badge status-active">

                                                    <span class="status-dot"></span>

                                                    Active

                                                </span>

                                            <?php else: ?>

                                                <span class="status-badge status-inactive">

                                                    <span class="status-dot"></span>

                                                    Inactive

                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <div class="actions">

                                                <a
                                                    href="homeroom-assignment.php?edit=<?= (int) $assignment['id'] ?>"
                                                    class="action-btn action-edit"
                                                    title="Edit"
                                                >

                                                    <i class="bi bi-pencil-fill"></i>

                                                </a>

                                                <form
                                                    method="POST"
                                                    action="homeroom-assignment.php"
                                                    class="delete-form"
                                                    onsubmit="return confirmDelete();"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="assignment_id"
                                                        value="<?= (int) $assignment['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        name="delete_assignment"
                                                        class="action-btn action-delete"
                                                        title="Delete"
                                                    >

                                                        <i class="bi bi-trash3-fill"></i>

                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <div class="empty-icon">

                            <i class="bi bi-person-workspace"></i>

                        </div>

                        <h6>
                            No homeroom assignments found
                        </h6>

                        <p>
                            Assign a teacher to a grade section to see the assignment here.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenu =
        document.getElementById('mobileMenu');

    function openSidebar() {

        sidebar.classList.add('show');

        overlay.classList.add('show');

    }

    function closeSidebar() {

        sidebar.classList.remove('show');

        overlay.classList.remove('show');

    }

    mobileMenu?.addEventListener(
        'click',
        openSidebar
    );

    overlay?.addEventListener(
        'click',
        closeSidebar
    );

    document
        .querySelectorAll('.sidebar .nav-link')
        .forEach(link => {

            link.addEventListener('click', () => {

                if (window.innerWidth <= 991) {

                    closeSidebar();

                }

            });

        });

    function confirmDelete() {

        return confirm(
            'Are you sure you want to delete this homeroom teacher assignment?'
        );

    }

</script>

</body>
</html>