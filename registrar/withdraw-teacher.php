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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
        return 'R';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return 'R';
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 1));
    }

    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$registrarName = (string) ($_SESSION['full_name'] ?? 'Registrar');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute();

$result = $stmt->get_result();

$activeAcademicYear = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Search Teachers
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));

$teachers = [];

if ($search !== '') {

    $searchLike = '%' . $search . '%';

    $stmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            t.id AS teacher_id,
            t.fayda_number,
            t.employment_status
        FROM users u
        INNER JOIN teachers t
            ON t.user_id = u.id
        WHERE LOWER(u.role) = 'teacher'
          AND COALESCE(u.is_deleted, 0) = 0
          AND (
                u.full_name LIKE ?
                OR u.email LIKE ?
                OR u.phone LIKE ?
                OR t.fayda_number LIKE ?
          )
        ORDER BY u.full_name ASC
        LIMIT 50
    ");

    $stmt->bind_param(
        'ssss',
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Teacher
|--------------------------------------------------------------------------
*/

$selectedTeacher = null;

$selectedTeacherId = (int) ($_GET['teacher_id'] ?? 0);

if ($selectedTeacherId > 0) {

    $stmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            t.id AS teacher_id,
            t.fayda_number,
            t.employment_status,
            t.gender,
            t.education_level,
            t.department,
            t.college_university_institution
        FROM users u
        INNER JOIN teachers t
            ON t.user_id = u.id
        WHERE t.id = ?
          AND LOWER(u.role) = 'teacher'
          AND COALESCE(u.is_deleted, 0) = 0
        LIMIT 1
    ");

    $stmt->bind_param('i', $selectedTeacherId);

    $stmt->execute();

    $result = $stmt->get_result();

    $selectedTeacher = $result->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Process Withdrawal
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Validate CSRF
    |--------------------------------------------------------------------------
    */

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedCsrf)) {

        $_SESSION['error'] =
            'Invalid security token. Please try again.';

        header('Location: withdraw-teacher.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Submitted Values
    |--------------------------------------------------------------------------
    */

    $teacherId = (int) ($_POST['teacher_id'] ?? 0);

    $withdrawalDate = trim(
        (string) ($_POST['withdrawal_date'] ?? '')
    );

    $reason = trim(
        (string) ($_POST['reason'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | Validate Academic Year
    |--------------------------------------------------------------------------
    */

    if (!$activeAcademicYear) {

        $_SESSION['error'] =
            'There is no active academic year.';

        header('Location: withdraw-teacher.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Teacher
    |--------------------------------------------------------------------------
    */

    if ($teacherId <= 0) {

        $_SESSION['error'] =
            'Please select a teacher.';

        header('Location: withdraw-teacher.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Withdrawal Date
    |--------------------------------------------------------------------------
    */

    if ($withdrawalDate === '') {

        $_SESSION['error'] =
            'Please select the withdrawal date.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $withdrawalDate
    );

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $withdrawalDate
    ) {

        $_SESSION['error'] =
            'Invalid withdrawal date.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Reason
    |--------------------------------------------------------------------------
    */

    if ($reason === '') {

        $_SESSION['error'] =
            'Please enter the withdrawal reason.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    if (mb_strlen($reason) < 3) {

        $_SESSION['error'] =
            'Withdrawal reason is too short.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Teacher
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            t.id AS teacher_id,
            t.user_id,
            t.employment_status,
            u.full_name,
            u.role,
            u.is_deleted
        FROM teachers t
        INNER JOIN users u
            ON u.id = t.user_id
        WHERE t.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $teacherId);

    $stmt->execute();

    $teacherResult = $stmt->get_result();

    $teacher = $teacherResult->fetch_assoc();

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Teacher Not Found
    |--------------------------------------------------------------------------
    */

    if (!$teacher) {

        $_SESSION['error'] =
            'Teacher not found.';

        header('Location: withdraw-teacher.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Teacher Account
    |--------------------------------------------------------------------------
    */

    if (
        strtolower((string) $teacher['role']) !== 'teacher' ||
        (int) $teacher['is_deleted'] === 1
    ) {

        $_SESSION['error'] =
            'The selected account is not an active teacher account.';

        header('Location: withdraw-teacher.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Check Employment Status
    |--------------------------------------------------------------------------
    */

    if ($teacher['employment_status'] === 'Withdrawn') {

        $_SESSION['error'] =
            'This teacher has already been withdrawn.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Academic Year ID
    |--------------------------------------------------------------------------
    */

    $academicYearId = (int) $activeAcademicYear['id'];

    /*
    |--------------------------------------------------------------------------
    | Check Existing Withdrawal Record
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            id
        FROM teacher_withdrawals
        WHERE teacher_id = ?
          AND academic_year_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'ii',
        $teacherId,
        $academicYearId
    );

    $stmt->execute();

    $existingResult = $stmt->get_result();

    $alreadyWithdrawn = $existingResult->fetch_assoc();

    $stmt->close();

    if ($alreadyWithdrawn) {

        $_SESSION['error'] =
            'This teacher already has a withdrawal record for the active academic year.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Perform Withdrawal
    |--------------------------------------------------------------------------
    */

    try {

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Insert Withdrawal History
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO teacher_withdrawals (
                teacher_id,
                academic_year_id,
                withdrawal_date,
                reason,
                withdrawn_by
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $registrarId = (int) $_SESSION['user_id'];

        $stmt->bind_param(
            'iissi',
            $teacherId,
            $academicYearId,
            $withdrawalDate,
            $reason,
            $registrarId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Update Teacher Employment Status
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE teachers
            SET employment_status = 'Withdrawn'
            WHERE id = ?
              AND employment_status = 'Active'
        ");

        $stmt->bind_param(
            'i',
            $teacherId
        );

        $stmt->execute();

        if ($stmt->affected_rows !== 1) {

            throw new RuntimeException(
                'Teacher status could not be updated.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        $_SESSION['success'] =
            'Teacher ' .
            (string) $teacher['full_name'] .
            ' has been withdrawn successfully.';

        /*
        |--------------------------------------------------------------------------
        | Go To Withdrawn Teachers List
        |--------------------------------------------------------------------------
        */

        header('Location: withdrawn-teachers.php');
        exit;

    } catch (Throwable $exception) {

        $conn->rollback();

        $_SESSION['error'] =
            'Withdrawal failed. Please try again.';

        header(
            'Location: withdraw-teacher.php?teacher_id=' .
            $teacherId
        );

        exit;
    }
}

$initials = getInitials($registrarName);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Withdraw Teacher | Registrar | BKHS</title>

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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
        }

        .brand {
            height: 78px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2563eb;
            font-size: 20px;
            margin-right: 12px;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 700;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 10px 30px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 3px;
            border-radius: 8px;
            color: #9ca3af;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link-custom i {
            width: 22px;
            font-size: 16px;
            margin-right: 10px;
        }

        .nav-link-custom:hover {
            background: rgba(255,255,255,.07);
            color: #fff;
        }

        .nav-link-custom.active {
            background: #2563eb;
            color: #fff;
        }

        .nav-group {
            margin: 0;
        }

        .nav-parent {
            width: 100%;
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
            margin-left: auto !important;
            margin-right: 0 !important;
            transition: transform .2s ease;
        }

        .nav-parent[aria-expanded="true"] .submenu-arrow {
            transform: rotate(180deg);
        }

        .nav-sub-link {
            margin-left: 30px;
            margin-right: 10px;
            width: calc(100% - 40px);
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

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
        }

        .profile-role {
            font-size: 11px;
            color: #6b7280;
        }

        .content {
            padding: 28px;
        }

        .card-box {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .section-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .section-description {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 22px;
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
            border-color: #d1d5db;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        textarea.form-control {
            min-height: 120px;
        }

        .teacher-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            margin-top: 18px;
            background: #f8fafc;
        }

        .teacher-name {
            font-size: 15px;
            font-weight: 700;
        }

        .teacher-meta {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-withdrawn {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-primary-custom {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            min-height: 44px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 0 18px;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .btn-danger-custom {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
            min-height: 44px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 0 18px;
        }

        .btn-danger-custom:hover {
            background: #b91c1c;
            border-color: #b91c1c;
            color: #fff;
        }

        .search-result {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px;
            margin-top: 10px;
            background: #fff;
        }

        .search-result:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }

        .search-result-name {
            font-size: 13px;
            font-weight: 700;
        }

        .search-result-meta {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .search-result .btn {
            font-size: 11px;
        }

        .year-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 13px 15px;
            color: #1e40af;
            font-size: 12px;
            margin-bottom: 20px;
        }

        .warning-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            border-radius: 10px;
            padding: 14px;
            font-size: 12px;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1030;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-right: 12px;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 18px;
            }
        }

        @media (max-width: 575px) {

            .profile-name,
            .profile-role {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .card-box {
                padding: 18px;
            }
        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="toggleSidebar()"
></div>

<aside class="sidebar" id="sidebar">

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

    <nav class="sidebar-nav">

        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="nav-link-custom"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <!-- Students -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#studentsMenu"
                aria-expanded="false"
                aria-controls="studentsMenu"
            >

                <i class="bi bi-people-fill"></i>

                <span>Students</span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse"
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
                    class="nav-link-custom nav-sub-link"
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

        <!-- Teachers -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#teachersMenu"
                aria-expanded="true"
                aria-controls="teachersMenu"
            >

                <i class="bi bi-person-video3"></i>

                <span>Teachers</span>

                <i class="bi bi-chevron-down submenu-arrow"></i>

            </button>

            <div
                class="collapse show"
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
                    class="nav-link-custom nav-sub-link active"
                >
                    <i class="bi bi-person-dash"></i>
                    <span>Withdraw Teacher</span>
                </a>

                <a
                    href="withdrawn-teachers.php"
                    class="nav-link-custom nav-sub-link"
                >
                    <i class="bi bi-person-check-fill"></i>
                    <span>Withdrawn Teachers</span>
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

        <!-- Other Staff -->

        <div class="nav-group">

            <button
                type="button"
                class="nav-link-custom nav-parent"
                data-bs-toggle="collapse"
                data-bs-target="#staffMenu"
                aria-expanded="false"
                aria-controls="staffMenu"
            >

                <i class="bi bi-person-badge-fill"></i>

                <span>Other Staff</span>

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

        <!-- Certificate -->

        <a
            href="certificate.php"
            class="nav-link-custom"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <!-- Roster -->

        <a
            href="Roster.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <!-- Transcript -->

        <a
            href="Transcript.php"
            class="nav-link-custom"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <!-- Profile -->

        <a
            href="profile.php"
            class="nav-link-custom"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <!-- Logout -->

        <a
            href="../auth/logout.php"
            class="nav-link-custom"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                onclick="toggleSidebar()"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Withdraw Teacher
                </h1>

                <div class="page-subtitle">
                    Withdraw a teacher from the current academic year
                </div>

            </div>

        </div>

        <div class="profile">

            <div class="text-end">

                <div class="profile-name">
                    <?= e($registrarName) ?>
                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

            <div class="avatar">
                <?= e($initials) ?>
            </div>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <!-- Success -->

        <?php if ($success !== ''): ?>

            <div class="alert alert-success alert-dismissible fade show">

                <i class="bi bi-check-circle-fill me-2"></i>

                <?= e($success) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Error -->

        <?php if ($error !== ''): ?>

            <div class="alert alert-danger alert-dismissible fade show">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($error) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- No Active Academic Year -->

        <?php if (!$activeAcademicYear): ?>

            <div class="card-box">

                <div class="warning-box">

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    There is currently no active academic year.
                    Teacher withdrawal cannot be performed until an
                    academic year is activated.

                </div>

            </div>

        <?php else: ?>

            <!-- Active Academic Year -->

            <div class="year-box">

                <i class="bi bi-calendar3 me-2"></i>

                <strong>
                    Active Academic Year:
                </strong>

                <?= e((string) $activeAcademicYear['name']) ?>

            </div>

            <div class="row g-4">

                <!-- Find Teacher -->

                <div class="col-lg-5">

                    <div class="card-box">

                        <div class="section-title">

                            <i class="bi bi-search me-2 text-primary"></i>

                            Find Teacher

                        </div>

                        <div class="section-description">

                            Search by teacher name, email, phone, or Fayda number.

                        </div>

                        <form method="get">

                            <div class="input-group">

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search teacher..."
                                    value="<?= e($search) ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-primary-custom"
                                >

                                    <i class="bi bi-search me-1"></i>

                                    Search

                                </button>

                            </div>

                        </form>

                        <?php if ($search !== ''): ?>

                            <div class="mt-3">

                                <?php if (!$teachers): ?>

                                    <div class="text-center py-4 text-muted">

                                        <i class="bi bi-person-x fs-3"></i>

                                        <div class="mt-2 small">
                                            No teachers found.
                                        </div>

                                    </div>

                                <?php else: ?>

                                    <?php foreach ($teachers as $teacher): ?>

                                        <div class="search-result">

                                            <div class="d-flex justify-content-between align-items-start gap-3">

                                                <div>

                                                    <div class="search-result-name">

                                                        <?= e(
                                                            (string) $teacher['full_name']
                                                        ) ?>

                                                    </div>

                                                    <div class="search-result-meta">

                                                        <?= e(
                                                            (string) $teacher['email']
                                                        ) ?>

                                                    </div>

                                                    <div class="search-result-meta">

                                                        <?= e(
                                                            (string) $teacher['phone']
                                                        ) ?>

                                                    </div>

                                                </div>

                                                <div>

                                                    <?php if (
                                                        $teacher['employment_status'] === 'Withdrawn'
                                                    ): ?>

                                                        <span class="status-badge status-withdrawn">

                                                            Withdrawn

                                                        </span>

                                                    <?php else: ?>

                                                        <a
                                                            href="withdraw-teacher.php?search=<?= urlencode($search) ?>&teacher_id=<?= (int) $teacher['teacher_id'] ?>"
                                                            class="btn btn-sm btn-outline-primary mt-2"
                                                        >

                                                            Select

                                                        </a>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

                <!-- Withdrawal Information -->

                <div class="col-lg-7">

                    <div class="card-box">

                        <div class="section-title">

                            <i class="bi bi-person-dash-fill me-2 text-danger"></i>

                            Withdrawal Information

                        </div>

                        <div class="section-description">

                            Select an active teacher and provide the withdrawal details.

                        </div>

                        <?php if (!$selectedTeacher): ?>

                            <div class="text-center py-5 text-muted">

                                <i class="bi bi-person-search fs-1"></i>

                                <div class="mt-3 fw-semibold">

                                    No teacher selected

                                </div>

                                <div class="small mt-1">

                                    Search for a teacher and select them to continue.

                                </div>

                            </div>

                        <?php elseif (
                            $selectedTeacher['employment_status'] === 'Withdrawn'
                        ): ?>

                            <div class="warning-box">

                                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                                This teacher has already been withdrawn and cannot
                                be withdrawn again.

                            </div>

                        <?php else: ?>

                            <!-- Selected Teacher -->

                            <div class="teacher-card">

                                <div class="d-flex justify-content-between align-items-start">

                                    <div>

                                        <div class="teacher-name">

                                            <?= e(
                                                (string) $selectedTeacher['full_name']
                                            ) ?>

                                        </div>

                                        <div class="teacher-meta">

                                            <?= e(
                                                (string) $selectedTeacher['email']
                                            ) ?>

                                        </div>

                                        <div class="teacher-meta">

                                            <?= e(
                                                (string) $selectedTeacher['phone']
                                            ) ?>

                                        </div>

                                    </div>

                                    <span class="status-badge status-active">

                                        Active

                                    </span>

                                </div>

                                <hr>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <div class="text-muted small">
                                            Fayda Number
                                        </div>

                                        <div class="fw-semibold small">

                                            <?= e(
                                                (string) $selectedTeacher['fayda_number']
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="text-muted small">
                                            Education
                                        </div>

                                        <div class="fw-semibold small">

                                            <?= e(
                                                (string) (
                                                    $selectedTeacher['education_level']
                                                    ?? 'Not provided'
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="text-muted small">
                                            Institution
                                        </div>

                                        <div class="fw-semibold small">

                                            <?= e(
                                                (string) (
                                                    $selectedTeacher[
                                                        'college_university_institution'
                                                    ]
                                                    ?? 'Not provided'
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="text-muted small">
                                            Department
                                        </div>

                                        <div class="fw-semibold small">

                                            <?= e(
                                                (string) (
                                                    $selectedTeacher['department']
                                                    ?? 'Not provided'
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                            <!-- Withdrawal Form -->

                            <form
                                method="post"
                                class="mt-4"
                                onsubmit="return confirmWithdrawal();"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="teacher_id"
                                    value="<?= (int) $selectedTeacher['teacher_id'] ?>"
                                >

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <label
                                            for="withdrawal_date"
                                            class="form-label"
                                        >

                                            Withdrawal Date

                                            <span class="text-danger">
                                                *
                                            </span>

                                        </label>

                                        <input
                                            type="date"
                                            id="withdrawal_date"
                                            name="withdrawal_date"
                                            class="form-control"
                                            value="<?= date('Y-m-d') ?>"
                                            required
                                        >

                                    </div>

                                    <div class="col-12">

                                        <label
                                            for="reason"
                                            class="form-label"
                                        >

                                            Withdrawal Reason

                                            <span class="text-danger">
                                                *
                                            </span>

                                        </label>

                                        <textarea
                                            id="reason"
                                            name="reason"
                                            class="form-control"
                                            placeholder="Enter the reason for withdrawal..."
                                            required
                                        ></textarea>

                                        <div class="form-text">

                                            This reason will be permanently stored
                                            in the withdrawal history.

                                        </div>

                                    </div>

                                </div>

                                <div class="warning-box mt-4">

                                    <i class="bi bi-shield-exclamation me-2"></i>

                                    <strong>Important:</strong>

                                    After withdrawal, this teacher will be marked
                                    as <strong>Withdrawn</strong>. Their historical
                                    records will remain in the system.

                                </div>

                                <div class="d-flex justify-content-end mt-4">

                                    <button
                                        type="submit"
                                        class="btn btn-danger-custom"
                                    >

                                        <i class="bi bi-person-dash-fill me-2"></i>

                                        Withdraw Teacher

                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endif; ?>

    </div>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

function toggleSidebar() {

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

function confirmWithdrawal() {

    return confirm(
        'Are you sure you want to withdraw this teacher?\n\n' +
        'The teacher will be marked as Withdrawn and will no longer be treated as an active teacher.'
    );
}

</script>

</body>

</html>