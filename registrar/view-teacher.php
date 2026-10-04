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
require_once '../includes/EthiopianCalendar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset('utf8mb4');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'T';
    }

    $parts = preg_split('/\s+/', $name) ?: [];

    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'T';
}

/**
 * Convert a stored relative upload path into a safe public URL.
 *
 * Database examples:
 * uploads/teachers/education/file.pdf
 * public/uploads/teachers/education/file.pdf
 */
function publicFileUrl(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }

    if ($path === '' || str_contains($path, '..')) {
        return '';
    }

    return '../public/' . implode(
        '/',
        array_map(
            'rawurlencode',
            explode('/', $path)
        )
    );
}

/**
 * Check whether an uploaded file exists inside public/.
 */
function publicFileExists(?string $path): bool
{
    $path = trim((string) $path);

    if ($path === '') {
        return false;
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }

    if ($path === '' || str_contains($path, '..')) {
        return false;
    }

    $fullPath = dirname(__DIR__) . '/public/' . $path;

    return is_file($fullPath);
}

function fileName(?string $path): string
{
    $path = str_replace('\\', '/', trim((string) $path));

    if ($path === '') {
        return '';
    }

    return basename($path);
}

function isImageFile(?string $path): bool
{
    $extension = strtolower(
        pathinfo((string) $path, PATHINFO_EXTENSION)
    );

    return in_array(
        $extension,
        ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        true
    );
}

function formatBirthDate(
    ?int $year,
    ?int $month,
    ?int $day
): string {
    if (
        !$year ||
        !$month ||
        !$day ||
        $month < 1 ||
        $month > 13 ||
        $day < 1
    ) {
        return 'Not provided';
    }

    $months = EthiopianCalendar::months('en');

    $monthName = $months[$month] ?? null;

    if (!$monthName) {
        return 'Not provided';
    }

    return $monthName . ' ' . $day . ', ' . $year;
}

$teacherId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$teacherId || $teacherId < 1) {
    header('Location: teachers.php');
    exit;
}

$sql = "
    SELECT
        t.id AS teacher_id,
        t.user_id,
        t.employment_status,
        t.fayda_number,
        t.photo_path AS teacher_photo_path,
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
        t.education_credential_path,
        t.has_experience,
        t.experience_file_path,
        t.college_university_institution,
        t.has_pgdt,
        t.pgdt_file_path,
        t.created_at AS teacher_created_at,
        t.updated_at AS teacher_updated_at,

        u.full_name,
        u.email,
        u.phone,
        u.signature_path,
        u.photo_path AS user_photo_path,
        u.role,
        u.is_deleted,
        u.created_at AS user_created_at

    FROM teachers t

    INNER JOIN users u
        ON u.id = t.user_id

    WHERE
        t.id = ?
        AND t.employment_status = 'Active'
        AND u.is_deleted = 0

    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $teacherId);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    header('Location: teachers.php');
    exit;
}

$fullName = (string) $teacher['full_name'];

$birthDate = formatBirthDate(
    $teacher['birth_eth_year'] !== null
        ? (int) $teacher['birth_eth_year']
        : null,
    $teacher['birth_eth_month'] !== null
        ? (int) $teacher['birth_eth_month']
        : null,
    $teacher['birth_eth_day'] !== null
        ? (int) $teacher['birth_eth_day']
        : null
);

$teacherPhotoPath = trim(
    (string) ($teacher['teacher_photo_path'] ?? '')
);

$userPhotoPath = trim(
    (string) ($teacher['user_photo_path'] ?? '')
);

$photoPath = $teacherPhotoPath !== ''
    ? $teacherPhotoPath
    : $userPhotoPath;

$photoUrl = publicFileUrl($photoPath);
$photoExists = publicFileExists($photoPath);

$signaturePath = trim(
    (string) ($teacher['signature_path'] ?? '')
);

$signatureUrl = publicFileUrl($signaturePath);
$signatureExists = publicFileExists($signaturePath);

$educationFilePath = trim(
    (string) ($teacher['education_credential_path'] ?? '')
);

$educationFileUrl = publicFileUrl($educationFilePath);
$educationFileExists = publicFileExists($educationFilePath);

$experienceFilePath = trim(
    (string) ($teacher['experience_file_path'] ?? '')
);

$experienceFileUrl = publicFileUrl($experienceFilePath);
$experienceFileExists = publicFileExists($experienceFilePath);

$pgdtFilePath = trim(
    (string) ($teacher['pgdt_file_path'] ?? '')
);

$pgdtFileUrl = publicFileUrl($pgdtFilePath);
$pgdtFileExists = publicFileExists($pgdtFilePath);

$today = EthiopianCalendar::todayFormatted('en');
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
        View Teacher - <?= e($fullName) ?> | BKHS Registrar
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
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
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-text: #9ca3af;
            --border: #e5e7eb;
            --muted: #6b7280;
            --background: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: #111827;
        }

        /* =========================
           SIDEBAR
        ========================= */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar);
            color: white;
            z-index: 1050;
            overflow-y: auto;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .07);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
            font-size: 20px;
        }

        .brand-text strong {
            display: block;
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-text span {
            display: block;
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 16px 10px 24px;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            width: 100%;
            text-decoration: none;
            color: var(--sidebar-text);
            padding: 11px 12px;
            border-radius: 8px;
            margin-bottom: 3px;
            font-size: 14px;
            font-weight: 500;
            transition: .2s ease;
        }

        .nav-link-custom i:first-child {
            width: 22px;
            margin-right: 10px;
            font-size: 17px;
        }

        .nav-link-custom:hover {
            background: rgba(255, 255, 255, .07);
            color: white;
        }

        .nav-link-custom.active {
            background: var(--primary);
            color: white;
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
            background: rgba(255, 255, 255, .07);
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
            background: rgba(255, 255, 255, .06);
        }

        .nav-sub-link.active {
            background: var(--primary);
            color: white;
        }

        /* =========================
           MAIN
        ========================= */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: white;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
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
            border: 0;
            background: #f3f4f6;
            width: 40px;
            height: 40px;
            border-radius: 9px;
            font-size: 20px;
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

        .today {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
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
            font-size: 13px;
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-info span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-top: 2px;
        }

        /* =========================
           CONTENT
        ========================= */

        .content {
            padding: 28px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: #374151;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            color: var(--primary);
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .profile-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .profile-header {
            padding: 28px;
            background: linear-gradient(
                135deg,
                #eff6ff 0%,
                #ffffff 65%
            );
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .teacher-photo {
            width: 110px;
            height: 110px;
            border-radius: 16px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .12);
        }

        .teacher-avatar {
            width: 110px;
            height: 110px;
            border-radius: 16px;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            border: 4px solid white;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .12);
        }

        .teacher-heading h2 {
            margin: 0;
            font-size: 25px;
            font-weight: 800;
        }

        .teacher-heading .role {
            color: var(--muted);
            font-size: 13px;
            margin-top: 5px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #dcfce7;
            color: #166534;
            border-radius: 999px;
            padding: 6px 11px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 10px;
        }

        .section-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .section-header {
            padding: 17px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .section-header i {
            color: var(--primary);
            font-size: 18px;
        }

        .section-header h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .section-body {
            padding: 20px;
        }

        .info-item {
            padding: 13px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-item:last-child {
            border-bottom: 0;
        }

        .info-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-bottom: 5px;
        }

        .info-value {
            color: #111827;
            font-size: 14px;
            font-weight: 500;
            word-break: break-word;
        }

        .empty-value {
            color: #9ca3af;
        }

        .document-card {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 15px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .document-top {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .document-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 9px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .document-name {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            word-break: break-word;
        }

        .document-file {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
            word-break: break-all;
        }

        .document-action {
            margin-top: 16px;
        }

        .open-file-btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            background: #eff6ff;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
        }

        .open-file-btn:hover {
            background: #dbeafe;
            color: var(--primary-dark);
        }

        .not-uploaded {
            color: #9ca3af;
            font-size: 12px;
            padding-top: 12px;
        }

        .photo-preview {
            max-width: 180px;
            max-height: 180px;
            object-fit: contain;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #f8fafc;
            padding: 5px;
        }

        .signature-preview {
            max-width: 260px;
            max-height: 110px;
            object-fit: contain;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: white;
            padding: 8px;
        }

        .yes-badge,
        .no-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .yes-badge {
            background: #dcfce7;
            color: #166534;
        }

        .no-badge {
            background: #f3f4f6;
            color: #6b7280;
        }

        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px 16px;
            }

            .today {
                display: none;
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, .45);
                z-index: 1040;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 575px) {
            .profile-info {
                display: none;
            }

            .profile-header {
                padding: 20px;
                gap: 15px;
            }

            .teacher-photo,
            .teacher-avatar {
                width: 80px;
                height: 80px;
                border-radius: 12px;
            }

            .teacher-heading h2 {
                font-size: 19px;
            }

            .section-body {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================
     SIDEBAR
========================= -->

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div class="brand-text">
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

<!-- =========================
     MAIN
========================= -->

<main class="main">

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

            <div class="page-title">

                <h1>Teacher Details</h1>

                <p>
                    View complete teacher information
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="today">
                <?= e($today) ?>
            </div>

            <div class="profile">

                <div class="profile-avatar">
                    <?= e(
                        getInitials(
                            (string) ($_SESSION['full_name'] ?? 'Registrar')
                        )
                    ) ?>
                </div>

                <div class="profile-info">

                    <strong>
                        <?= e(
                            (string) ($_SESSION['full_name'] ?? 'Registrar')
                        ) ?>
                    </strong>

                    <span>Registrar</span>

                </div>

            </div>

        </div>

    </header>

    <div class="content">

        <a
            href="teachers.php"
            class="back-btn"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Teachers
        </a>

        <!-- =========================
             TEACHER HEADER
        ========================= -->

        <div class="profile-card">

            <div class="profile-header">

                <?php if ($photoExists && isImageFile($photoPath)): ?>

                    <img
                        src="<?= e($photoUrl) ?>"
                        alt="<?= e($fullName) ?>"
                        class="teacher-photo"
                    >

                <?php else: ?>

                    <div class="teacher-avatar">
                        <?= e(getInitials($fullName)) ?>
                    </div>

                <?php endif; ?>

                <div class="teacher-heading">

                    <h2>
                        <?= e($fullName) ?>
                    </h2>

                    <div class="role">
                        Teacher
                    </div>

                    <span class="status-badge">
                        <i class="bi bi-check-circle-fill"></i>
                        Active
                    </span>

                </div>

            </div>

        </div>

        <div class="row g-4">

            <!-- =========================
                 PERSONAL INFORMATION
            ========================= -->

            <div class="col-lg-6">

                <div class="section-card">

                    <div class="section-header">

                        <i class="bi bi-person-vcard-fill"></i>

                        <h3>Personal Information</h3>

                    </div>

                    <div class="section-body">

                        <div class="row">

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Full Name
                                    </div>

                                    <div class="info-value">
                                        <?= e($fullName) ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Fayda Number
                                    </div>

                                    <div class="info-value">
                                        <?= e(
                                            (string) $teacher['fayda_number']
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Gender
                                    </div>

                                    <div class="info-value">
                                        <?= $teacher['gender']
                                            ? e((string) $teacher['gender'])
                                            : '<span class="empty-value">Not provided</span>'
                                        ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Birth Date
                                    </div>

                                    <div class="info-value">
                                        <?= e($birthDate) ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Region
                                    </div>

                                    <div class="info-value">
                                        <?= $teacher['region']
                                            ? e((string) $teacher['region'])
                                            : '<span class="empty-value">Not provided</span>'
                                        ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Zone
                                    </div>

                                    <div class="info-value">
                                        <?= $teacher['zone']
                                            ? e((string) $teacher['zone'])
                                            : '<span class="empty-value">Not provided</span>'
                                        ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Woreda
                                    </div>

                                    <div class="info-value">
                                        <?= $teacher['woreda']
                                            ? e((string) $teacher['woreda'])
                                            : '<span class="empty-value">Not provided</span>'
                                        ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <div class="info-item">

                                    <div class="info-label">
                                        Marital Status
                                    </div>

                                    <div class="info-value">
                                        <?= $teacher['marital_status']
                                            ? e((string) $teacher['marital_status'])
                                            : '<span class="empty-value">Not provided</span>'
                                        ?>
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- =========================
                 CONTACT INFORMATION
            ========================= -->

            <div class="col-lg-6">

                <div class="section-card">

                    <div class="section-header">

                        <i class="bi bi-telephone-fill"></i>

                        <h3>Contact & Account Information</h3>

                    </div>

                    <div class="section-body">

                        <div class="info-item">

                            <div class="info-label">
                                Email
                            </div>

                            <div class="info-value">

                                <?php if (!empty($teacher['email'])): ?>

                                    <a
                                        href="mailto:<?= e((string) $teacher['email']) ?>"
                                        class="text-decoration-none"
                                    >
                                        <?= e((string) $teacher['email']) ?>
                                    </a>

                                <?php else: ?>

                                    <span class="empty-value">
                                        Not provided
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Phone
                            </div>

                            <div class="info-value">

                                <?php if (!empty($teacher['phone'])): ?>

                                    <a
                                        href="tel:<?= e((string) $teacher['phone']) ?>"
                                        class="text-decoration-none"
                                    >
                                        <?= e((string) $teacher['phone']) ?>
                                    </a>

                                <?php else: ?>

                                    <span class="empty-value">
                                        Not provided
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Account Role
                            </div>

                            <div class="info-value">
                                <?= e((string) $teacher['role']) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Employment Status
                            </div>

                            <div class="info-value">

                                <span class="yes-badge">
                                    <i class="bi bi-check-circle me-1"></i>
                                    <?= e(
                                        (string) $teacher['employment_status']
                                    ) ?>
                                </span>

                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Teacher Record Created
                            </div>

                            <div class="info-value">
                                <?= e(
                                    date(
                                        'Y-m-d',
                                        strtotime(
                                            (string) $teacher['teacher_created_at']
                                        )
                                    )
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =========================
             EDUCATION
        ========================= -->

        <div class="section-card">

            <div class="section-header">

                <i class="bi bi-mortarboard-fill"></i>

                <h3>Education Information</h3>

            </div>

            <div class="section-body">

                <div class="row">

                    <div class="col-md-4">

                        <div class="info-item">

                            <div class="info-label">
                                Education Level
                            </div>

                            <div class="info-value">
                                <?= $teacher['education_level']
                                    ? e((string) $teacher['education_level'])
                                    : '<span class="empty-value">Not provided</span>'
                                ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="info-item">

                            <div class="info-label">
                                Institution
                            </div>

                            <div class="info-value">
                                <?= $teacher['college_university_institution']
                                    ? e(
                                        (string) $teacher['college_university_institution']
                                    )
                                    : '<span class="empty-value">Not provided</span>'
                                ?>
                            </div>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="info-item">

                            <div class="info-label">
                                Department
                            </div>

                            <div class="info-value">
                                <?= $teacher['department']
                                    ? e((string) $teacher['department'])
                                    : '<span class="empty-value">Not provided</span>'
                                ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =========================
             EXPERIENCE & PGDT
        ========================= -->

        <div class="row g-4">

            <div class="col-lg-6">

                <div class="section-card">

                    <div class="section-header">

                        <i class="bi bi-briefcase-fill"></i>

                        <h3>Work Experience</h3>

                    </div>

                    <div class="section-body">

                        <div class="info-item">

                            <div class="info-label">
                                Has Experience
                            </div>

                            <div class="info-value">

                                <?php if ($teacher['has_experience'] === 'Yes'): ?>

                                    <span class="yes-badge">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Yes
                                    </span>

                                <?php else: ?>

                                    <span class="no-badge">
                                        No
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Experience Document
                            </div>

                            <div class="info-value">

                                <?php if ($experienceFileExists): ?>

                                    <a
                                        href="<?= e($experienceFileUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="open-file-btn"
                                    >
                                        <i class="bi bi-file-earmark-arrow-up"></i>
                                        Open Experience File
                                    </a>

                                    <div class="document-file mt-2">
                                        <?= e(fileName($experienceFilePath)) ?>
                                    </div>

                                <?php else: ?>

                                    <span class="not-uploaded">
                                        No experience file uploaded.
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-lg-6">

                <div class="section-card">

                    <div class="section-header">

                        <i class="bi bi-award-fill"></i>

                        <h3>PGDT Information</h3>

                    </div>

                    <div class="section-body">

                        <div class="info-item">

                            <div class="info-label">
                                Has PGDT
                            </div>

                            <div class="info-value">

                                <?php if ($teacher['has_pgdt'] === 'Yes'): ?>

                                    <span class="yes-badge">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Yes
                                    </span>

                                <?php else: ?>

                                    <span class="no-badge">
                                        No
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                PGDT Document
                            </div>

                            <div class="info-value">

                                <?php if ($pgdtFileExists): ?>

                                    <a
                                        href="<?= e($pgdtFileUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="open-file-btn"
                                    >
                                        <i class="bi bi-file-earmark-arrow-up"></i>
                                        Open PGDT File
                                    </a>

                                    <div class="document-file mt-2">
                                        <?= e(fileName($pgdtFilePath)) ?>
                                    </div>

                                <?php else: ?>

                                    <span class="not-uploaded">
                                        No PGDT file uploaded.
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =========================
             UPLOADED DOCUMENTS
        ========================= -->

        <div class="section-card">

            <div class="section-header">

                <i class="bi bi-folder2-open"></i>

                <h3>Uploaded Documents</h3>

            </div>

            <div class="section-body">

                <div class="row g-3">

                    <!-- Education Credential -->

                    <div class="col-lg-4 col-md-6">

                        <div class="document-card">

                            <div class="document-top">

                                <div class="document-icon">
                                    <i class="bi bi-mortarboard"></i>
                                </div>

                                <div>

                                    <div class="document-name">
                                        Education Credential
                                    </div>

                                    <?php if ($educationFileExists): ?>

                                        <div class="document-file">
                                            <?= e(
                                                fileName($educationFilePath)
                                            ) ?>
                                        </div>

                                    <?php else: ?>

                                        <div class="document-file">
                                            No file uploaded
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="document-action">

                                <?php if ($educationFileExists): ?>

                                    <a
                                        href="<?= e($educationFileUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="open-file-btn"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        Open File
                                    </a>

                                <?php else: ?>

                                    <div class="not-uploaded">
                                        <i class="bi bi-dash-circle"></i>
                                        Not available
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Experience -->

                    <div class="col-lg-4 col-md-6">

                        <div class="document-card">

                            <div class="document-top">

                                <div class="document-icon">
                                    <i class="bi bi-briefcase"></i>
                                </div>

                                <div>

                                    <div class="document-name">
                                        Experience File
                                    </div>

                                    <?php if ($experienceFileExists): ?>

                                        <div class="document-file">
                                            <?= e(
                                                fileName($experienceFilePath)
                                            ) ?>
                                        </div>

                                    <?php else: ?>

                                        <div class="document-file">
                                            No file uploaded
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="document-action">

                                <?php if ($experienceFileExists): ?>

                                    <a
                                        href="<?= e($experienceFileUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="open-file-btn"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        Open File
                                    </a>

                                <?php else: ?>

                                    <div class="not-uploaded">
                                        <i class="bi bi-dash-circle"></i>
                                        Not available
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                    <!-- PGDT -->

                    <div class="col-lg-4 col-md-6">

                        <div class="document-card">

                            <div class="document-top">

                                <div class="document-icon">
                                    <i class="bi bi-award"></i>
                                </div>

                                <div>

                                    <div class="document-name">
                                        PGDT File
                                    </div>

                                    <?php if ($pgdtFileExists): ?>

                                        <div class="document-file">
                                            <?= e(
                                                fileName($pgdtFilePath)
                                            ) ?>
                                        </div>

                                    <?php else: ?>

                                        <div class="document-file">
                                            No file uploaded
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="document-action">

                                <?php if ($pgdtFileExists): ?>

                                    <a
                                        href="<?= e($pgdtFileUrl) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="open-file-btn"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        Open File
                                    </a>

                                <?php else: ?>

                                    <div class="not-uploaded">
                                        <i class="bi bi-dash-circle"></i>
                                        Not available
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =========================
             PHOTO & SIGNATURE
        ========================= -->

        <div class="section-card">

            <div class="section-header">

                <i class="bi bi-images"></i>

                <h3>Photo & Signature</h3>

            </div>

            <div class="section-body">

                <div class="row g-4">

                    <div class="col-md-6">

                        <div class="info-label mb-2">
                            Teacher Photo
                        </div>

                        <?php if (
                            $photoExists &&
                            isImageFile($photoPath)
                        ): ?>

                            <div>
                                <img
                                    src="<?= e($photoUrl) ?>"
                                    alt="<?= e($fullName) ?>"
                                    class="photo-preview"
                                >
                            </div>

                            <a
                                href="<?= e($photoUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="open-file-btn mt-3"
                                style="max-width:180px;"
                            >
                                <i class="bi bi-box-arrow-up-right"></i>
                                Open Photo
                            </a>

                        <?php else: ?>

                            <div class="not-uploaded">
                                <i class="bi bi-person-x"></i>
                                No teacher photo uploaded.
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="col-md-6">

                        <div class="info-label mb-2">
                            Signature
                        </div>

                        <?php if (
                            $signatureExists &&
                            isImageFile($signaturePath)
                        ): ?>

                            <div>
                                <img
                                    src="<?= e($signatureUrl) ?>"
                                    alt="Teacher signature"
                                    class="signature-preview"
                                >
                            </div>

                            <a
                                href="<?= e($signatureUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="open-file-btn mt-3"
                                style="max-width:260px;"
                            >
                                <i class="bi bi-box-arrow-up-right"></i>
                                Open Signature
                            </a>

                        <?php elseif ($signatureExists): ?>

                            <a
                                href="<?= e($signatureUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="open-file-btn"
                                style="max-width:260px;"
                            >
                                <i class="bi bi-file-earmark"></i>
                                Open Signature File
                            </a>

                        <?php else: ?>

                            <div class="not-uploaded">
                                <i class="bi bi-file-earmark-x"></i>
                                No signature uploaded.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('show');
        sidebarOverlay.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }

    mobileMenuBtn?.addEventListener('click', openSidebar);
    sidebarOverlay?.addEventListener('click', closeSidebar);
</script>

</body>

</html>