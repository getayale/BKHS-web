<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken(): string
{
    if (empty($_SESSION['returning_student_csrf'])) {
        $_SESSION['returning_student_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['returning_student_csrf'];
}

function verifyCsrfToken(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['returning_student_csrf'])
        && hash_equals($_SESSION['returning_student_csrf'], $token);
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken = generateCsrfToken();

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$registrar = [
    'full_name' => 'Registrar',
    'photo' => null,
];

$stmt = $conn->prepare("
    SELECT
        u.full_name,
        r.photo
    FROM users u
    LEFT JOIN registrars r
        ON r.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $registrar['full_name'] = $row['full_name'] ?? 'Registrar';
        $registrar['photo'] = $row['photo'] ?? null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

$academicYears = [];
$grades = [];
$sections = [];

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT
        id,
        name,
        status
    FROM academic_years
    ORDER BY start_year DESC, start_month DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $academicYears[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
|
| Only A-E are displayed.
|
*/

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM sections
    WHERE code IN ('A', 'B', 'C', 'D', 'E')
    ORDER BY FIELD(code, 'A', 'B', 'C', 'D', 'E')
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$successData = $_SESSION['returning_student_registration'] ?? null;

unset($_SESSION['returning_student_registration']);

$searchTerm = trim((string) ($_GET['student'] ?? ''));

$selectedStudent = null;
$searchResults = [];

/*
|--------------------------------------------------------------------------
| Student Search
|--------------------------------------------------------------------------
*/

if ($searchTerm !== '') {

    $searchLike = '%' . $searchTerm . '%';

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name,
            s.date_of_birth,
            s.gender,
            s.region,
            s.zone,
            s.woreda,
            s.fyda_number,
            s.photo_path
        FROM students s
        WHERE
            s.student_code = ?
            OR s.full_name LIKE ?
        ORDER BY s.full_name ASC
        LIMIT 20
    ");

    if ($stmt) {

        $stmt->bind_param(
            'ss',
            $searchTerm,
            $searchLike
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $searchResults[] = $row;
        }

        $stmt->close();

        foreach ($searchResults as $student) {

            if (
                isset($student['student_code']) &&
                strcasecmp(
                    (string) $student['student_code'],
                    $searchTerm
                ) === 0
            ) {
                $selectedStudent = $student;
                break;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Selected Student Through GET
|--------------------------------------------------------------------------
*/

$studentIdFromRequest = (int) ($_GET['student_id'] ?? 0);

if ($studentIdFromRequest > 0) {

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name,
            s.date_of_birth,
            s.gender,
            s.region,
            s.zone,
            s.woreda,
            s.fyda_number,
            s.photo_path
        FROM students s
        WHERE s.id = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param('i', $studentIdFromRequest);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $selectedStudent = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Registration POST
|--------------------------------------------------------------------------
*/

$transactionStarted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new Exception(
                'Invalid security token. Please refresh the page and try again.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Action
        |--------------------------------------------------------------------------
        */

        $action = (string) ($_POST['action'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Search Action
        |--------------------------------------------------------------------------
        */

        if ($action === 'search_student') {

            $searchValue = trim(
                (string) ($_POST['student_search'] ?? '')
            );

            if ($searchValue === '') {
                throw new Exception(
                    'Please enter a student code or student name.'
                );
            }

            header(
                'Location: returning.php?student=' .
                urlencode($searchValue)
            );

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Registration Action
        |--------------------------------------------------------------------------
        */

        if ($action !== 'register_returning_student') {
            throw new Exception('Invalid registration request.');
        }

        $studentId = (int) ($_POST['student_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $gradeId = (int) ($_POST['grade_id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($studentId <= 0) {
            throw new Exception('Please select a student.');
        }

        if ($academicYearId <= 0) {
            throw new Exception('Please select an academic year.');
        }

        if ($gradeId <= 0) {
            throw new Exception('Please select a grade.');
        }

        if ($sectionId <= 0) {
            throw new Exception('Please select a section.');
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Student
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                student_code,
                full_name
            FROM students
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Unable to verify student.');
        }

        $stmt->bind_param('i', $studentId);
        $stmt->execute();

        $studentResult = $stmt->get_result();
        $student = $studentResult->fetch_assoc();

        $stmt->close();

        if (!$student) {
            throw new Exception(
                'The selected student could not be found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Academic Year
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                status
            FROM academic_years
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to verify academic year.'
            );
        }

        $stmt->bind_param('i', $academicYearId);
        $stmt->execute();

        $yearResult = $stmt->get_result();
        $academicYear = $yearResult->fetch_assoc();

        $stmt->close();

        if (!$academicYear) {
            throw new Exception(
                'The selected academic year does not exist.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Grade
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                grade_number
            FROM grades
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to verify grade.'
            );
        }

        $stmt->bind_param('i', $gradeId);
        $stmt->execute();

        $gradeResult = $stmt->get_result();
        $grade = $gradeResult->fetch_assoc();

        $stmt->close();

        if (!$grade) {
            throw new Exception(
                'The selected grade does not exist.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Section
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                code
            FROM sections
            WHERE
                id = ?
                AND code IN ('A', 'B', 'C', 'D', 'E')
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to verify section.'
            );
        }

        $stmt->bind_param('i', $sectionId);
        $stmt->execute();

        $sectionResult = $stmt->get_result();
        $section = $sectionResult->fetch_assoc();

        $stmt->close();

        if (!$section) {
            throw new Exception(
                'Please select a valid section.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Registration
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                sr.id,
                sr.registration_date,
                g.name AS grade_name,
                s.code AS section_code
            FROM student_registrations sr
            INNER JOIN grades g
                ON g.id = sr.grade_id
            INNER JOIN sections s
                ON s.id = sr.section_id
            WHERE
                sr.student_id = ?
                AND sr.academic_year_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to check previous registration.'
            );
        }

        $stmt->bind_param(
            'ii',
            $studentId,
            $academicYearId
        );

        $stmt->execute();

        $duplicateResult = $stmt->get_result();
        $existingRegistration = $duplicateResult->fetch_assoc();

        $stmt->close();

        if ($existingRegistration) {

            throw new Exception(
                'This student is already registered for ' .
                $academicYear['name'] .
                '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Start Transaction
        |--------------------------------------------------------------------------
        */

        if (!$conn->begin_transaction()) {
            throw new Exception(
                'Registration could not be started.'
            );
        }

        $transactionStarted = true;

        /*
        |--------------------------------------------------------------------------
        | Insert Returning Registration
        |--------------------------------------------------------------------------
        */

        $registrationType = 'Returning';
        $resultStatus = 'Pending';

        $stmt = $conn->prepare("
            INSERT INTO student_registrations (
                student_id,
                academic_year_id,
                grade_id,
                section_id,
                registration_type,
                result,
                registration_date
            )
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to prepare student registration.'
            );
        }

        $stmt->bind_param(
            'iiiiss',
            $studentId,
            $academicYearId,
            $gradeId,
            $sectionId,
            $registrationType,
            $resultStatus
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                'The student registration could not be saved.'
            );
        }

        $registrationId = $stmt->insert_id;

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        if (!$conn->commit()) {
            throw new Exception(
                'Registration could not be completed.'
            );
        }

        $transactionStarted = false;

        /*
        |--------------------------------------------------------------------------
        | Success Data
        |--------------------------------------------------------------------------
        */

        $_SESSION['returning_student_registration'] = [
            'registration_id' => $registrationId,
            'student_id' => $student['id'],
            'student_code' => $student['student_code'],
            'student_name' => $student['full_name'],
            'academic_year' => $academicYear['name'],
            'grade' => $grade['name'],
            'section' => $section['code'],
            'registration_type' => 'Returning',
            'registered_at' => date('Y-m-d H:i:s'),
        ];

        header('Location: returning.php?success=1');
        exit;

    } catch (Throwable $e) {

        if ($transactionStarted) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }

            $transactionStarted = false;
        }

        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Success State
|--------------------------------------------------------------------------
*/

$showSuccess =
    isset($_GET['success']) &&
    $_GET['success'] === '1';

/*
|--------------------------------------------------------------------------
| Print / PDF
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['print']) &&
    $_GET['print'] === '1' &&
    $successData
) {
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Returning Student Registration</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #fff;
            color: #111827;
        }

        .print-wrapper {
            max-width: 850px;
            margin: 40px auto;
            padding: 40px;
            border: 1px solid #d1d5db;
        }

        .school-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .school-header h1 {
            margin-bottom: 5px;
            font-size: 28px;
        }

        .school-header p {
            margin: 0;
            color: #6b7280;
        }

        .document-title {
            text-align: center;
            margin: 30px 0;
            font-weight: 700;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            border: 1px solid #d1d5db;
            padding: 12px;
        }

        .info-table td:first-child {
            width: 35%;
            font-weight: 700;
            background: #f3f4f6;
        }

        .footer {
            margin-top: 40px;
            font-size: 13px;
            color: #6b7280;
        }

        @media print {

            .no-print {
                display: none !important;
            }

            .print-wrapper {
                border: none;
                margin: 0;
                max-width: none;
            }

        }

    </style>

</head>

<body>

<div class="print-wrapper">

    <div class="school-header">

        <h1>
            Bole Kale Hiwot School
        </h1>

        <p>
            School Management System
        </p>

    </div>

    <h2 class="document-title">
        Returning Student Registration
    </h2>

    <table class="info-table">

        <tr>
            <td>Registration ID</td>
            <td>
                <?= e((string) $successData['registration_id']) ?>
            </td>
        </tr>

        <tr>
            <td>Student ID</td>
            <td>
                <?= e($successData['student_code']) ?>
            </td>
        </tr>

        <tr>
            <td>Student Name</td>
            <td>
                <?= e($successData['student_name']) ?>
            </td>
        </tr>

        <tr>
            <td>Academic Year</td>
            <td>
                <?= e($successData['academic_year']) ?>
            </td>
        </tr>

        <tr>
            <td>Grade</td>
            <td>
                <?= e($successData['grade']) ?>
            </td>
        </tr>

        <tr>
            <td>Section</td>
            <td>
                <?= e($successData['section']) ?>
            </td>
        </tr>

        <tr>
            <td>Registration Type</td>
            <td>
                <?= e($successData['registration_type']) ?>
            </td>
        </tr>

        <tr>
            <td>Registration Date</td>
            <td>
                <?= e($successData['registered_at']) ?>
            </td>
        </tr>

    </table>

    <div class="footer">

        <p>
            This document confirms the student's registration for the
            selected academic year.
        </p>

    </div>

</div>

<script>

    window.addEventListener('load', function () {
        window.print();
    });

</script>

</body>
</html>
<?php
    exit;
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

    <title>Returning Student Registration | BKHS</title>

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
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --success: #16a34a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

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

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .page-heading h4 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Back Button
        |--------------------------------------------------------------------------
        */

        .back-registration-btn {
            min-height: 42px;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: #374151;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            transition: .2s;
        }

        .back-registration-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: var(--primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Registrar Information
        |--------------------------------------------------------------------------
        */

        .registrar-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .registrar-info img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
        }

        .registrar-name {
            font-size: 13px;
            font-weight: 700;
        }

        .registrar-role {
            display: block;
            font-size: 11px;
            color: var(--muted);
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            width: 100%;
            padding: 30px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .page-intro {
            margin-bottom: 24px;
        }

        .page-intro h3 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .page-intro p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Cards
        |--------------------------------------------------------------------------
        */

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, .03);
        }

        /*
        |--------------------------------------------------------------------------
        | Section Title
        |--------------------------------------------------------------------------
        */

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .section-title-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .section-title h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
        }

        .section-title p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Forms
        |--------------------------------------------------------------------------
        */

        .form-label {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-color: #dbe1e8;
            border-radius: 10px;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10);
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 10px;
            min-height: 46px;
            font-weight: 700;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-light {
            border: 1px solid var(--border);
            border-radius: 10px;
            min-height: 46px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Search Results
        |--------------------------------------------------------------------------
        */

        .student-result {
            display: block;
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 10px;
            transition: .2s;
        }

        .student-result:hover {
            border-color: var(--primary);
            background: #f8fbff;
        }

        .student-result-code {
            font-size: 13px;
            font-weight: 800;
            color: var(--primary);
        }

        .student-result-name {
            font-size: 14px;
            font-weight: 700;
            margin-top: 3px;
        }

        .student-result-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Information
        |--------------------------------------------------------------------------
        */

        .student-profile {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 24px;
            padding: 18px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1px solid var(--border);
        }

        .student-avatar {
            width: 70px;
            height: 70px;
            border-radius: 14px;
            object-fit: cover;
            border: 1px solid var(--border);
        }

        .student-profile h4 {
            margin: 0 0 4px;
            font-size: 18px;
            font-weight: 800;
        }

        .student-code {
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }

        .student-meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .info-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            height: 100%;
        }

        .info-item-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .info-item-value {
            font-size: 14px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Success
        |--------------------------------------------------------------------------
        */

        .success-card {
            background: #fff;
            border: 1px solid #bbf7d0;
            border-radius: 18px;
            padding: 35px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(22, 163, 74, .08);
        }

        .success-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            background: #dcfce7;
            color: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
        }

        .success-card h3 {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .success-card p {
            color: var(--muted);
            font-size: 14px;
        }

        .registration-summary {
            max-width: 700px;
            margin: 25px auto;
            text-align: left;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-label {
            color: var(--muted);
            font-size: 13px;
        }

        .summary-value {
            font-size: 13px;
            font-weight: 700;
            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | Alerts
        |--------------------------------------------------------------------------
        */

        .alert {
            border-radius: 12px;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 767px) {

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 22px 18px;
            }

            .registrar-name,
            .registrar-role {
                display: none;
            }

        }

        @media (max-width: 575px) {

            .topbar {
                gap: 10px;
            }

            .topbar-left {
                min-width: 0;
            }

            .page-heading h4 {
                font-size: 15px;
            }

            .page-heading p {
                display: none;
            }

            .back-registration-btn {
                width: 42px;
                height: 42px;
                padding: 0;
                flex-shrink: 0;
            }

            .back-registration-btn span {
                display: none;
            }

            .content {
                padding: 18px 14px;
            }

            .card-box {
                padding: 18px;
            }

            .page-intro h3 {
                font-size: 21px;
            }

            .student-profile {
                align-items: flex-start;
            }

            .summary-row {
                flex-direction: column;
                gap: 3px;
            }

            .summary-value {
                text-align: left;
            }

        }

    </style>

</head>

<body>

<main class="main">

    <header class="topbar">

        <div class="topbar-left">

            <div class="page-heading">

                <h4>
                    Returning Student Registration
                </h4>

                <p>
                    Register an existing student for a new academic year
                </p>

            </div>

        </div>

        <div class="d-flex align-items-center gap-3">

            <a
                href="register.php"
                class="back-registration-btn"
                title="Back to Registration"
            >
                <i class="bi bi-arrow-left"></i>
                <span>Back to Registration</span>
            </a>

            <div class="registrar-info">

                <?php

                $registrarPhoto = $registrar['photo']
                    ? '../' . ltrim($registrar['photo'], '/')
                    : '../public/images/default-avatar.png';

                ?>

                <img
                    src="<?= e($registrarPhoto) ?>"
                    alt="Registrar"
                >

                <div>

                    <div class="registrar-name">
                        <?= e($registrar['full_name']) ?>
                    </div>

                    <span class="registrar-role">
                        Registrar
                    </span>

                </div>

            </div>

        </div>

    </header>

    <section class="content">

        <?php if ($errorMessage): ?>

            <div
                class="alert alert-danger d-flex align-items-start gap-2"
                role="alert"
            >

                <i class="bi bi-exclamation-circle-fill mt-1"></i>

                <div>

                    <strong>
                        Registration could not be completed.
                    </strong>

                    <div class="mt-1">
                        <?= e($errorMessage) ?>
                    </div>

                </div>

            </div>

        <?php endif; ?>

        <?php if ($showSuccess && $successData): ?>

            <div class="success-card">

                <div class="success-icon">
                    <i class="bi bi-check-lg"></i>
                </div>

                <h3>
                    Registration Completed Successfully
                </h3>

                <p>
                    The returning student has been registered for the
                    selected academic year.
                </p>

                <div class="registration-summary">

                    <div class="summary-row">

                        <span class="summary-label">
                            Student ID
                        </span>

                        <span class="summary-value">
                            <?= e($successData['student_code']) ?>
                        </span>

                    </div>

                    <div class="summary-row">

                        <span class="summary-label">
                            Student Name
                        </span>

                        <span class="summary-value">
                            <?= e($successData['student_name']) ?>
                        </span>

                    </div>

                    <div class="summary-row">

                        <span class="summary-label">
                            Academic Year
                        </span>

                        <span class="summary-value">
                            <?= e($successData['academic_year']) ?>
                        </span>

                    </div>

                    <div class="summary-row">

                        <span class="summary-label">
                            Grade
                        </span>

                        <span class="summary-value">
                            <?= e($successData['grade']) ?>
                        </span>

                    </div>

                    <div class="summary-row">

                        <span class="summary-label">
                            Section
                        </span>

                        <span class="summary-value">
                            <?= e($successData['section']) ?>
                        </span>

                    </div>

                    <div class="summary-row">

                        <span class="summary-label">
                            Registration Type
                        </span>

                        <span class="summary-value">
                            Returning
                        </span>

                    </div>

                </div>

                <div class="d-flex justify-content-center gap-2 flex-wrap">

                    <a
                        href="returning.php?print=1"
                        target="_blank"
                        class="btn btn-primary px-4"
                    >

                        <i class="bi bi-printer me-2"></i>

                        Export / Print PDF

                    </a>

                    <a
                        href="register.php"
                        class="btn btn-light px-4"
                    >

                        <i class="bi bi-arrow-left me-2"></i>

                        Back to Registration

                    </a>

                    <a
                        href="returning.php"
                        class="btn btn-light px-4"
                    >

                        <i class="bi bi-person-plus me-2"></i>

                        Register Another Student

                    </a>

                </div>

            </div>

        <?php else: ?>

            <div class="page-intro">

                <h3>
                    Returning Student
                </h3>

                <p>
                    Search for an existing student and register them
                    for the new academic year.
                </p>

            </div>

            <div class="card-box">

                <div class="section-title">

                    <div class="section-title-icon">
                        <i class="bi bi-search"></i>
                    </div>

                    <div>

                        <h5>
                            Find Student
                        </h5>

                        <p>
                            Search using the permanent Student ID or
                            student name.
                        </p>

                    </div>

                </div>

                <form
                    method="POST"
                    action="returning.php"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="search_student"
                    >

                    <div class="row g-3 align-items-end">

                        <div class="col-md-9">

                            <label
                                for="student_search"
                                class="form-label"
                            >
                                Student ID or Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="student_search"
                                name="student_search"
                                value="<?= e($searchTerm) ?>"
                                placeholder="Example: BKHS-STU-000123 or student name"
                                autocomplete="off"
                            >

                        </div>

                        <div class="col-md-3">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >

                                <i class="bi bi-search me-2"></i>

                                Search Student

                            </button>

                        </div>

                    </div>

                </form>

            </div>

            <?php if ($searchTerm !== '' && !$selectedStudent): ?>

                <div class="card-box">

                    <div class="section-title">

                        <div class="section-title-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>

                            <h5>
                                Search Results
                            </h5>

                            <p>
                                Select the student you want to register.
                            </p>

                        </div>

                    </div>

                    <?php if ($searchResults): ?>

                        <?php foreach ($searchResults as $resultStudent): ?>

                            <a
                                href="returning.php?student_id=<?= (int) $resultStudent['id'] ?>"
                                class="student-result"
                            >

                                <div class="student-result-code">
                                    <?= e($resultStudent['student_code']) ?>
                                </div>

                                <div class="student-result-name">
                                    <?= e($resultStudent['full_name']) ?>
                                </div>

                                <div class="student-result-meta">

                                    <?= e($resultStudent['gender']) ?>

                                    <?php if (!empty($resultStudent['date_of_birth'])): ?>

                                        ·
                                        <?= e($resultStudent['date_of_birth']) ?>

                                    <?php endif; ?>

                                    <?php if (!empty($resultStudent['woreda'])): ?>

                                        ·
                                        <?= e($resultStudent['woreda']) ?>

                                    <?php endif; ?>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="text-center py-4">

                            <div
                                class="text-muted mb-2"
                                style="font-size: 35px;"
                            >
                                <i class="bi bi-person-x"></i>
                            </div>

                            <h6 class="fw-bold">
                                Student Not Found
                            </h6>

                            <p class="text-muted small mb-0">
                                No student matched your search.
                            </p>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <?php if ($selectedStudent): ?>

                <div class="card-box">

                    <div class="section-title">

                        <div class="section-title-icon">
                            <i class="bi bi-person-check-fill"></i>
                        </div>

                        <div>

                            <h5>
                                Student Information
                            </h5>

                            <p>
                                Confirm that this is the correct student.
                            </p>

                        </div>

                    </div>

                    <?php

                    $studentPhoto = !empty($selectedStudent['photo_path'])
                        ? '../' . ltrim($selectedStudent['photo_path'], '/')
                        : '../public/images/default-avatar.png';

                    ?>

                    <div class="student-profile">

                        <img
                            src="<?= e($studentPhoto) ?>"
                            alt="Student"
                            class="student-avatar"
                        >

                        <div>

                            <h4>
                                <?= e($selectedStudent['full_name']) ?>
                            </h4>

                            <div class="student-code">
                                <?= e($selectedStudent['student_code']) ?>
                            </div>

                            <div class="student-meta">
                                Existing student account · Permanent Student ID
                            </div>

                        </div>

                    </div>

                    <div class="row g-3">

                        <div class="col-md-3">

                            <div class="info-item">

                                <div class="info-item-label">
                                    Gender
                                </div>

                                <div class="info-item-value">
                                    <?= e($selectedStudent['gender']) ?>
                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="info-item">

                                <div class="info-item-label">
                                    Date of Birth
                                </div>

                                <div class="info-item-value">
                                    <?= e($selectedStudent['date_of_birth']) ?>
                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="info-item">

                                <div class="info-item-label">
                                    Region
                                </div>

                                <div class="info-item-value">
                                    <?= e($selectedStudent['region']) ?>
                                </div>

                            </div>

                        </div>

                        <div class="col-md-3">

                            <div class="info-item">

                                <div class="info-item-label">
                                    Woreda
                                </div>

                                <div class="info-item-value">
                                    <?= e($selectedStudent['woreda']) ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="card-box">

                    <div class="section-title">

                        <div class="section-title-icon">
                            <i class="bi bi-calendar2-plus-fill"></i>
                        </div>

                        <div>

                            <h5>
                                Academic Registration
                            </h5>

                            <p>
                                Select the academic year, grade and section.
                            </p>

                        </div>

                    </div>

                    <form
                        method="POST"
                        action="returning.php"
                        id="returningRegistrationForm"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="register_returning_student"
                        >

                        <input
                            type="hidden"
                            name="student_id"
                            value="<?= (int) $selectedStudent['id'] ?>"
                        >

                        <div class="row g-4">

                            <div class="col-md-4">

                                <label
                                    for="academic_year_id"
                                    class="form-label"
                                >

                                    Academic Year

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    class="form-select"
                                    id="academic_year_id"
                                    name="academic_year_id"
                                    required
                                >

                                    <option value="">
                                        Select Academic Year
                                    </option>

                                    <?php

                                    $activeYearSelected = false;

                                    foreach ($academicYears as $year):

                                        $isActive =
                                            strtolower(
                                                (string) $year['status']
                                            ) === 'active';

                                        $shouldSelect =
                                            $isActive &&
                                            !$activeYearSelected;

                                        if ($shouldSelect) {
                                            $activeYearSelected = true;
                                        }

                                    ?>

                                        <option
                                            value="<?= (int) $year['id'] ?>"
                                            <?= $shouldSelect ? 'selected' : '' ?>
                                        >

                                            <?= e($year['name']) ?>

                                            <?php if ($isActive): ?>

                                                — Active

                                            <?php endif; ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="grade_id"
                                    class="form-label"
                                >

                                    Grade

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    class="form-select"
                                    id="grade_id"
                                    name="grade_id"
                                    required
                                >

                                    <option value="">
                                        Select Grade
                                    </option>

                                    <?php foreach ($grades as $grade): ?>

                                        <option
                                            value="<?= (int) $grade['id'] ?>"
                                        >
                                            <?= e($grade['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="section_id"
                                    class="form-label"
                                >

                                    Section

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    class="form-select"
                                    id="section_id"
                                    name="section_id"
                                    required
                                >

                                    <option value="">
                                        Select Section
                                    </option>

                                    <?php foreach ($sections as $section): ?>

                                        <option
                                            value="<?= (int) $section['id'] ?>"
                                        >
                                            <?= e($section['code']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                        <div class="alert alert-info mt-4 mb-0">

                            <i class="bi bi-info-circle-fill me-2"></i>

                            This is a <strong>Returning</strong> registration.
                            The student's existing Student ID and account will
                            remain unchanged.

                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4">

                            <a
                                href="register.php"
                                class="btn btn-light px-4"
                            >
                                <i class="bi bi-arrow-left me-2"></i>
                                Back to Registration
                            </a>

                            <button
                                type="submit"
                                class="btn btn-primary px-4"
                                id="submitRegistration"
                            >

                                <i class="bi bi-check2-circle me-2"></i>

                                Complete Registration

                            </button>

                        </div>

                    </form>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<script>

    const registrationForm =
        document.getElementById('returningRegistrationForm');

    if (registrationForm) {

        registrationForm.addEventListener('submit', function () {

            const button =
                document.getElementById('submitRegistration');

            if (!button) {
                return;
            }

            button.disabled = true;

            button.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                ></span>
                Registering...
            `;

        });

    }

</script>

</body>
</html>