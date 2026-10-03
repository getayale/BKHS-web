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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function calculateGrade(float $mark): string
{
    if ($mark >= 90) {
        return 'A+';
    }

    if ($mark >= 80) {
        return 'A';
    }

    if ($mark >= 70) {
        return 'B';
    }

    if ($mark >= 60) {
        return 'C';
    }

    if ($mark >= 50) {
        return 'D';
    }

    return 'F';
}

function normalizeSemesterName(string $name): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($name));

    return strtolower((string) $normalized);
}

function semesterShowsGrade(string $semesterName): bool
{
    $name = normalizeSemesterName($semesterName);

    return in_array(
        $name,
        [
            'first semester',
            'second semester'
        ],
        true
    );
}

function semesterShowsMark(string $semesterName): bool
{
    $name = normalizeSemesterName($semesterName);

    return in_array(
        $name,
        [
            'mid semester',
            'quarter semester'
        ],
        true
    );
}

/*
|--------------------------------------------------------------------------
| AJAX: Load Semesters
|--------------------------------------------------------------------------
|
| IMPORTANT:
| This must run BEFORE any HTML output so that the JSON response
| is returned correctly.
|
*/

if (
    isset($_GET['get_semesters']) &&
    (int) $_GET['get_semesters'] === 1
) {
    header('Content-Type: application/json; charset=utf-8');

    $academicYearId = (int) ($_GET['academic_year_id'] ?? 0);

    $semesters = [];

    if ($academicYearId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                academic_year_id,
                name,
                order_number,
                max_mark,
                status
            FROM semesters
            WHERE academic_year_id = ?
              AND LOWER(status) IN ('active', 'completed')
            ORDER BY order_number ASC, id ASC
        ");

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $academicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $semesters[] = [
                    'id' => (int) $row['id'],
                    'academic_year_id' => (int) $row['academic_year_id'],
                    'name' => (string) $row['name'],
                    'order_number' => (int) $row['order_number'],
                    'max_mark' => (float) $row['max_mark'],
                    'status' => (string) $row['status']
                ];
            }

            $stmt->close();
        }
    }

    echo json_encode(
        $semesters,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

$registrar = [
    'id' => $userId,
    'full_name' => 'Registrar',
    'email' => '',
    'phone' => '',
    'photo' => null
];

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

if ($stmt) {

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $registrar = $row;
    }

    $stmt->close();
}

$photoPath = '../public/images/default-avatar.png';

if (!empty($registrar['photo'])) {

    $candidate =
        '../' . ltrim(
            (string) $registrar['photo'],
            '/'
        );

    if (file_exists($candidate)) {
        $photoPath = $candidate;
    }
}

/*
|--------------------------------------------------------------------------
| Logo
|--------------------------------------------------------------------------
*/

$logoPath = '../public/image/logo.webp';
$logoDataUri = '';

if (file_exists($logoPath)) {

    $logoBinary = file_get_contents($logoPath);

    if ($logoBinary !== false) {

        $logoDataUri =
            'data:image/webp;base64,' .
            base64_encode($logoBinary);
    }
}

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
|
| Only Active and Completed academic years are available.
|
*/

$academicYears = [];

$result = $conn->query("
    SELECT
        id,
        name,
        start_year,
        end_year,
        status
    FROM academic_years
    WHERE LOWER(status) IN ('active', 'completed')
    ORDER BY start_year DESC, id DESC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $academicYears[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Selected Values
|--------------------------------------------------------------------------
*/

$studentCode = '';

$selectedAcademicYearId = 0;
$selectedSemesterId = 0;

$selectedAcademicYear = null;
$selectedSemester = null;

$student = null;
$certificateRows = [];

$totalMark = 0;
$averageMark = 0;

$searchPerformed = false;

$errorMessage = '';
$successMessage = '';

/*
|--------------------------------------------------------------------------
| POST Search
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $studentCode =
        trim(
            (string) (
                $_POST['student_code'] ?? ''
            )
        );

    $selectedAcademicYearId =
        (int) (
            $_POST['academic_year_id'] ?? 0
        );

    $selectedSemesterId =
        (int) (
            $_POST['semester_id'] ?? 0
        );

    $searchPerformed = true;

    /*
    |--------------------------------------------------------------------------
    | Validate Student Code
    |--------------------------------------------------------------------------
    */

    if ($studentCode === '') {

        $errorMessage =
            'Please enter the Student Code.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Academic Year
    |--------------------------------------------------------------------------
    */

    if (
        $errorMessage === '' &&
        $selectedAcademicYearId <= 0
    ) {

        $errorMessage =
            'Please select an Academic Year.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Semester
    |--------------------------------------------------------------------------
    */

    if (
        $errorMessage === '' &&
        $selectedSemesterId <= 0
    ) {

        $errorMessage =
            'Please select a Semester.';
    }

    /*
    |--------------------------------------------------------------------------
    | Get Academic Year
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                start_year,
                end_year,
                status
            FROM academic_years
            WHERE id = ?
              AND LOWER(status) IN ('active', 'completed')
            LIMIT 1
        ");

        if (!$stmt) {

            $errorMessage =
                'Unable to load the selected academic year.';

        } else {

            $stmt->bind_param(
                'i',
                $selectedAcademicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $selectedAcademicYear =
                $result->fetch_assoc();

            $stmt->close();

            if (!$selectedAcademicYear) {

                $errorMessage =
                    'The selected academic year is not available. ' .
                    'Only Active or Completed academic years can be used.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Semester
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $stmt = $conn->prepare("
            SELECT
                id,
                academic_year_id,
                name,
                order_number,
                max_mark,
                start_year,
                start_month,
                start_day,
                end_year,
                end_month,
                end_day,
                status
            FROM semesters
            WHERE id = ?
              AND academic_year_id = ?
              AND LOWER(status) IN ('active', 'completed')
            LIMIT 1
        ");

        if (!$stmt) {

            $errorMessage =
                'Unable to load the selected semester.';

        } else {

            $stmt->bind_param(
                'ii',
                $selectedSemesterId,
                $selectedAcademicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $selectedSemester =
                $result->fetch_assoc();

            $stmt->close();

            if (!$selectedSemester) {

                $errorMessage =
                    'The selected semester is not available ' .
                    'for the selected academic year.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Student Registration
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $stmt = $conn->prepare("
            SELECT
                sr.id AS registration_id,

                s.id AS student_id,
                s.student_code,
                s.full_name,
                s.gender,
                s.date_of_birth,
                s.photo_path,

                ay.id AS academic_year_id,
                ay.name AS academic_year_name,

                g.id AS grade_id,
                g.name AS grade_name,
                g.grade_number,

                sec.id AS section_id,
                sec.name AS section_name,
                sec.code AS section_code

            FROM students s

            INNER JOIN student_registrations sr
                ON sr.student_id = s.id

            INNER JOIN academic_years ay
                ON ay.id = sr.academic_year_id

            INNER JOIN grades g
                ON g.id = sr.grade_id

            INNER JOIN sections sec
                ON sec.id = sr.section_id

            WHERE s.student_code = ?
              AND s.is_deleted = 0
              AND sr.academic_year_id = ?

            ORDER BY sr.id DESC

            LIMIT 1
        ");

        if (!$stmt) {

            $errorMessage =
                'Unable to search for the student.';

        } else {

            $stmt->bind_param(
                'si',
                $studentCode,
                $selectedAcademicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $student =
                $result->fetch_assoc();

            $stmt->close();

            if (!$student) {

                $errorMessage =
                    'No student registration was found for Student Code "' .
                    $studentCode .
                    '" in the selected academic year.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Academic Results
    |--------------------------------------------------------------------------
    */

    if (
        $errorMessage === '' &&
        $student &&
        $selectedSemester
    ) {

        $stmt = $conn->prepare("
            SELECT
                gs.id AS grade_subject_id,
                gs.subject_name,
                r.mark

            FROM results r

            INNER JOIN grade_subjects gs
                ON gs.id = r.grade_subject_id

            WHERE r.student_registration_id = ?
              AND r.semester_id = ?

            ORDER BY gs.id ASC
        ");

        if (!$stmt) {

            $errorMessage =
                'Unable to load the student academic results.';

        } else {

            $stmt->bind_param(
                'ii',
                $student['registration_id'],
                $selectedSemesterId
            );

            $stmt->execute();

            $marksResult =
                $stmt->get_result();

            $showGrade =
                semesterShowsGrade(
                    (string) $selectedSemester['name']
                );

            $showMark =
                semesterShowsMark(
                    (string) $selectedSemester['name']
                );

            while (
                $row =
                    $marksResult->fetch_assoc()
            ) {

                $mark =
                    (float) $row['mark'];

                $row['mark'] = $mark;

                /*
                |--------------------------------------------------------------------------
                | First + Second Semester
                | Show Grade
                |--------------------------------------------------------------------------
                */

                if ($showGrade) {

                    $row['grade'] =
                        calculateGrade($mark);

                } else {

                    $row['grade'] = '';
                }

                /*
                |--------------------------------------------------------------------------
                | Mid + Quarter Semester
                | Show Mark
                |--------------------------------------------------------------------------
                */

                $row['show_mark'] =
                    $showMark;

                $certificateRows[] =
                    $row;

                $totalMark += $mark;
            }

            $stmt->close();

            if (count($certificateRows) > 0) {

                $averageMark =
                    $totalMark /
                    count($certificateRows);
            }

            if (
                count($certificateRows) === 0
            ) {

                $errorMessage =
                    'No academic results were found for this student and semester.';

            } else {

                $successMessage =
                    'Certificate information loaded successfully.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Semesters for Selected Academic Year
|--------------------------------------------------------------------------
|
| Only Active and Completed semesters are shown.
|
*/

$availableSemesters = [];

if ($selectedAcademicYearId > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            academic_year_id,
            name,
            order_number,
            max_mark,
            status
        FROM semesters
        WHERE academic_year_id = ?
          AND LOWER(status) IN ('active', 'completed')
        ORDER BY order_number ASC, id ASC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedAcademicYearId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
                $result->fetch_assoc()
        ) {

            $availableSemesters[] =
                $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Display Mode
|--------------------------------------------------------------------------
*/

$showGradeColumn = false;
$showMarkColumn = false;

if ($selectedSemester) {

    $semesterName =
        (string) $selectedSemester['name'];

    $showGradeColumn =
        semesterShowsGrade(
            $semesterName
        );

    $showMarkColumn =
        semesterShowsMark(
            $semesterName
        );
}

/*
|--------------------------------------------------------------------------
| Student Photo
|--------------------------------------------------------------------------
*/

$studentPhotoPath = '';

if (
    $student &&
    !empty($student['photo_path'])
) {

    $candidateStudentPhoto =
        '../' .
        ltrim(
            (string) $student['photo_path'],
            '/'
        );

    if (
        file_exists(
            $candidateStudentPhoto
        )
    ) {

        $studentPhotoPath =
            $candidateStudentPhoto;
    }
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
        Certificate | Registrar | BKHS
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
        href="https://fonts.googleapis.com"
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
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;

            --certificate-bg: #ffffff;
            --certificate-primary: #173b68;
            --certificate-secondary: #b48a2c;
        }

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
            z-index: 1050;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 4px;
        }

        .brand-title {
            color: #fff;
            font-weight: 800;
            font-size: 17px;
            line-height: 1.1;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-menu {
            padding: 20px 12px;
        }

        .sidebar-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d1d5db;
            text-decoration: none;
            padding: 12px 14px;
            margin-bottom: 4px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-link i {
            width: 20px;
            font-size: 17px;
        }

        .sidebar-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
            transform: translateX(2px);
        }

        .sidebar-link.active {
            color: #fff;
            background: var(--primary);
        }

        .sidebar-link.logout {
            color: #fca5a5;
            margin-top: 18px;
        }

        .sidebar-link.logout:hover {
            background: rgba(239,68,68,0.12);
            color: #fecaca;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            position: sticky;
            top: 0;
            height: 78px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .page-title {
            font-size: 21px;
            font-weight: 800;
            margin: 0;
        }

        .page-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .registrar-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .registrar-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .registrar-name {
            font-size: 13px;
            font-weight: 700;
        }

        .registrar-role {
            color: var(--muted);
            font-size: 11px;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 25px;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1040;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 30px;
        }

        .page-intro {
            margin-bottom: 24px;
        }

        .page-intro h2 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .page-intro p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .card-custom {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(15,23,42,0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card-custom:hover {
            box-shadow: 0 8px 25px rgba(15,23,42,0.07);
        }

        .search-card {
            padding: 24px;
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-color: #dbe1e8;
            border-radius: 10px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
        }

        .btn-primary-custom {
            min-height: 46px;
            border: 0;
            border-radius: 10px;
            padding: 0 22px;
            background: var(--primary);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .alert-custom {
            border-radius: 12px;
            border: 0;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Summary
        |--------------------------------------------------------------------------
        */

        .student-summary {
            padding: 20px;
            margin-bottom: 25px;
        }

        .student-photo {
            width: 74px;
            height: 74px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .student-placeholder {
            width: 74px;
            height: 74px;
            border-radius: 12px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .student-name {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .student-code {
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }

        .student-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .meta-badge {
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            color: #4b5563;
            padding: 6px 9px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Certificate Toolbar
        |--------------------------------------------------------------------------
        */

        .certificate-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 15px;
        }

        .toolbar-title {
            font-size: 16px;
            font-weight: 800;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .color-control {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
            background: #fff;
            padding: 5px 8px 5px 10px;
            border-radius: 9px;
        }

        .color-control span {
            font-size: 11px;
            font-weight: 700;
            color: #4b5563;
        }

        .color-picker {
            width: 35px;
            height: 30px;
            padding: 2px;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .btn-tool {
            border: 1px solid #dbe1e8;
            background: #fff;
            color: #374151;
            min-height: 40px;
            border-radius: 9px;
            padding: 0 13px;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn-tool:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }

        .btn-tool.primary {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-tool.primary:hover {
            color: #fff;
            background: var(--primary-dark);
        }

        /*
        |--------------------------------------------------------------------------
        | Certificate
        |--------------------------------------------------------------------------
        */

        .certificate-wrapper {
            display: flex;
            justify-content: center;
            padding: 20px 0 50px;
            overflow-x: auto;
        }

        .certificate {
            position: relative;
            width: 210mm;
            height: 297mm;
            min-width: 210mm;
            min-height: 297mm;
            max-width: 210mm;
            max-height: 297mm;

            padding: 17mm 18mm 15mm;

            background: var(--certificate-bg);

            border: 3px double var(--certificate-primary);

            box-shadow:
                0 14px 45px rgba(15,23,42,0.12);

            color: #111827;

            display: flex;
            flex-direction: column;

            overflow: hidden;
        }

        .certificate::before {
            content: "";
            position: absolute;
            inset: 5mm;
            border: 1px solid var(--certificate-secondary);
            pointer-events: none;
        }

        .certificate-inner {
            position: relative;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .certificate-header {
            text-align: center;
        }

        .certificate-logo {
            width: 27mm;
            height: 27mm;
            object-fit: contain;
            margin-bottom: 4mm;
        }

        .certificate-school {
            font-size: 23px;
            font-weight: 800;
            color: var(--certificate-primary);
            letter-spacing: 0.4px;
            margin-bottom: 2px;
        }

        .certificate-school-subtitle {
            font-size: 11px;
            color: #4b5563;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .certificate-title-line {
            width: 50mm;
            height: 2px;
            margin: 5mm auto 3mm;
            background: var(--certificate-secondary);
        }

        .certificate-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--certificate-primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0;
        }

        .certificate-academic-info {
            text-align: center;
            margin-top: 3mm;
            font-size: 11px;
            color: #4b5563;
            font-weight: 600;
        }

        .certificate-body {
            margin-top: 9mm;
        }

        .certificate-intro {
            font-size: 14px;
            line-height: 1.9;
            text-align: justify;
            margin: 0;
        }

        .certificate-intro strong {
            color: var(--certificate-primary);
            font-weight: 800;
        }

        .record-heading {
            text-align: center;
            margin: 7mm 0 4mm;
            font-size: 13px;
            font-weight: 800;
            color: var(--certificate-primary);
            text-transform: uppercase;
            letter-spacing: 0.7px;
        }

        .certificate-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }

        .certificate-table th {
            background: var(--certificate-primary);
            color: #fff;
            border: 1px solid #111827;
            padding: 8px 7px;
            font-weight: 800;
            text-align: center;
        }

        .certificate-table td {
            border: 1px solid #6b7280;
            padding: 7px;
            vertical-align: middle;
        }

        .certificate-table td:first-child {
            width: 12%;
            text-align: center;
        }

        .certificate-table td:nth-child(2) {
            text-align: left;
            font-weight: 600;
        }

        .certificate-table td.mark-cell,
        .certificate-table td.grade-cell {
            width: 18%;
            text-align: center;
            font-weight: 700;
        }

        .certificate-summary {
            margin-top: 6mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5mm;
        }

        .summary-box {
            border: 1px solid #6b7280;
            padding: 8px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #4b5563;
            font-weight: 700;
        }

        .summary-value {
            font-size: 14px;
            font-weight: 800;
            color: var(--certificate-primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Principal Signature
        |--------------------------------------------------------------------------
        */

        .certificate-signature-area {
            margin-top: auto;
            padding-top: 12mm;
            display: flex;
            justify-content: flex-end;
        }

        .principal-signature {
            width: 62mm;
            text-align: center;
        }

        .signature-space {
            height: 17mm;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        .signature-line {
            width: 100%;
            border-bottom: 1px solid #111827;
        }

        .signature-name {
            margin-top: 3mm;
            font-size: 12px;
            font-weight: 800;
            color: #111827;
        }

        .signature-title {
            margin-top: 1mm;
            font-size: 10px;
            color: #4b5563;
            font-weight: 600;
        }

        .certificate-footer {
            margin-top: 5mm;
            text-align: center;
            font-size: 8.5px;
            color: #6b7280;
            letter-spacing: 0.2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty Result
        |--------------------------------------------------------------------------
        */

        .empty-results {
            padding: 40px;
            text-align: center;
            color: var(--muted);
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
            }

            .overlay.show {
                display: block;
            }

            .content {
                padding: 20px;
            }

            .topbar {
                padding: 0 20px;
            }
        }

        @media (max-width: 767px) {

            .topbar {
                height: 70px;
            }

            .page-title {
                font-size: 17px;
            }

            .registrar-name,
            .registrar-role {
                display: none;
            }

            .content {
                padding: 15px;
            }

            .certificate-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .certificate-wrapper {
                justify-content: flex-start;
            }

            .certificate {
                transform-origin: top left;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Print
        |--------------------------------------------------------------------------
        */

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {

            html,
            body {
                width: 210mm;
                height: 297mm;
                min-height: 297mm;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
            }

            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body * {
                visibility: hidden !important;
            }

            .certificate,
            .certificate * {
                visibility: visible !important;
            }

            .certificate {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;

                width: 210mm !important;
                height: 297mm !important;

                min-width: 210mm !important;
                min-height: 297mm !important;

                max-width: 210mm !important;
                max-height: 297mm !important;

                margin: 0 !important;

                box-shadow: none !important;

                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;

                page-break-after: avoid !important;
                break-after: avoid !important;
            }

            .certificate-wrapper {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
            }
        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
-->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <?php if ($logoDataUri !== ''): ?>

            <img
                src="<?= e($logoDataUri) ?>"
                class="brand-logo"
                alt="BKHS Logo"
            >

        <?php else: ?>

            <div
                class="brand-logo d-flex align-items-center justify-content-center"
            >
                <i class="bi bi-building text-primary"></i>
            </div>

        <?php endif; ?>

        <div>

            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                Registrar Portal
            </div>

        </div>

    </div>

    <div class="sidebar-menu">

        <div class="sidebar-section-title">
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
            href="register.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-plus"></i>
            <span>Register</span>
        </a>

        <a
            href="delete-student.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-x"></i>
            <span>Delete Student</span>
        </a>

        

        <a
            href="update-student.php"
            class="sidebar-link"
        >
            <i class="bi bi-pencil-square"></i>
            <span>Update Student</span>
        </a>

        <a
            href="certificate.php"
            class="sidebar-link active"
        >
            <i class="bi bi-award"></i>
            <span>Certificate</span>
        </a>

        <div class="sidebar-section-title mt-4">
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
            class="sidebar-link logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<div
    class="overlay"
    id="overlay"
></div>

<!--
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
-->

<div class="main-wrapper">

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Certificate
                </h1>

                <div class="page-subtitle">
                    Generate and print student academic certificates
                </div>

            </div>

        </div>

        <div class="registrar-profile">

            <div class="text-end">

                <div class="registrar-name">
                    <?= e($registrar['full_name'] ?? 'Registrar') ?>
                </div>

                <div class="registrar-role">
                    Registrar
                </div>

            </div>

            <img
                src="<?= e($photoPath) ?>"
                alt="Registrar"
                class="registrar-avatar"
            >

        </div>

    </header>

    <main class="content">

        <div class="page-intro">

            <h2>
                Student Certificate
            </h2>

            <p>
                Search for a student and generate an official academic certificate.
            </p>

        </div>

        <!--
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        -->

        <div class="card-custom search-card">

            <div class="section-title">

                <i class="bi bi-search me-2 text-primary"></i>

                Certificate Search

            </div>

            <?php if ($errorMessage !== ''): ?>

                <div class="alert alert-danger alert-custom mb-4">

                    <i class="bi bi-exclamation-circle me-2"></i>

                    <?= e($errorMessage) ?>

                </div>

            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>

                <div class="alert alert-success alert-custom mb-4">

                    <i class="bi bi-check-circle me-2"></i>

                    <?= e($successMessage) ?>

                </div>

            <?php endif; ?>

            <form
                method="POST"
                action=""
                id="certificateSearchForm"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-lg-4">

                        <label
                            for="student_code"
                            class="form-label"
                        >
                            Student Code
                        </label>

                        <input
                            type="text"
                            name="student_code"
                            id="student_code"
                            class="form-control"
                            placeholder="Example: BKHS-STU-000001"
                            value="<?= e($studentCode) ?>"
                            autocomplete="off"
                            required
                        >

                    </div>

                    <div class="col-lg-3">

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

                            <?php foreach ($academicYears as $year): ?>

                                <option
                                    value="<?= (int) $year['id'] ?>"
                                    <?= (
                                        $selectedAcademicYearId ===
                                        (int) $year['id']
                                    ) ? 'selected' : '' ?>
                                >

                                    <?= e($year['name']) ?>

                                    —
                                    <?= e(
                                        ucfirst(
                                            strtolower(
                                                (string) $year['status']
                                            )
                                        )
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-3">

                        <label
                            for="semester_id"
                            class="form-label"
                        >
                            Semester
                        </label>

                        <select
                            name="semester_id"
                            id="semester_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Semester
                            </option>

                            <?php foreach ($availableSemesters as $semester): ?>

                                <option
                                    value="<?= (int) $semester['id'] ?>"
                                    <?= (
                                        $selectedSemesterId ===
                                        (int) $semester['id']
                                    ) ? 'selected' : '' ?>
                                >

                                    <?= e($semester['name']) ?>

                                    —
                                    <?= e(
                                        ucfirst(
                                            strtolower(
                                                (string) $semester['status']
                                            )
                                        )
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-2">

                        <button
                            type="submit"
                            class="btn-primary-custom w-100"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                    </div>

                </div>

            </form>

        </div>

        <?php if ($student && $selectedSemester): ?>

            <!--
            |--------------------------------------------------------------------------
            | Student Summary
            |--------------------------------------------------------------------------
            -->

            <div class="card-custom student-summary">

                <div class="row align-items-center g-3">

                    <div class="col-auto">

                        <?php if ($studentPhotoPath !== ''): ?>

                            <img
                                src="<?= e($studentPhotoPath) ?>"
                                alt="Student"
                                class="student-photo"
                            >

                        <?php else: ?>

                            <div class="student-placeholder">

                                <i class="bi bi-person"></i>

                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="col">

                        <div class="student-name">

                            <?= e($student['full_name']) ?>

                        </div>

                        <div class="student-code">

                            <?= e($student['student_code']) ?>

                        </div>

                        <div class="student-meta">

                            <span class="meta-badge">

                                <i class="bi bi-mortarboard me-1"></i>

                                <?= e($student['grade_name']) ?>

                            </span>

                            <span class="meta-badge">

                                <i class="bi bi-diagram-3 me-1"></i>

                                Section
                                <?= e($student['section_name']) ?>

                            </span>

                            <span class="meta-badge">

                                <i class="bi bi-calendar3 me-1"></i>

                                <?= e(
                                    $selectedAcademicYear['name']
                                ) ?>

                            </span>

                            <span class="meta-badge">

                                <i class="bi bi-journal-text me-1"></i>

                                <?= e(
                                    $selectedSemester['name']
                                ) ?>

                            </span>

                        </div>

                    </div>

                </div>

            </div>

            <!--
            |--------------------------------------------------------------------------
            | Certificate Toolbar
            |--------------------------------------------------------------------------
            -->

            <div class="certificate-toolbar">

                <div class="toolbar-title">
                    Certificate Preview
                </div>

                <div class="toolbar-actions">

                    <div class="color-control">

                        <span>
                            Background
                        </span>

                        <input
                            type="color"
                            id="certificateBackground"
                            class="color-picker"
                            value="#ffffff"
                            title="Change certificate background color"
                        >

                    </div>

                    <button
                        type="button"
                        class="btn-tool"
                        id="resetBackground"
                    >

                        <i class="bi bi-arrow-counterclockwise me-1"></i>

                        Reset

                    </button>

                    <button
                        type="button"
                        class="btn-tool"
                        id="printCertificate"
                    >

                        <i class="bi bi-printer me-1"></i>

                        Print

                    </button>

                    <button
                        type="button"
                        class="btn-tool primary"
                        id="exportPdf"
                    >

                        <i class="bi bi-file-earmark-pdf me-1"></i>

                        Export PDF

                    </button>

                    <button
                        type="button"
                        class="btn-tool"
                        id="exportWord"
                    >

                        <i class="bi bi-file-earmark-word me-1"></i>

                        Export Word

                    </button>

                </div>

            </div>

            <!--
            |--------------------------------------------------------------------------
            | Certificate
            |--------------------------------------------------------------------------
            -->

            <div class="certificate-wrapper">

                <section
                    class="certificate"
                    id="certificate"
                >

                    <div class="certificate-inner">

                        <!-- Header -->

                        <div class="certificate-header">

                            <?php if ($logoDataUri !== ''): ?>

                                <img
                                    src="<?= e($logoDataUri) ?>"
                                    class="certificate-logo"
                                    alt="Bole Kale Hiwot School Logo"
                                >

                            <?php else: ?>

                                <div
                                    style="
                                        height:27mm;
                                        display:flex;
                                        align-items:center;
                                        justify-content:center;
                                        font-weight:800;
                                    "
                                >
                                    BKHS
                                </div>

                            <?php endif; ?>

                            <div class="certificate-school">
                                Bole Kale Hiwot School
                            </div>

                            <div class="certificate-school-subtitle">
                                Academic Certificate
                            </div>

                            <div class="certificate-title-line"></div>

                            <h2 class="certificate-title">
                                Certificate of Academic Record
                            </h2>

                            <div class="certificate-academic-info">

                                Academic Year:

                                <strong>
                                    <?= e(
                                        $selectedAcademicYear['name']
                                    ) ?>
                                </strong>

                                &nbsp;&nbsp; | &nbsp;&nbsp;

                                Semester:

                                <strong>
                                    <?= e(
                                        $selectedSemester['name']
                                    ) ?>
                                </strong>

                            </div>

                        </div>

                        <!-- Body -->

                        <div class="certificate-body">

                            <p class="certificate-intro">

                                This is to certify that

                                <strong>
                                    <?= e(
                                        $student['full_name']
                                    ) ?>
                                </strong>

                                is a student of

                                <strong>
                                    Bole Kale Hiwot School
                                </strong>

                                and has the following academic record for

                                <strong>
                                    <?= e(
                                        $selectedSemester['name']
                                    ) ?>
                                </strong>.

                            </p>

                            <div class="record-heading">
                                Academic Record
                            </div>

                            <table class="certificate-table">

                                <thead>

                                    <tr>

                                        <th>
                                            No.
                                        </th>

                                        <th>
                                            Subject
                                        </th>

                                        <?php if ($showGradeColumn): ?>

                                            <th>
                                                Grade
                                            </th>

                                        <?php elseif ($showMarkColumn): ?>

                                            <th>
                                                Mark
                                            </th>

                                        <?php endif; ?>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $certificateRows
                                        as $index => $row
                                    ): ?>

                                        <tr>

                                            <td>
                                                <?= $index + 1 ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $row['subject_name']
                                                ) ?>
                                            </td>

                                            <?php if ($showGradeColumn): ?>

                                                <td class="grade-cell">

                                                    <?= e(
                                                        $row['grade']
                                                    ) ?>

                                                </td>

                                            <?php elseif ($showMarkColumn): ?>

                                                <td class="mark-cell">

                                                    <?= number_format(
                                                        (float) $row['mark'],
                                                        2
                                                    ) ?>

                                                </td>

                                            <?php endif; ?>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                            <!-- Summary -->

                            <div class="certificate-summary">

                                <div class="summary-box">

                                    <span class="summary-label">
                                        Total Mark
                                    </span>

                                    <span class="summary-value">

                                        <?= number_format(
                                            $totalMark,
                                            2
                                        ) ?>

                                    </span>

                                </div>

                                <div class="summary-box">

                                    <span class="summary-label">
                                        Average
                                    </span>

                                    <span class="summary-value">

                                        <?= number_format(
                                            $averageMark,
                                            2
                                        ) ?>

                                    </span>

                                </div>

                            </div>

                        </div>

                        <!-- Principal Signature -->

                        <div class="certificate-signature-area">

                            <div class="principal-signature">

                                <div class="signature-space">

                                    <div class="signature-line"></div>

                                </div>

                                <div class="signature-name">
                                    Principal
                                </div>

                                <div class="signature-title">
                                    Principal, Bole Kale Hiwot School
                                </div>

                            </div>

                        </div>

                        <div class="certificate-footer">

                            Official Academic Record

                        </div>

                    </div>

                </section>

            </div>

        <?php elseif (
            $searchPerformed &&
            $errorMessage === ''
        ): ?>

            <div class="card-custom empty-results">

                <i class="bi bi-file-earmark-text fs-1 d-block mb-3"></i>

                <div class="fw-bold">
                    No certificate data available.
                </div>

            </div>

        <?php endif; ?>

    </main>

</div>

<script>

/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('overlay');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

if (mobileMenuBtn) {

    mobileMenuBtn.addEventListener(
        'click',
        () => {

            sidebar.classList.add('show');

            overlay.classList.add('show');

        }
    );
}

if (overlay) {

    overlay.addEventListener(
        'click',
        () => {

            sidebar.classList.remove('show');

            overlay.classList.remove('show');

        }
    );
}

/*
|--------------------------------------------------------------------------
| Academic Year -> Semester
|--------------------------------------------------------------------------
*/

const academicYearSelect =
    document.getElementById(
        'academic_year_id'
    );

const semesterSelect =
    document.getElementById(
        'semester_id'
    );

const currentSemesterId =
    <?= (int) $selectedSemesterId ?>;

async function loadSemesters(
    academicYearId,
    preserveSelected = true
) {

    if (!semesterSelect) {
        return;
    }

    semesterSelect.innerHTML =
        '<option value="">Loading semesters...</option>';

    semesterSelect.disabled = true;

    if (!academicYearId) {

        semesterSelect.innerHTML =
            '<option value="">Select Semester</option>';

        semesterSelect.disabled = false;

        return;
    }

    try {

        const response =
            await fetch(
                'certificate.php?get_semesters=1&academic_year_id=' +
                encodeURIComponent(
                    academicYearId
                ),
                {
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        if (!response.ok) {
            throw new Error(
                'Unable to load semesters.'
            );
        }

        const data =
            await response.json();

        semesterSelect.innerHTML =
            '<option value="">Select Semester</option>';

        if (
            !Array.isArray(data) ||
            data.length === 0
        ) {

            semesterSelect.innerHTML =
                '<option value="">No Active or Completed Semesters</option>';

            semesterSelect.disabled = false;

            return;
        }

        data.forEach(
            semester => {

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    semester.id;

                option.textContent =
                    semester.name +
                    ' — ' +
                    semester.status;

                if (
                    preserveSelected &&
                    String(semester.id) ===
                    String(currentSemesterId)
                ) {

                    option.selected =
                        true;
                }

                semesterSelect.appendChild(
                    option
                );
            }
        );

        semesterSelect.disabled = false;

    } catch (error) {

        semesterSelect.innerHTML =
            '<option value="">Unable to load semesters</option>';

        semesterSelect.disabled = false;
    }
}

if (academicYearSelect) {

    academicYearSelect.addEventListener(
        'change',
        function () {

            /*
             * When the user changes the academic year,
             * the old semester should not remain selected.
             */

            semesterSelect.innerHTML =
                '<option value="">Loading semesters...</option>';

            semesterSelect.disabled = true;

            loadSemesters(
                this.value,
                false
            );

        }
    );

    /*
     * If there is already a selected academic year,
     * make sure its semesters are loaded.
     */

    if (academicYearSelect.value) {

        /*
         * The PHP page already loads semesters after POST,
         * so we only need AJAX loading when necessary.
         */

        const initialSemesterCount =
            semesterSelect
                ? semesterSelect.options.length
                : 0;

        if (initialSemesterCount <= 1) {

            loadSemesters(
                academicYearSelect.value,
                true
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Certificate Background Color
|--------------------------------------------------------------------------
*/

const certificate =
    document.getElementById(
        'certificate'
    );

const colorPicker =
    document.getElementById(
        'certificateBackground'
    );

const resetBackground =
    document.getElementById(
        'resetBackground'
    );

const DEFAULT_BACKGROUND =
    '#ffffff';

function setCertificateBackground(
    color
) {

    if (!certificate) {
        return;
    }

    certificate.style.backgroundColor =
        color;

    if (colorPicker) {

        colorPicker.value =
            color;
    }
}

if (colorPicker) {

    colorPicker.addEventListener(
        'input',
        function () {

            setCertificateBackground(
                this.value
            );

        }
    );
}

if (resetBackground) {

    resetBackground.addEventListener(
        'click',
        function () {

            setCertificateBackground(
                DEFAULT_BACKGROUND
            );

        }
    );
}

/*
|--------------------------------------------------------------------------
| Print / PDF
|--------------------------------------------------------------------------
*/

const printButton =
    document.getElementById(
        'printCertificate'
    );

const pdfButton =
    document.getElementById(
        'exportPdf'
    );

if (printButton) {

    printButton.addEventListener(
        'click',
        function () {

            window.print();

        }
    );
}

if (pdfButton) {

    pdfButton.addEventListener(
        'click',
        function () {

            window.print();

        }
    );
}

/*
|--------------------------------------------------------------------------
| Word Export
|--------------------------------------------------------------------------
*/

const wordButton =
    document.getElementById(
        'exportWord'
    );

const logoData =
    <?= json_encode(
        $logoDataUri,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?>;

function exportCertificateToWord() {

    if (!certificate) {
        return;
    }

    const selectedBackground =
        colorPicker
            ? colorPicker.value
            : '#ffffff';

    const certificateContent =
        certificate.innerHTML;

    const wordHtml = `
<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>Certificate</title>

<style>

@page {
    size: A4 portrait;
    margin: 0;
}

html,
body {
    margin: 0;
    padding: 0;
    width: 210mm;
    min-height: 297mm;
}

body {
    font-family: Arial, sans-serif;
    background: #ffffff;
    color: #111111;
}

.certificate {
    position: relative;

    width: 210mm;
    height: 297mm;

    box-sizing: border-box;

    padding: 17mm 18mm 15mm;

    background: ${selectedBackground};

    border: 3px double #173b68;

    color: #111111;

    overflow: hidden;
}

.certificate::before {
    content: "";

    position: absolute;

    inset: 5mm;

    border: 1px solid #b48a2c;

    pointer-events: none;
}

.certificate-inner {
    position: relative;
    z-index: 1;

    height: 100%;

    display: flex;
    flex-direction: column;
}

.certificate-header {
    text-align: center;
}

.certificate-logo {
    width: 27mm;
    height: 27mm;
    object-fit: contain;
    margin-bottom: 4mm;
}

.certificate-school {
    font-size: 23px;
    font-weight: 800;
    color: #173b68;
    margin-bottom: 2px;
}

.certificate-school-subtitle {
    font-size: 11px;
    color: #4b5563;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.certificate-title-line {
    width: 50mm;
    height: 2px;
    margin: 5mm auto 3mm;
    background: #b48a2c;
}

.certificate-title {
    font-size: 22px;
    font-weight: 800;
    color: #173b68;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 0;
}

.certificate-academic-info {
    text-align: center;
    margin-top: 3mm;
    font-size: 11px;
    color: #4b5563;
    font-weight: 600;
}

.certificate-body {
    margin-top: 9mm;
}

.certificate-intro {
    font-size: 14px;
    line-height: 1.9;
    text-align: justify;
    margin: 0;
}

.certificate-intro strong {
    color: #173b68;
    font-weight: 800;
}

.record-heading {
    text-align: center;
    margin: 7mm 0 4mm;
    font-size: 13px;
    font-weight: 800;
    color: #173b68;
    text-transform: uppercase;
    letter-spacing: 0.7px;
}

.certificate-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}

.certificate-table th {
    background: #173b68;
    color: #ffffff;
    border: 1px solid #111111;
    padding: 8px 7px;
    font-weight: 800;
    text-align: center;
}

.certificate-table td {
    border: 1px solid #6b7280;
    padding: 7px;
}

.certificate-table td:first-child {
    width: 12%;
    text-align: center;
}

.certificate-table td:nth-child(2) {
    text-align: left;
    font-weight: 600;
}

.certificate-table td.mark-cell,
.certificate-table td.grade-cell {
    width: 18%;
    text-align: center;
    font-weight: 700;
}

.certificate-summary {
    margin-top: 6mm;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5mm;
}

.summary-box {
    border: 1px solid #6b7280;
    padding: 8px 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.summary-label {
    font-size: 10px;
    text-transform: uppercase;
    color: #4b5563;
    font-weight: 700;
}

.summary-value {
    font-size: 14px;
    font-weight: 800;
    color: #173b68;
}

.certificate-signature-area {
    margin-top: auto;
    padding-top: 12mm;
    display: flex;
    justify-content: flex-end;
}

.principal-signature {
    width: 62mm;
    text-align: center;
}

.signature-space {
    height: 17mm;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.signature-line {
    width: 100%;
    border-bottom: 1px solid #111111;
}

.signature-name {
    margin-top: 3mm;
    font-size: 12px;
    font-weight: 800;
}

.signature-title {
    margin-top: 1mm;
    font-size: 10px;
    color: #4b5563;
    font-weight: 600;
}

.certificate-footer {
    margin-top: 5mm;
    text-align: center;
    font-size: 8.5px;
    color: #6b7280;
}

</style>

</head>

<body>

<div class="certificate">

${certificateContent}

</div>

</body>

</html>
`;

    const blob =
        new Blob(
            [wordHtml],
            {
                type:
                    'application/msword'
            }
        );

    const url =
        URL.createObjectURL(
            blob
        );

    const link =
        document.createElement(
            'a'
        );

    const studentName =
        <?= json_encode(
            $student['full_name'] ?? 'Student',
            JSON_UNESCAPED_UNICODE
        ) ?>;

    const semesterName =
        <?= json_encode(
            $selectedSemester['name'] ?? 'Semester',
            JSON_UNESCAPED_UNICODE
        ) ?>;

    const safeStudentName =
        String(studentName)
            .replace(
                /[^a-z0-9]+/gi,
                '-'
            )
            .replace(
                /^-+|-+$/g,
                ''
            );

    const safeSemesterName =
        String(semesterName)
            .replace(
                /[^a-z0-9]+/gi,
                '-'
            )
            .replace(
                /^-+|-+$/g,
                ''
            );

    link.href =
        url;

    link.download =
        'BKHS-Certificate-' +
        safeStudentName +
        '-' +
        safeSemesterName +
        '.doc';

    document.body.appendChild(
        link
    );

    link.click();

    link.remove();

    setTimeout(
        function () {

            URL.revokeObjectURL(
                url
            );

        },
        1000
    );
}

if (wordButton) {

    wordButton.addEventListener(
        'click',
        exportCertificateToWord
    );
}

</script>

</body>

</html>