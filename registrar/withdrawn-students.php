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

$registrarId = (int) $_SESSION['user_id'];

$search = trim((string) ($_GET['search'] ?? ''));
$academicYearFilter = (int) ($_GET['academic_year_id'] ?? 0);
$gradeFilter = (int) ($_GET['grade_id'] ?? 0);
$sectionFilter = (int) ($_GET['section_id'] ?? 0);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$registrar = [
    'full_name' => $_SESSION['full_name'] ?? 'Registrar',
    'photo_path' => null
];

/*
 * Registrar profile.
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

if ($stmt) {
    $stmt->bind_param('i', $registrarId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $registrar['full_name'] = $row['full_name'];
        $registrar['photo_path'] = $row['photo'];
    }

    $stmt->close();
}

/*
 * Academic years.
 */
$academicYears = [];

$result = $conn->query("
    SELECT
        id,
        name,
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
 * Grades.
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
 * Sections.
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
 * Build filters.
 */
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "
        (
            s.student_code LIKE ?
            OR s.full_name LIKE ?
            OR sw.reason LIKE ?
            OR u.full_name LIKE ?
        )
    ";

    $searchLike = '%' . $search . '%';

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;

    $types .= 'ssss';
}

if ($academicYearFilter > 0) {
    $where[] = 'sw.academic_year_id = ?';
    $params[] = $academicYearFilter;
    $types .= 'i';
}

if ($gradeFilter > 0) {
    $where[] = 'sw.grade_id = ?';
    $params[] = $gradeFilter;
    $types .= 'i';
}

if ($sectionFilter > 0) {
    $where[] = 'sw.section_id = ?';
    $params[] = $sectionFilter;
    $types .= 'i';
}

$whereSql = '';

if ($where) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
 * Count total records.
 */
$totalRecords = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM student_withdrawals sw
    INNER JOIN students s
        ON s.id = sw.student_id
    INNER JOIN academic_years ay
        ON ay.id = sw.academic_year_id
    INNER JOIN grades g
        ON g.id = sw.grade_id
    INNER JOIN sections sec
        ON sec.id = sw.section_id
    LEFT JOIN users u
        ON u.id = sw.withdrawn_by
    {$whereSql}
";

$stmt = $conn->prepare($countSql);

if ($stmt) {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $totalRecords = (int) $row['total'];
    }

    $stmt->close();
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
 * Withdrawal records.
 */
$withdrawals = [];

$sql = "
    SELECT
        sw.id,
        sw.student_id,
        sw.academic_year_id,
        sw.grade_id,
        sw.section_id,
        sw.reason,
        sw.withdrawn_by,
        sw.withdrawn_at,

        s.student_code,
        s.full_name AS student_name,

        ay.name AS academic_year_name,

        g.name AS grade_name,
        g.grade_number,

        sec.name AS section_name,
        sec.code AS section_code,

        u.full_name AS withdrawn_by_name

    FROM student_withdrawals sw

    INNER JOIN students s
        ON s.id = sw.student_id

    INNER JOIN academic_years ay
        ON ay.id = sw.academic_year_id

    INNER JOIN grades g
        ON g.id = sw.grade_id

    INNER JOIN sections sec
        ON sec.id = sw.section_id

    LEFT JOIN users u
        ON u.id = sw.withdrawn_by

    {$whereSql}

    ORDER BY sw.withdrawn_at DESC, sw.id DESC
    LIMIT ? OFFSET ?
";

$dataParams = $params;
$dataTypes = $types . 'ii';

$dataParams[] = $perPage;
$dataParams[] = $offset;

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param($dataTypes, ...$dataParams);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }

    $stmt->close();
}

/*
 * Profile photo.
 */
$profilePhoto = '../public/images/default-avatar.png';

if (!empty($registrar['photo_path'])) {
    $profilePhoto = '../public/images/' . ltrim(
        (string) $registrar['photo_path'],
        '/'
    );
}

/*
 * Pagination query.
 */
function pageUrl(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = $pageNumber;

    return '?' . http_build_query($query);
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

    <title>Withdrawn Students | Registrar Portal</title>

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
            z-index: 1050;
            overflow-y: auto;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand i {
            font-size: 27px;
            color: #60a5fa;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 700;
            line-height: 1.2;
        }

        .brand-subtitle {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #9ca3af;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 16px 12px 30px;
        }

        .nav-link {
            color: #d1d5db;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            margin-bottom: 4px;
            transition: .2s;
        }

        .nav-link:hover,
        .nav-link.active {
            color: #fff;
            background: #2563eb;
        }

        .nav-link i {
            width: 20px;
            font-size: 17px;
            text-align: center;
        }

        .nav-link .arrow {
            margin-left: auto;
            font-size: 13px;
            transition: transform .2s;
        }

        .nav-link[aria-expanded="true"] .arrow {
            transform: rotate(180deg);
        }

        .submenu {
            padding-left: 28px;
            margin-bottom: 5px;
        }

        .submenu .nav-link {
            font-size: 13px;
            padding: 9px 12px;
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
            font-size: 13px;
            margin-top: 3px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 600;
        }

        .profile-role {
            font-size: 11px;
            color: #6b7280;
        }

        .content {
            padding: 28px;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 20px;
        }

        .card-body {
            padding: 20px;
        }

        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-radius: 8px;
        }

        .btn-primary {
            background: #2563eb;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .student-table th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .student-table td {
            vertical-align: middle;
            font-size: 13px;
        }

        .student-code {
            color: #2563eb;
            font-weight: 700;
        }

        .grade-badge,
        .section-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .grade-badge {
            background: #eef2ff;
            color: #4338ca;
        }

        .section-badge {
            background: #f0fdf4;
            color: #15803d;
        }

        .reason-text {
            max-width: 260px;
            color: #4b5563;
            line-height: 1.5;
        }

        .date-text {
            white-space: nowrap;
            color: #4b5563;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state i {
            display: block;
            font-size: 44px;
            color: #cbd5e1;
            margin-bottom: 12px;
        }

        .pagination .page-link {
            color: #2563eb;
            border-color: #e5e7eb;
        }

        .pagination .active .page-link {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .5);
            z-index: 1040;
        }

        @media (max-width: 1200px) {

            .content {
                padding: 20px;
            }

            .reason-text {
                max-width: 200px;
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

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-block;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px 16px;
            }

        }

        @media (max-width: 650px) {

            .profile-name,
            .profile-role {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
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

    <div class="sidebar-brand">

        <i class="bi bi-mortarboard-fill"></i>

        <div>

            <div class="brand-title">
                BKHS
            </div>

            <span class="brand-subtitle">
                Registrar Portal
            </span>

        </div>

    </div>

    <nav class="sidebar-nav">

        <a
            href="dashboard.php"
            class="nav-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#studentsMenu"
            role="button"
            aria-expanded="true"
        >
            <i class="bi bi-people-fill"></i>
            <span>Students</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse show"
            id="studentsMenu"
        >

            <div class="submenu">

                <a
                    href="register.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Register</span>
                </a>

                <a
                    href="students.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="update-student.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update</span>
                </a>

                <a
                    href="delete-student.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-x-fill"></i>
                    <span>Delete</span>
                </a>

                <a
                    href="withdraw-student.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

                <a
                    href="withdrawn-students.php"
                    class="nav-link active"
                >
                    <i class="bi bi-person-check-fill"></i>
                    <span>Withdrawn Students</span>
                </a>

            </div>

        </div>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#teachersMenu"
            role="button"
            aria-expanded="false"
        >
            <i class="bi bi-person-video3"></i>
            <span>Teachers</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse"
            id="teachersMenu"
        >

            <div class="submenu">

                <a
                    href="teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="update-teacher.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update</span>
                </a>

                <a
                    href="withdraw-teacher.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

                <a
                    href="homeroom-teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-workspace"></i>
                    <span>Homeroom Teachers</span>
                </a>

                <a
                    href="subject-teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-video2"></i>
                    <span>Subject Teachers</span>
                </a>

            </div>

        </div>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#staffMenu"
            role="button"
            aria-expanded="false"
        >
            <i class="bi bi-person-badge-fill"></i>
            <span>Other Staff</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse"
            id="staffMenu"
        >

            <div class="submenu">

                <a
                    href="add-staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Add Staff</span>
                </a>

                <a
                    href="staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="withdraw-staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

            </div>

        </div>

        <a
            href="certificate.php"
            class="nav-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="nav-link"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
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

        <a
            href="../auth/logout.php"
            class="nav-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<main class="main">

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
                    Withdrawn Students
                </h1>

                <div class="page-subtitle">
                    View and search student withdrawal records
                </div>

            </div>

        </div>

        <div class="profile">

            <img
                src="<?= htmlspecialchars(
                    $profilePhoto,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                alt="Registrar"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div>

                <div class="profile-name">
                    <?= htmlspecialchars(
                        (string) $registrar['full_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>

    <section class="content">

        <div class="d-flex justify-content-end mb-3 gap-2">

            <a
                href="withdraw-student.php"
                class="btn btn-primary"
            >
                <i class="bi bi-person-dash-fill me-1"></i>
                Withdraw Student
            </a>

            <a
                href="export-withdrawn-students.php?<?= htmlspecialchars(
                    http_build_query([
                        'search' => $search,
                        'academic_year_id' => $academicYearFilter,
                        'grade_id' => $gradeFilter,
                        'section_id' => $sectionFilter
                    ]),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="btn btn-outline-success"
            >
                <i class="bi bi-file-earmark-excel-fill me-1"></i>
                Export
            </a>

        </div>

        <div class="card mb-4">

            <div class="card-header">

                <div class="d-flex align-items-center gap-2">

                    <i class="bi bi-funnel-fill text-primary"></i>

                    <strong>
                        Search & Filter
                    </strong>

                </div>

            </div>

            <div class="card-body">

                <form
                    method="GET"
                    action="withdrawn-students.php"
                >

                    <div class="row g-3">

                        <div class="col-lg-4">

                            <label
                                class="filter-label"
                                for="search"
                            >
                                Search
                            </label>

                            <div class="input-group">

                                <span class="input-group-text bg-white">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="text"
                                    id="search"
                                    name="search"
                                    class="form-control"
                                    placeholder="Student ID, name, reason..."
                                    value="<?= htmlspecialchars(
                                        $search,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >

                            </div>

                        </div>

                        <div class="col-lg-2">

                            <label
                                class="filter-label"
                                for="academic_year_id"
                            >
                                Academic Year
                            </label>

                            <select
                                name="academic_year_id"
                                id="academic_year_id"
                                class="form-select"
                            >

                                <option value="0">
                                    All Years
                                </option>

                                <?php foreach ($academicYears as $year): ?>

                                    <option
                                        value="<?= (int) $year['id'] ?>"
                                        <?= $academicYearFilter === (int) $year['id']
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

                        <div class="col-lg-2">

                            <label
                                class="filter-label"
                                for="grade_id"
                            >
                                Grade
                            </label>

                            <select
                                name="grade_id"
                                id="grade_id"
                                class="form-select"
                            >

                                <option value="0">
                                    All Grades
                                </option>

                                <?php foreach ($grades as $grade): ?>

                                    <option
                                        value="<?= (int) $grade['id'] ?>"
                                        <?= $gradeFilter === (int) $grade['id']
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

                        <div class="col-lg-2">

                            <label
                                class="filter-label"
                                for="section_id"
                            >
                                Section
                            </label>

                            <select
                                name="section_id"
                                id="section_id"
                                class="form-select"
                            >

                                <option value="0">
                                    All Sections
                                </option>

                                <?php foreach ($sections as $section): ?>

                                    <option
                                        value="<?= (int) $section['id'] ?>"
                                        <?= $sectionFilter === (int) $section['id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            (string) $section['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 d-flex align-items-end">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>

                        </div>

                    </div>

                    <?php if (
                        $search !== '' ||
                        $academicYearFilter > 0 ||
                        $gradeFilter > 0 ||
                        $sectionFilter > 0
                    ): ?>

                        <div class="mt-3">

                            <a
                                href="withdrawn-students.php"
                                class="small text-decoration-none"
                            >
                                <i class="bi bi-x-circle me-1"></i>
                                Clear all filters
                            </a>

                        </div>

                    <?php endif; ?>

                </form>

            </div>

        </div>

        <div class="card">

            <div class="card-header">

                <div class="d-flex justify-content-between align-items-center">

                    <div>

                        <strong>
                            Withdrawal Records
                        </strong>

                        <div class="small text-muted mt-1">
                            <?= number_format($totalRecords) ?>
                            total record(s)
                        </div>

                    </div>

                    <span class="badge text-bg-light">
                        Page <?= $page ?> of <?= $totalPages ?>
                    </span>

                </div>

            </div>

            <div class="card-body p-0">

                <?php if ($withdrawals): ?>

                    <div class="table-responsive">

                        <table class="table table-hover student-table mb-0">

                            <thead>

                                <tr>

                                    <th class="px-4">
                                        Student ID
                                    </th>

                                    <th>
                                        Student
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
                                        Reason
                                    </th>

                                    <th>
                                        Withdrawn Date
                                    </th>

                                    <th class="pe-4">
                                        Withdrawn By
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($withdrawals as $withdrawal): ?>

                                <tr>

                                    <td class="px-4">

                                        <span class="student-code">
                                            <?= htmlspecialchars(
                                                (string) $withdrawal['student_code'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <div class="fw-semibold">
                                            <?= htmlspecialchars(
                                                (string) $withdrawal['student_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <span class="text-muted">
                                            <?= htmlspecialchars(
                                                (string) $withdrawal['academic_year_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <span class="grade-badge">

                                            <?= htmlspecialchars(
                                                (string) $withdrawal['grade_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="section-badge">

                                            <?= htmlspecialchars(
                                                (string) $withdrawal['section_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div
                                            class="reason-text"
                                            title="<?= htmlspecialchars(
                                                (string) $withdrawal['reason'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >

                                            <?= nl2br(
                                                htmlspecialchars(
                                                    (string) $withdrawal['reason'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="date-text">

                                            <?= htmlspecialchars(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string) $withdrawal['withdrawn_at']
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                            <small class="d-block text-muted">
                                                <?= htmlspecialchars(
                                                    date(
                                                        'h:i A',
                                                        strtotime(
                                                            (string) $withdrawal['withdrawn_at']
                                                        )
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </small>

                                        </span>

                                    </td>

                                    <td class="pe-4">

                                        <span class="text-muted">

                                            <?= htmlspecialchars(
                                                (string) (
                                                    $withdrawal['withdrawn_by_name']
                                                    ?: 'Unknown'
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-person-check"></i>

                        <h6 class="fw-semibold">
                            No Withdrawal Records Found
                        </h6>

                        <p class="mb-0 small">
                            There are no withdrawal records matching your
                            current search and filters.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($totalPages > 1): ?>

                <div class="card-footer bg-white border-0 py-3">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                        <div class="small text-muted">

                            Showing

                            <?= $totalRecords > 0
                                ? (($page - 1) * $perPage) + 1
                                : 0 ?>

                            to

                            <?= min(
                                $page * $perPage,
                                $totalRecords
                            ) ?>

                            of <?= number_format($totalRecords) ?>

                        </div>

                        <nav aria-label="Withdrawal pagination">

                            <ul class="pagination pagination-sm mb-0">

                                <li
                                    class="page-item <?= $page <= 1
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page > 1
                                            ? htmlspecialchars(
                                                pageUrl($page - 1),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )
                                            : '#' ?>"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                ?>

                                <?php for (
                                    $i = $startPage;
                                    $i <= $endPage;
                                    $i++
                                ): ?>

                                    <li
                                        class="page-item <?= $i === $page
                                            ? 'active'
                                            : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= htmlspecialchars(
                                                pageUrl($i),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >
                                            <?= $i ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $totalPages
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page < $totalPages
                                            ? htmlspecialchars(
                                                pageUrl($page + 1),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )
                                            : '#' ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    document
        .querySelectorAll('.sidebar .nav-link')
        .forEach(function (link) {

            link.addEventListener('click', function () {

                if (
                    window.innerWidth <= 900 &&
                    !link.hasAttribute('data-bs-toggle')
                ) {
                    closeSidebar();
                }

            });

        });

</script>

</body>
</html>