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

/* =========================================================
   HELPERS
========================================================= */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['subject_assignment_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: subject-assignment.php');
    exit;
}

/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['principal_subject_assignment_csrf'])) {
    $_SESSION['principal_subject_assignment_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['principal_subject_assignment_csrf'];

/* =========================================================
   GET CURRENT ACADEMIC YEAR FROM DATABASE
=========================================================

   The active academic year is managed centrally in:

       academic_years

   Current year is determined by:

       status = 'Active'

   Example:

       2018/19
========================================================= */

$currentAcademicYearId = 0;
$currentAcademicYear = '';

$academicYearQuery = $conn->query("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($academicYearQuery) {

    if ($academicYearRow = $academicYearQuery->fetch_assoc()) {

        $currentAcademicYearId =
            (int)$academicYearRow['id'];

        $currentAcademicYear =
            trim($academicYearRow['name']);
    }
}

$currentAcademicYearError = '';

if (
    $currentAcademicYearId <= 0 ||
    $currentAcademicYear === ''
) {
    $currentAcademicYearError =
        'No active academic year is configured in the database.';
}

/* =========================================================
   AJAX: LOAD SUBJECTS BY GRADE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['action']) &&
    $_GET['action'] === 'get_subjects'
) {

    header('Content-Type: application/json; charset=utf-8');

    $grade = (int)($_GET['grade'] ?? 0);

    if ($grade < 1 || $grade > 12) {
        echo json_encode([]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            subject_name
        FROM grade_subjects
        WHERE grade = ?
          AND is_active = 1
        ORDER BY subject_name ASC
    ");

    $stmt->bind_param(
        'i',
        $grade
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $subjects = [];

    while ($row = $result->fetch_assoc()) {

        $subjects[] = [
            'id' => (int)$row['id'],
            'subject_name' => $row['subject_name']
        ];
    }

    $stmt->close();

    echo json_encode($subjects);
    exit;
}

/* =========================================================
   FLASH MESSAGE
========================================================= */

$flashMessage =
    $_SESSION['subject_assignment_message'] ?? null;

unset(
    $_SESSION['subject_assignment_message']
);

/* =========================================================
   FORM VARIABLES
========================================================= */

$formId = 0;

$formAcademicYear =
    $currentAcademicYear;

$formGrade = '';

$formSection = '';

$formGradeSubjectId = '';

$formTeacherUserId = '';

$editing = false;

$errors = [];

/* =========================================================
   DELETE ASSIGNMENT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {

    $postedCsrf =
        $_POST['csrf_token'] ?? '';

    if (!hash_equals(
        $csrfToken,
        $postedCsrf
    )) {

        redirectWithMessage(
            'danger',
            'Invalid security token. Please try again.'
        );
    }

    $assignmentId =
        (int)($_POST['assignment_id'] ?? 0);

    if ($assignmentId <= 0) {

        redirectWithMessage(
            'danger',
            'Invalid assignment selected.'
        );
    }

    $stmt = $conn->prepare("
        DELETE FROM subject_teacher_assignments
        WHERE id = ?
    ");

    $stmt->bind_param(
        'i',
        $assignmentId
    );

    if ($stmt->execute()) {

        if ($stmt->affected_rows > 0) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Subject teacher assignment deleted successfully.'
            );
        }

        $stmt->close();

        redirectWithMessage(
            'warning',
            'The selected assignment was not found.'
        );
    }

    $stmt->close();

    redirectWithMessage(
        'danger',
        'Unable to delete the assignment.'
    );
}

/* =========================================================
   SAVE / UPDATE ASSIGNMENT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save'
) {

    $postedCsrf =
        $_POST['csrf_token'] ?? '';

    if (!hash_equals(
        $csrfToken,
        $postedCsrf
    )) {

        $errors[] =
            'Invalid security token. Please refresh the page and try again.';
    }

    $formId =
        (int)($_POST['assignment_id'] ?? 0);

    $editing =
        $formId > 0;

    $formGrade =
        (int)($_POST['grade'] ?? 0);

    $formSection =
        strtoupper(
            trim($_POST['section'] ?? '')
        );

    $formGradeSubjectId =
        (int)($_POST['grade_subject_id'] ?? 0);

    $formTeacherUserId =
        (int)($_POST['teacher_user_id'] ?? 0);

    /* -----------------------------------------------------
       DETERMINE ACADEMIC YEAR

       IMPORTANT:

       We DO NOT accept academic year from POST.

       New assignment:
           use currently active academic year.

       Existing assignment:
           preserve its original academic year.
    ----------------------------------------------------- */

    if ($editing) {

        $yearStmt = $conn->prepare("
            SELECT academic_year
            FROM subject_teacher_assignments
            WHERE id = ?
            LIMIT 1
        ");

        $yearStmt->bind_param(
            'i',
            $formId
        );

        $yearStmt->execute();

        $yearResult =
            $yearStmt->get_result();

        if ($yearRow = $yearResult->fetch_assoc()) {

            $formAcademicYear =
                trim($yearRow['academic_year']);

        } else {

            $errors[] =
                'The selected assignment was not found.';
        }

        $yearStmt->close();

    } else {

        $formAcademicYear =
            $currentAcademicYear;
    }

    /* -----------------------------------------------------
       CURRENT ACADEMIC YEAR CHECK
    ----------------------------------------------------- */

    if (
        !$editing &&
        $currentAcademicYear === ''
    ) {

        $errors[] =
            'The current academic year could not be determined.';
    }

    /* -----------------------------------------------------
       VALIDATE GRADE
    ----------------------------------------------------- */

    if (
        $formGrade < 1 ||
        $formGrade > 12
    ) {

        $errors[] =
            'Please select a valid grade.';
    }

    /* -----------------------------------------------------
       VALIDATE SECTION
    ----------------------------------------------------- */

    $allowedSections = [
        'A',
        'B',
        'C',
        'D',
        'E'
    ];

    if (!in_array(
        $formSection,
        $allowedSections,
        true
    )) {

        $errors[] =
            'Please select a valid section.';
    }

    /* -----------------------------------------------------
       VALIDATE SUBJECT
    ----------------------------------------------------- */

    if ($formGradeSubjectId <= 0) {

        $errors[] =
            'Please select a subject.';
    }

    /* -----------------------------------------------------
       VALIDATE TEACHER
    ----------------------------------------------------- */

    if ($formTeacherUserId <= 0) {

        $errors[] =
            'Please select a teacher.';
    }

    /* -----------------------------------------------------
       VERIFY SUBJECT BELONGS TO SELECTED GRADE
    ----------------------------------------------------- */

    if (
        $formGrade >= 1 &&
        $formGrade <= 12 &&
        $formGradeSubjectId > 0
    ) {

        $stmt = $conn->prepare("
            SELECT id
            FROM grade_subjects
            WHERE id = ?
              AND grade = ?
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->bind_param(
            'ii',
            $formGradeSubjectId,
            $formGrade
        );

        $stmt->execute();

        $subjectResult =
            $stmt->get_result();

        if ($subjectResult->num_rows === 0) {

            $errors[] =
                'The selected subject is not assigned to the selected grade by Admin.';
        }

        $stmt->close();
    }

    /* -----------------------------------------------------
       VERIFY TEACHER
    ----------------------------------------------------- */

    if ($formTeacherUserId > 0) {

        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND LOWER(role) = 'teacher'
              AND is_deleted = 0
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $formTeacherUserId
        );

        $stmt->execute();

        $teacherResult =
            $stmt->get_result();

        if ($teacherResult->num_rows === 0) {

            $errors[] =
                'The selected teacher is not valid.';
        }

        $stmt->close();
    }

    /* -----------------------------------------------------
       CHECK DUPLICATE ASSIGNMENT
    ----------------------------------------------------- */

    if (empty($errors)) {

        if ($editing) {

            $stmt = $conn->prepare("
                SELECT id
                FROM subject_teacher_assignments
                WHERE academic_year = ?
                  AND grade = ?
                  AND section = ?
                  AND grade_subject_id = ?
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'sisii',
                $formAcademicYear,
                $formGrade,
                $formSection,
                $formGradeSubjectId,
                $formId
            );

        } else {

            $stmt = $conn->prepare("
                SELECT id
                FROM subject_teacher_assignments
                WHERE academic_year = ?
                  AND grade = ?
                  AND section = ?
                  AND grade_subject_id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'sisi',
                $formAcademicYear,
                $formGrade,
                $formSection,
                $formGradeSubjectId
            );
        }

        $stmt->execute();

        $duplicateResult =
            $stmt->get_result();

        if ($duplicateResult->num_rows > 0) {

            $errors[] =
                'This subject is already assigned to a teacher for this academic year, grade, and section.';
        }

        $stmt->close();
    }

    /* -----------------------------------------------------
       INSERT / UPDATE
    ----------------------------------------------------- */

    if (empty($errors)) {

        /* ================================================
           UPDATE
        ================================================ */

        if ($editing) {

            /*
             * Academic year is intentionally NOT updated.
             *
             * This preserves historical records.
             */

            $stmt = $conn->prepare("
                UPDATE subject_teacher_assignments
                SET
                    grade = ?,
                    section = ?,
                    grade_subject_id = ?,
                    teacher_user_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->bind_param(
                'isiii',
                $formGrade,
                $formSection,
                $formGradeSubjectId,
                $formTeacherUserId,
                $formId
            );

            if ($stmt->execute()) {

                $stmt->close();

                redirectWithMessage(
                    'success',
                    'Subject teacher assignment updated successfully.'
                );
            }

            $dbError =
                $stmt->error;

            $stmt->close();

            if ($conn->errno === 1062) {

                $errors[] =
                    'This subject is already assigned for this academic year, grade, and section.';

            } else {

                $errors[] =
                    'Unable to update the assignment. '
                    . $dbError;
            }

        }

        /* ================================================
           INSERT
        ================================================ */

        else {

            $stmt = $conn->prepare("
                INSERT INTO subject_teacher_assignments (
                    academic_year,
                    grade,
                    section,
                    grade_subject_id,
                    teacher_user_id,
                    is_active
                )
                VALUES (?, ?, ?, ?, ?, 1)
            ");

            $stmt->bind_param(
                'sisii',
                $currentAcademicYear,
                $formGrade,
                $formSection,
                $formGradeSubjectId,
                $formTeacherUserId
            );

            if ($stmt->execute()) {

                $stmt->close();

                redirectWithMessage(
                    'success',
                    'Subject teacher assigned successfully.'
                );
            }

            $dbError =
                $stmt->error;

            $stmt->close();

            if ($conn->errno === 1062) {

                $errors[] =
                    'This subject is already assigned for the current academic year, grade, and section.';

            } else {

                $errors[] =
                    'Unable to save the assignment. '
                    . $dbError;
            }
        }
    }
}

/* =========================================================
   EDIT RECORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['edit'])
) {

    $editId =
        (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                academic_year,
                grade,
                section,
                grade_subject_id,
                teacher_user_id
            FROM subject_teacher_assignments
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $editId
        );

        $stmt->execute();

        $editResult =
            $stmt->get_result();

        if ($editRow = $editResult->fetch_assoc()) {

            $formId =
                (int)$editRow['id'];

            $formAcademicYear =
                trim($editRow['academic_year']);

            $formGrade =
                (int)$editRow['grade'];

            $formSection =
                $editRow['section'];

            $formGradeSubjectId =
                (int)$editRow['grade_subject_id'];

            $formTeacherUserId =
                (int)$editRow['teacher_user_id'];

            $editing = true;

        } else {

            $flashMessage = [
                'type' => 'warning',
                'message' =>
                    'The selected assignment was not found.'
            ];
        }

        $stmt->close();
    }
}

/* =========================================================
   LOAD TEACHERS
========================================================= */

$teachers = [];

$teacherQuery = $conn->query("
    SELECT
        id,
        full_name,
        email
    FROM users
    WHERE LOWER(role) = 'teacher'
      AND is_deleted = 0
    ORDER BY full_name ASC
");

if ($teacherQuery) {

    while (
        $teacher =
        $teacherQuery->fetch_assoc()
    ) {

        $teachers[] = $teacher;
    }
}

/* =========================================================
   FILTERS
========================================================= */

$filterAcademicYear =
    trim($_GET['academic_year'] ?? '');

$filterGrade =
    (int)($_GET['grade'] ?? 0);

$filterSection =
    strtoupper(
        trim($_GET['section'] ?? '')
    );

$filterSubject =
    trim($_GET['subject'] ?? '');

$filterTeacher =
    (int)($_GET['teacher'] ?? 0);

/* =========================================================
   LOAD ASSIGNMENTS
========================================================= */

$assignments = [];

$sql = "
    SELECT
        sta.id,
        sta.academic_year,
        sta.grade,
        sta.section,
        sta.is_active,
        gs.subject_name,
        u.full_name AS teacher_name

    FROM subject_teacher_assignments sta

    INNER JOIN grade_subjects gs
        ON gs.id = sta.grade_subject_id

    INNER JOIN users u
        ON u.id = sta.teacher_user_id

    WHERE 1 = 1
";

$params = [];

$types = '';

if ($filterAcademicYear !== '') {

    $sql .= "
        AND sta.academic_year LIKE ?
    ";

    $params[] =
        '%' . $filterAcademicYear . '%';

    $types .= 's';
}

if (
    $filterGrade >= 1 &&
    $filterGrade <= 12
) {

    $sql .= "
        AND sta.grade = ?
    ";

    $params[] =
        $filterGrade;

    $types .= 'i';
}

if (
    in_array(
        $filterSection,
        ['A', 'B', 'C', 'D', 'E'],
        true
    )
) {

    $sql .= "
        AND sta.section = ?
    ";

    $params[] =
        $filterSection;

    $types .= 's';
}

if ($filterSubject !== '') {

    $sql .= "
        AND gs.subject_name LIKE ?
    ";

    $params[] =
        '%' . $filterSubject . '%';

    $types .= 's';
}

if ($filterTeacher > 0) {

    $sql .= "
        AND sta.teacher_user_id = ?
    ";

    $params[] =
        $filterTeacher;

    $types .= 'i';
}

$sql .= "
    ORDER BY
        sta.academic_year DESC,
        sta.grade ASC,
        sta.section ASC,
        gs.subject_name ASC
";

$stmt =
    $conn->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$result =
    $stmt->get_result();

while (
    $row =
    $result->fetch_assoc()
) {

    $assignments[] = $row;
}

$stmt->close();

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
        Subject Teacher Assignment | BKHS
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7fb;
            font-family: 'Inter', sans-serif;
            color: #111827;
        }

        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: #111827;
            color: #fff;
            overflow-y: auto;
            z-index: 1000;
        }

        .brand {
            height: 72px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            font-size: 18px;
            font-weight: 800;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }

        .menu {
            padding: 18px 12px;
        }

        .menu-title {
            color: #6b7280;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 10px 12px;
        }

        .menu a {
            display: flex;
            align-items: center;
            color: #d1d5db;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 4px;
            font-size: 13px;
            font-weight: 600;
            transition: .2s;
        }

        .menu a i {
            font-size: 17px;
            width: 24px;
        }

        .menu a:hover {
            background: #1f2937;
            color: #fff;
        }

        .menu a.active {
            background: #4f46e5;
            color: #fff;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 72px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .page-title {
            font-size: 18px;
            font-weight: 800;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .principal-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .content {
            padding: 30px;
        }

        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, .05);
            margin-bottom: 24px;
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #eef0f4;
            padding: 22px;
            font-weight: 800;
            border-radius: 18px 18px 0 0 !important;
        }

        .card-body {
            padding: 25px;
        }

        .section-heading {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .section-description {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 0;
        }

        .form-label {
            font-size: 12px;
            font-weight: 800;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border: 1px solid #dfe3eb;
            border-radius: 10px;
            font-size: 13px;
            box-shadow: none !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #818cf8;
            box-shadow:
                0 0 0 3px
                rgba(79, 70, 229, .08) !important;
        }

        .form-select:disabled {
            background-color: #f3f4f6;
            cursor: not-allowed;
        }

        .btn-primary {
            background: #4f46e5;
            border-color: #4f46e5;
            border-radius: 10px;
            min-height: 45px;
            font-size: 13px;
            font-weight: 700;
            padding: 0 18px;
        }

        .btn-primary:hover {
            background: #4338ca;
            border-color: #4338ca;
        }

        .btn-light {
            border-radius: 10px;
            min-height: 45px;
            font-size: 13px;
            font-weight: 700;
        }

        .assignment-table {
            margin: 0;
        }

        .assignment-table thead th {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 14px 16px;
            white-space: nowrap;
        }

        .assignment-table tbody td {
            padding: 15px 16px;
            border-bottom: 1px solid #f0f1f4;
            vertical-align: middle;
            font-size: 13px;
        }

        .assignment-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .assignment-table tbody tr:hover {
            background: #fafbff;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 30px;
            padding: 0 9px;
            border-radius: 8px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 800;
        }

        .section-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #f3f4f6;
            color: #374151;
            font-size: 12px;
            font-weight: 800;
        }

        .subject-name {
            font-weight: 700;
            color: #111827;
        }

        .teacher-name {
            font-weight: 600;
            color: #374151;
        }

        .year-text {
            font-weight: 700;
            color: #4b5563;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-size: 11px;
            font-weight: 800;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #6b7280;
        }

        .empty-icon {
            width: 55px;
            height: 55px;
            border-radius: 15px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 25px;
        }

        .empty-state h5 {
            color: #374151;
            font-size: 15px;
            font-weight: 800;
        }

        .empty-state p {
            font-size: 12px;
            margin-bottom: 0;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #4b5563;
            text-decoration: none;
            transition: .2s;
        }

        .action-btn:hover {
            background: #f9fafb;
            color: #111827;
        }

        .action-btn.delete:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        .subject-info {
            display: none;
            margin-top: 8px;
            font-size: 11px;
            color: #6b7280;
        }

        .subject-info.show {
            display: block;
        }

        .current-year-box {
            min-height: 45px;
            display: flex;
            align-items: center;
            padding: 0 13px;
            border: 1px solid #dfe3eb;
            border-radius: 10px;
            background: #f8fafc;
            color: #374151;
            font-size: 13px;
            font-weight: 700;
        }

        .current-year-box i {
            color: #4f46e5;
        }

        .alert {
            border: 0;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }

        .filter-card {
            background: #fafbff;
            border: 1px solid #eef0f5;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 24px;
        }

        .filter-title {
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 15px;
            color: #374151;
        }

        .table-responsive {
            border-radius: 0 0 18px 18px;
        }

        .current-year-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 800;
        }

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .content {
                padding: 20px;
            }

            .topbar {
                padding: 0 20px;
            }
        }

        @media (max-width: 575px) {

            .content {
                padding: 15px;
            }

            .card-body {
                padding: 18px;
            }

            .topbar {
                padding: 0 15px;
            }

            .page-subtitle {
                display: none;
            }

            .principal-badge span {
                display: none;
            }
        }

    </style>

</head>

<body>

<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        BKHS

    </div>

    <div class="menu">

        <div class="menu-title">
            Principal
        </div>

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="announcements.php">
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <a
            href="subject-assignment.php"
            class="active"
        >
            <i class="bi bi-book-fill"></i>
            <span>Subject Assignment</span>
        </a>

        <a href="homeroom-assignment.php">
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a href="student-assignment.php">
            <i class="bi bi-people-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a href="attendance.php">
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a href="roster.php">
            <i class="bi bi-list-ul"></i>
            <span>Roster</span>
        </a>

        <a href="certificate.php">
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a href="result.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="menu-title mt-3">
            Account
        </div>

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<!-- =====================================================
     MAIN
====================================================== -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div>

            <h1 class="page-title">
                Subject Teacher Assignment
            </h1>

            <div class="page-subtitle">
                Assign teachers to subjects and sections
            </div>

        </div>

        <div class="principal-badge">

            <div class="avatar">
                <i class="bi bi-person-fill"></i>
            </div>

            <span>Principal</span>

        </div>

    </header>

    <!-- CONTENT -->

    <section class="content">

        <!-- FLASH MESSAGE -->

        <?php if ($flashMessage): ?>

            <div
                class="alert alert-<?= e($flashMessage['type']) ?>
                       alert-dismissible fade show"
            >

                <i class="bi bi-check-circle me-2"></i>

                <?= e($flashMessage['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- CURRENT ACADEMIC YEAR ERROR -->

        <?php if ($currentAcademicYearError !== ''): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-triangle me-2"></i>

                <?= e($currentAcademicYearError) ?>

            </div>

        <?php endif; ?>

        <!-- VALIDATION ERRORS -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger">

                <div class="fw-bold mb-2">

                    <i class="bi bi-exclamation-triangle me-2"></i>

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

        <!-- =================================================
             ASSIGNMENT FORM
        ================================================== -->

        <div class="card">

            <div class="card-header">

                <div
                    class="d-flex justify-content-between
                           align-items-center"
                >

                    <div>

                        <div class="section-heading">

                            <?= $editing
                                ? 'Edit Subject Teacher Assignment'
                                : 'Assign Subject Teacher'
                            ?>

                        </div>

                        <p class="section-description">

                            Assign a teacher to an Admin-defined
                            subject and section.

                        </p>

                    </div>

                    <?php if ($editing): ?>

                        <a
                            href="subject-assignment.php"
                            class="btn btn-light btn-sm"
                        >

                            <i class="bi bi-x-lg me-1"></i>

                            Cancel Edit

                        </a>

                    <?php endif; ?>

                </div>

            </div>

            <div class="card-body">

                <form
                    method="POST"
                    action="subject-assignment.php"
                    autocomplete="off"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="assignment_id"
                        value="<?= (int)$formId ?>"
                    >

                    <div class="row g-3">

                        <!-- CURRENT ACADEMIC YEAR -->

                        <div class="col-md-6 col-lg-3">

                            <label class="form-label">

                                Academic Year

                            </label>

                            <div class="current-year-box">

                                <i class="bi bi-calendar3"></i>

                                <?= e($formAcademicYear) ?>

                                <i
                                    class="bi bi-lock-fill ms-auto"
                                    title="Managed by system"
                                ></i>

                            </div>

                            <div class="subject-info show">

                                <?php if ($editing): ?>

                                    This assignment belongs to
                                    <?= e($formAcademicYear) ?>.
                                    Academic year cannot be changed.

                                <?php else: ?>

                                    Current academic year is controlled
                                    by the school database.

                                <?php endif; ?>

                            </div>

                        </div>

                        <!-- GRADE -->

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="grade"
                                class="form-label"
                            >
                                Grade
                            </label>

                            <select
                                id="grade"
                                name="grade"
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
                                        <?= (
                                            (int)$formGrade === $grade
                                        )
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

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="section"
                                class="form-label"
                            >
                                Section
                            </label>

                            <select
                                id="section"
                                name="section"
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
                                        <?= (
                                            $formSection === $section
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        Section <?= $section ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- SUBJECT -->

                        <div class="col-md-6 col-lg-3">

                            <label
                                for="grade_subject_id"
                                class="form-label"
                            >
                                Subject
                            </label>

                            <select
                                id="grade_subject_id"
                                name="grade_subject_id"
                                class="form-select"
                                required
                                disabled
                            >

                                <option value="">
                                    Select Grade First
                                </option>

                            </select>

                            <div
                                id="subjectInfo"
                                class="subject-info"
                            >

                                Subjects assigned by Admin for
                                the selected grade will appear here.

                            </div>

                        </div>

                        <!-- TEACHER -->

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="teacher_user_id"
                                class="form-label"
                            >
                                Teacher
                            </label>

                            <select
                                id="teacher_user_id"
                                name="teacher_user_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Teacher
                                </option>

                                <?php foreach (
                                    $teachers
                                    as $teacher
                                ): ?>

                                    <option
                                        value="<?= (int)$teacher['id'] ?>"
                                        <?= (
                                            (int)$formTeacherUserId ===
                                            (int)$teacher['id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        <?= e(
                                            $teacher['full_name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- BUTTON -->

                        <div class="col-12">

                            <div
                                class="d-flex justify-content-end
                                       gap-2 mt-2"
                            >

                                <?php if ($editing): ?>

                                    <a
                                        href="subject-assignment.php"
                                        class="btn btn-light"
                                    >
                                        Cancel
                                    </a>

                                <?php endif; ?>

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    id="saveButton"
                                    <?= (
                                        !$editing &&
                                        $currentAcademicYear === ''
                                    )
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >

                                    <i
                                        class="bi bi-check2-circle me-1"
                                    ></i>

                                    <?= $editing
                                        ? 'Update Assignment'
                                        : 'Assign Teacher'
                                    ?>

                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- =================================================
             FILTERS
        ================================================== -->

        <div class="filter-card">

            <div class="filter-title">

                <i class="bi bi-funnel me-1"></i>

                Filter Assignments

            </div>

            <form
                method="GET"
                action="subject-assignment.php"
            >

                <div class="row g-3 align-items-end">

                    <!-- YEAR -->

                    <div class="col-md-6 col-lg-2">

                        <label class="form-label">
                            Academic Year
                        </label>

                        <input
                            type="text"
                            name="academic_year"
                            class="form-control"
                            value="<?= e(
                                $filterAcademicYear
                            ) ?>"
                            placeholder="2018/19"
                        >

                    </div>

                    <!-- GRADE -->

                    <div class="col-md-6 col-lg-2">

                        <label class="form-label">
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
                                    <?= (
                                        $filterGrade === $grade
                                    )
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

                    <div class="col-md-6 col-lg-2">

                        <label class="form-label">
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
                                    <?= (
                                        $filterSection === $section
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    Section <?= $section ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- SUBJECT -->

                    <div class="col-md-6 col-lg-2">

                        <label class="form-label">
                            Subject
                        </label>

                        <input
                            type="text"
                            name="subject"
                            class="form-control"
                            value="<?= e($filterSubject) ?>"
                            placeholder="Search subject"
                        >

                    </div>

                    <!-- TEACHER -->

                    <div class="col-md-6 col-lg-2">

                        <label class="form-label">
                            Teacher
                        </label>

                        <select
                            name="teacher"
                            class="form-select"
                        >

                            <option value="">
                                All Teachers
                            </option>

                            <?php foreach (
                                $teachers
                                as $teacher
                            ): ?>

                                <option
                                    value="<?= (int)$teacher['id'] ?>"
                                    <?= (
                                        $filterTeacher ===
                                        (int)$teacher['id']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= e(
                                        $teacher['full_name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- BUTTONS -->

                    <div class="col-md-6 col-lg-2">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >

                                <i
                                    class="bi bi-search me-1"
                                ></i>

                                Filter

                            </button>

                            <a
                                href="subject-assignment.php"
                                class="btn btn-light"
                                title="Clear filters"
                            >

                                <i
                                    class="bi bi-arrow-counterclockwise"
                                ></i>

                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <!-- =================================================
             ASSIGNMENT LIST
        ================================================== -->

        <div class="card">

            <div class="card-header">

                <div
                    class="d-flex justify-content-between
                           align-items-center"
                >

                    <div>

                        <div class="section-heading">

                            Subject Teacher Assignments

                        </div>

                        <p class="section-description">

                            Current and historical subject
                            teacher assignments.

                        </p>

                    </div>

                    <div class="d-flex align-items-center gap-2">

                        <?php if (
                            $currentAcademicYear !== ''
                        ): ?>

                            <span
                                class="current-year-badge"
                                title="Current academic year"
                            >

                                <i class="bi bi-calendar3"></i>

                                <?= e(
                                    $currentAcademicYear
                                ) ?>

                            </span>

                        <?php endif; ?>

                        <span class="badge text-bg-light">

                            <?= count($assignments) ?>

                            assignment<?= (
                                count($assignments) === 1
                            )
                                ? ''
                                : 's'
                            ?>

                        </span>

                    </div>

                </div>

            </div>

            <div class="table-responsive">

                <?php if (empty($assignments)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">

                            <i
                                class="bi bi-person-workspace"
                            ></i>

                        </div>

                        <h5>

                            No subject teacher assignments found

                        </h5>

                        <p>

                            Assign a teacher to a subject and
                            section using the form above.

                        </p>

                    </div>

                <?php else: ?>

                    <table
                        class="table assignment-table"
                    >

                        <thead>

                            <tr>

                                <th>#</th>

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
                                    Subject
                                </th>

                                <th>
                                    Teacher
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-end">
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

                                    <td class="text-muted">

                                        <?= $index + 1 ?>

                                    </td>

                                    <td>

                                        <span class="year-text">

                                            <?= e(
                                                $assignment[
                                                    'academic_year'
                                                ]
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="grade-badge">

                                            <?= (int)
                                                $assignment['grade']
                                            ?>

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

                                        <span class="subject-name">

                                            <?= e(
                                                $assignment[
                                                    'subject_name'
                                                ]
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="teacher-name">

                                            <?= e(
                                                $assignment[
                                                    'teacher_name'
                                                ]
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?php if (
                                            (int)
                                            $assignment['is_active']
                                            === 1
                                        ): ?>

                                            <span
                                                class="status-badge"
                                            >

                                                <i
                                                    class="bi
                                                           bi-check-circle
                                                           me-1"
                                                ></i>

                                                Active

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge
                                                       text-bg-secondary"
                                            >
                                                Inactive
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end">

                                        <div
                                            class="d-flex
                                                   justify-content-end
                                                   gap-1"
                                        >

                                            <a
                                                href="subject-assignment.php?edit=<?= (int)$assignment['id'] ?>"
                                                class="action-btn"
                                                title="Edit"
                                            >

                                                <i
                                                    class="bi bi-pencil"
                                                ></i>

                                            </a>

                                            <form
                                                method="POST"
                                                action="subject-assignment.php"
                                                class="delete-form"
                                                style="display:inline;"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e(
                                                        $csrfToken
                                                    ) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="assignment_id"
                                                    value="<?= (int)
                                                        $assignment['id']
                                                    ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-btn delete"
                                                    title="Delete"
                                                >

                                                    <i
                                                        class="bi
                                                               bi-trash3"
                                                    ></i>

                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const gradeSelect =
            document.getElementById('grade');

        const subjectSelect =
            document.getElementById(
                'grade_subject_id'
            );

        const subjectInfo =
            document.getElementById(
                'subjectInfo'
            );

        const initialSubjectId =
            <?= json_encode(
                (string)$formGradeSubjectId
            ) ?>;

        /* =================================================
           LOAD SUBJECTS FOR SELECTED GRADE
        ================================================= */

        async function loadSubjects(
            grade,
            selectedSubjectId = ''
        ) {

            subjectSelect.innerHTML =
                '<option value="">Loading subjects...</option>';

            subjectSelect.disabled = true;

            subjectInfo.classList.remove(
                'show'
            );

            if (!grade) {

                subjectSelect.innerHTML =
                    '<option value="">Select Grade First</option>';

                return;
            }

            try {

                const response =
                    await fetch(
                        'subject-assignment.php?action=get_subjects&grade='
                        + encodeURIComponent(grade),
                        {
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                if (!response.ok) {

                    throw new Error(
                        'Unable to load subjects.'
                    );
                }

                const subjects =
                    await response.json();

                subjectSelect.innerHTML =
                    '<option value="">Select Subject</option>';

                if (!subjects.length) {

                    subjectSelect.innerHTML =
                        '<option value="">No subjects assigned to this grade</option>';

                    subjectSelect.disabled =
                        true;

                    subjectInfo.textContent =
                        'Admin has not assigned any active subjects to this grade yet.';

                    subjectInfo.classList.add(
                        'show'
                    );

                    return;
                }

                subjects.forEach(
                    function (subject) {

                        const option =
                            document.createElement(
                                'option'
                            );

                        option.value =
                            subject.id;

                        option.textContent =
                            subject.subject_name;

                        if (
                            selectedSubjectId &&
                            String(subject.id) ===
                            String(
                                selectedSubjectId
                            )
                        ) {

                            option.selected =
                                true;
                        }

                        subjectSelect.appendChild(
                            option
                        );
                    }
                );

                subjectSelect.disabled =
                    false;

                subjectInfo.textContent =
                    'Only subjects assigned by Admin to Grade '
                    + grade
                    + ' are available.';

                subjectInfo.classList.add(
                    'show'
                );

            } catch (error) {

                subjectSelect.innerHTML =
                    '<option value="">Unable to load subjects</option>';

                subjectSelect.disabled =
                    true;

                subjectInfo.textContent =
                    'Unable to load subjects. Please refresh the page and try again.';

                subjectInfo.classList.add(
                    'show'
                );
            }
        }

        /* =================================================
           GRADE CHANGE
        ================================================= */

        gradeSelect.addEventListener(
            'change',
            function () {

                loadSubjects(
                    this.value,
                    ''
                );

            }
        );

        /* =================================================
           LOAD SUBJECTS WHEN EDITING
        ================================================= */

        if (gradeSelect.value) {

            loadSubjects(
                gradeSelect.value,
                initialSubjectId
            );
        }

        /* =================================================
           DELETE CONFIRMATION
        ================================================= */

        document
            .querySelectorAll('.delete-form')
            .forEach(
                function (form) {

                    form.addEventListener(
                        'submit',
                        function (event) {

                            const confirmed =
                                confirm(
                                    'Are you sure you want to delete this subject teacher assignment?'
                                );

                            if (!confirmed) {

                                event.preventDefault();
                            }

                        }
                    );

                }
            );

    }
);

</script>

</body>
</html>