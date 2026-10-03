<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Registrar Authentication
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/
require_once '../config/database.php';

$conn->set_charset('utf8mb4');

$registrarId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Helper
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

/*
|--------------------------------------------------------------------------
| Registrar Profile
|--------------------------------------------------------------------------
*/
$registrar = [
    'full_name' => 'Registrar',
    'email' => '',
    'phone' => '',
    'photo' => ''
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

$stmt->bind_param('i', $registrarId);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $registrar = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/
$academicYears = [];

$result = $conn->query("
    SELECT
        id,
        name,
        start_year,
        start_month,
        start_day,
        end_year,
        end_month,
        end_day,
        status
    FROM academic_years
    ORDER BY id DESC
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
$grades = [];

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
*/
$sections = [];

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

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Selected Filters
|--------------------------------------------------------------------------
*/
$academicYearId = (int) ($_GET['academic_year_id'] ?? 0);
$gradeId = (int) ($_GET['grade_id'] ?? 0);
$sectionId = (int) ($_GET['section_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Default Academic Year
|--------------------------------------------------------------------------
*/
if ($academicYearId === 0) {

    $stmt = $conn->prepare("
        SELECT id
        FROM academic_years
        WHERE LOWER(status) = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $academicYearId = (int) $row['id'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/
$studentsPerPage = 20;

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$offset = ($page - 1) * $studentsPerPage;

/*
|--------------------------------------------------------------------------
| Student Statistics
|--------------------------------------------------------------------------
*/
$totalStudents = 0;
$maleStudents = 0;
$femaleStudents = 0;
$newStudents = 0;
$returningStudents = 0;

$totalPages = 0;

$students = [];

$filtersSelected =
    $academicYearId > 0 &&
    $gradeId > 0 &&
    $sectionId > 0;

/*
|--------------------------------------------------------------------------
| Load Statistics
|--------------------------------------------------------------------------
*/
if ($filtersSelected) {

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_students,

            SUM(
                CASE
                    WHEN LOWER(s.gender) = 'male'
                    THEN 1
                    ELSE 0
                END
            ) AS male_students,

            SUM(
                CASE
                    WHEN LOWER(s.gender) = 'female'
                    THEN 1
                    ELSE 0
                END
            ) AS female_students,

            SUM(
                CASE
                    WHEN LOWER(sr.registration_type) = 'new'
                    THEN 1
                    ELSE 0
                END
            ) AS new_students,

            SUM(
                CASE
                    WHEN LOWER(sr.registration_type) IN (
                        'returning',
                        're-registration',
                        'reregistration'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS returning_students

        FROM student_registrations sr

        INNER JOIN students s
            ON s.id = sr.student_id

        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND s.is_deleted = 0
    ");

    $stmt->bind_param(
        'iii',
        $academicYearId,
        $gradeId,
        $sectionId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($stats = $result->fetch_assoc()) {

        $totalStudents = (int) ($stats['total_students'] ?? 0);
        $maleStudents = (int) ($stats['male_students'] ?? 0);
        $femaleStudents = (int) ($stats['female_students'] ?? 0);
        $newStudents = (int) ($stats['new_students'] ?? 0);
        $returningStudents = (int) ($stats['returning_students'] ?? 0);

    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Calculate Pages
    |--------------------------------------------------------------------------
    */
    $totalPages = max(
        1,
        (int) ceil($totalStudents / $studentsPerPage)
    );

    if ($page > $totalPages) {

        $page = $totalPages;

        $offset =
            ($page - 1) *
            $studentsPerPage;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Students
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name,
            s.date_of_birth,
            s.gender,
            sr.registration_type,
            sr.registration_date
        FROM student_registrations sr

        INNER JOIN students s
            ON s.id = sr.student_id

        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND s.is_deleted = 0

        ORDER BY
            s.full_name ASC

        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param(
        'iiiii',
        $academicYearId,
        $gradeId,
        $sectionId,
        $studentsPerPage,
        $offset
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Names
|--------------------------------------------------------------------------
*/
$selectedAcademicYearName = '';
$selectedGradeName = '';
$selectedSectionName = '';

foreach ($academicYears as $year) {

    if ((int) $year['id'] === $academicYearId) {

        $selectedAcademicYearName =
            (string) $year['name'];

        break;
    }
}

foreach ($grades as $grade) {

    if ((int) $grade['id'] === $gradeId) {

        $selectedGradeName =
            (string) $grade['name'];

        break;
    }
}

foreach ($sections as $section) {

    if ((int) $section['id'] === $sectionId) {

        $selectedSectionName =
            (string) $section['name'];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/
function pageUrl(int $page): string
{
    $params = $_GET;

    $params['page'] = $page;

    return '?' . http_build_query($params);
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

    <title>Students - Registrar | BKHS</title>

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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #111827;
            font-family: 'Inter', sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
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

        .sidebar-brand-icon {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #2563eb;
            font-size: 21px;
        }

        .sidebar-brand h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .sidebar-brand small {
            color: #9ca3af;
            font-size: 11px;
        }

        .sidebar-menu {
            padding: 18px 12px 25px;
        }

        .sidebar-link,
        .sidebar-parent {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 8px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            border: 0;
            background: transparent;
            text-align: left;
        }

        .sidebar-link:hover,
        .sidebar-parent:hover {
            background: #1f2937;
            color: #fff;
        }

        .sidebar-link.active {
            background: #2563eb;
            color: #fff;
        }

        .sidebar-link i,
        .sidebar-parent i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-parent .arrow {
            margin-left: auto;
            font-size: 12px;
            transition: transform 0.2s ease;
        }

        .sidebar-parent[aria-expanded="true"] .arrow {
            transform: rotate(180deg);
        }

        .submenu {
            padding-left: 20px;
            margin-bottom: 7px;
        }

        .submenu .sidebar-link {
            font-size: 12px;
            padding: 9px 12px;
        }

        .main-wrapper {
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
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            color: #374151;
        }

        .page-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .page-subtitle {
            margin: 3px 0 0;
            color: #6b7280;
            font-size: 12px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .profile-photo {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
        }

        .profile-role {
            color: #6b7280;
            font-size: 11px;
        }

        .content {
            padding: 28px 30px 40px;
        }

        .filter-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            margin-bottom: 22px;
        }

        .filter-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .filter-title i {
            color: #2563eb;
            font-size: 18px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-select {
            min-height: 43px;
            border-color: #d1d5db;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-primary {
            background: #2563eb;
            border-color: #2563eb;
            border-radius: 8px;
            min-height: 43px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .btn-export {
            min-height: 42px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .summary-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .summary-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-info {
            color: #6b7280;
            font-size: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 13px;
        }

        .stat-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 18px;
        }

        .stat-number {
            font-size: 25px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 500;
        }

        .student-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: #f8fafc;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 14px 16px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            border-color: #f1f5f9;
            font-size: 13px;
        }

        .student-name {
            font-weight: 600;
            color: #111827;
        }

        .student-id {
            font-family: monospace;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
        }

        .badge-registration {
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 600;
            padding: 5px 8px;
            border-radius: 6px;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 50%;
            font-size: 25px;
        }

        .empty-state h5 {
            font-size: 16px;
            font-weight: 700;
        }

        .empty-state p {
            color: #6b7280;
            font-size: 12px;
            margin: 0;
        }

        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 12px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            font-size: 12px;
            color: #374151;
            border-color: #e5e7eb;
        }

        .page-item.active .page-link {
            background: #2563eb;
            border-color: #2563eb;
        }

        .page-item.disabled .page-link {
            color: #9ca3af;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1040;
        }

        @media (max-width: 1150px) {

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }

        }

        @media (max-width: 900px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-wrapper {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 22px 18px 35px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 650px) {

            .profile-text {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .content {
                padding: 18px 12px 30px;
            }

            .filter-card {
                padding: 17px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .pagination-wrapper {
                flex-direction: column;
                gap: 13px;
                align-items: center;
            }

            .student-card {
                overflow-x: auto;
            }

            .table {
                min-width: 700px;
            }

        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->
<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>
            <h5>BKHS</h5>
            <small>Registrar Portal</small>
        </div>

    </div>

    <nav class="sidebar-menu">

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <!-- Students -->
        <button
            class="sidebar-parent"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#studentsMenu"
            aria-expanded="true"
        >
            <i class="bi bi-people-fill"></i>
            <span>Students</span>
            <i class="bi bi-chevron-down arrow"></i>
        </button>

        <div
            class="collapse show submenu"
            id="studentsMenu"
        >

            <a
                href="register.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="update-student.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="delete-student.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete</span>
            </a>

            <a
                href="withdraw-student.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

            <a
                href="withdrawn-students.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>Withdrawn Students</span>
            </a>

        </div>

        <!-- Teachers -->
        <button
            class="sidebar-parent"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#teachersMenu"
            aria-expanded="false"
        >
            <i class="bi bi-person-video3"></i>
            <span>Teachers</span>
            <i class="bi bi-chevron-down arrow"></i>
        </button>

        <div
            class="collapse submenu"
            id="teachersMenu"
        >

            <a
                href="teachers.php"
                class="sidebar-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>Teachers</span>
            </a>

            <a
                href="update-teacher.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="withdraw-teacher.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

            <a
                href="homeroom-teachers.php"
                class="sidebar-link"
            >
                <i class="bi bi-house-door-fill"></i>
                <span>Homeroom Teachers</span>
            </a>

            <a
                href="subject-teachers.php"
                class="sidebar-link"
            >
                <i class="bi bi-book-fill"></i>
                <span>Subject Teachers</span>
            </a>

        </div>

        <!-- Other Staff -->
        <button
            class="sidebar-parent"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#staffMenu"
            aria-expanded="false"
        >
            <i class="bi bi-person-badge-fill"></i>
            <span>Other Staff</span>
            <i class="bi bi-chevron-down arrow"></i>
        </button>

        <div
            class="collapse submenu"
            id="staffMenu"
        >

            <a
                href="add-staff.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Add Staff</span>
            </a>

            <a
                href="staff.php"
                class="sidebar-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>Staff</span>
            </a>

            <a
                href="withdraw-staff.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

        </div>

        <a
            href="certificate.php"
            class="sidebar-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="sidebar-link"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="sidebar-link"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<!-- Main Wrapper -->
<div class="main-wrapper">

    <!-- Topbar -->
    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Students
                </h1>

                <p class="page-subtitle">
                    View students by academic year, grade and section
                </p>

            </div>

        </div>

        <div class="profile">

            <img
                src="<?= e(
                    !empty($registrar['photo'])
                        ? '../public/uploads/registrars/' . ltrim($registrar['photo'], '/')
                        : '../public/images/default-avatar.png'
                ) ?>"
                alt="Registrar"
                class="profile-photo"
            >

            <div class="profile-text">

                <div class="profile-name">
                    <?= e($registrar['full_name']) ?>
                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>

    <!-- Content -->
    <main class="content">

        <!-- Filters -->
        <div class="filter-card">

            <div class="filter-title">
                <i class="bi bi-funnel-fill"></i>
                <span>Select Student List</span>
            </div>

            <form
                method="GET"
                action="students.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-lg-4 col-md-6">

                        <label class="form-label">
                            Academic Year
                        </label>

                        <select
                            name="academic_year_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Academic Year
                            </option>

                            <?php foreach ($academicYears as $year): ?>

                                <option
                                    value="<?= (int) $year['id'] ?>"
                                    <?= $academicYearId === (int) $year['id'] ? 'selected' : '' ?>
                                >

                                    <?= e($year['name']) ?>

                                    <?php if (strtolower((string) $year['status']) === 'active'): ?>
                                        (Active)
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-3 col-md-6">

                        <label class="form-label">
                            Grade
                        </label>

                        <select
                            name="grade_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Grade
                            </option>

                            <?php foreach ($grades as $grade): ?>

                                <option
                                    value="<?= (int) $grade['id'] ?>"
                                    <?= $gradeId === (int) $grade['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($grade['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-3 col-md-6">

                        <label class="form-label">
                            Section
                        </label>

                        <select
                            name="section_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Section
                            </option>

                            <?php foreach ($sections as $section): ?>

                                <option
                                    value="<?= (int) $section['id'] ?>"
                                    <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($section['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-2 col-md-6">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >
                            <i class="bi bi-search me-1"></i>
                            View Students
                        </button>

                    </div>

                </div>

            </form>

        </div>

        <?php if ($filtersSelected): ?>

            <!-- Class Summary -->
            <div class="summary-card">

                <div
                    class="d-flex flex-wrap justify-content-between align-items-center gap-3"
                >

                    <div>

                        <div class="summary-title">

                            <?= e($selectedGradeName) ?>
                            -
                            <?= e($selectedSectionName) ?>

                        </div>

                        <div class="summary-info">

                            Academic Year:
                            <strong>
                                <?= e($selectedAcademicYearName) ?>
                            </strong>

                        </div>

                    </div>

                    <a
                        href="studentslist_export.php?<?= e(
                            http_build_query([
                                'academic_year_id' => $academicYearId,
                                'grade_id' => $gradeId,
                                'section_id' => $sectionId
                            ])
                        ) ?>"
                        class="btn btn-success btn-export"
                    >
                        <i class="bi bi-file-earmark-excel-fill me-1"></i>
                        Export
                    </a>

                </div>

            </div>

            <!-- Statistics -->
            <div class="stats-grid">

                <!-- Total -->
                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= number_format($totalStudents) ?>
                    </div>

                    <div class="stat-label">
                        Total Students
                    </div>

                </div>

                <!-- Male -->
                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon">
                            <i class="bi bi-gender-male"></i>
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= number_format($maleStudents) ?>
                    </div>

                    <div class="stat-label">
                        Male Students
                    </div>

                </div>

                <!-- Female -->
                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon">
                            <i class="bi bi-gender-female"></i>
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= number_format($femaleStudents) ?>
                    </div>

                    <div class="stat-label">
                        Female Students
                    </div>

                </div>

                <!-- New -->
                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= number_format($newStudents) ?>
                    </div>

                    <div class="stat-label">
                        New Students
                    </div>

                </div>

                <!-- Returning -->
                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>

                    </div>

                    <div class="stat-number">
                        <?= number_format($returningStudents) ?>
                    </div>

                    <div class="stat-label">
                        Returning Students
                    </div>

                </div>

            </div>

            <!-- Student Table -->
            <div class="student-card">

                <?php if (!empty($students)): ?>

                    <div class="table-responsive">

                        <table class="table align-middle">

                            <thead>

                                <tr>

                                    <th style="width: 70px;">
                                        No.
                                    </th>

                                    <th>
                                        Student ID
                                    </th>

                                    <th>
                                        Student Name
                                    </th>

                                    <th>
                                        Gender
                                    </th>

                                    <th>
                                        Date of Birth
                                    </th>

                                    <th>
                                        Registration
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($students as $index => $student): ?>

                                    <tr>

                                        <td class="text-center">

                                            <?= $offset + $index + 1 ?>

                                        </td>

                                        <td>

                                            <span class="student-id">
                                                <?= e($student['student_code']) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span class="student-name">
                                                <?= e($student['full_name']) ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?= e($student['gender']) ?>
                                        </td>

                                        <td>
                                            <?= e($student['date_of_birth']) ?>
                                        </td>

                                        <td>

                                            <span class="badge-registration">

                                                <?= e(
                                                    $student['registration_type']
                                                ) ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <!-- Pagination -->
                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing

                            <strong>
                                <?= $offset + 1 ?>
                            </strong>

                            to

                            <strong>
                                <?= min(
                                    $offset + count($students),
                                    $totalStudents
                                ) ?>
                            </strong>

                            of

                            <strong>
                                <?= number_format($totalStudents) ?>
                            </strong>

                            students

                        </div>

                        <?php if ($totalPages > 1): ?>

                            <nav>

                                <ul class="pagination pagination-sm">

                                    <li
                                        class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                    >

                                        <?php if ($page > 1): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(pageUrl($page - 1)) ?>"
                                            >
                                                <i class="bi bi-chevron-left"></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i class="bi bi-chevron-left"></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>

                                    <?php

                                    $startPage =
                                        max(1, $page - 2);

                                    $endPage =
                                        min(
                                            $totalPages,
                                            $page + 2
                                        );

                                    for (
                                        $pageNumber = $startPage;
                                        $pageNumber <= $endPage;
                                        $pageNumber++
                                    ):
                                    ?>

                                        <li
                                            class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"
                                        >

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl($pageNumber)
                                                ) ?>"
                                            >
                                                <?= $pageNumber ?>
                                            </a>

                                        </li>

                                    <?php endfor; ?>

                                    <li
                                        class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                    >

                                        <?php if ($page < $totalPages): ?>

                                            <a
                                                class="page-link"
                                                href="<?= e(
                                                    pageUrl($page + 1)
                                                ) ?>"
                                            >
                                                <i class="bi bi-chevron-right"></i>
                                            </a>

                                        <?php else: ?>

                                            <span class="page-link">
                                                <i class="bi bi-chevron-right"></i>
                                            </span>

                                        <?php endif; ?>

                                    </li>

                                </ul>

                            </nav>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <h5>
                            No Students Found
                        </h5>

                        <p>
                            There are no students registered in
                            <?= e($selectedGradeName) ?>,
                            Section <?= e($selectedSectionName) ?>
                            for <?= e($selectedAcademicYearName) ?>.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <!-- Initial State -->
            <div class="student-card">

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-funnel"></i>
                    </div>

                    <h5>
                        Select Academic Year, Grade and Section
                    </h5>

                    <p>
                        Select all three options above to view the
                        students registered in that class.
                    </p>

                </div>

            </div>

        <?php endif; ?>

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

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');

    mobileMenuBtn.addEventListener(
        'click',
        function () {

            sidebar.classList.add('show');

            overlay.classList.add('show');

        }
    );

    overlay.addEventListener(
        'click',
        function () {

            sidebar.classList.remove('show');

            overlay.classList.remove('show');

        }
    );

</script>

</body>

</html>