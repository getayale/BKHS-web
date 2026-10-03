<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatEthiopianDate(?string $dateTime): string
{
    if (empty($dateTime)) {
        return '—';
    }

    try {
        $date = substr($dateTime, 0, 10);
        $eth = EthiopianCalendar::fromGregorian($date);

        return e(
            $eth['day']
            . ' '
            . $eth['month_name']
            . ' '
            . $eth['year']
        );
    } catch (Throwable $exception) {
        return e($dateTime);
    }
}

function statusClass(string $status): string
{
    return match (strtolower($status)) {
        'published' => 'status-published',
        'closed' => 'status-closed',
        default => 'status-draft',
    };
}

function statusIcon(string $status): string
{
    return match (strtolower($status)) {
        'published' => 'bi-check-circle-fill',
        'closed' => 'bi-x-circle-fill',
        default => 'bi-pencil-square',
    };
}

function audienceLabel(string $type, ?int $grade): string
{
    return match ($type) {
        'Public' => 'Public',
        'Student' => $grade !== null
            ? 'Students — Grade ' . $grade
            : 'Students — All Grades',
        'Parent' => $grade !== null
            ? 'Parents — Grade ' . $grade
            : 'Parents — All Grades',
        'Teacher' => 'Teachers — All Teachers',
        default => $type,
    };
}

function mediaUrl(string $path): string
{
    return '../../' . ltrim($path, '/');
}

function isImageMime(?string $mimeType): bool
{
    return in_array(
        strtolower((string) $mimeType),
        [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/jpg',
        ],
        true
    );
}

/*
|--------------------------------------------------------------------------
| Announcement ID
|--------------------------------------------------------------------------
*/

$announcementId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!$announcementId) {
    header('Location: ../announcements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['announcement_csrf']) ||
    !is_string($_SESSION['announcement_csrf']) ||
    strlen($_SESSION['announcement_csrf']) !== 64
) {
    $_SESSION['announcement_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['announcement_csrf'];

/*
|--------------------------------------------------------------------------
| Automatically close expired published announcements
|--------------------------------------------------------------------------
*/

$expireStmt = $conn->prepare(
    "UPDATE announcements
     SET status = 'Closed'
     WHERE status = 'Published'
       AND closed_at IS NOT NULL
       AND closed_at < NOW()"
);

$expireStmt->execute();
$expireStmt->close();

/*
|--------------------------------------------------------------------------
| Handle delete
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $_SESSION['announcement_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid security token. Please try again.',
        ];

        header('Location: view.php?id=' . $announcementId);
        exit;
    }

    if ($action === 'delete') {
        try {
            $conn->begin_transaction();

            $deleteStmt = $conn->prepare(
                "DELETE FROM announcements WHERE id = ? LIMIT 1"
            );

            $deleteStmt->bind_param('i', $announcementId);
            $deleteStmt->execute();

            $deleted = $deleteStmt->affected_rows > 0;

            $deleteStmt->close();

            if (!$deleted) {
                throw new RuntimeException('Announcement was not found.');
            }

            $conn->commit();

            $_SESSION['announcement_flash'] = [
                'type' => 'success',
                'message' => 'Announcement deleted successfully.',
            ];

            header('Location: ../announcements.php');
            exit;
        } catch (Throwable $exception) {
            if ($conn->errno === 0) {
                // No active transaction information available.
            }

            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // Ignore rollback errors.
            }

            $_SESSION['announcement_flash'] = [
                'type' => 'danger',
                'message' => 'The announcement could not be deleted.',
            ];

            header('Location: view.php?id=' . $announcementId);
            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Principal information
|--------------------------------------------------------------------------
*/

$principal = [
    'id' => 0,
    'full_name' => 'Principal',
    'email' => '',
    'phone' => '',
    'photo' => '',
];

$principalStmt = $conn->prepare(
    "SELECT
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
     LIMIT 1"
);

$principalUserId = (int) $_SESSION['user_id'];

$principalStmt->bind_param('i', $principalUserId);
$principalStmt->execute();

$principalResult = $principalStmt->get_result();

if ($principalRow = $principalResult->fetch_assoc()) {
    $principal = $principalRow;
}

$principalStmt->close();

$principalPhoto = $principal['photo'] ?? '';
$principalPhotoUrl = '';

if (!empty($principalPhoto)) {
    $principalPhotoUrl = '../../' . ltrim($principalPhoto, '/');
}

/*
|--------------------------------------------------------------------------
| Load announcement
|--------------------------------------------------------------------------
*/

$announcementStmt = $conn->prepare(
    "SELECT
        a.id,
        a.title,
        a.content,
        a.status,
        a.created_by,
        a.published_at,
        a.closed_at,
        a.created_at,
        a.updated_at,
        u.full_name AS created_by_name
     FROM announcements a
     LEFT JOIN users u
        ON u.id = a.created_by
     WHERE a.id = ?
     LIMIT 1"
);

$announcementStmt->bind_param('i', $announcementId);
$announcementStmt->execute();

$announcementResult = $announcementStmt->get_result();
$announcement = $announcementResult->fetch_assoc();

$announcementStmt->close();

if (!$announcement) {
    $_SESSION['announcement_flash'] = [
        'type' => 'danger',
        'message' => 'Announcement not found.',
    ];

    header('Location: ../announcements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load audiences
|--------------------------------------------------------------------------
*/

$audiences = [];

$audienceStmt = $conn->prepare(
    "SELECT
        id,
        audience_type,
        grade
     FROM announcement_audiences
     WHERE announcement_id = ?
     ORDER BY
        FIELD(audience_type, 'Public', 'Student', 'Parent', 'Teacher'),
        grade ASC,
        id ASC"
);

$audienceStmt->bind_param('i', $announcementId);
$audienceStmt->execute();

$audienceResult = $audienceStmt->get_result();

while ($row = $audienceResult->fetch_assoc()) {
    $audiences[] = $row;
}

$audienceStmt->close();

/*
|--------------------------------------------------------------------------
| Load media
|--------------------------------------------------------------------------
*/

$media = [];

$mediaStmt = $conn->prepare(
    "SELECT
        id,
        media_type,
        file_path,
        original_name,
        mime_type,
        file_size,
        display_order
     FROM announcement_media
     WHERE announcement_id = ?
     ORDER BY display_order ASC, id ASC"
);

$mediaStmt->bind_param('i', $announcementId);
$mediaStmt->execute();

$mediaResult = $mediaStmt->get_result();

while ($row = $mediaResult->fetch_assoc()) {
    $media[] = $row;
}

$mediaStmt->close();

/*
|--------------------------------------------------------------------------
| Load content blocks
|--------------------------------------------------------------------------
*/

$contentBlocks = [];

$blocksStmt = $conn->prepare(
    "SELECT
        b.id,
        b.block_type,
        b.content,
        b.media_id,
        b.display_order,
        m.file_path,
        m.original_name,
        m.mime_type
     FROM announcement_content_blocks b
     LEFT JOIN announcement_media m
        ON m.id = b.media_id
     WHERE b.announcement_id = ?
     ORDER BY b.display_order ASC, b.id ASC"
);

$blocksStmt->bind_param('i', $announcementId);
$blocksStmt->execute();

$blocksResult = $blocksStmt->get_result();

while ($row = $blocksResult->fetch_assoc()) {
    $contentBlocks[] = $row;
}

$blocksStmt->close();

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['announcement_flash'] ?? null;
unset($_SESSION['announcement_flash']);

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

$announcementStatus = (string) $announcement['status'];

$closedAt = $announcement['closed_at'] ?? null;

$isExpired = false;

if (!empty($closedAt)) {
    $isExpired = strtotime((string) $closedAt) < time();
}

$statusText = $announcementStatus;

if (
    strtolower($announcementStatus) === 'published' &&
    $isExpired
) {
    $statusText = 'Closed';
}

/*
|--------------------------------------------------------------------------
| Ethiopian today
|--------------------------------------------------------------------------
*/

$today = EthiopianCalendar::todayFormatted();

/*
|--------------------------------------------------------------------------
| Current page
|--------------------------------------------------------------------------
*/

$currentPage = 'announcements.php';

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
        <?= e((string) $announcement['title']) ?> - Announcement
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
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            color: #fff;
            font-size: 20px;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .sidebar-brand i {
            color: #818cf8;
            font-size: 25px;
            margin-right: 10px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            overflow-y: auto;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 10px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #9ca3af;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
            transform: translateX(2px);
        }

        .sidebar-link.active {
            background: rgba(79, 70, 229, .18);
            color: #fff;
        }

        .sidebar-link.active i {
            color: #818cf8;
        }

        .sidebar-bottom {
            padding: 14px 12px 18px;
            border-top: 1px solid rgba(255,255,255,.06);
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

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
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0;
        }

        .today {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: #f3f4f6;
            width: 40px;
            height: 40px;
            border-radius: 9px;
            font-size: 20px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .principal-info {
            text-align: right;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            color: var(--muted);
            font-size: 11px;
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content {
            padding: 28px 30px 40px;
        }

        .breadcrumb-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 18px;
        }

        .breadcrumb-wrap a {
            color: var(--primary);
            font-weight: 600;
        }

        .announcement-header {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 26px;
            margin-bottom: 20px;
            box-shadow: 0 3px 14px rgba(15, 23, 42, .03);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
        }

        .announcement-title {
            margin: 0 0 12px;
            font-size: 27px;
            line-height: 1.3;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            color: var(--muted);
            font-size: 12px;
        }

        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .meta-item i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-primary-custom {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
            border-radius: 9px;
            padding: 9px 14px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-light-custom {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 9px;
            padding: 9px 14px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .btn-light-custom:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-published {
            background: #dcfce7;
            color: #15803d;
        }

        .status-closed {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-draft {
            background: #fef3c7;
            color: #b45309;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }

        .info-box {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 13px 14px;
            background: #fafafa;
        }

        .info-label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .info-value {
            font-size: 12px;
            font-weight: 600;
        }

        .page-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 310px;
            gap: 20px;
            align-items: start;
        }

        .card-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 14px rgba(15, 23, 42, .03);
        }

        .card-header-custom {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .card-header-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
        }

        .card-body-custom {
            padding: 22px;
        }

        .announcement-content {
            font-size: 14px;
            line-height: 1.8;
            color: #374151;
        }

        .announcement-content p:last-child {
            margin-bottom: 0;
        }

        .content-block {
            margin-bottom: 24px;
        }

        .content-block:last-child {
            margin-bottom: 0;
        }

        .block-heading {
            font-size: 20px;
            font-weight: 750;
            line-height: 1.4;
            margin: 0;
        }

        .block-quote {
            margin: 0;
            padding: 14px 18px;
            border-left: 4px solid var(--primary);
            background: #f5f3ff;
            border-radius: 0 9px 9px 0;
            color: #4b5563;
            font-style: italic;
        }

        .block-image {
            width: 100%;
            max-height: 520px;
            object-fit: contain;
            border-radius: 11px;
            border: 1px solid var(--border);
            background: #f9fafb;
        }

        .content-image-caption {
            color: var(--muted);
            font-size: 11px;
            margin-top: 7px;
        }

        .audience-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .audience-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 11px;
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 12px;
            font-weight: 600;
        }

        .audience-item i {
            color: var(--primary);
            font-size: 15px;
        }

        .media-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .media-item {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 10px;
            transition: all .2s ease;
        }

        .media-item:hover {
            border-color: #c7d2fe;
            background: #fafaff;
        }

        .media-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .media-name {
            flex: 1;
            min-width: 0;
        }

        .media-name strong {
            display: block;
            font-size: 11px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .media-type {
            display: block;
            color: var(--muted);
            font-size: 10px;
            margin-top: 2px;
        }

        .download-btn {
            color: var(--primary);
            font-size: 16px;
            padding: 4px;
        }

        .no-content {
            padding: 35px 10px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .flash-message {
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: 12px;
            border: 1px solid transparent;
        }

        .flash-success {
            background: #ecfdf5;
            border-color: #bbf7d0;
            color: #166534;
        }

        .flash-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .delete-form {
            display: inline;
        }

        .btn-delete {
            border: 1px solid #fecaca;
            color: #dc2626;
            background: #fff;
            border-radius: 9px;
            padding: 9px 12px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .btn-delete:hover {
            background: #fef2f2;
            border-color: #fca5a5;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 1100px) {
            .page-grid {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .overlay.show {
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
                padding: 22px 18px 35px;
            }
        }

        @media (max-width: 650px) {
            .principal-info {
                display: none;
            }

            .announcement-header {
                padding: 19px;
            }

            .header-top {
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions a,
            .header-actions button {
                flex: 1;
                text-align: center;
            }

            .announcement-title {
                font-size: 22px;
            }

            .info-grid {
                grid-template-columns: 1fr 1fr;
            }

            .card-body-custom {
                padding: 17px;
            }
        }

        @media (max-width: 450px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                height: 68px;
            }

            .content {
                padding: 18px 13px 30px;
            }
        }
    </style>
</head>

<body>

<div class="overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <i class="bi bi-mortarboard-fill"></i>
        BKHS School
    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">Main</div>

        <a
            href="../dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="../announcements.php"
            class="sidebar-link active"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <a
            href="../subject-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-half"></i>
            <span>Subject Assignment</span>
        </a>

        <a
            href="../homeroom-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a
            href="../student-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-check-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a
            href="../attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="../roster.php"
            class="sidebar-link"
        >
            <i class="bi bi-people-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="../certificate.php"
            class="sidebar-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="../result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="nav-section-title mt-2">Account</div>

        <a
            href="../profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

    </nav>

    <div class="sidebar-bottom">
        <a
            href="../../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>

<!-- Main -->
<div class="main">

    <!-- Topbar -->
    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="page-title">Announcement</h1>
                <div class="today">
                    <?= e($today) ?>
                </div>
            </div>

        </div>

        <div class="topbar-right">

            <div class="principal-info">
                <div class="principal-name">
                    <?= e((string) $principal['full_name']) ?>
                </div>

                <div class="principal-role">
                    Principal
                </div>
            </div>

            <div class="avatar">
                <?php if ($principalPhotoUrl !== ''): ?>
                    <img
                        src="<?= e($principalPhotoUrl) ?>"
                        alt="Principal"
                    >
                <?php else: ?>
                    <i class="bi bi-person-fill"></i>
                <?php endif; ?>
            </div>

        </div>

    </header>

    <main class="content">

        <?php if (is_array($flash)): ?>

            <div
                class="flash-message
                    <?= ($flash['type'] ?? '') === 'success'
                        ? 'flash-success'
                        : 'flash-danger' ?>"
            >
                <i
                    class="bi
                        <?= ($flash['type'] ?? '') === 'success'
                            ? 'bi-check-circle-fill'
                            : 'bi-exclamation-triangle-fill' ?>"
                ></i>

                <?= e((string) ($flash['message'] ?? '')) ?>
            </div>

        <?php endif; ?>

        <!-- Breadcrumb -->
        <div class="breadcrumb-wrap">

            <a href="../announcements.php">
                <i class="bi bi-megaphone-fill"></i>
                Announcements
            </a>

            <i class="bi bi-chevron-right"></i>

            <span>View</span>

        </div>

        <!-- Announcement Header -->
        <section class="announcement-header">

            <div class="header-top">

                <div>
                    <h2 class="announcement-title">
                        <?= e((string) $announcement['title']) ?>
                    </h2>

                    <div class="meta-row">

                        <span class="meta-item">
                            <i class="bi bi-person-fill"></i>
                            <?= e((string) ($announcement['created_by_name'] ?? 'Unknown')) ?>
                        </span>

                        <span class="meta-item">
                            <i class="bi bi-calendar3"></i>
                            Created
                            <?= formatEthiopianDate((string) $announcement['created_at']) ?>
                        </span>

                    </div>
                </div>

                <div class="header-actions">

                    <a
                        href="edit.php?id=<?= (int) $announcement['id'] ?>"
                        class="btn-primary-custom"
                    >
                        <i class="bi bi-pencil-square"></i>
                        Edit
                    </a>

                    <form
                        method="post"
                        class="delete-form"
                        onsubmit="return confirm('Are you sure you want to delete this announcement?');"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="delete"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <button
                            type="submit"
                            class="btn-delete"
                        >
                            <i class="bi bi-trash3"></i>
                            Delete
                        </button>
                    </form>

                </div>

            </div>

            <div class="info-grid">

                <div class="info-box">

                    <div class="info-label">
                        Status
                    </div>

                    <div class="info-value">

                        <span
                            class="status-badge <?= e(statusClass($statusText)) ?>"
                        >
                            <i
                                class="bi <?= e(statusIcon($statusText)) ?>"
                            ></i>

                            <?= e($statusText) ?>
                        </span>

                    </div>

                </div>

                <div class="info-box">

                    <div class="info-label">
                        Published
                    </div>

                    <div class="info-value">
                        <?= formatEthiopianDate($announcement['published_at']) ?>
                    </div>

                </div>

                <div class="info-box">

                    <div class="info-label">
                        Last Date
                    </div>

                    <div class="info-value">
                        <?= formatEthiopianDate($announcement['closed_at']) ?>

                        <?php if ($isExpired): ?>
                            <span
                                style="
                                    display:block;
                                    color:#dc2626;
                                    font-size:10px;
                                    margin-top:3px;
                                "
                            >
                                Expired
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

                <div class="info-box">

                    <div class="info-label">
                        Updated
                    </div>

                    <div class="info-value">
                        <?= formatEthiopianDate($announcement['updated_at']) ?>
                    </div>

                </div>

            </div>

        </section>

        <!-- Main Content -->
        <div class="page-grid">

            <section class="card-box">

                <div class="card-header-custom">

                    <h3 class="card-header-title">
                        <i class="bi bi-file-text me-2"></i>
                        Announcement Content
                    </h3>

                </div>

                <div class="card-body-custom announcement-content">

                    <?php
                    $hasContent = false;

                    foreach ($contentBlocks as $block):
                        $hasContent = true;

                        $blockType = (string) $block['block_type'];
                    ?>

                        <div class="content-block">

                            <?php if ($blockType === 'Heading'): ?>

                                <h3 class="block-heading">
                                    <?= nl2br(e((string) $block['content'])) ?>
                                </h3>

                            <?php elseif ($blockType === 'Quote'): ?>

                                <blockquote class="block-quote">
                                    <?= nl2br(e((string) $block['content'])) ?>
                                </blockquote>

                            <?php elseif ($blockType === 'Image'): ?>

                                <?php if (!empty($block['file_path'])): ?>

                                    <img
                                        src="<?= e(mediaUrl((string) $block['file_path'])) ?>"
                                        alt="<?= e((string) ($block['original_name'] ?? 'Announcement image')) ?>"
                                        class="block-image"
                                    >

                                    <?php if (!empty($block['original_name'])): ?>
                                        <div class="content-image-caption">
                                            <?= e((string) $block['original_name']) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php elseif (!empty($block['content'])): ?>

                                    <div>
                                        <?= nl2br(e((string) $block['content'])) ?>
                                    </div>

                                <?php endif; ?>

                            <?php else: ?>

                                <div>
                                    <?= nl2br(e((string) ($block['content'] ?? ''))) ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                    <?php
                    /*
                     * Older announcements may have content directly in
                     * announcements.content without a Text block.
                     */
                    if (
                        !$hasContent &&
                        !empty($announcement['content'])
                    ):
                        $hasContent = true;
                    ?>

                        <div>
                            <?= nl2br(e((string) $announcement['content'])) ?>
                        </div>

                    <?php endif; ?>

                    <?php if (!$hasContent): ?>

                        <div class="no-content">
                            <i class="bi bi-file-earmark-text fs-3 d-block mb-2"></i>
                            No announcement content available.
                        </div>

                    <?php endif; ?>

                </div>

            </section>

            <!-- Right Sidebar -->
            <aside>

                <!-- Audience -->
                <section class="card-box mb-3">

                    <div class="card-header-custom">

                        <h3 class="card-header-title">
                            <i class="bi bi-people-fill me-2"></i>
                            Audience
                        </h3>

                    </div>

                    <div class="card-body-custom">

                        <?php if (!empty($audiences)): ?>

                            <div class="audience-list">

                                <?php foreach ($audiences as $audience): ?>

                                    <div class="audience-item">

                                        <?php
                                        $audienceType =
                                            (string) $audience['audience_type'];

                                        $audienceIcon = match ($audienceType) {
                                            'Public' => 'bi-globe2',
                                            'Student' => 'bi-mortarboard-fill',
                                            'Parent' => 'bi-people-fill',
                                            'Teacher' => 'bi-person-workspace',
                                            default => 'bi-people',
                                        };
                                        ?>

                                        <i class="bi <?= e($audienceIcon) ?>"></i>

                                        <span>
                                            <?= e(
                                                audienceLabel(
                                                    $audienceType,
                                                    $audience['grade'] !== null
                                                        ? (int) $audience['grade']
                                                        : null
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="no-content py-3">
                                No audience specified.
                            </div>

                        <?php endif; ?>

                    </div>

                </section>

                <!-- Media -->
                <section class="card-box">

                    <div class="card-header-custom">

                        <h3 class="card-header-title">
                            <i class="bi bi-paperclip me-2"></i>
                            Media & Files
                        </h3>

                        <span
                            style="
                                font-size:11px;
                                color:var(--muted);
                                font-weight:600;
                            "
                        >
                            <?= count($media) ?>
                        </span>

                    </div>

                    <div class="card-body-custom">

                        <?php if (!empty($media)): ?>

                            <div class="media-list">

                                <?php foreach ($media as $item): ?>

                                    <?php
                                    $itemType = (string) $item['media_type'];
                                    $mimeType = $item['mime_type'] ?? null;

                                    if ($itemType === 'Image' || isImageMime($mimeType)) {
                                        $icon = 'bi-image-fill';
                                    } elseif ($mimeType === 'application/pdf') {
                                        $icon = 'bi-file-earmark-pdf-fill';
                                    } elseif (
                                        in_array(
                                            strtolower((string) $mimeType),
                                            [
                                                'application/msword',
                                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                            ],
                                            true
                                        )
                                    ) {
                                        $icon = 'bi-file-earmark-word-fill';
                                    } elseif (
                                        in_array(
                                            strtolower((string) $mimeType),
                                            [
                                                'application/vnd.ms-excel',
                                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                            ],
                                            true
                                        )
                                    ) {
                                        $icon = 'bi-file-earmark-excel-fill';
                                    } elseif (
                                        in_array(
                                            strtolower((string) $mimeType),
                                            [
                                                'application/vnd.ms-powerpoint',
                                                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                            ],
                                            true
                                        )
                                    ) {
                                        $icon = 'bi-file-earmark-ppt-fill';
                                    } else {
                                        $icon = 'bi-file-earmark-fill';
                                    }
                                    ?>

                                    <div class="media-item">

                                        <div class="media-icon">
                                            <i class="bi <?= e($icon) ?>"></i>
                                        </div>

                                        <div class="media-name">

                                            <strong
                                                title="<?= e((string) $item['original_name']) ?>"
                                            >
                                                <?= e((string) $item['original_name']) ?>
                                            </strong>

                                            <span class="media-type">
                                                <?= e($itemType) ?>

                                                <?php if (!empty($item['file_size'])): ?>
                                                    ·
                                                    <?= e(
                                                        number_format(
                                                            ((int) $item['file_size']) / 1024,
                                                            0
                                                        )
                                                    ) ?>
                                                    KB
                                                <?php endif; ?>
                                            </span>

                                        </div>

                                        <a
                                            href="<?= e(mediaUrl((string) $item['file_path'])) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="download-btn"
                                            title="Open file"
                                        >
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="no-content py-3">
                                <i class="bi bi-paperclip fs-4 d-block mb-2"></i>
                                No media or attachments.
                            </div>

                        <?php endif; ?>

                    </div>

                </section>

            </aside>

        </div>

    </main>

</div>

<script>
    const mobileMenu = document.getElementById('mobileMenu');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (mobileMenu) {
        mobileMenu.addEventListener('click', openSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    document.querySelectorAll('.sidebar-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 900) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) {
            closeSidebar();
        }
    });
</script>

</body>
</html>