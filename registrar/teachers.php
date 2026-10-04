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

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
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
        $initials .= strtoupper(
            substr($part, 0, 1)
        );
    }

    return $initials ?: 'T';
}

/*
|--------------------------------------------------------------------------
| Calculate Age From Ethiopian Birth Date
|--------------------------------------------------------------------------
*/

function calculateAge(
    mixed $birthYear,
    mixed $birthMonth,
    mixed $birthDay,
    mixed $todayYear,
    mixed $todayMonth,
    mixed $todayDay
): string {
    if (
        $birthYear === null ||
        $birthYear === '' ||
        $birthMonth === null ||
        $birthMonth === '' ||
        $birthDay === null ||
        $birthDay === ''
    ) {
        return '-';
    }

    $birthYear = (int) $birthYear;
    $birthMonth = (int) $birthMonth;
    $birthDay = (int) $birthDay;
    $todayYear = (int) $todayYear;
    $todayMonth = (int) $todayMonth;
    $todayDay = (int) $todayDay;

    if (
        $birthYear <= 0 ||
        $birthMonth < 1 ||
        $birthMonth > 13 ||
        $birthDay < 1 ||
        $birthDay > 30
    ) {
        return '-';
    }

    $age = $todayYear - $birthYear;

    if (
        $todayMonth < $birthMonth ||
        (
            $todayMonth === $birthMonth &&
            $todayDay < $birthDay
        )
    ) {
        $age--;
    }

    if ($age < 0) {
        return '-';
    }

    return (string) $age;
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) (
        $_GET['search'] ?? ''
    )
);

$page = max(
    1,
    (int) (
        $_GET['page'] ?? 1
    )
);

$perPage = 20;
$errorMessage = '';
$teachers = [];
$totalTeachers = 0;
$totalPages = 1;
$offset = 0;

/*
|--------------------------------------------------------------------------
| Ethiopian Today's Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::today();

$todayEthiopianFormatted = (string) (
    $todayEthiopian['formatted'] ?? ''
);

$todayEthYear = (int) (
    $todayEthiopian['year'] ?? 0
);

$todayEthMonth = (int) (
    $todayEthiopian['month'] ?? 0
);

$todayEthDay = (int) (
    $todayEthiopian['day'] ?? 0
);

/*
|--------------------------------------------------------------------------
| Count Active Teachers
|--------------------------------------------------------------------------
*/

try {
    $where = [
        "u.role = 'Teacher'",
        "u.is_deleted = 0",
        "t.employment_status = 'Active'"
    ];

    $types = '';
    $params = [];

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {
        $where[] = "(
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR t.fayda_number LIKE ?
            OR t.department LIKE ?
            OR t.education_level LIKE ?
            OR t.college_university_institution LIKE ?
            OR t.region LIKE ?
            OR t.zone LIKE ?
            OR t.woreda LIKE ?
        )";

        $searchValue =
            '%' . $search . '%';

        $types .= 'ssssssssss';

        for ($i = 0; $i < 10; $i++) {
            $params[] = $searchValue;
        }
    }

    $whereSql =
        implode(
            ' AND ',
            $where
        );

    $countSql = "
        SELECT COUNT(*) AS total
        FROM teachers t
        INNER JOIN users u
            ON u.id = t.user_id
        WHERE {$whereSql}
    ";

    $stmt =
        $conn->prepare($countSql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare teacher count query.'
        );
    }

    if ($types !== '') {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $result =
        $stmt->get_result();

    $row =
        $result->fetch_assoc();

    $totalTeachers =
        (int) (
            $row['total'] ?? 0
        );

    $stmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teachers count error: ' .
        $e->getMessage()
    );

    $errorMessage =
        'Unable to load teacher records.';
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalTeachers / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Load Teachers
|--------------------------------------------------------------------------
*/

if ($totalTeachers > 0) {
    try {
        $sql = "
            SELECT
                t.id,
                t.user_id,
                t.fayda_number,
                t.employment_status,
                t.gender,
                t.birth_eth_year,
                t.birth_eth_month,
                t.birth_eth_day,
                t.region,
                t.zone,
                t.woreda,
                t.marital_status,
                t.education_level,
                t.department,
                t.college_university_institution,
                t.has_experience,
                t.has_pgdt,
                t.photo_path,

                u.full_name,
                u.email,
                u.phone,
                u.signature_path,
                u.created_at AS account_created_at,

                (
                    SELECT GROUP_CONCAT(
                        DISTINCT CONCAT('Grade ', sta.grade)
                        ORDER BY sta.grade ASC
                        SEPARATOR ', '
                    )
                    FROM subject_teacher_assignments sta
                    WHERE sta.teacher_user_id = t.user_id
                      AND sta.is_active = 1
                ) AS teaching_grades,

                (
                    SELECT GROUP_CONCAT(
                        DISTINCT gs.subject_name
                        ORDER BY gs.subject_name ASC
                        SEPARATOR ', '
                    )
                    FROM subject_teacher_assignments sta
                    INNER JOIN grade_subjects gs
                        ON gs.id = sta.grade_subject_id
                    WHERE sta.teacher_user_id = t.user_id
                      AND sta.is_active = 1
                      AND gs.is_active = 1
                ) AS teaching_subjects

            FROM teachers t

            INNER JOIN users u
                ON u.id = t.user_id

            WHERE {$whereSql}

            ORDER BY
                u.full_name ASC

            LIMIT ? OFFSET ?
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare teacher query.'
            );
        }

        $limit = $perPage;

        $typesWithPagination =
            $types . 'ii';

        $paramsWithPagination =
            array_merge(
                $params,
                [
                    $limit,
                    $offset
                ]
            );

        $stmt->bind_param(
            $typesWithPagination,
            ...$paramsWithPagination
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row = $result->fetch_assoc()
        ) {
            $teachers[] =
                $row;
        }

        $stmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teachers query error: ' .
            $e->getMessage()
        );

        $errorMessage =
            'Unable to load teacher records.';
    }
}

/*
|--------------------------------------------------------------------------
| Preserve Search For Pagination
|--------------------------------------------------------------------------
*/

$queryParams = [];

if ($search !== '') {
    $queryParams['search'] =
        $search;
}

$queryString =
    http_build_query(
        $queryParams
    );

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
        Teachers | Registrar | BKHS
    </title>

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

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: rgba(255, 255, 255, .07);
            --text: #172033;
            --muted: #64748b;
            --border: #e5e7eb;
            --background: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        a {
            text-decoration: none;
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
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, .15);
            color: #60a5fa;
            border-radius: 10px;
            font-size: 20px;
        }

        .sidebar-brand-text strong {
            display: block;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sidebar-brand-text span {
            display: block;
            margin-top: 3px;
            color: #9ca3af;
            font-size: 11px;
        }

        .sidebar-nav {
            padding: 14px 10px 25px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            margin-bottom: 3px;
            border-radius: 8px;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .nav-link-custom i {
            width: 22px;
            margin-right: 10px;
            font-size: 17px;
        }

        .nav-link-custom:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .nav-link-custom.active {
            color: #fff;
            background: var(--primary);
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
            text-align: left;
        }

        .nav-parent:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .nav-parent[aria-expanded="true"] {
            color: #fff;
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
            color: #fff;
            background: rgba(255, 255, 255, .06);
        }

        .nav-sub-link.active {
            background: var(--primary);
            color: #fff;
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
            height: 78px;
            background: #fff;
            border-bottom: 1px solid var(--border);
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
        }

        .topbar-title h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .topbar-title p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .profile-area {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-info span {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: 11px;
        }

        .content {
            padding: 28px 30px 40px;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            z-index: 1030;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Header
        |--------------------------------------------------------------------------
        */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }

        .page-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .today-date {
            margin-top: 8px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-value {
            margin-top: 5px;
            font-size: 25px;
            font-weight: 800;
        }

        /*
        |--------------------------------------------------------------------------
        | Filter
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #374151;
            font-size: 12px;
            font-weight: 600;
        }

        .form-control {
            min-height: 42px;
            border-color: #dbe1e8;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .1);
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .table-header h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            min-width: 1750px;
            margin: 0;
        }

        .table thead th {
            background: #f8fafc;
            color: #475569;
            border-bottom: 1px solid var(--border);
            padding: 13px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 13px 16px;
            border-color: #eef2f7;
            vertical-align: middle;
            font-size: 13px;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .teacher-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .teacher-avatar {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
        }

        .teacher-name {
            color: #172033;
            font-weight: 700;
        }

        .teacher-email {
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .department {
            font-weight: 600;
            color: #334155;
        }

        .education {
            color: #475569;
        }

        .institution {
            color: #475569;
            max-width: 220px;
        }

        .address {
            color: #475569;
            line-height: 1.5;
            min-width: 180px;
        }

        .address span {
            display: block;
            white-space: nowrap;
        }

        .fayda {
            font-family: monospace;
            font-size: 12px;
            color: #334155;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Teaching Assignment
        |--------------------------------------------------------------------------
        */

        .teaching-assignment {
            min-width: 180px;
            max-width: 280px;
            color: #475569;
            line-height: 1.6;
        }

        .teaching-assignment span {
            display: block;
        }

        .teaching-assignment-empty {
            color: #94a3b8;
        }

        .pgdt-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .pgdt-yes {
            background: #dcfce7;
            color: #166534;
        }

        .pgdt-no {
            background: #f1f5f9;
            color: #64748b;
        }

        .age {
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            color: var(--primary);
            font-size: 12px;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            font-size: 38px;
            color: #94a3b8;
        }

        .empty-state h4 {
            margin-bottom: 6px;
            color: #334155;
            font-size: 15px;
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

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
                display: inline-flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .topbar-left {
                gap: 12px;
            }

            .content {
                padding: 22px 18px 35px;
            }

            .profile-info {
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 767.98px) {

            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
            }

            .table-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .pagination-wrapper {
                align-items: flex-start;
                flex-direction: column;
            }
        }

    </style>

</head>

<body>

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

        <div class="sidebar-brand-text">

            <strong>BKHS</strong>

            <span>Registrar Portal</span>

        </div>

    </div>

    <nav class="sidebar-nav">

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
                    class="nav-link-custom nav-sub-link active"
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

    </nav>

</aside>

<!-- Main -->

<div class="main-wrapper">

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">

                <h1>
                    Teachers
                </h1>

                <p>
                    Manage active teacher records
                </p>

            </div>

        </div>

        <div class="profile-area">

            <div class="profile-avatar">

                <?= e(
                    getInitials(
                        (string) (
                            $_SESSION['full_name']
                            ?? 'Registrar'
                        )
                    )
                ) ?>

            </div>

            <div class="profile-info">

                <strong>
                    <?= e(
                        (string) (
                            $_SESSION['full_name']
                            ?? 'Registrar'
                        )
                    ) ?>
                </strong>

                <span>
                    Registrar
                </span>

            </div>

        </div>

    </header>

    <main class="content">

        <!-- Page Header -->

        <div class="page-header">

            <div>

                <h2>
                    Teachers
                </h2>

                <p>
                    View and manage active teachers.
                </p>

                <div class="today-date">

                    <i class="bi bi-calendar3 me-1"></i>

                    Today:

                    <?= e(
                        $todayEthiopianFormatted
                    ) ?>

                </div>

            </div>

            <div class="header-actions">

                <a
                    href="export-teachers.php<?= $queryString !== '' ? '?' . e($queryString) : '' ?>"
                    class="btn btn-primary"
                >

                    <i class="bi bi-file-earmark-excel me-1"></i>

                    Export

                </a>

            </div>

        </div>

        <?php if ($errorMessage !== ''): ?>

            <div
                class="alert alert-danger border-0 shadow-sm"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="row">

            <div class="col-12 col-md-4">

                <div class="stat-card">

                    <div class="stat-label">
                        Active Teachers
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalTeachers) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Search -->

        <div class="filter-card">

            <form
                method="GET"
                action="teachers.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-12 col-md-8">

                        <label class="form-label">
                            Search Teachers
                        </label>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Name, email, phone, Fayda, department, institution..."
                        >

                    </div>

                    <div class="col-12 col-md-4 d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                        <a
                            href="teachers.php"
                            class="btn btn-outline-secondary"
                            title="Clear search"
                        >

                            <i class="bi bi-arrow-counterclockwise"></i>

                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- Table -->

        <div class="table-card">

            <div class="table-header">

                <h3>
                    Active Teachers
                </h3>

                <span class="text-muted small">

                    <?= number_format($totalTeachers) ?>

                    teacher<?= $totalTeachers === 1 ? '' : 's' ?>

                </span>

            </div>

            <?php if (!empty($teachers)): ?>

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    No.
                                </th>

                                <th>
                                    Teacher
                                </th>

                                <th>
                                    Phone
                                </th>

                                <th>
                                    Gender
                                </th>

                                <th>
                                    Age
                                </th>

                                <th>
                                    Address
                                </th>

                                <th>
                                    Fayda Number
                                </th>

                                <th>
                                    Teaching Grade
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Education Level
                                </th>

                                <th>
                                    Institution
                                </th>

                                <th>
                                    Department
                                </th>

                                <th>
                                    Has PGDT
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $teachers as $index => $teacher
                            ): ?>

                                <?php

                                $age = calculateAge(
                                    $teacher['birth_eth_year'] ?? null,
                                    $teacher['birth_eth_month'] ?? null,
                                    $teacher['birth_eth_day'] ?? null,
                                    $todayEthYear,
                                    $todayEthMonth,
                                    $todayEthDay
                                );

                                $teachingGrades =
                                    trim(
                                        (string) (
                                            $teacher['teaching_grades']
                                            ?? ''
                                        )
                                    );

                                $teachingSubjects =
                                    trim(
                                        (string) (
                                            $teacher['teaching_subjects']
                                            ?? ''
                                        )
                                    );

                                ?>

                                <tr>

                                    <td>
                                        <?= $offset + $index + 1 ?>
                                    </td>

                                    <!-- Teacher -->

                                    <td>

                                        <div class="teacher-cell">

                                            <div class="teacher-avatar">

                                                <?= e(
                                                    getInitials(
                                                        (string) (
                                                            $teacher['full_name']
                                                        )
                                                    )
                                                ) ?>

                                            </div>

                                            <div>

                                                <div class="teacher-name">

                                                    <?= e(
                                                        (string) (
                                                            $teacher['full_name']
                                                        )
                                                    ) ?>

                                                </div>

                                                <?php if (
                                                    !empty(
                                                        $teacher['email']
                                                    )
                                                ): ?>

                                                    <div class="teacher-email">

                                                        <?= e(
                                                            (string) (
                                                                $teacher['email']
                                                            )
                                                        ) ?>

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <!-- Phone -->

                                    <td>

                                        <?= e(
                                            (string) (
                                                $teacher['phone']
                                                ?? '-'
                                            )
                                        ) ?>

                                    </td>

                                    <!-- Gender -->

                                    <td>

                                        <?= e(
                                            (string) (
                                                $teacher['gender']
                                                ?? '-'
                                            )
                                        ) ?>

                                    </td>

                                    <!-- Age -->

                                    <td>

                                        <div class="age">

                                            <?= e($age) ?>

                                            <?php if ($age !== '-'): ?>

                                                <small class="text-muted">
                                                    yrs
                                                </small>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <!-- Address -->

                                    <td>

                                        <div class="address">

                                            <span>

                                                <strong>Region:</strong>

                                                <?= e(
                                                    (string) (
                                                        $teacher['region']
                                                        ?? '-'
                                                    )
                                                ) ?>

                                            </span>

                                            <span>

                                                <strong>Zone:</strong>

                                                <?= e(
                                                    (string) (
                                                        $teacher['zone']
                                                        ?? '-'
                                                    )
                                                ) ?>

                                            </span>

                                            <span>

                                                <strong>Woreda:</strong>

                                                <?= e(
                                                    (string) (
                                                        $teacher['woreda']
                                                        ?? '-'
                                                    )
                                                ) ?>

                                            </span>

                                        </div>

                                    </td>

                                    <!-- Fayda Number -->

                                    <td>

                                        <div class="fayda">

                                            <?= e(
                                                (string) (
                                                    $teacher['fayda_number']
                                                    ?? '-'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                    <!-- Teaching Grade -->

                                    <td>

                                        <?php if ($teachingGrades !== ''): ?>

                                            <div class="teaching-assignment">

                                                <?= e(
                                                    $teachingGrades
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="teaching-assignment-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Subject -->

                                    <td>

                                        <?php if ($teachingSubjects !== ''): ?>

                                            <div class="teaching-assignment">

                                                <?= e(
                                                    $teachingSubjects
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="teaching-assignment-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- Education Level -->

                                    <td>

                                        <div class="education">

                                            <?= e(
                                                (string) (
                                                    $teacher['education_level']
                                                    ?? '-'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                    <!-- Institution -->

                                    <td>

                                        <div class="institution">

                                            <?= e(
                                                (string) (
                                                    $teacher[
                                                        'college_university_institution'
                                                    ]
                                                    ?? '-'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                    <!-- Department -->

                                    <td>

                                        <div class="department">

                                            <?= e(
                                                (string) (
                                                    $teacher['department']
                                                    ?? '-'
                                                )
                                            ) ?>

                                        </div>

                                    </td>

                                    <!-- Has PGDT -->

                                    <td>

                                        <?php

                                        $hasPgdt =
                                            strtolower(
                                                (string) (
                                                    $teacher['has_pgdt']
                                                    ?? ''
                                                )
                                            ) === 'yes';

                                        ?>

                                        <span
                                            class="pgdt-badge <?= $hasPgdt ? 'pgdt-yes' : 'pgdt-no' ?>"
                                        >

                                            <i
                                                class="bi <?= $hasPgdt ? 'bi-check-circle-fill' : 'bi-x-circle' ?> me-1"
                                            ></i>

                                            <?= $hasPgdt ? 'Yes' : 'No' ?>

                                        </span>

                                    </td>

                                    <!-- Action -->

                                    <td>

                                        <a
                                            href="view-teacher.php?id=<?= (int) $teacher['id'] ?>"
                                            class="btn btn-sm btn-outline-primary action-btn"
                                        >

                                            <i class="bi bi-eye"></i>

                                            View

                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- Pagination -->

                <?php if ($totalPages > 1): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing

                            <?= $offset + 1 ?>

                            to

                            <?= min(
                                $offset + $perPage,
                                $totalTeachers
                            ) ?>

                            of

                            <?= number_format(
                                $totalTeachers
                            ) ?>

                            teachers

                        </div>

                        <nav>

                            <ul class="pagination pagination-sm">

                                <?php

                                $baseQuery =
                                    $queryParams;

                                $previousParams =
                                    $baseQuery;

                                $previousParams['page'] =
                                    max(
                                        1,
                                        $page - 1
                                    );

                                $previousUrl =
                                    '?' .
                                    http_build_query(
                                        $previousParams
                                    );

                                ?>

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e($previousUrl) ?>"
                                    >
                                        Previous
                                    </a>

                                </li>

                                <?php

                                $startPage =
                                    max(
                                        1,
                                        $page - 2
                                    );

                                $endPage =
                                    min(
                                        $totalPages,
                                        $page + 2
                                    );

                                ?>

                                <?php for (
                                    $p = $startPage;
                                    $p <= $endPage;
                                    $p++
                                ): ?>

                                    <?php

                                    $pageParams =
                                        $baseQuery;

                                    $pageParams['page'] =
                                        $p;

                                    $pageUrl =
                                        '?' .
                                        http_build_query(
                                            $pageParams
                                        );

                                    ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e($pageUrl) ?>"
                                        >

                                            <?= $p ?>

                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <?php

                                $nextParams =
                                    $baseQuery;

                                $nextParams['page'] =
                                    min(
                                        $totalPages,
                                        $page + 1
                                    );

                                $nextUrl =
                                    '?' .
                                    http_build_query(
                                        $nextParams
                                    );

                                ?>

                                <li
                                    class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e($nextUrl) ?>"
                                    >
                                        Next
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php else: ?>

                <div class="empty-state">

                    <i class="bi bi-people"></i>

                    <h4>
                        No Active Teachers
                    </h4>

                    <p>
                        No active teacher records were found.
                    </p>

                </div>

            <?php endif; ?>

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

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');

    function openSidebar() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }

    mobileMenuBtn.addEventListener(
        'click',
        openSidebar
    );

    overlay.addEventListener(
        'click',
        closeSidebar
    );

    document
        .querySelectorAll('.sidebar a')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <= 991
                    ) {
                        closeSidebar();
                    }

                }
            );

        });

</script>

</body>

</html>