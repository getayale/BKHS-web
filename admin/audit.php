<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    strtolower((string) ($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$fullName = $_SESSION['full_name'] ?? 'Administrator';

$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = strtolower(trim((string) ($_GET['role'] ?? '')));
$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedRoles = [
    'admin',
    'principal',
    'registrar',
    'teacher',
    'librarian',
    'student',
    'parent'
];

if ($roleFilter !== '' && !in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(
        user_name LIKE ?
        OR action LIKE ?
        OR description LIKE ?
        OR target_type LIKE ?
        OR target_id LIKE ?
        OR ip_address LIKE ?
        OR device_type LIKE ?
        OR browser LIKE ?
        OR operating_system LIKE ?
    )';

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'sssssssss';
}

if ($roleFilter !== '') {
    $where[] = 'user_role = ?';
    $params[] = $roleFilter;
    $types .= 's';
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$countSql = "
    SELECT COUNT(*)
    FROM audit_logs
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die('Failed to prepare audit count query.');
}

if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$countStmt->bind_result($totalRecords);
$countStmt->fetch();
$countStmt->close();

$totalRecords = (int) $totalRecords;

$totalPages = max(1, (int) ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT
        id,
        user_id,
        user_name,
        user_role,
        action,
        description,
        target_type,
        target_id,
        old_values,
        new_values,
        ip_address,
        device_type,
        browser,
        operating_system,
        user_agent,
        created_at
    FROM audit_logs
    {$whereSql}
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare audit query.');
}

$queryParams = $params;
$queryTypes = $types . 'ii';

$queryParams[] = $perPage;
$queryParams[] = $offset;

$stmt->bind_param($queryTypes, ...$queryParams);

$stmt->execute();

$result = $stmt->get_result();

$auditLogs = [];

while ($row = $result->fetch_assoc()) {
    $auditLogs[] = $row;
}

$stmt->close();

$todayCount = 0;
$warningCount = 0;
$criticalCount = 0;

$todaySql = "
    SELECT COUNT(*)
    FROM audit_logs
    WHERE DATE(created_at) = CURDATE()
";

$todayResult = $conn->query($todaySql);

if ($todayResult) {
    $todayCount = (int) $todayResult->fetch_row()[0];
    $todayResult->free();
}

$warningSql = "
    SELECT COUNT(*)
    FROM audit_logs
    WHERE LOWER(action) LIKE '%warning%'
       OR LOWER(description) LIKE '%warning%'
";

$warningResult = $conn->query($warningSql);

if ($warningResult) {
    $warningCount = (int) $warningResult->fetch_row()[0];
    $warningResult->free();
}

$criticalSql = "
    SELECT COUNT(*)
    FROM audit_logs
    WHERE LOWER(action) LIKE '%delete%'
       OR LOWER(action) LIKE '%remove%'
       OR LOWER(action) LIKE '%failed%'
       OR LOWER(action) LIKE '%critical%'
";

$criticalResult = $conn->query($criticalSql);

if ($criticalResult) {
    $criticalCount = (int) $criticalResult->fetch_row()[0];
    $criticalResult->free();
}

function roleLabel(?string $role): string
{
    if (!$role) {
        return 'Unknown';
    }

    return match (strtolower($role)) {
        'admin' => 'Administrator',
        'principal' => 'Principal',
        'registrar' => 'Registrar',
        'teacher' => 'Teacher',
        'librarian' => 'Librarian',
        'student' => 'Student',
        'parent' => 'Parent',
        default => ucfirst($role)
    };
}

function roleIcon(?string $role): string
{
    return match (strtolower((string) $role)) {
        'admin' => 'bi-shield-lock-fill',
        'principal' => 'bi-person-badge-fill',
        'registrar' => 'bi-clipboard2-check-fill',
        'teacher' => 'bi-person-workspace',
        'librarian' => 'bi-book-fill',
        'student' => 'bi-mortarboard-fill',
        'parent' => 'bi-people-fill',
        default => 'bi-person-fill'
    };
}

function actionLabel(string $action): string
{
    $action = str_replace(['_', '-'], ' ', strtolower($action));

    return ucwords($action);
}

function formatAuditDate(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return htmlspecialchars($date);
    }

    return date('M d, Y', $timestamp) .
        '<span class="time">' .
        date('h:i A', $timestamp) .
        '</span>';
}

function formatJsonValue(?string $json): string
{
    if ($json === null || trim($json) === '') {
        return 'No data';
    }

    $decoded = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $json;
    }

    return json_encode(
        $decoded,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}

function buildPageUrl(
    int $page,
    string $search,
    string $role
): string {
    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($role !== '') {
        $query['role'] = $role;
    }

    return '?' . http_build_query($query);
}

$firstRecord = $totalRecords > 0
    ? $offset + 1
    : 0;

$lastRecord = min(
    $offset + $perPage,
    $totalRecords
);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Log | BKHS Admin</title>

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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../public/css/admin.css"
    >

    <style>
        .audit-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .audit-stat {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .audit-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #4f46e5;
            font-size: 20px;
            flex-shrink: 0;
        }

        .audit-stat-value {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }

        .audit-stat-label {
            margin-top: 3px;
            color: #6b7280;
            font-size: 13px;
        }

        .audit-toolbar {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .audit-toolbar-form {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .audit-search {
            position: relative;
            flex: 1;
        }

        .audit-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 17px;
        }

        .audit-search input {
            width: 100%;
            height: 44px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            padding: 0 14px 0 42px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            outline: none;
        }

        .audit-search input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .08);
        }

        .audit-role-select {
            height: 44px;
            min-width: 190px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            padding: 0 38px 0 13px;
            color: #374151;
            background-color: #fff;
            font-size: 14px;
            outline: none;
        }

        .audit-role-select:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .08);
        }

        .audit-search-button {
            height: 44px;
            border: 0;
            border-radius: 9px;
            padding: 0 18px;
            background: #4f46e5;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }

        .audit-search-button:hover {
            background: #4338ca;
        }

        .audit-clear-button {
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            color: #374151;
            background: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .audit-clear-button:hover {
            background: #f9fafb;
        }

        .audit-table-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
        }

        .audit-table-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .audit-table-title {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: #111827;
        }

        .audit-table-count {
            font-size: 13px;
            color: #6b7280;
        }

        .audit-table-wrapper {
            overflow-x: auto;
        }

        .audit-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            min-width: 950px;
        }

        .audit-table th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 13px 18px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .audit-table td {
            padding: 15px 18px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
            color: #374151;
            font-size: 13px;
        }

        .audit-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .audit-table tbody tr:hover {
            background: #fafafa;
        }

        .audit-date {
            color: #374151;
            font-weight: 600;
            white-space: nowrap;
        }

        .audit-date .time {
            display: block;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 500;
            margin-top: 3px;
        }

        .audit-user {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 170px;
        }

        .audit-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        .audit-user-name {
            font-weight: 600;
            color: #111827;
        }

        .audit-user-id {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 2px;
        }

        .audit-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f3f4f6;
            color: #4b5563;
            border-radius: 7px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .audit-action {
            color: #111827;
            font-weight: 600;
            white-space: nowrap;
        }

        .audit-description {
            color: #6b7280;
            max-width: 300px;
            line-height: 1.5;
        }

        .audit-target {
            font-size: 12px;
            color: #6b7280;
        }

        .audit-target strong {
            color: #374151;
        }

        .audit-view-button {
            width: 34px;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #4f46e5;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .audit-view-button:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
        }

        .audit-empty {
            padding: 60px 20px;
            text-align: center;
            color: #6b7280;
        }

        .audit-empty-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: #f3f4f6;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }

        .audit-empty h3 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 16px;
            color: #374151;
            margin-bottom: 6px;
        }

        .audit-empty p {
            margin: 0;
            font-size: 13px;
        }

        .audit-pagination {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .audit-pagination-info {
            color: #6b7280;
            font-size: 13px;
        }

        .audit-pagination-links {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .audit-page-link {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #374151;
            background: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        .audit-page-link:hover {
            background: #f9fafb;
        }

        .audit-page-link.active {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
        }

        .audit-page-link.disabled {
            color: #d1d5db;
            pointer-events: none;
        }

        .audit-modal-label {
            color: #9ca3af;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .audit-modal-value {
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            word-break: break-word;
        }

        .audit-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .audit-detail-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 13px;
        }

        .audit-device-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .audit-device-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .audit-device-content {
            min-width: 0;
        }

        .audit-device-name {
            color: #111827;
            font-size: 14px;
            font-weight: 700;
        }

        .audit-device-meta {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .audit-json {
            background: #111827;
            color: #e5e7eb;
            border-radius: 10px;
            padding: 14px;
            font-family: Consolas, monospace;
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 260px;
            overflow-y: auto;
        }

        @media (max-width: 1100px) {
            .audit-summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .audit-summary {
                grid-template-columns: 1fr;
            }

            .audit-toolbar-form {
                flex-direction: column;
                align-items: stretch;
            }

            .audit-role-select,
            .audit-search-button,
            .audit-clear-button {
                width: 100%;
            }

            .audit-pagination {
                flex-direction: column;
                align-items: stretch;
            }

            .audit-pagination-links {
                justify-content: center;
            }

            .audit-detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <aside class="admin-sidebar" id="adminSidebar">

        <div class="sidebar-brand">
            <div class="brand-mark">
                <i class="bi bi-grid-1x2-fill"></i>
            </div>

            <div class="brand-text">
                <strong>BKHS</strong>
                <span>Administration</span>
            </div>

            <button
                type="button"
                class="sidebar-close"
                id="sidebarClose"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <nav class="sidebar-nav">

            <div class="nav-section-title">
                MAIN
            </div>

            <a
                href="dashboard.php"
                class="sidebar-link"
            >
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="users/index.php"
                class="sidebar-link"
            >
                <i class="bi bi-people"></i>
                <span>Users</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-mortarboard"></i>
                <span>Students</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Staff</span>
            </a>

            <div class="nav-section-title">
                ACADEMIC
            </div>

            <a
                href="subjectassignment.php"
                class="sidebar-link"
            >
                <i class="bi bi-journal-bookmark"></i>
                <span>Subjects</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-building"></i>
                <span>Classes</span>
            </a>

            <a
                href="academic-calendar/index.php"
                class="sidebar-link"
            >
                <i class="bi bi-calendar3"></i>
                <span>Academic Calendar</span>
            </a>

            <div class="nav-section-title">
                MANAGEMENT
            </div>

            <a
                href="gallery.php"
                class="sidebar-link"
            >
                <i class="bi bi-wallet2"></i>
                <span>Gallery</span>
            </a>

            <a
                href="backup.php"
                class="sidebar-link"
            >
                <i class="bi bi-database"></i>
                <span>Backup</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-bar-chart"></i>
                <span>Reports</span>
            </a>

            <div class="nav-section-title">
                SYSTEM
            </div>

            <a
                href="#"
                class="sidebar-link active"
            >
                <i class="bi bi-clock-history"></i>
                <span>Audit Log</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-gear"></i>
                <span>Settings</span>
            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="../auth/logout.php"
                class="sidebar-link logout-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign out</span>
            </a>

        </div>

    </aside>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <div class="admin-main">

        <header class="admin-topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">
                <span>Administration</span>
                <h1>Audit Log</h1>
            </div>

            <div class="topbar-actions">

                <button
                    type="button"
                    class="topbar-icon-button"
                    title="Notifications"
                >
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot"></span>
                </button>

                <div class="admin-profile">

                    <div class="profile-avatar">
                        <?php echo htmlspecialchars(
                            strtoupper(substr($fullName, 0, 1))
                        ); ?>
                    </div>

                    <div class="profile-info">
                        <strong>
                            <?php echo htmlspecialchars($fullName); ?>
                        </strong>

                        <span>
                            Administrator
                        </span>
                    </div>

                    <i class="bi bi-chevron-down profile-chevron"></i>

                </div>

            </div>

        </header>

        <main class="admin-content">

            <div class="audit-summary">

                <div class="audit-stat">
                    <div class="audit-stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>

                    <div>
                        <div class="audit-stat-value">
                            <?php echo number_format($totalRecords); ?>
                        </div>

                        <div class="audit-stat-label">
                            Total Events
                        </div>
                    </div>
                </div>

                <div class="audit-stat">
                    <div class="audit-stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>

                    <div>
                        <div class="audit-stat-value">
                            <?php echo number_format($todayCount); ?>
                        </div>

                        <div class="audit-stat-label">
                            Events Today
                        </div>
                    </div>
                </div>

                <div class="audit-stat">
                    <div class="audit-stat-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>

                    <div>
                        <div class="audit-stat-value">
                            <?php echo number_format($warningCount); ?>
                        </div>

                        <div class="audit-stat-label">
                            Warnings
                        </div>
                    </div>
                </div>

                <div class="audit-stat">
                    <div class="audit-stat-icon">
                        <i class="bi bi-shield-exclamation"></i>
                    </div>

                    <div>
                        <div class="audit-stat-value">
                            <?php echo number_format($criticalCount); ?>
                        </div>

                        <div class="audit-stat-label">
                            Critical Events
                        </div>
                    </div>
                </div>

            </div>

            <div class="audit-toolbar">

                <form
                    method="GET"
                    class="audit-toolbar-form"
                >

                    <div class="audit-search">
                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            name="search"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search audit logs..."
                        >
                    </div>

                    <select
                        name="role"
                        class="audit-role-select"
                    >
                        <option value="">
                            All Users
                        </option>

                        <?php foreach ($allowedRoles as $role): ?>
                            <option
                                value="<?php echo htmlspecialchars($role); ?>"
                                <?php echo $roleFilter === $role ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars(roleLabel($role)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="submit"
                        class="audit-search-button"
                    >
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>

                    <?php if ($search !== '' || $roleFilter !== ''): ?>

                        <a
                            href="audit.php"
                            class="audit-clear-button"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </form>

            </div>

            <div class="audit-table-card">

                <div class="audit-table-header">

                    <h2 class="audit-table-title">
                        System Activity
                    </h2>

                    <div class="audit-table-count">

                        <?php if ($totalRecords > 0): ?>

                            Showing
                            <?php echo number_format($firstRecord); ?>
                            –
                            <?php echo number_format($lastRecord); ?>
                            of
                            <?php echo number_format($totalRecords); ?>

                        <?php else: ?>

                            No events found

                        <?php endif; ?>

                    </div>

                </div>

                <?php if (empty($auditLogs)): ?>

                    <div class="audit-empty">

                        <div class="audit-empty-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>

                        <h3>
                            No audit events found
                        </h3>

                        <p>
                            There are no activities matching your search.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="audit-table-wrapper">

                        <table class="audit-table">

                            <thead>

                                <tr>
                                    <th>Date & Time</th>
                                    <th>User</th>
                                    <th>User Type</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>Target</th>
                                    <th>View</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($auditLogs as $log): ?>

                                <?php
                                $userName = trim(
                                    (string) ($log['user_name'] ?? '')
                                );

                                if ($userName === '') {
                                    $userName = 'Unknown User';
                                }

                                $initial = strtoupper(
                                    substr($userName, 0, 1)
                                );
                                ?>

                                <tr>

                                    <td>

                                        <div class="audit-date">

                                            <?php
                                            echo formatAuditDate(
                                                (string) $log['created_at']
                                            );
                                            ?>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="audit-user">

                                            <div class="audit-avatar">
                                                <?php
                                                echo htmlspecialchars($initial);
                                                ?>
                                            </div>

                                            <div>

                                                <div class="audit-user-name">
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $userName
                                                    );
                                                    ?>
                                                </div>

                                                <?php if ($log['user_id'] !== null): ?>

                                                    <div class="audit-user-id">
                                                        User #<?php
                                                        echo htmlspecialchars(
                                                            (string) $log['user_id']
                                                        );
                                                        ?>
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="audit-role">

                                            <i class="bi <?php
                                            echo htmlspecialchars(
                                                roleIcon($log['user_role'])
                                            );
                                            ?>"></i>

                                            <?php
                                            echo htmlspecialchars(
                                                roleLabel($log['user_role'])
                                            );
                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div class="audit-action">

                                            <?php
                                            echo htmlspecialchars(
                                                actionLabel(
                                                    (string) $log['action']
                                                )
                                            );
                                            ?>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="audit-description">

                                            <?php
                                            echo htmlspecialchars(
                                                (string) $log['description']
                                            );
                                            ?>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="audit-target">

                                            <?php if (!empty($log['target_type'])): ?>

                                                <strong>

                                                    <?php
                                                    echo htmlspecialchars(
                                                        ucfirst(
                                                            (string) $log['target_type']
                                                        )
                                                    );
                                                    ?>

                                                </strong>

                                                <?php if ($log['target_id'] !== null): ?>

                                                    #

                                                    <?php
                                                    echo htmlspecialchars(
                                                        (string) $log['target_id']
                                                    );
                                                    ?>

                                                <?php endif; ?>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <td>

                                        <button
                                            type="button"
                                            class="audit-view-button"
                                            title="View Details"
                                            data-bs-toggle="modal"
                                            data-bs-target="#auditDetailsModal"

                                            data-id="<?php
                                            echo htmlspecialchars(
                                                (string) $log['id']
                                            );
                                            ?>"

                                            data-user="<?php
                                            echo htmlspecialchars(
                                                $userName
                                            );
                                            ?>"

                                            data-user-id="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['user_id'] ?? '')
                                            );
                                            ?>"

                                            data-role="<?php
                                            echo htmlspecialchars(
                                                roleLabel($log['user_role'])
                                            );
                                            ?>"

                                            data-action="<?php
                                            echo htmlspecialchars(
                                                actionLabel(
                                                    (string) $log['action']
                                                )
                                            );
                                            ?>"

                                            data-description="<?php
                                            echo htmlspecialchars(
                                                (string) $log['description']
                                            );
                                            ?>"

                                            data-target-type="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['target_type'] ?? '')
                                            );
                                            ?>"

                                            data-target-id="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['target_id'] ?? '')
                                            );
                                            ?>"

                                            data-created="<?php
                                            echo htmlspecialchars(
                                                (string) $log['created_at']
                                            );
                                            ?>"

                                            data-ip="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['ip_address'] ?? '')
                                            );
                                            ?>"

                                            data-device="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['device_type'] ?? '')
                                            );
                                            ?>"

                                            data-browser="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['browser'] ?? '')
                                            );
                                            ?>"

                                            data-os="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['operating_system'] ?? '')
                                            );
                                            ?>"

                                            data-user-agent="<?php
                                            echo htmlspecialchars(
                                                (string) ($log['user_agent'] ?? '')
                                            );
                                            ?>"

                                            data-old="<?php
                                            echo htmlspecialchars(
                                                formatJsonValue(
                                                    $log['old_values']
                                                )
                                            );
                                            ?>"

                                            data-new="<?php
                                            echo htmlspecialchars(
                                                formatJsonValue(
                                                    $log['new_values']
                                                )
                                            );
                                            ?>"
                                        >

                                            <i class="bi bi-eye"></i>

                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if ($totalPages > 1): ?>

                        <div class="audit-pagination">

                            <div class="audit-pagination-info">

                                Page
                                <?php echo $page; ?>
                                of
                                <?php echo $totalPages; ?>

                            </div>

                            <div class="audit-pagination-links">

                                <a
                                    href="<?php
                                    echo $page > 1
                                        ? htmlspecialchars(
                                            buildPageUrl(
                                                $page - 1,
                                                $search,
                                                $roleFilter
                                            )
                                        )
                                        : '#';
                                    ?>"
                                    class="audit-page-link <?php
                                    echo $page <= 1 ? 'disabled' : '';
                                    ?>"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                for (
                                    $i = $startPage;
                                    $i <= $endPage;
                                    $i++
                                ):
                                ?>

                                    <a
                                        href="<?php
                                        echo htmlspecialchars(
                                            buildPageUrl(
                                                $i,
                                                $search,
                                                $roleFilter
                                            )
                                        );
                                        ?>"
                                        class="audit-page-link <?php
                                        echo $i === $page ? 'active' : '';
                                        ?>"
                                    >
                                        <?php echo $i; ?>
                                    </a>

                                <?php endfor; ?>

                                <a
                                    href="<?php
                                    echo $page < $totalPages
                                        ? htmlspecialchars(
                                            buildPageUrl(
                                                $page + 1,
                                                $search,
                                                $roleFilter
                                            )
                                        )
                                        : '#';
                                    ?>"
                                    class="audit-page-link <?php
                                    echo $page >= $totalPages ? 'disabled' : '';
                                    ?>"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </main>

    </div>

</div>

<div
    class="modal fade"
    id="auditDetailsModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">
                        Audit Event Details
                    </h5>

                    <small class="text-muted">
                        Complete information about this activity
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div class="audit-detail-grid">

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Event ID
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailId"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Date & Time
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailCreated"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            User
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailUser"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            User Type
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailRole"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Action
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailAction"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Target
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailTarget"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            IP Address
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailIp"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            User ID
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailUserId"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Device
                        </div>

                        <div class="audit-device-box">

                            <div
                                class="audit-device-icon"
                                id="detailDeviceIcon"
                            >
                                <i class="bi bi-pc-display"></i>
                            </div>

                            <div class="audit-device-content">

                                <div
                                    class="audit-device-name"
                                    id="detailDevice"
                                ></div>

                                <div
                                    class="audit-device-meta"
                                    id="detailDeviceMeta"
                                ></div>

                            </div>

                        </div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Browser
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailBrowser"
                        ></div>

                    </div>

                    <div class="audit-detail-box">

                        <div class="audit-modal-label">
                            Operating System
                        </div>

                        <div
                            class="audit-modal-value"
                            id="detailOs"
                        ></div>

                    </div>

                </div>

                <div class="mt-4">

                    <div class="audit-modal-label">
                        Description
                    </div>

                    <div
                        class="audit-modal-value"
                        id="detailDescription"
                    ></div>

                </div>

                <div class="mt-4">

                    <div class="audit-modal-label">
                        Old Values
                    </div>

                    <div
                        class="audit-json"
                        id="detailOld"
                    ></div>

                </div>

                <div class="mt-4">

                    <div class="audit-modal-label">
                        New Values
                    </div>

                    <div
                        class="audit-json"
                        id="detailNew"
                    ></div>

                </div>

                <div class="mt-4">

                    <div class="audit-modal-label">
                        Full User Agent
                    </div>

                    <div
                        class="audit-json"
                        id="detailUserAgent"
                    ></div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-light"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const sidebarClose = document.getElementById('sidebarClose');

    function openSidebar() {
        sidebar.classList.add('show');
        overlay.classList.add('show');
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');
    }

    mobileMenuButton?.addEventListener(
        'click',
        openSidebar
    );

    sidebarClose?.addEventListener(
        'click',
        closeSidebar
    );

    overlay?.addEventListener(
        'click',
        closeSidebar
    );

    window.addEventListener(
        'resize',
        function () {
            if (window.innerWidth >= 992) {
                closeSidebar();
            }
        }
    );

    const auditModal = document.getElementById(
        'auditDetailsModal'
    );

    auditModal?.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            if (!button) {
                return;
            }

            document.getElementById('detailId').textContent =
                button.dataset.id || '—';

            document.getElementById('detailCreated').textContent =
                button.dataset.created || '—';

            document.getElementById('detailUser').textContent =
                button.dataset.user || 'Unknown User';

            document.getElementById('detailUserId').textContent =
                button.dataset.userId || '—';

            document.getElementById('detailRole').textContent =
                button.dataset.role || 'Unknown';

            document.getElementById('detailAction').textContent =
                button.dataset.action || '—';

            let target = '—';

            if (button.dataset.targetType) {

                target = button.dataset.targetType;

                if (button.dataset.targetId) {
                    target += ' #' + button.dataset.targetId;
                }
            }

            document.getElementById('detailTarget').textContent =
                target;

            document.getElementById('detailDescription').textContent =
                button.dataset.description || '—';

            document.getElementById('detailIp').textContent =
                button.dataset.ip || 'Not available';

            document.getElementById('detailDevice').textContent =
                button.dataset.device || 'Unknown';

            document.getElementById('detailBrowser').textContent =
                button.dataset.browser || 'Unknown';

            document.getElementById('detailOs').textContent =
                button.dataset.os || 'Unknown';

            const device =
                (button.dataset.device || '').toLowerCase();

            const deviceIcon =
                document.querySelector('#detailDeviceIcon i');

            if (deviceIcon) {

                if (device.includes('mobile')) {

                    deviceIcon.className =
                        'bi bi-phone-fill';

                } else if (device.includes('tablet')) {

                    deviceIcon.className =
                        'bi bi-tablet-fill';

                } else {

                    deviceIcon.className =
                        'bi bi-pc-display';
                }
            }

            const browser =
                button.dataset.browser || '';

            const operatingSystem =
                button.dataset.os || '';

            let deviceMeta = '';

            if (browser && operatingSystem) {

                deviceMeta =
                    browser +
                    ' • ' +
                    operatingSystem;

            } else if (browser) {

                deviceMeta = browser;

            } else if (operatingSystem) {

                deviceMeta = operatingSystem;
            }

            document.getElementById('detailDeviceMeta').textContent =
                deviceMeta;

            document.getElementById('detailOld').textContent =
                button.dataset.old || 'No data';

            document.getElementById('detailNew').textContent =
                button.dataset.new || 'No data';

            document.getElementById('detailUserAgent').textContent =
                button.dataset.userAgent || 'No data';
        }
    );
</script>

</body>
</html>
