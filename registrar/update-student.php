<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'registrar'
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

function generateTemporaryPassword(int $length = 10): string
{
    $characters =
        'ABCDEFGHJKLMNPQRSTUVWXYZ' .
        'abcdefghijkmnopqrstuvwxyz' .
        '23456789';

    $password = '';

    $max = strlen($characters) - 1;

    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $max)];
    }

    return $password;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Registrar
|--------------------------------------------------------------------------
*/

$registrar = null;

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
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$registrar = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$registrar) {
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
$parent = null;

$searchCode = trim($_GET['student_code'] ?? '');

$successMessage = $_SESSION['update_student_success'] ?? null;

unset($_SESSION['update_student_success']);

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
            s.date_of_birth,
            s.gender,
            s.region,
            s.zone,
            s.woreda,
            s.fyda_number,
            s.photo_path,

            u.full_name AS user_full_name,
            u.phone AS user_phone

        FROM students s

        LEFT JOIN users u
            ON u.id = s.user_id

        WHERE s.student_code = ?

        LIMIT 1
    ");

    $stmt->bind_param('s', $searchCode);
    $stmt->execute();

    $student = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($student) {

        /*
        |--------------------------------------------------------------------------
        | Current / Latest Registration
        |--------------------------------------------------------------------------
        */

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

        $studentId = (int) $student['id'];

        $stmt->bind_param('i', $studentId);
        $stmt->execute();

        $registration =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Parent Account
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                sp.id AS relationship_id,
                sp.relationship,
                sp.is_account_access,

                p.id AS parent_id,
                p.user_id AS parent_user_id,
                p.full_name AS parent_name,
                p.phone AS parent_phone

            FROM student_parents sp

            INNER JOIN parents p
                ON p.id = sp.parent_id

            WHERE sp.student_id = ?

            ORDER BY sp.is_account_access DESC, sp.id ASC

            LIMIT 1
        ");

        $stmt->bind_param('i', $studentId);
        $stmt->execute();

        $parent =
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

        $postedToken = $_POST['csrf_token'] ?? '';

        if (
            empty($postedToken) ||
            !hash_equals($_SESSION['csrf_token'], $postedToken)
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
        | Permanent Student Code
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                user_id,
                student_code
            FROM students
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param('i', $studentId);
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

        /*
        |--------------------------------------------------------------------------
        | Student Information
        |--------------------------------------------------------------------------
        */

        $fullName =
            trim($_POST['full_name'] ?? '');

        $dateOfBirth =
            trim($_POST['date_of_birth'] ?? '');

        $gender =
            trim($_POST['gender'] ?? '');

        $region =
            trim($_POST['region'] ?? '');

        $zone =
            trim($_POST['zone'] ?? '');

        $woreda =
            trim($_POST['woreda'] ?? '');

        $fydaNumber =
            trim($_POST['fyda_number'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Academic Information
        |--------------------------------------------------------------------------
        */

        $academicYearId =
            (int) ($_POST['academic_year_id'] ?? 0);

        $gradeId =
            (int) ($_POST['grade_id'] ?? 0);

        $sectionId =
            (int) ($_POST['section_id'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Account Actions
        |--------------------------------------------------------------------------
        */

        $resetStudentPassword =
            isset($_POST['reset_student_password']) &&
            $_POST['reset_student_password'] === '1';

        $resetParentPassword =
            isset($_POST['reset_parent_password']) &&
            $_POST['reset_parent_password'] === '1';

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($fullName === '') {
            $errors[] =
                'Student full name is required.';
        }

        if ($dateOfBirth === '') {
            $errors[] =
                'Date of birth is required.';
        }

        if (!in_array(
            $gender,
            ['Male', 'Female'],
            true
        )) {
            $errors[] =
                'Please select a valid gender.';
        }

        if ($region === '') {
            $errors[] =
                'Region is required.';
        }

        if ($zone === '') {
            $errors[] =
                'Zone is required.';
        }

        if ($woreda === '') {
            $errors[] =
                'Woreda is required.';
        }

        if ($academicYearId <= 0) {
            $errors[] =
                'Please select an academic year.';
        }

        if ($gradeId <= 0) {
            $errors[] =
                'Please select a grade.';
        }

        if ($sectionId <= 0) {
            $errors[] =
                'Please select a section.';
        }

        if (!empty($errors)) {
            throw new Exception(
                implode(' ', $errors)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Academic Year
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id, name
            FROM academic_years
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $academicYearId
        );

        $stmt->execute();

        $academicYear =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$academicYear) {
            throw new Exception(
                'Selected academic year was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Grade
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name
            FROM grades
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $gradeId
        );

        $stmt->execute();

        $grade =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$grade) {
            throw new Exception(
                'Selected grade was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Section
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                code
            FROM sections
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $sectionId
        );

        $stmt->execute();

        $section =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$section) {
            throw new Exception(
                'Selected section was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Current Registration
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                academic_year_id,
                grade_id,
                section_id,
                registration_type,
                result
            FROM student_registrations
            WHERE student_id = ?
            ORDER BY id DESC
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
        | Prevent changing historical academic year accidentally
        |--------------------------------------------------------------------------
        */

        if (
            (int) $currentRegistration['academic_year_id']
            !== $academicYearId
        ) {
            throw new Exception(
                'The academic year of an existing registration cannot be changed. Create or update the appropriate academic-year registration instead.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Parent
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                sp.id AS relationship_id,
                sp.relationship,
                sp.is_account_access,

                p.id AS parent_id,
                p.user_id AS parent_user_id,
                p.full_name AS parent_name,
                p.phone AS parent_phone

            FROM student_parents sp

            INNER JOIN parents p
                ON p.id = sp.parent_id

            WHERE sp.student_id = ?

            ORDER BY sp.is_account_access DESC, sp.id ASC

            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $studentId
        );

        $stmt->execute();

        $currentParent =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Start Transaction
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Update Student
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE students
            SET
                full_name = ?,
                date_of_birth = ?,
                gender = ?,
                region = ?,
                zone = ?,
                woreda = ?,
                fyda_number = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'sssssssi',
            $fullName,
            $dateOfBirth,
            $gender,
            $region,
            $zone,
            $woreda,
            $fydaNumber,
            $studentId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Update Student User Name
        |--------------------------------------------------------------------------
        */

        if (!empty($studentIdentity['user_id'])) {

            $studentUserId =
                (int) $studentIdentity['user_id'];

            $stmt = $conn->prepare("
                UPDATE users
                SET full_name = ?
                WHERE id = ?
                  AND role = 'Student'
            ");

            $stmt->bind_param(
                'si',
                $fullName,
                $studentUserId
            );

            $stmt->execute();

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Detect Section Change
        |--------------------------------------------------------------------------
        */

        $oldSectionId =
            (int) $currentRegistration['section_id'];

        $sectionChanged =
            $oldSectionId !== $sectionId;

        /*
        |--------------------------------------------------------------------------
        | Update Registration
        |--------------------------------------------------------------------------
        */

        $registrationId =
            (int) $currentRegistration['id'];

        $stmt = $conn->prepare("
            UPDATE student_registrations
            SET
                grade_id = ?,
                section_id = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'iii',
            $gradeId,
            $sectionId,
            $registrationId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Record Section Change
        |--------------------------------------------------------------------------
        */

        if ($sectionChanged) {

            $reason =
                trim($_POST['section_change_reason'] ?? '');

            if ($reason === '') {
                $reason =
                    'Section updated by registrar.';
            }

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
                $sectionId,
                $userId,
                $reason
            );

            $stmt->execute();

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Reset Student Password
        |--------------------------------------------------------------------------
        */

        $newStudentPassword = null;

        if ($resetStudentPassword) {

            $studentUserId =
                (int) ($studentIdentity['user_id'] ?? 0);

            if ($studentUserId <= 0) {
                throw new Exception(
                    'This student does not have a user account.'
                );
            }

            $newStudentPassword =
                generateTemporaryPassword();

            $studentPasswordHash =
                password_hash(
                    $newStudentPassword,
                    PASSWORD_DEFAULT
                );

            $stmt = $conn->prepare("
                UPDATE users
                SET
                    password = ?,
                    is_logged_in = 0
                WHERE id = ?
                  AND role = 'Student'
            ");

            $stmt->bind_param(
                'si',
                $studentPasswordHash,
                $studentUserId
            );

            $stmt->execute();

            if ($stmt->affected_rows < 0) {
                throw new Exception(
                    'Student password could not be reset.'
                );
            }

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Reset Parent Password
        |--------------------------------------------------------------------------
        */

        $newParentPassword = null;

        if ($resetParentPassword) {

            if (!$currentParent) {
                throw new Exception(
                    'No parent account is connected to this student.'
                );
            }

            $parentUserId =
                (int) ($currentParent['parent_user_id'] ?? 0);

            if ($parentUserId <= 0) {
                throw new Exception(
                    'The parent does not have a user account.'
                );
            }

            $newParentPassword =
                generateTemporaryPassword();

            $parentPasswordHash =
                password_hash(
                    $newParentPassword,
                    PASSWORD_DEFAULT
                );

            $stmt = $conn->prepare("
                UPDATE users
                SET
                    password = ?,
                    is_logged_in = 0
                WHERE id = ?
                  AND role = 'Parent'
            ");

            $stmt->bind_param(
                'si',
                $parentPasswordHash,
                $parentUserId
            );

            $stmt->execute();

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | Store Success
        |--------------------------------------------------------------------------
        */

        $_SESSION['update_student_success'] = [
            'student_code' => $studentCode,
            'student_name' => $fullName,

            'academic_year' =>
                $academicYear['name'],

            'grade' =>
                $grade['name'],

            'section' =>
                $section['name'],

            'section_changed' =>
                $sectionChanged,

            'student_password' =>
                $newStudentPassword,

            'parent_password' =>
                $newParentPassword,

            'parent_name' =>
                $currentParent['parent_name'] ?? null,

            'parent_phone' =>
                $currentParent['parent_phone'] ?? null
        ];

        header(
            'Location: update-student.php?student_code=' .
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
                    s.date_of_birth,
                    s.gender,
                    s.region,
                    s.zone,
                    s.woreda,
                    s.fyda_number,
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
                        sr.registration_type,
                        sr.result,

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

                $registration =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();

                $stmt = $conn->prepare("
                    SELECT
                        sp.id AS relationship_id,
                        sp.relationship,
                        sp.is_account_access,

                        p.id AS parent_id,
                        p.user_id AS parent_user_id,
                        p.full_name AS parent_name,
                        p.phone AS parent_phone

                    FROM student_parents sp

                    INNER JOIN parents p
                        ON p.id = sp.parent_id

                    WHERE sp.student_id = ?

                    ORDER BY
                        sp.is_account_access DESC,
                        sp.id ASC

                    LIMIT 1
                ");

                $stmt->bind_param(
                    'i',
                    $studentId
                );

                $stmt->execute();

                $parent =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Dropdown Data
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
        name
    FROM academic_years
    ORDER BY
        start_year DESC,
        start_month DESC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $academicYears[] = $row;
    }
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
}

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM sections
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
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

    <title>Update Student | BKHS</title>

    <!-- FAVICON -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
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

        /* =========================================================
           SIDEBAR
        ========================================================= */

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
            background: #2563eb;
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
            background: #2563eb;
            color: white;
        }

        .nav-link-custom i {
            font-size: 17px;
            width: 20px;
        }

        .nav-group {
            margin: 0;
        }

        .nav-parent {
            width: calc(100% - 20px);
            border: 0;
            background: transparent;
            font-family: inherit;
            cursor: pointer;
        }

        .nav-parent:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-parent[aria-expanded="true"] {
            color: white;
        }

        .submenu-arrow {
            width: auto !important;
            font-size: 11px !important;
            transition: transform .2s ease;
        }

        .nav-parent[aria-expanded="true"] .submenu-arrow {
            transform: rotate(180deg);
        }

        .nav-sub-link {
            margin-left: 30px;
            margin-right: 10px;
            padding: 9px 12px;
            font-size: 13px;
            color: #9ca3af;
        }

        .nav-sub-link i {
            font-size: 15px;
        }

        .nav-sub-link:hover {
            color: white;
            background: rgba(255,255,255,.06);
        }

        .nav-sub-link.active {
            background: #2563eb;
            color: white;
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

        /* =========================================================
           MAIN
        ========================================================= */

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
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .content {
            padding: 30px;
            max-width: 1450px;
            margin: auto;
        }

        /* =========================================================
           CARDS
        ========================================================= */

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

        /* =========================================================
           FORMS
        ========================================================= */

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
            min-height: 44px;
            border-color: #dfe3e8;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .readonly-field {
            background: #f3f4f6 !important;
            color: #4b5563;
        }

        /* =========================================================
           STUDENT
        ========================================================= */

        .student-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .student-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            background: #eff6ff;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .student-name {
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 4px;
        }

        .student-code {
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 20px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 700;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .section-number {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        /* =========================================================
           ACCOUNT
        ========================================================= */

        .account-card {
            border: 1px solid #e5e7eb;
            border-radius: 11px;
            padding: 18px;
            background: #fafafa;
        }

        .account-card h4 {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .account-card p {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 15px;
        }

        .account-username {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 13px;
        }

        .reset-option {
            border: 1px solid #fed7aa;
            background: #fff7ed;
            border-radius: 9px;
            padding: 12px;
        }

        .reset-option label {
            font-size: 12px;
            font-weight: 600;
            color: #9a3412;
            cursor: pointer;
        }

        .warning-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 12px 14px;
            border-radius: 9px;
            font-size: 12px;
        }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 12px 14px;
            border-radius: 9px;
            font-size: 12px;
        }

        .success-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .password-result {
            margin-top: 10px;
            background: white;
            border: 1px dashed #10b981;
            border-radius: 8px;
            padding: 10px 12px;
            color: #111827;
            font-weight: 700;
        }

        /* =========================================================
           BUTTONS
        ========================================================= */

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
            background: #2563eb;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
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
        }

        .btn-search {
            background: #111827;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 0 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-search:hover {
            background: #1f2937;
            color: white;
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 13px;
        }

        .history-note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 6px;
        }

        /* =========================================================
           MOBILE
        ========================================================= */

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
                min-height: 44px;
            }

            .submit-area {
                flex-direction: column;
            }

            .submit-area button,
            .submit-area a {
                width: 100%;
                text-align: center;
            }

            .student-header {
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

<!-- =========================================================
     SIDEBAR
========================================================= -->

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
                Registrar Portal
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main Menu
    </div>

    <!-- DASHBOARD -->

    <a
        href="dashboard.php"
        class="nav-link-custom"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>

    <!-- STUDENTS -->

    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#studentsMenu"
            aria-expanded="true"
        >

            <i class="bi bi-people-fill"></i>

            <span class="flex-grow-1 text-start">
                Students
            </span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse show"
            id="studentsMenu"
        >

            <a
                href="register.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="update-student.php"
                class="nav-link-custom nav-sub-link active"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Student</span>
            </a>

            <a
                href="delete-student.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete Student</span>
            </a>

            <a
                href="withdraw-student.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw Student</span>
            </a>

            <a
                href="withdrawn-students.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>Withdrawn Students</span>
            </a>

        </div>

    </div>

    <!-- TEACHERS -->

    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#teachersMenu"
            aria-expanded="false"
        >

            <i class="bi bi-person-video3"></i>

            <span class="flex-grow-1 text-start">
                Teachers
            </span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse"
            id="teachersMenu"
        >

            <a
                href="teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-people"></i>
                <span>Teachers</span>
            </a>

            <a
                href="update-teacher.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Teacher</span>
            </a>

            <a
                href="withdraw-teacher.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash"></i>
                <span>Withdraw Teacher</span>
            </a>

            <a
                href="homeroom-teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Homeroom Teachers</span>
            </a>

            <a
                href="subject-teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-video2"></i>
                <span>Subject Teachers</span>
            </a>

        </div>

    </div>

    <!-- OTHER STAFF -->

    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#staffMenu"
            aria-expanded="false"
        >

            <i class="bi bi-person-badge-fill"></i>

            <span class="flex-grow-1 text-start">
                Other Staff
            </span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse"
            id="staffMenu"
        >

            <a
                href="add-staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Add Staff</span>
            </a>

            <a
                href="staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Staff</span>
            </a>

            <a
                href="withdraw-staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw Staff</span>
            </a>

        </div>

    </div>

    <!-- OTHER MAIN ITEMS -->

    <a
        href="certificate.php"
        class="nav-link-custom"
    >
        <i class="bi bi-award-fill"></i>
        <span>Certificate</span>
    </a>

    <a
        href="Roster.php"
        class="nav-link-custom"
    >
        <i class="bi bi-clipboard2-check-fill"></i>
        <span>Roster</span>
    </a>

    <a
        href="Transcript.php"
        class="nav-link-custom"
    >
        <i class="bi bi-file-earmark-text-fill"></i>
        <span>Transcript</span>
    </a>

    <!-- ACCOUNT -->

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

    <!-- SIDEBAR PROFILE -->

    <div class="sidebar-profile">

        <div class="sidebar-profile-inner">

            <?php

            $sidebarPhoto = !empty($registrar['photo'])
                ? '../' . ltrim($registrar['photo'], '/')
                : '../public/images/default-avatar.png';

            ?>

            <img
                src="<?= e($sidebarPhoto) ?>"
                alt="Registrar"
                class="sidebar-avatar"
                onerror="this.onerror=null;this.src='../public/images/default-avatar.png';"
            >

            <div>

                <div class="sidebar-profile-name">
                    <?= e($registrar['full_name']) ?>
                </div>

                <div class="sidebar-profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </div>

</aside>

<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

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
                    Update Student
                </h1>

                <div class="page-subtitle">
                    Update student information, academic placement and account passwords
                </div>

            </div>

        </div>

        <div class="topbar-user">

            <i class="bi bi-person"></i>

            <span>
                <?= e($registrar['full_name']) ?>
            </span>

        </div>

    </header>

    <div class="content">

        <?php if ($successMessage): ?>

            <div class="success-box">

                <div class="fw-bold mb-1">

                    <i class="bi bi-check-circle me-1"></i>

                    Student updated successfully.

                </div>

                <div>
                    <?= e($successMessage['student_name']) ?>
                    —
                    <?= e($successMessage['student_code']) ?>
                </div>

                <?php if ($successMessage['section_changed']): ?>

                    <div class="mt-2">

                        <strong>
                            Section changed successfully.
                        </strong>

                    </div>

                <?php endif; ?>

                <?php if (!empty($successMessage['student_password'])): ?>

                    <div class="password-result">

                        <i class="bi bi-person-lock me-1"></i>

                        Student temporary password:

                        <span class="text-primary">
                            <?= e($successMessage['student_password']) ?>
                        </span>

                    </div>

                    <div class="mt-2 small">

                        Give this temporary password to the student.
                        The student should change it after logging in.

                    </div>

                <?php endif; ?>

                <?php if (!empty($successMessage['parent_password'])): ?>

                    <div class="password-result">

                        <i class="bi bi-people me-1"></i>

                        Parent temporary password:

                        <span class="text-primary">
                            <?= e($successMessage['parent_password']) ?>
                        </span>

                    </div>

                    <div class="mt-2 small">

                        Parent username:

                        <strong>
                            <?= e($successMessage['parent_phone'] ?? '') ?>
                        </strong>

                    </div>

                <?php endif; ?>

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
                    action="update-student.php"
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

                        The Student ID is permanent and cannot be changed.

                    </div>

                </form>

            </div>

        </div>

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger alert-custom">

                <div class="fw-semibold mb-1">

                    <i class="bi bi-exclamation-triangle me-1"></i>

                    Student update could not be completed.

                </div>

                <?php foreach ($errors as $error): ?>

                    <div>
                        <?= e($error) ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

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

            <?php

            $studentPhoto = !empty($student['photo_path'])
                ? '../' . ltrim($student['photo_path'], '/')
                : '../public/images/default-avatar.png';

            ?>

            <!-- STUDENT HEADER -->

            <div class="card-custom">

                <div class="card-body-custom">

                    <div class="student-header">

                        <img
                            src="<?= e($studentPhoto) ?>"
                            class="student-avatar"
                            alt="Student"
                            onerror="this.onerror=null;this.src='../public/images/default-avatar.png';"
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

            <!-- UPDATE FORM -->

            <form
                method="POST"
                id="updateStudentForm"
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

                <!-- PERSONAL INFORMATION -->

                <div class="card-custom">

                    <div class="card-header-custom">

                        <h3>
                            <i class="bi bi-person-vcard me-2 text-primary"></i>
                            Student Information
                        </h3>

                        <p>
                            Update the student's permanent personal information.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="section-title">

                            <span class="section-number">
                                1
                            </span>

                            Personal Details

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Student ID
                                </label>

                                <input
                                    type="text"
                                    class="form-control readonly-field"
                                    value="<?= e($student['student_code']) ?>"
                                    readonly
                                >

                                <div class="history-note">
                                    Permanent ID. This cannot be changed.
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Full Name
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['full_name'] ?? $student['full_name']) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Date of Birth
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="date"
                                    name="date_of_birth"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['date_of_birth'] ?? $student['date_of_birth']) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Gender
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="gender"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select gender
                                    </option>

                                    <option
                                        value="Male"
                                        <?= ($_POST['gender'] ?? $student['gender']) === 'Male' ? 'selected' : '' ?>
                                    >
                                        Male
                                    </option>

                                    <option
                                        value="Female"
                                        <?= ($_POST['gender'] ?? $student['gender']) === 'Female' ? 'selected' : '' ?>
                                    >
                                        Female
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    FYDA Number
                                </label>

                                <input
                                    type="text"
                                    name="fyda_number"
                                    class="form-control"
                                    value="<?= e($_POST['fyda_number'] ?? $student['fyda_number']) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Region
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="region"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['region'] ?? $student['region']) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Zone
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="zone"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['zone'] ?? $student['zone']) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Woreda
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="woreda"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['woreda'] ?? $student['woreda']) ?>"
                                >

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ACADEMIC -->

                <?php if ($registration): ?>

                    <div class="card-custom">

                        <div class="card-header-custom">

                            <h3>
                                <i class="bi bi-mortarboard me-2 text-primary"></i>
                                Current Academic Registration
                            </h3>

                            <p>
                                Update the student's current grade and section.
                            </p>

                        </div>

                        <div class="card-body-custom">

                            <div class="row g-3">

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Academic Year
                                    </label>

                                    <select
                                        name="academic_year_id"
                                        class="form-select"
                                        required
                                    >

                                        <?php foreach ($academicYears as $year): ?>

                                            <option
                                                value="<?= (int) $year['id'] ?>"
                                                <?= (
                                                    (int) (
                                                        $_POST['academic_year_id']
                                                        ?? $registration['academic_year_id']
                                                    )
                                                    ===
                                                    (int) $year['id']
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >

                                                <?= e($year['name']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <div class="history-note">

                                        Existing academic year cannot be changed
                                        to another year from this page.

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Grade
                                        <span class="text-danger">*</span>
                                    </label>

                                    <select
                                        name="grade_id"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select grade
                                        </option>

                                        <?php foreach ($grades as $gradeItem): ?>

                                            <option
                                                value="<?= (int) $gradeItem['id'] ?>"
                                                <?= (
                                                    (int) (
                                                        $_POST['grade_id']
                                                        ?? $registration['grade_id']
                                                    )
                                                    ===
                                                    (int) $gradeItem['id']
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >

                                                <?= e($gradeItem['name']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Section
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

                                            <option
                                                value="<?= (int) $sectionItem['id'] ?>"
                                                <?= (
                                                    (int) (
                                                        $_POST['section_id']
                                                        ?? $registration['section_id']
                                                    )
                                                    ===
                                                    (int) $sectionItem['id']
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >

                                                <?= e($sectionItem['code']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-12">

                                    <label class="form-label">
                                        Section Change Reason
                                    </label>

                                    <input
                                        type="text"
                                        name="section_change_reason"
                                        class="form-control"
                                        placeholder="Example: Parent request, administrative correction, class balancing..."
                                        value="<?= e($_POST['section_change_reason'] ?? '') ?>"
                                    >

                                    <div class="history-note">

                                        If the section changes, this reason will
                                        be saved in the student's section history.

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>

                <!-- ACCOUNT MANAGEMENT -->

                <div class="card-custom">

                    <div class="card-header-custom">

                        <h3>
                            <i class="bi bi-shield-lock me-2 text-primary"></i>
                            Account & Password Management
                        </h3>

                        <p>
                            Reset forgotten student or parent passwords when necessary.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="row g-3">

                            <!-- STUDENT -->

                            <div class="col-lg-6">

                                <div class="account-card">

                                    <h4>

                                        <i class="bi bi-person-lock me-1"></i>

                                        Student Account

                                    </h4>

                                    <p>
                                        The student's Student ID is used as the username.
                                    </p>

                                    <div class="account-username">

                                        Username:

                                        <?= e($student['student_code']) ?>

                                    </div>

                                    <div class="reset-option">

                                        <label>

                                            <input
                                                type="checkbox"
                                                name="reset_student_password"
                                                value="1"
                                                class="form-check-input me-2"
                                            >

                                            Reset student password

                                        </label>

                                        <div class="history-note mt-2">

                                            A new temporary password will be generated.
                                            The old password will stop working.

                                        </div>

                                    </div>

                                </div>

                            </div>

                            <!-- PARENT -->

                            <div class="col-lg-6">

                                <div class="account-card">

                                    <h4>

                                        <i class="bi bi-people me-1"></i>

                                        Parent Account

                                    </h4>

                                    <?php if ($parent): ?>

                                        <p>

                                            <?= e($parent['relationship']) ?>

                                            account connected to this student.

                                        </p>

                                        <div class="account-username">

                                            Username:

                                            <?= e($parent['parent_phone']) ?>

                                        </div>

                                        <div class="reset-option">

                                            <label>

                                                <input
                                                    type="checkbox"
                                                    name="reset_parent_password"
                                                    value="1"
                                                    class="form-check-input me-2"
                                                >

                                                Reset parent password

                                            </label>

                                            <div class="history-note mt-2">

                                                A new temporary password will be
                                                generated for this parent.

                                            </div>

                                        </div>

                                    <?php else: ?>

                                        <div class="warning-box">

                                            <i class="bi bi-exclamation-triangle me-1"></i>

                                            No parent account is currently connected
                                            to this student.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                        <div class="info-box mt-3">

                            <i class="bi bi-info-circle me-1"></i>

                            Passwords are stored securely as hashes. The system
                            cannot display an old forgotten password. When a
                            password is forgotten, the registrar resets it and
                            provides the newly generated temporary password.

                        </div>

                    </div>

                </div>

                <!-- SUBMIT -->

                <div class="submit-area">

                    <a
                        href="dashboard.php"
                        class="btn-secondary-custom text-decoration-none"
                    >
                        <i class="bi bi-arrow-left me-1"></i>
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn-primary-custom"
                        id="updateButton"
                    >
                        <i class="bi bi-save me-1"></i>
                        Save Student Changes
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

</main>

<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

    const sidebar =
        document.getElementById('sidebar');

    const mobileMenu =
        document.getElementById('mobileMenu');

    const mobileOverlay =
        document.getElementById('mobileOverlay');


    if (mobileMenu) {

        mobileMenu.addEventListener('click', function () {

            sidebar.classList.toggle('open');

            mobileOverlay.classList.toggle('show');

        });

    }


    if (mobileOverlay) {

        mobileOverlay.addEventListener('click', function () {

            sidebar.classList.remove('open');

            mobileOverlay.classList.remove('show');

        });

    }


    const updateForm =
        document.getElementById('updateStudentForm');

    if (updateForm) {

        updateForm.addEventListener(
            'submit',
            function (event) {

                const resetStudent =
                    document.querySelector(
                        'input[name="reset_student_password"]'
                    );

                const resetParent =
                    document.querySelector(
                        'input[name="reset_parent_password"]'
                    );

                if (
                    (resetStudent && resetStudent.checked) ||
                    (resetParent && resetParent.checked)
                ) {

                    const confirmed =
                        confirm(
                            'A new temporary password will be generated for the selected account. The old password will stop working. Continue?'
                        );

                    if (!confirmed) {

                        event.preventDefault();

                        return;

                    }

                }

                const button =
                    document.getElementById(
                        'updateButton'
                    );

                if (button) {

                    button.disabled = true;

                    button.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2"></span>' +
                        'Updating Student...';

                }

            }
        );

    }

</script>

</body>

</html>