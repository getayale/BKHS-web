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

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['principal_student_assignment_csrf'])) {
    $_SESSION['principal_student_assignment_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['principal_student_assignment_csrf'];

/*
|--------------------------------------------------------------------------
| Principal Information
|--------------------------------------------------------------------------
*/

$principal = null;

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

$principal =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$principal) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$errors = [];

$student = null;
$registration = null;

$searchCode =
    trim($_GET['student_code'] ?? '');

$successMessage =
    $_SESSION['principal_student_assignment_success']
    ?? null;

unset(
    $_SESSION['principal_student_assignment_success']
);

/*
|--------------------------------------------------------------------------
| Current Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute();

$activeAcademicYear =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Sections A-E
|--------------------------------------------------------------------------
*/

$sections = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM sections
    WHERE code IN ('A', 'B', 'C', 'D', 'E')
    ORDER BY
        CASE code
            WHEN 'A' THEN 1
            WHEN 'B' THEN 2
            WHEN 'C' THEN 3
            WHEN 'D' THEN 4
            WHEN 'E' THEN 5
            ELSE 99
        END
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Search Student
|--------------------------------------------------------------------------
*/

if ($searchCode !== '') {

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.user_id,
            s.student_code,
            s.full_name,
            s.photo_path

        FROM students s

        WHERE s.student_code = ?

        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $searchCode
    );

    $stmt->execute();

    $student =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($student) {

        /*
        |--------------------------------------------------------------------------
        | Latest Registration
        |--------------------------------------------------------------------------
        */

        $studentId =
            (int) $student['id'];

        $stmt = $conn->prepare("
            SELECT
                sr.id,
                sr.student_id,
                sr.academic_year_id,
                sr.grade_id,
                sr.section_id,
                sr.registration_type,
                sr.result,

                ay.name AS academic_year_name,

                g.name AS grade_name,
                g.grade_number,

                sec.name AS section_name,
                sec.code AS section_code

            FROM student_registrations sr

            INNER JOIN academic_years ay
                ON ay.id = sr.academic_year_id

            INNER JOIN grades g
                ON g.id = sr.grade_id

            INNER JOIN sections sec
                ON sec.id = sr.section_id

            WHERE sr.student_id = ?

            ORDER BY sr.id DESC

            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $studentId
        );

        $stmt->execute();

        $registration =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        $postedToken =
            $_POST['csrf_token'] ?? '';

        if (
            empty($postedToken) ||
            !hash_equals(
                $_SESSION['principal_student_assignment_csrf'],
                $postedToken
            )
        ) {
            throw new Exception(
                'Invalid security token. Please refresh the page and try again.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student ID
        |--------------------------------------------------------------------------
        */

        $studentId =
            (int) ($_POST['student_id'] ?? 0);

        if ($studentId <= 0) {
            throw new Exception(
                'Invalid student.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | New Section
        |--------------------------------------------------------------------------
        */

        $newSectionId =
            (int) ($_POST['section_id'] ?? 0);

        if ($newSectionId <= 0) {
            throw new Exception(
                'Please select a new section.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Section Change Reason
        |--------------------------------------------------------------------------
        */

        $reason =
            trim($_POST['section_change_reason'] ?? '');

        if ($reason === '') {
            $reason =
                'Section changed by principal.';
        }

        /*
        |--------------------------------------------------------------------------
        | Student Identity
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

        $stmt->bind_param(
            'i',
            $studentId
        );

        $stmt->execute();

        $studentIdentity =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$studentIdentity) {
            throw new Exception(
                'Student was not found.'
            );
        }

        $studentCode =
            $studentIdentity['student_code'];

        $studentName =
            $studentIdentity['full_name'];

        /*
        |--------------------------------------------------------------------------
        | Current Latest Registration
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                sr.id,
                sr.student_id,
                sr.academic_year_id,
                sr.grade_id,
                sr.section_id,

                ay.name AS academic_year_name,

                g.name AS grade_name,

                sec.name AS section_name,
                sec.code AS section_code

            FROM student_registrations sr

            INNER JOIN academic_years ay
                ON ay.id = sr.academic_year_id

            INNER JOIN grades g
                ON g.id = sr.grade_id

            INNER JOIN sections sec
                ON sec.id = sr.section_id

            WHERE sr.student_id = ?

            ORDER BY sr.id DESC

            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $studentId
        );

        $stmt->execute();

        $currentRegistration =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$currentRegistration) {
            throw new Exception(
                'The student does not have a registration record.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Active Academic Year
        |--------------------------------------------------------------------------
        */

        if (!$activeAcademicYear) {
            throw new Exception(
                'There is no active academic year. Section transfer cannot be completed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Only Current Active Academic Year
        |--------------------------------------------------------------------------
        */

        if (
            (int) $currentRegistration['academic_year_id']
            !== (int) $activeAcademicYear['id']
        ) {
            throw new Exception(
                'This student is not registered in the current active academic year. Historical registrations cannot be changed from this page.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Current Registration Values
        |--------------------------------------------------------------------------
        */

        $registrationId =
            (int) $currentRegistration['id'];

        $academicYearId =
            (int) $currentRegistration['academic_year_id'];

        $gradeId =
            (int) $currentRegistration['grade_id'];

        $oldSectionId =
            (int) $currentRegistration['section_id'];

        /*
        |--------------------------------------------------------------------------
        | Validate New Section
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                code
            FROM sections
            WHERE id = ?
              AND code IN ('A', 'B', 'C', 'D', 'E')
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $newSectionId
        );

        $stmt->execute();

        $newSection =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$newSection) {
            throw new Exception(
                'Selected section was not found. Please select a valid section from A to E.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Same Section
        |--------------------------------------------------------------------------
        */

        if ($oldSectionId === $newSectionId) {
            throw new Exception(
                'The student is already assigned to Section ' .
                $newSection['code'] .
                '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Start Transaction
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Update ONLY Section
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE student_registrations
            SET
                section_id = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'ii',
            $newSectionId,
            $registrationId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Record Section Change History
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO student_section_changes (
                student_id,
                registration_id,
                academic_year_id,
                grade_id,
                old_section_id,
                new_section_id,
                changed_by,
                reason
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iiiiiiis',
            $studentId,
            $registrationId,
            $academicYearId,
            $gradeId,
            $oldSectionId,
            $newSectionId,
            $userId,
            $reason
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | Success Message
        |--------------------------------------------------------------------------
        */

        $_SESSION['principal_student_assignment_success'] = [
            'student_code' =>
                $studentCode,

            'student_name' =>
                $studentName,

            'academic_year' =>
                $currentRegistration['academic_year_name'],

            'grade' =>
                $currentRegistration['grade_name'],

            'old_section' =>
                $currentRegistration['section_code'],

            'new_section' =>
                $newSection['code'],

            'reason' =>
                $reason
        ];

        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */

        header(
            'Location: student-assignment.php?student_code=' .
            urlencode($studentCode)
        );

        exit;

    } catch (Throwable $exception) {

        if ($conn->in_transaction) {
            $conn->rollback();
        }

        $errors[] =
            $exception->getMessage();

        /*
        |--------------------------------------------------------------------------
        | Reload Student After Error
        |--------------------------------------------------------------------------
        */

        $searchCode =
            trim($_POST['student_code'] ?? '');

        if ($searchCode !== '') {

            $stmt = $conn->prepare("
                SELECT
                    s.id,
                    s.user_id,
                    s.student_code,
                    s.full_name,
                    s.photo_path
                FROM students s
                WHERE s.student_code = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                's',
                $searchCode
            );

            $stmt->execute();

            $student =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if ($student) {

                $studentId =
                    (int) $student['id'];

                $stmt = $conn->prepare("
                    SELECT
                        sr.id,
                        sr.student_id,
                        sr.academic_year_id,
                        sr.grade_id,
                        sr.section_id,

                        ay.name AS academic_year_name,

                        g.name AS grade_name,
                        g.grade_number,

                        sec.name AS section_name,
                        sec.code AS section_code

                    FROM student_registrations sr

                    INNER JOIN academic_years ay
                        ON ay.id = sr.academic_year_id

                    INNER JOIN grades g
                        ON g.id = sr.grade_id

                    INNER JOIN sections sec
                        ON sec.id = sr.section_id

                    WHERE sr.student_id = ?

                    ORDER BY sr.id DESC

                    LIMIT 1
                ");

                $stmt->bind_param(
                    'i',
                    $studentId
                );

                $stmt->execute();

                $registration =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Sidebar Photo
|--------------------------------------------------------------------------
*/

$sidebarPhoto = !empty($principal['photo'])
    ? '../' . ltrim($principal['photo'], '/')
    : '../public/images/default-avatar.png';

/*
|--------------------------------------------------------------------------
| Student Photo
|--------------------------------------------------------------------------
*/

$studentPhoto = null;

if ($student) {

    $studentPhoto = !empty($student['photo_path'])
        ? '../' . ltrim($student['photo_path'], '/')
        : '../public/images/default-avatar.png';
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
        Student Assignment | BKHS
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
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            color: #111827;
        }

        /* SIDEBAR */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: white;
            z-index: 1050;
            overflow-y: auto;
        }

        .brand {
            height: 74px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
        }

        .brand-title {
            font-weight: 800;
            font-size: 16px;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 22px 14px 8px;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin: 3px 10px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link-custom:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-link-custom.active {
            background: #4f46e5;
            color: white;
        }

        .nav-link-custom i {
            font-size: 17px;
            width: 20px;
        }

        .sidebar-profile {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,.08);
            background: #0f172a;
        }

        .sidebar-profile-inner {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            background: #374151;
        }

        .sidebar-profile-name {
            font-size: 13px;
            font-weight: 600;
            color: white;
        }

        .sidebar-profile-role {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        /* MAIN */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 74px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-menu {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 20px;
        }

        .page-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .topbar-user i {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        /* CONTENT */

        .content {
            padding: 30px;
            max-width: 1450px;
            margin: auto;
        }

        .card-custom {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(15,23,42,.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header-custom {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f3;
        }

        .card-header-custom h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .card-header-custom p {
            margin: 5px 0 0;
            color: #6b7280;
            font-size: 12px;
        }

        .card-body-custom {
            padding: 22px;
        }

        /* SEARCH */

        .search-box {
            display: flex;
            gap: 10px;
        }

        .search-box .form-control {
            flex: 1;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-color: #dfe3e8;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,.1);
        }

        .btn-search {
            background: #111827;
            color: white;
            border: 0;
            border-radius: 9px;
            padding: 0 21px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-search:hover {
            background: #1f2937;
            color: white;
        }

        /* STUDENT */

        .student-header {
            display: flex;
            align-items: center;
            gap: 17px;
        }

        .student-avatar {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            object-fit: cover;
            background: #eef2ff;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .student-name {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .student-code {
            color: #4f46e5;
            font-size: 13px;
            font-weight: 700;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 20px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 700;
        }

        /* INFO */

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .info-item {
            border: 1px solid #e5e7eb;
            background: #fafafa;
            border-radius: 10px;
            padding: 15px;
        }

        .info-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 700;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .current-section-box {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            padding: 18px;
            text-align: center;
        }

        .current-section-label {
            color: #6366f1;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 5px;
        }

        .current-section-value {
            color: #3730a3;
            font-size: 28px;
            font-weight: 800;
        }

        .arrow-box {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #9ca3af;
            font-size: 24px;
        }

        .new-section-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
        }

        /* INFORMATION BOXES */

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 12px;
        }

        .warning-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 12px;
        }

        .success-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .success-change {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .success-section {
            background: white;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 8px 12px;
            font-weight: 800;
        }

        /* BUTTONS */

        .submit-area {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            box-shadow: 0 4px 18px rgba(15,23,42,.04);
        }

        .btn-primary-custom {
            background: #4f46e5;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: #4338ca;
            color: white;
        }

        .btn-secondary-custom {
            background: #f3f4f6;
            color: #374151;
            border: 0;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-secondary-custom:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .history-note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 6px;
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 13px;
        }

        /* MOBILE */

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-overlay.show {
                display: block;
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
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .topbar-user span {
                display: none;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 575px) {

            .content {
                padding: 14px;
            }

            .page-subtitle {
                display: none;
            }

            .card-body-custom {
                padding: 16px;
            }

            .card-header-custom {
                padding: 17px;
            }

            .search-box {
                flex-direction: column;
            }

            .btn-search {
                min-height: 45px;
            }

            .student-header {
                align-items: flex-start;
            }

            .student-avatar {
                width: 62px;
                height: 62px;
            }

            .student-name {
                font-size: 17px;
            }

            .submit-area {
                flex-direction: column;
            }

            .submit-area button,
            .submit-area a {
                width: 100%;
                text-align: center;
            }

            .success-change {
                flex-direction: column;
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

<div
    class="mobile-overlay"
    id="mobileOverlay"
></div>

<!-- SIDEBAR -->

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
                BKHS
            </div>

            <div class="brand-subtitle">
                School Management
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main Menu
    </div>

    <a
        href="dashboard.php"
        class="nav-link-custom"
    >
        <i class="bi bi-grid-1x2"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="announcements.php"
        class="nav-link-custom"
    >
        <i class="bi bi-megaphone"></i>
        <span>Announcement</span>
    </a>

    <a
        href="subject-assignment.php"
        class="nav-link-custom"
    >
        <i class="bi bi-book"></i>
        <span>Subject Assignment</span>
    </a>

    <a
        href="homeroom-assignment.php"
        class="nav-link-custom"
    >
        <i class="bi bi-person-workspace"></i>
        <span>Homeroom Assignment</span>
    </a>

    <a
        href="student-assignment.php"
        class="nav-link-custom active"
    >
        <i class="bi bi-people"></i>
        <span>Student Assignment</span>
    </a>

    <a
        href="attendance.php"
        class="nav-link-custom"
    >
        <i class="bi bi-calendar-check"></i>
        <span>Attendance</span>
    </a>

    <a
        href="roster.php"
        class="nav-link-custom"
    >
        <i class="bi bi-list-ul"></i>
        <span>Roster</span>
    </a>

    <a
        href="certificates.php"
        class="nav-link-custom"
    >
        <i class="bi bi-award"></i>
        <span>Certificate</span>
    </a>

    <a
        href="results.php"
        class="nav-link-custom"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Result</span>
    </a>

    <div class="sidebar-section">
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
        class="nav-link-custom"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

    <div class="sidebar-profile">

        <div class="sidebar-profile-inner">

            <img
                src="<?= e($sidebarPhoto) ?>"
                alt="Principal"
                class="sidebar-avatar"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div>

                <div class="sidebar-profile-name">
                    <?= e($principal['full_name']) ?>
                </div>

                <div class="sidebar-profile-role">
                    Principal
                </div>

            </div>

        </div>

    </div>

</aside>

<!-- MAIN -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-heading">

            <button
                class="mobile-menu"
                id="mobileMenu"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Student Assignment
                </h1>

                <div class="page-subtitle">
                    Change a student's section within the current academic year
                </div>

            </div>

        </div>

        <div class="topbar-user">

            <i class="bi bi-person"></i>

            <span>
                <?= e($principal['full_name']) ?>
            </span>

        </div>

    </header>

    <div class="content">

        <!-- SUCCESS -->

        <?php if ($successMessage): ?>

            <div class="success-box">

                <div class="fw-bold">

                    <i class="bi bi-check-circle me-1"></i>

                    Section changed successfully.

                </div>

                <div class="mt-1">

                    <?= e($successMessage['student_name']) ?>

                    <span class="mx-1">—</span>

                    <strong>
                        <?= e($successMessage['student_code']) ?>
                    </strong>

                </div>

                <div class="mt-2 small">

                    <?= e($successMessage['academic_year']) ?>

                    <span class="mx-1">•</span>

                    <?= e($successMessage['grade']) ?>

                </div>

                <div class="success-change">

                    <div class="success-section">

                        Section
                        <?= e($successMessage['old_section']) ?>

                    </div>

                    <i class="bi bi-arrow-right"></i>

                    <div class="success-section">

                        Section
                        <?= e($successMessage['new_section']) ?>

                    </div>

                </div>

                <div class="mt-2 small">

                    <strong>Reason:</strong>

                    <?= e($successMessage['reason']) ?>

                </div>

            </div>

        <?php endif; ?>


        <!-- SEARCH -->

        <div class="card-custom">

            <div class="card-header-custom">

                <h3>

                    <i class="bi bi-search me-2 text-primary"></i>

                    Find Student

                </h3>

                <p>

                    Search using the student's permanent BKHS Student ID.

                </p>

            </div>

            <div class="card-body-custom">

                <form
                    method="GET"
                    action="student-assignment.php"
                >

                    <div class="search-box">

                        <input
                            type="text"
                            name="student_code"
                            class="form-control"
                            placeholder="Example: BKHS-STU-000001"
                            value="<?= e($searchCode) ?>"
                            required
                        >

                        <button
                            type="submit"
                            class="btn-search"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search Student

                        </button>

                    </div>

                    <div class="history-note">

                        The Student ID is permanent and is not changed during
                        section transfer.

                    </div>

                </form>

            </div>

        </div>


        <!-- ERRORS -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger alert-custom">

                <div class="fw-semibold mb-1">

                    <i class="bi bi-exclamation-triangle me-1"></i>

                    Section change could not be completed.

                </div>

                <?php foreach ($errors as $error): ?>

                    <div>
                        <?= e($error) ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <!-- STUDENT NOT FOUND -->

        <?php if ($searchCode !== '' && !$student): ?>

            <div class="alert alert-warning alert-custom">

                <i class="bi bi-person-x me-1"></i>

                No student was found with Student ID:

                <strong>
                    <?= e($searchCode) ?>
                </strong>

            </div>

        <?php endif; ?>


        <?php if ($student): ?>

            <!-- STUDENT HEADER -->

            <div class="card-custom">

                <div class="card-body-custom">

                    <div class="student-header">

                        <img
                            src="<?= e($studentPhoto) ?>"
                            class="student-avatar"
                            alt="Student"
                            onerror="this.src='../public/images/default-avatar.png'"
                        >

                        <div>

                            <div class="student-name">

                                <?= e($student['full_name']) ?>

                            </div>

                            <div class="student-code">

                                <?= e($student['student_code']) ?>

                            </div>

                            <?php if ($registration): ?>

                                <div class="mt-2">

                                    <span class="badge-soft">

                                        <?= e($registration['grade_name']) ?>

                                        &nbsp; / &nbsp;

                                        Section
                                        <?= e($registration['section_code']) ?>

                                    </span>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>


            <?php if (!$registration): ?>

                <div class="warning-box mb-4">

                    <i class="bi bi-exclamation-triangle me-1"></i>

                    This student does not have a registration record.
                    Section assignment cannot be performed.

                </div>

            <?php else: ?>


                <!-- CURRENT REGISTRATION -->

                <div class="card-custom">

                    <div class="card-header-custom">

                        <h3>

                            <i class="bi bi-mortarboard me-2 text-primary"></i>

                            Current Academic Placement

                        </h3>

                        <p>

                            The academic year and grade are locked.
                            Only the section can be changed.

                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="info-grid">

                            <div class="info-item">

                                <div class="info-label">
                                    Academic Year
                                </div>

                                <div class="info-value">

                                    <?= e($registration['academic_year_name']) ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">
                                    Grade
                                </div>

                                <div class="info-value">

                                    <?= e($registration['grade_name']) ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">
                                    Student ID
                                </div>

                                <div class="info-value">

                                    <?= e($student['student_code']) ?>

                                </div>

                            </div>

                        </div>

                        <div class="info-box mt-3">

                            <i class="bi bi-info-circle me-1"></i>

                            The Principal can move this student between
                            sections A–E within the current academic year.
                            The student's permanent Student ID and grade
                            will remain unchanged.

                        </div>

                    </div>

                </div>


                <!-- CHANGE SECTION -->

                <form
                    method="POST"
                    id="sectionChangeForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="student_id"
                        value="<?= (int) $student['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="student_code"
                        value="<?= e($student['student_code']) ?>"
                    >


                    <div class="card-custom">

                        <div class="card-header-custom">

                            <h3>

                                <i class="bi bi-arrow-left-right me-2 text-primary"></i>

                                Change Student Section

                            </h3>

                            <p>

                                Select the new section for this student's
                                current registration.

                            </p>

                        </div>

                        <div class="card-body-custom">

                            <div class="row g-3 align-items-stretch">

                                <!-- CURRENT -->

                                <div class="col-md-5">

                                    <div class="current-section-box h-100">

                                        <div class="current-section-label">

                                            Current Section

                                        </div>

                                        <div class="current-section-value">

                                            <?= e($registration['section_code']) ?>

                                        </div>

                                        <div class="small text-muted mt-1">

                                            <?= e($registration['section_name']) ?>

                                        </div>

                                    </div>

                                </div>


                                <!-- ARROW -->

                                <div class="col-md-2">

                                    <div class="arrow-box">

                                        <i class="bi bi-arrow-right"></i>

                                    </div>

                                </div>


                                <!-- NEW -->

                                <div class="col-md-5">

                                    <div class="new-section-box h-100">

                                        <label class="form-label">

                                            New Section

                                            <span class="text-danger">*</span>

                                        </label>

                                        <select
                                            name="section_id"
                                            class="form-select"
                                            required
                                        >

                                            <option value="">
                                                Select Section
                                            </option>

                                            <?php foreach ($sections as $sectionItem): ?>

                                                <?php
                                                $isCurrent =
                                                    (int) $sectionItem['id']
                                                    ===
                                                    (int) $registration['section_id'];
                                                ?>

                                                <option
                                                    value="<?= (int) $sectionItem['id'] ?>"
                                                    <?= $isCurrent ? 'disabled' : '' ?>
                                                >

                                                    Section
                                                    <?= e($sectionItem['code']) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <div class="history-note">

                                            The current section is disabled.
                                            Select a different section.

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- REASON -->

                            <div class="mt-4">

                                <label class="form-label">

                                    Section Change Reason

                                </label>

                                <input
                                    type="text"
                                    name="section_change_reason"
                                    class="form-control"
                                    placeholder="Example: Class balancing, parent request, administrative correction..."
                                    value="<?= e($_POST['section_change_reason'] ?? '') ?>"
                                >

                                <div class="history-note">

                                    The reason is saved in the student's
                                    section-change history.

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- IMPORTANT -->

                    <div class="info-box mb-3">

                        <i class="bi bi-shield-check me-1"></i>

                        <strong>Section transfer only:</strong>

                        This action changes only the student's section.
                        The Student ID, student information, academic year,
                        and grade will not be changed.

                    </div>


                    <!-- SUBMIT -->

                    <div class="submit-area">

                        <a
                            href="student-assignment.php"
                            class="btn-secondary-custom"
                        >

                            <i class="bi bi-x-lg me-1"></i>

                            Cancel

                        </a>

                        <button
                            type="submit"
                            class="btn-primary-custom"
                            id="changeSectionButton"
                        >

                            <i class="bi bi-arrow-left-right me-1"></i>

                            Change Section

                        </button>

                    </div>

                </form>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</main>


<script>

    /*
    |--------------------------------------------------------------------------
    | Mobile Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar =
        document.getElementById('sidebar');

    const mobileMenu =
        document.getElementById('mobileMenu');

    const mobileOverlay =
        document.getElementById('mobileOverlay');


    if (mobileMenu) {

        mobileMenu.addEventListener(
            'click',
            function () {

                sidebar.classList.toggle('open');

                mobileOverlay.classList.toggle('show');

            }
        );

    }


    if (mobileOverlay) {

        mobileOverlay.addEventListener(
            'click',
            function () {

                sidebar.classList.remove('open');

                mobileOverlay.classList.remove('show');

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Section Change Confirmation
    |--------------------------------------------------------------------------
    */

    const sectionChangeForm =
        document.getElementById('sectionChangeForm');


    if (sectionChangeForm) {

        sectionChangeForm.addEventListener(
            'submit',
            function (event) {

                const sectionSelect =
                    document.querySelector(
                        'select[name="section_id"]'
                    );

                if (
                    !sectionSelect ||
                    !sectionSelect.value
                ) {

                    event.preventDefault();

                    alert(
                        'Please select a new section.'
                    );

                    return;

                }


                const selectedText =
                    sectionSelect.options[
                        sectionSelect.selectedIndex
                    ].text;


                const confirmed =
                    confirm(
                        'Move this student to ' +
                        selectedText +
                        '?\n\n' +
                        'Only the section will be changed.'
                    );


                if (!confirmed) {

                    event.preventDefault();

                    return;

                }


                const button =
                    document.getElementById(
                        'changeSectionButton'
                    );


                if (button) {

                    button.disabled = true;

                    button.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2"></span>' +
                        'Changing Section...';

                }

            }
        );

    }

</script>

</body>

</html>