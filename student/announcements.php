<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
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

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatEthiopianDate(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '';
    }

    try {
        $gregorianDate = substr(
            trim($date),
            0,
            10
        );

        $ethiopian = EthiopianCalendar::fromGregorian(
            $gregorianDate
        );

        return e(
            (string) $ethiopian['formatted']
        );
    } catch (Throwable $exception) {
        return '';
    }
}

function formatFileSize(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format(
            $bytes / 1024,
            1
        ) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format(
            $bytes / (1024 * 1024),
            1
        ) . ' MB';
    }

    return number_format(
        $bytes / (1024 * 1024 * 1024),
        1
    ) . ' GB';
}

/*
|--------------------------------------------------------------------------
| Convert database file path to URL
|--------------------------------------------------------------------------
*/

function announcementFileUrl(?string $filePath): string
{
    $filePath = trim(
        (string) $filePath
    );

    if ($filePath === '') {
        return '';
    }

    $filePath = str_replace(
        '\\',
        '/',
        $filePath
    );

    $filePath = ltrim(
        $filePath,
        '/'
    );

    /*
    | If database contains:
    | BKHS/uploads/...
    */
    if (
        str_starts_with(
            strtolower($filePath),
            'bkhs/'
        )
    ) {
        $filePath = substr(
            $filePath,
            5
        );
    }

    /*
    | If database contains:
    | student/uploads/...
    */
    if (
        str_starts_with(
            strtolower($filePath),
            'student/uploads/'
        )
    ) {
        $filePath = substr(
            $filePath,
            8
        );
    }

    return '../' . $filePath;
}

function attachmentIcon(
    ?string $mimeType,
    ?string $fileName
): string {

    $mime = strtolower(
        trim(
            (string) $mimeType
        )
    );

    $name = strtolower(
        trim(
            (string) $fileName
        )
    );

    if (
        str_contains($mime, 'pdf') ||
        str_ends_with($name, '.pdf')
    ) {
        return 'bi-file-earmark-pdf';
    }

    if (
        str_contains($mime, 'word') ||
        str_contains($mime, 'officedocument.word') ||
        str_ends_with($name, '.doc') ||
        str_ends_with($name, '.docx')
    ) {
        return 'bi-file-earmark-word';
    }

    if (
        str_contains($mime, 'excel') ||
        str_contains($mime, 'spreadsheet') ||
        str_ends_with($name, '.xls') ||
        str_ends_with($name, '.xlsx')
    ) {
        return 'bi-file-earmark-excel';
    }

    if (
        str_contains($mime, 'powerpoint') ||
        str_contains($mime, 'presentation') ||
        str_ends_with($name, '.ppt') ||
        str_ends_with($name, '.pptx')
    ) {
        return 'bi-file-earmark-ppt';
    }

    if (
        str_contains($mime, 'zip') ||
        str_contains($mime, 'compressed') ||
        str_ends_with($name, '.zip') ||
        str_ends_with($name, '.rar')
    ) {
        return 'bi-file-earmark-zip';
    }

    if (
        str_starts_with(
            $mime,
            'image/'
        )
    ) {
        return 'bi-file-earmark-image';
    }

    if (
        str_contains($mime, 'text') ||
        str_ends_with($name, '.txt')
    ) {
        return 'bi-file-earmark-text';
    }

    return 'bi-paperclip';
}

function isPreviewableAttachment(
    ?string $mimeType,
    ?string $fileName
): bool {

    $mime = strtolower(
        trim(
            (string) $mimeType
        )
    );

    $name = strtolower(
        trim(
            (string) $fileName
        )
    );

    if (
        $mime === 'application/pdf' ||
        str_contains($mime, 'pdf') ||
        str_ends_with($name, '.pdf')
    ) {
        return true;
    }

    if (
        str_starts_with(
            $mime,
            'image/'
        )
    ) {
        return true;
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Student Information
|--------------------------------------------------------------------------
*/

$student = null;

$studentSql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year

    FROM students AS s

    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id

    INNER JOIN grades AS g
        ON g.id = sr.grade_id

    INNER JOIN sections AS sec
        ON sec.id = sr.section_id

    INNER JOIN academic_years AS ay
        ON ay.id = sr.academic_year_id

    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND ay.status = 'Active'

    ORDER BY sr.id DESC

    LIMIT 1
";

$stmt = $conn->prepare(
    $studentSql
);

if (!$stmt) {
    die(
        'Failed to prepare student query.'
    );
}

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$result = $stmt->get_result();

$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    die(
        'Student record or active registration was not found.'
    );
}

$studentId = (int) $student['student_id'];

$studentName = (string) $student['full_name'];

$studentCode = (string) $student['student_code'];

$registrationId = (int) $student['registration_id'];

$gradeNumber = (int) $student['grade_number'];

$section = (string) $student['section'];

$academicYearId = (int) $student['academic_year_id'];

$academicYear = (string) $student['academic_year'];

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage = basename(
    $_SERVER['PHP_SELF']
);

/*
|--------------------------------------------------------------------------
| Automatically close expired announcements
|--------------------------------------------------------------------------
*/

$closeExpiredSql = "
    UPDATE announcements
    SET status = 'Closed'
    WHERE status = 'Published'
      AND closed_at IS NOT NULL
      AND closed_at < CURDATE()
";

$conn->query(
    $closeExpiredSql
);

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::todayFormatted('en');

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) (
        $_GET['search']
        ?? ''
    )
);

if (strlen($search) > 100) {
    $search = substr(
        $search,
        0,
        100
    );
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 9;

$page = isset($_GET['page'])
    ? max(
        1,
        (int) $_GET['page']
    )
    : 1;

/*
|--------------------------------------------------------------------------
| Count Announcements
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT
        COUNT(DISTINCT a.id) AS total

    FROM announcements AS a

    INNER JOIN announcement_audiences AS aa
        ON aa.announcement_id = a.id

    WHERE a.status = 'Published'

      AND (
            a.closed_at IS NULL
            OR a.closed_at >= CURDATE()
          )

      AND (
            aa.audience_type = 'Public'

            OR (
                aa.audience_type = 'Student'
                AND aa.grade IS NULL
            )

            OR (
                aa.audience_type = 'Student'
                AND aa.grade = ?
            )
          )
";

$countParams = [
    $gradeNumber
];

$countTypes = 'i';

if ($search !== '') {

    $countSql .= "
        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )
    ";

    $searchLike =
        '%' . $search . '%';

    $countParams[] =
        $searchLike;

    $countParams[] =
        $searchLike;

    $countTypes .= 'ss';
}

$countStmt =
    $conn->prepare(
        $countSql
    );

if (!$countStmt) {
    die(
        'Unable to prepare announcement count query.'
    );
}

$countStmt->bind_param(
    $countTypes,
    ...$countParams
);

$countStmt->execute();

$countResult =
    $countStmt->get_result();

$countRow =
    $countResult->fetch_assoc();

$totalAnnouncements =
    (int) (
        $countRow['total']
        ?? 0
    );

$countStmt->close();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalAnnouncements /
            $perPage
        )
    );

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Fetch Announcements
|--------------------------------------------------------------------------
*/

$announcementSql = "
    SELECT
        a.id,
        a.title,
        a.content,
        a.status,
        a.published_at,
        a.closed_at,
        a.created_at

    FROM announcements AS a

    INNER JOIN announcement_audiences AS aa
        ON aa.announcement_id = a.id

    WHERE a.status = 'Published'

      AND (
            a.closed_at IS NULL
            OR a.closed_at >= CURDATE()
          )

      AND (
            aa.audience_type = 'Public'

            OR (
                aa.audience_type = 'Student'
                AND aa.grade IS NULL
            )

            OR (
                aa.audience_type = 'Student'
                AND aa.grade = ?
            )
          )
";

$params = [
    $gradeNumber
];

$types = 'i';

if ($search !== '') {

    $announcementSql .= "
        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )
    ";

    $searchLike =
        '%' . $search . '%';

    $params[] =
        $searchLike;

    $params[] =
        $searchLike;

    $types .= 'ss';
}

$announcementSql .= "
    GROUP BY
        a.id,
        a.title,
        a.content,
        a.status,
        a.published_at,
        a.closed_at,
        a.created_at

    ORDER BY
        a.published_at DESC,
        a.created_at DESC,
        a.id DESC

    LIMIT ? OFFSET ?
";

$params[] =
    $perPage;

$params[] =
    $offset;

$types .= 'ii';

$stmt =
    $conn->prepare(
        $announcementSql
    );

if (!$stmt) {
    die(
        'Unable to prepare announcements query.'
    );
}

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result =
    $stmt->get_result();

$announcements = [];

while (
    $row =
        $result->fetch_assoc()
) {
    $announcements[] =
        $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Fetch Announcement Media
|--------------------------------------------------------------------------
*/

$announcementMedia = [];

if (!empty($announcements)) {

    $announcementIds =
        array_map(
            static fn(
                array $announcement
            ): int =>
                (int) $announcement['id'],
            $announcements
        );

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($announcementIds),
                '?'
            )
        );

    $mediaSql = "
        SELECT
            id,
            announcement_id,
            media_type,
            file_path,
            original_name,
            mime_type,
            file_size,
            display_order

        FROM announcement_media

        WHERE announcement_id IN (
            $placeholders
        )

        ORDER BY
            announcement_id ASC,
            display_order ASC,
            id ASC
    ";

    $mediaStmt =
        $conn->prepare(
            $mediaSql
        );

    if ($mediaStmt) {

        $mediaTypes =
            str_repeat(
                'i',
                count($announcementIds)
            );

        $mediaStmt->bind_param(
            $mediaTypes,
            ...$announcementIds
        );

        $mediaStmt->execute();

        $mediaResult =
            $mediaStmt->get_result();

        while (
            $mediaRow =
                $mediaResult->fetch_assoc()
        ) {

            $announcementId =
                (int) (
                    $mediaRow[
                        'announcement_id'
                    ]
                );

            if (
                !isset(
                    $announcementMedia[
                        $announcementId
                    ]
                )
            ) {
                $announcementMedia[
                    $announcementId
                ] = [
                    'Image' => [],
                    'Attachment' => []
                ];
            }

            $mediaType =
                (string) (
                    $mediaRow['media_type']
                );

            if (
                isset(
                    $announcementMedia[
                        $announcementId
                    ][$mediaType]
                )
            ) {
                $announcementMedia[
                    $announcementId
                ][$mediaType][] =
                    $mediaRow;
            }
        }

        $mediaStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $pageNumber,
    string $search
): string {

    $query = [
        'page' => $pageNumber
    ];

    if ($search !== '') {
        $query['search'] =
            $search;
    }

    return '?' .
        http_build_query(
            $query
        );
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
        Announcements | Student Portal | BKHS
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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f5f7fb;
            --text: #172033;
            --muted: #6b7280;
            --border: #e5e7eb;

            /* Mobile bottom navigation */
            --bottom-nav-height: 76px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        /* =====================================================
           SIDEBAR
        ====================================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 72px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom:
                1px solid
                rgba(255,255,255,.08);
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            margin-right: 12px;
        }

        .sidebar-brand h5 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
        }

        .sidebar-brand small {
            color: #9ca3af;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .sidebar-label {
            padding: 8px 12px;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            transition: .2s;
        }

        .sidebar-link:hover,
        .sidebar-link.active {
            color: #fff;
            background:
                rgba(37,99,235,.9);
        }

        .sidebar-link i {
            width: 20px;
            font-size: 17px;
        }

        .logout-link {
            color: #fca5a5;
        }

        /* =====================================================
           MAIN
        ====================================================== */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* =====================================================
           TOPBAR
        ====================================================== */

        .topbar {
            height: 72px;
            background: #fff;
            border-bottom:
                1px solid
                var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .menu-button {
            display: none;
            border: 0;
            background: transparent;
            font-size: 25px;
            padding: 5px;
        }

        .topbar-title h4 {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
        }

        .topbar-title small {
            color: var(--muted);
        }

        .student-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: var(--primary);
            font-weight: 700;
        }

        /* =====================================================
           CONTENT
        ====================================================== */

        .content {
            padding: 28px;
        }

        /* =====================================================
           PAGE HEADER
        ====================================================== */

        .page-header {
            margin-bottom: 22px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }

        .page-header p {
            margin:
                5px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .student-context {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 12px;
        }

        .context-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding:
                5px 9px;
            border-radius: 7px;
            background: #fff;
            border:
                1px solid
                var(--border);
            color: #4b5563;
            font-size: 10px;
            font-weight: 600;
        }

        .context-item i {
            color: var(--primary);
            font-size: 11px;
        }

        /* =====================================================
           SEARCH
        ====================================================== */

        .search-box {
            margin-bottom: 20px;
        }

        .search-form {
            position: relative;
            width: 100%;
            max-width: 600px;
        }

        .search-form input {
            width: 100%;
            height: 42px;
            padding:
                0 48px 0 14px;
            background: #fff;
            border:
                1px solid
                var(--border);
            border-radius: 9px;
            color: var(--text);
            font-size: 12px;
            outline: none;
        }

        .search-form input:focus {
            border-color:
                var(--primary);
            box-shadow:
                0 0 0 3px
                rgba(37,99,235,.08);
        }

        .search-button {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 7px;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .search-button:hover {
            background: var(--primary-dark);
        }

        /* =====================================================
           ANNOUNCEMENT GRID
        ====================================================== */

        .announcement-grid {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 18px;
            align-items: stretch;
        }

        /* =====================================================
           ANNOUNCEMENT CARD
        ====================================================== */

        .announcement-card {
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-self: stretch;
            background: #fff;
            border:
                1px solid
                var(--border);
            border-radius: 13px;
            overflow: hidden;
            box-shadow:
                0 3px 8px
                rgba(15,23,42,.04);
            transition:
                transform .2s ease,
                box-shadow .2s ease,
                border-color .2s ease;
        }

        .announcement-card:hover {
            transform:
                translateY(-2px);
            border-color:
                #bfdbfe;
            box-shadow:
                0 9px 22px
                rgba(15,23,42,.08);
        }

        /* =====================================================
           TITLE
        ====================================================== */

        .announcement-card-header {
            padding:
                13px 15px 7px;
        }

        .announcement-card-title {
            margin: 0;
            color: var(--primary);
            font-size: 16px;
            font-weight: 800;
            line-height: 1.35;
        }

        .announcement-card:hover
        .announcement-card-title {
            color: var(--primary-dark);
        }

        /* =====================================================
           BODY
        ====================================================== */

        .announcement-card-body {
            flex: 1;
            padding:
                0 15px 12px;
            display: flex;
            flex-direction: column;
        }

        /* =====================================================
           DESCRIPTION
        ====================================================== */

        .announcement-description {
            width: 100%;
            margin:
                7px 0 0;
            padding: 0;
            color: #4b5563;
            font-size: 11px;
            line-height: 1.55;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            text-align: justify;
        }

        /* =====================================================
           IMAGES
        ====================================================== */

        .announcement-images {
            margin-top: 9px;
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 6px;
        }

        .announcement-images.single-image {
            grid-template-columns: 1fr;
        }

        .announcement-image-wrapper {
            width: 100%;
            border-radius: 7px;
            overflow: hidden;
            background: #f8fafc;
            border:
                1px solid
                #edf0f4;
        }

        .announcement-image {
            display: block;
            width: 100%;
            height: auto;
            max-height: 150px;
            object-fit: contain;
            background: #f8fafc;
            cursor: pointer;
        }

        .announcement-images.single-image
        .announcement-image {
            max-height: 190px;
        }

        /* =====================================================
           ATTACHMENTS
        ====================================================== */

        .announcement-attachments {
            margin-top: 9px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .attachments-label {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #374151;
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 1px;
        }

        .attachments-label i {
            color: var(--primary);
            font-size: 11px;
        }

        .attachment-item {
            min-width: 0;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 7px;
            padding:
                6px 7px;
            background: #f8fafc;
            border:
                1px solid
                #edf0f4;
            border-radius: 6px;
        }

        .attachment-icon {
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .attachment-details {
            min-width: 0;
            flex: 1;
        }

        .attachment-name {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #374151;
            font-size: 9px;
            font-weight: 600;
        }

        .attachment-size {
            display: block;
            margin-top: 1px;
            color: #9ca3af;
            font-size: 8px;
        }

        .attachment-view {
            flex-shrink: 0;
            width: 27px;
            height: 27px;
            border: 0;
            border-radius: 6px;
            background: var(--primary);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 11px;
        }

        .attachment-view:hover {
            background:
                var(--primary-dark);
        }

        /* =====================================================
           DATES
        ====================================================== */

        .announcement-dates {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: auto;
            padding:
                7px 15px;
            border-top:
                1px solid
                #f0f1f3;
            background: #fff;
        }

        .announcement-date {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--muted);
            font-size: 9px;
            line-height: 1.3;
            white-space: nowrap;
        }

        .announcement-date i {
            flex-shrink: 0;
            color: var(--primary);
            font-size: 10px;
        }

        .announcement-last-date i {
            color: #dc2626;
        }

        /* =====================================================
           EMPTY STATE
        ====================================================== */

        .empty-state {
            background: #fff;
            border:
                1px solid
                var(--border);
            border-radius: 14px;
            padding:
                45px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 56px;
            height: 56px;
            margin:
                0 auto 13px;
            border-radius: 14px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .empty-state h3 {
            margin:
                0 0 5px;
            font-size: 17px;
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        /* =====================================================
           PAGINATION
        ====================================================== */

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 25px;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 4px;
        }

        .page-link {
            min-width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border:
                1px solid
                var(--border);
            border-radius: 7px !important;
            background: #fff;
            color: var(--text);
            font-size: 12px;
            box-shadow: none;
        }

        .page-link:hover {
            background: #eff6ff;
            color: var(--primary);
            border-color: #bfdbfe;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        /* =====================================================
           PREVIEW MODAL
        ====================================================== */

        .preview-modal .modal-dialog {
            width: min(96vw, 1000px);
            max-width: 1000px;
            margin:
                1.25rem auto;
        }

        .preview-modal .modal-content {
            height:
                min(92vh, 850px);
            border: 0;
            border-radius: 12px;
            overflow: hidden;
            background: #111827;
        }

        .preview-modal .modal-header {
            min-height: 50px;
            padding:
                8px 12px;
            background: #111827;
            color: #fff;
            border-bottom:
                1px solid
                rgba(255,255,255,.1);
        }

        .preview-modal .modal-title {
            min-width: 0;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 600;
        }

        .preview-modal .btn-close {
            flex-shrink: 0;
            filter: invert(1);
            opacity: .9;
        }

        .preview-modal .modal-body {
            min-height: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1f2937;
            overflow: hidden;
        }

        .preview-frame {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }

        .preview-image {
            display: block;
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        .preview-fallback {
            width: min(92%, 500px);
            padding:
                30px 20px;
            text-align: center;
            color: #fff;
        }

        .preview-fallback-icon {
            font-size: 55px;
            margin-bottom: 12px;
        }

        .preview-fallback h4 {
            margin:
                0 0 8px;
            font-size: 17px;
            font-weight: 700;
        }

        .preview-fallback p {
            margin:
                0 0 18px;
            color: #d1d5db;
            font-size: 12px;
        }

        /* =====================================================
           SIDEBAR OVERLAY
        ====================================================== */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background:
                rgba(0,0,0,.45);
            z-index: 1040;
        }

        /* =====================================================
           MOBILE BOTTOM NAVIGATION
        ====================================================== */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-nav-item {
            display: none;
        }

        @media screen and (max-width: 767.98px) {

            body {
                padding-bottom:
                    calc(
                        var(--bottom-nav-height) +
                        env(safe-area-inset-bottom)
                    ) !important;
            }

            .mobile-bottom-nav {
                position: fixed !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height:
                    calc(
                        var(--bottom-nav-height) +
                        env(safe-area-inset-bottom)
                    ) !important;
                min-height:
                    var(--bottom-nav-height) !important;
                display: flex !important;
                align-items: stretch !important;
                justify-content: space-around !important;
                background: #ffffff !important;
                border-top:
                    1px solid
                    #e5e7eb !important;
                box-shadow:
                    0 -4px 20px
                    rgba(0,0,0,.10) !important;
                z-index: 99999 !important;
                padding:
                    5px
                    4px
                    env(safe-area-inset-bottom)
                    4px !important;
                margin: 0 !important;
                visibility: visible !important;
                opacity: 1 !important;
            }

            .mobile-nav-item {
                display: flex !important;
                flex: 1 1 0 !important;
                min-width: 0 !important;
                height: 100% !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 4px !important;
                margin: 0 2px !important;
                padding: 5px 2px !important;
                border-radius: 10px !important;
                text-decoration: none !important;
                color: #6b7280 !important;
                background: transparent !important;
                font-size: 10px !important;
                font-weight: 600 !important;
                visibility: visible !important;
                opacity: 1 !important;
                -webkit-tap-highlight-color: transparent;
            }

            .mobile-nav-item i {
                display: block !important;
                width: auto !important;
                font-size: 21px !important;
                line-height: 1 !important;
                visibility: visible !important;
            }

            .mobile-nav-item span {
                display: block !important;
                max-width: 100% !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                line-height: 1.1 !important;
                visibility: visible !important;
            }

            .mobile-nav-item.active {
                color: #2563eb !important;
                background: #eff6ff !important;
            }

            .mobile-nav-item:active {
                transform: scale(.96);
            }
        }

        @media screen and (max-width: 380px) {

            :root {
                --bottom-nav-height: 72px;
            }

            .mobile-bottom-nav {
                padding-left: 2px !important;
                padding-right: 2px !important;
            }

            .mobile-nav-item {
                margin: 0 1px !important;
                padding-left: 1px !important;
                padding-right: 1px !important;
                font-size: 9px !important;
                gap: 3px !important;
            }

            .mobile-nav-item i {
                font-size: 19px !important;
            }
        }

        /* =====================================================
           TABLET
        ====================================================== */

        @media (max-width: 1100px) {

            .announcement-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform:
                    translateX(-100%);
            }

            .sidebar.show {
                transform:
                    translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .menu-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding:
                    0 18px;
            }

            .content {
                padding: 20px;
            }
        }

        /* =====================================================
           MOBILE
        ====================================================== */

        @media (max-width: 575.98px) {

            .topbar {
                height: 64px;
                padding:
                    0 14px;
            }

            .topbar-title h4 {
                font-size: 16px;
            }

            .topbar-title small {
                display: none;
            }

            .student-avatar {
                width: 36px;
                height: 36px;
            }

            .content {
                padding: 14px;
            }

            .page-header {
                margin-bottom: 17px;
            }

            .page-header h2 {
                font-size: 21px;
            }

            .page-header p {
                font-size: 11px;
            }

            .student-context {
                gap: 5px;
                margin-top: 9px;
            }

            .context-item {
                padding:
                    4px 7px;
                font-size: 8px;
            }

            .search-box {
                margin-bottom: 15px;
            }

            .announcement-grid {
                grid-template-columns: 1fr;
                gap: 13px;
            }

            .announcement-card {
                border-radius: 11px;
            }

            .announcement-card-header {
                padding:
                    11px 12px 6px;
            }

            .announcement-card-body {
                padding:
                    0 12px 10px;
            }

            .announcement-card-title {
                font-size: 14px;
            }

            .announcement-description {
                margin-top: 6px;
                font-size: 10px;
                line-height: 1.5;
                text-align: justify;
            }

            .announcement-images {
                margin-top: 7px;
                gap: 5px;
            }

            .announcement-image {
                max-height: 125px;
            }

            .announcement-images.single-image
            .announcement-image {
                max-height: 170px;
            }

            .announcement-attachments {
                margin-top: 7px;
            }

            .attachment-item {
                padding:
                    5px 6px;
            }

            .attachment-icon {
                width: 25px;
                height: 25px;
                font-size: 11px;
            }

            .attachment-name {
                font-size: 8px;
            }

            .attachment-size {
                font-size: 7px;
            }

            .attachment-view {
                width: 26px;
                height: 26px;
            }

            .announcement-dates {
                gap: 8px;
                padding:
                    6px 12px;
            }

            .announcement-date {
                font-size: 8px;
            }

            .announcement-date i {
                font-size: 9px;
            }

            .empty-state {
                padding:
                    38px 15px;
            }

            .preview-modal .modal-dialog {
                width: 96vw;
                margin:
                    .5rem auto;
            }

            .preview-modal .modal-content {
                height: 94vh;
                border-radius: 9px;
            }
        }

        @media (max-width: 360px) {

            .announcement-card-title {
                font-size: 13px;
            }

            .announcement-images {
                grid-template-columns: 1fr;
            }

            .announcement-image {
                max-height: 165px;
            }
        }

    </style>

</head>

<body>

<!-- =====================================================
     SIDEBAR OVERLAY
===================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">

            <i class="bi bi-mortarboard-fill"></i>

        </div>

        <div>

            <h5>
                BKHS
            </h5>

            <small>
                Student Portal
            </small>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="sidebar-label">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>
        </a>

        <a
            href="subjects.php"
            class="sidebar-link"
        >
            <i class="bi bi-book"></i>

            <span>
                My Subjects
            </span>
        </a>

        <a
            href="materials.php"
            class="sidebar-link"
        >
            <i class="bi bi-folder2-open"></i>

            <span>
                Materials
            </span>
        </a>

        <a
            href="result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart"></i>

            <span>
                Results
            </span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check"></i>

            <span>
                Attendance
            </span>
        </a>

        <a
            href="homework.php"
            class="sidebar-link"
        >
            <i class="bi bi-journal-text"></i>

            <span>
                Homework
            </span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link active"
        >
            <i class="bi bi-megaphone"></i>

            <span>
                Announcements
            </span>
        </a>

        <div class="sidebar-label mt-3">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person"></i>

            <span>
                Profile
            </span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>
        </a>

    </nav>

</aside>

<!-- =====================================================
     MAIN
===================================================== -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-2">

            <button
                type="button"
                class="menu-button"
                id="menuButton"
                aria-label="Open menu"
            >

                <i class="bi bi-list"></i>

            </button>

            <div class="topbar-title">

                <h4>
                    Announcements
                </h4>

                <small>
                    BKHS Student Portal
                </small>

            </div>

        </div>

        <div class="student-avatar">

            <?= e(
                strtoupper(
                    substr(
                        $studentName,
                        0,
                        1
                    )
                )
            ) ?>

        </div>

    </header>

    <!-- CONTENT -->

    <div class="content">

        <!-- PAGE HEADER -->

        <section class="page-header">

            <h2>
                School Announcements
            </h2>

            <p>
                Important information and updates for students.
            </p>

            <div class="student-context">

                <div class="context-item">

                    <i class="bi bi-person-badge"></i>

                    <?= e($studentCode) ?>

                </div>

                <div class="context-item">

                    <i class="bi bi-mortarboard"></i>

                    Grade <?= $gradeNumber ?>

                </div>

                <div class="context-item">

                    <i class="bi bi-people"></i>

                    Section <?= e($section) ?>

                </div>

                <div class="context-item">

                    <i class="bi bi-calendar3"></i>

                    <?= e($academicYear) ?>

                </div>

                <div class="context-item">

                    <i class="bi bi-calendar-event"></i>

                    Today:
                    <?= e($todayEthiopian) ?>

                </div>

            </div>

        </section>

        <!-- SEARCH -->

        <?php if (
            $totalAnnouncements > 0 ||
            $search !== ''
        ): ?>

            <div class="search-box">

                <form
                    method="get"
                    action=""
                    class="search-form"
                >

                    <input
                        type="search"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search announcements..."
                        autocomplete="off"
                    >

                    <button
                        type="submit"
                        class="search-button"
                        aria-label="Search announcements"
                    >

                        <i class="bi bi-search"></i>

                    </button>

                </form>

            </div>

        <?php endif; ?>

        <!-- EMPTY STATE -->

        <?php if (
            empty($announcements)
        ): ?>

            <div class="empty-state">

                <div class="empty-icon">

                    <i class="bi bi-megaphone"></i>

                </div>

                <?php if (
                    $search !== ''
                ): ?>

                    <h3>
                        No announcements found
                    </h3>

                    <p>
                        Try another search term.
                    </p>

                <?php else: ?>

                    <h3>
                        No announcements available
                    </h3>

                    <p>
                        There are currently no announcements for you.
                    </p>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <!-- ANNOUNCEMENT GRID -->

            <div class="announcement-grid">

                <?php foreach (
                    $announcements
                    as $announcement
                ): ?>

                    <?php

                    $announcementId =
                        (int) $announcement['id'];

                    $title =
                        (string) $announcement['title'];

                    $content =
                        trim(
                            strip_tags(
                                (string) (
                                    $announcement['content']
                                    ?? ''
                                )
                            )
                        );

                    $publishedDate =
                        $announcement['published_at']
                        ?? null;

                    $closedDate =
                        $announcement['closed_at']
                        ?? null;

                    $media =
                        $announcementMedia[
                            $announcementId
                        ]
                        ?? [
                            'Image' => [],
                            'Attachment' => []
                        ];

                    $images =
                        $media['Image']
                        ?? [];

                    $attachments =
                        $media['Attachment']
                        ?? [];

                    ?>

                    <article
                        class="announcement-card"
                    >

                        <!-- TITLE -->

                        <div
                            class="announcement-card-header"
                        >

                            <h2
                                class="announcement-card-title"
                            >

                                <?= e($title) ?>

                            </h2>

                        </div>

                        <!-- BODY -->

                        <div
                            class="announcement-card-body"
                        >

                            <!-- DESCRIPTION -->

                            <?php if (
                                $content !== ''
                            ): ?>

                                <div
                                    class="announcement-description"
                                >

                                    <?= e($content) ?>

                                </div>

                            <?php endif; ?>

                            <!-- IMAGES -->

                            <?php if (
                                !empty($images)
                            ): ?>

                                <div
                                    class="
                                        announcement-images
                                        <?= count($images) === 1
                                            ? 'single-image'
                                            : ''
                                        ?>
                                    "
                                >

                                    <?php foreach (
                                        $images
                                        as $image
                                    ): ?>

                                        <?php

                                        $imagePath =
                                            trim(
                                                (string) (
                                                    $image[
                                                        'file_path'
                                                    ]
                                                    ?? ''
                                                )
                                            );

                                        $imageUrl =
                                            announcementFileUrl(
                                                $imagePath
                                            );

                                        if (
                                            $imageUrl === ''
                                        ) {
                                            continue;
                                        }

                                        ?>

                                        <div
                                            class="
                                                announcement-image-wrapper
                                            "
                                        >

                                            <img
                                                src="<?= e(
                                                    $imageUrl
                                                ) ?>"
                                                alt="<?= e(
                                                    $title
                                                ) ?>"
                                                class="announcement-image"
                                                loading="lazy"
                                                onclick="openImagePreview(
                                                    <?= htmlspecialchars(
                                                        json_encode(
                                                            $imageUrl,
                                                            JSON_UNESCAPED_SLASHES |
                                                            JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>,
                                                    <?= htmlspecialchars(
                                                        json_encode(
                                                            $title,
                                                            JSON_UNESCAPED_SLASHES |
                                                            JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                )"
                                                onerror="this.parentElement.style.display='none';"
                                            >

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <!-- ATTACHMENTS -->

                            <?php if (
                                !empty($attachments)
                            ): ?>

                                <div
                                    class="announcement-attachments"
                                >

                                    <div
                                        class="attachments-label"
                                    >

                                        <i
                                            class="bi bi-paperclip"
                                        ></i>

                                        Attachments

                                    </div>

                                    <?php foreach (
                                        $attachments
                                        as $attachment
                                    ): ?>

                                        <?php

                                        $attachmentPath =
                                            trim(
                                                (string) (
                                                    $attachment[
                                                        'file_path'
                                                    ]
                                                    ?? ''
                                                )
                                            );

                                        $attachmentName =
                                            trim(
                                                (string) (
                                                    $attachment[
                                                        'original_name'
                                                    ]
                                                    ?? 'Attachment'
                                                )
                                            );

                                        $mimeType =
                                            trim(
                                                (string) (
                                                    $attachment[
                                                        'mime_type'
                                                    ]
                                                    ?? ''
                                                )
                                            );

                                        $fileSize =
                                            !empty(
                                                $attachment[
                                                    'file_size'
                                                ]
                                            )
                                            ? formatFileSize(
                                                (int) (
                                                    $attachment[
                                                        'file_size'
                                                    ]
                                                )
                                            )
                                            : '';

                                        if (
                                            $attachmentPath === ''
                                        ) {
                                            continue;
                                        }

                                        $attachmentUrl =
                                            announcementFileUrl(
                                                $attachmentPath
                                            );

                                        if (
                                            $attachmentUrl === ''
                                        ) {
                                            continue;
                                        }

                                        $icon =
                                            attachmentIcon(
                                                $mimeType,
                                                $attachmentName
                                            );

                                        $previewable =
                                            isPreviewableAttachment(
                                                $mimeType,
                                                $attachmentName
                                            );

                                        ?>

                                        <div
                                            class="attachment-item"
                                        >

                                            <div
                                                class="attachment-icon"
                                            >

                                                <i
                                                    class="
                                                        bi
                                                        <?= e(
                                                            $icon
                                                        ) ?>
                                                    "
                                                ></i>

                                            </div>

                                            <div
                                                class="attachment-details"
                                            >

                                                <span
                                                    class="attachment-name"
                                                    title="<?= e(
                                                        $attachmentName
                                                    ) ?>"
                                                >

                                                    <?= e(
                                                        $attachmentName
                                                    ) ?>

                                                </span>

                                                <?php if (
                                                    $fileSize !== ''
                                                ): ?>

                                                    <span
                                                        class="attachment-size"
                                                    >

                                                        <?= e(
                                                            $fileSize
                                                        ) ?>

                                                    </span>

                                                <?php endif; ?>

                                            </div>

                                            <button
                                                type="button"
                                                class="attachment-view"
                                                title="<?= $previewable
                                                    ? 'View attachment'
                                                    : 'Open attachment'
                                                ?>"
                                                onclick="openAttachmentPreview(
                                                    <?= htmlspecialchars(
                                                        json_encode(
                                                            $attachmentUrl,
                                                            JSON_UNESCAPED_SLASHES |
                                                            JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>,
                                                    <?= htmlspecialchars(
                                                        json_encode(
                                                            $attachmentName,
                                                            JSON_UNESCAPED_SLASHES |
                                                            JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>,
                                                    <?= htmlspecialchars(
                                                        json_encode(
                                                            $mimeType,
                                                            JSON_UNESCAPED_SLASHES |
                                                            JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>,
                                                    <?= $previewable
                                                        ? 'true'
                                                        : 'false'
                                                    ?>
                                                )"
                                            >

                                                <i
                                                    class="
                                                        bi
                                                        <?= $previewable
                                                            ? 'bi-eye'
                                                            : 'bi-box-arrow-up-right'
                                                        ?>
                                                    "
                                                ></i>

                                            </button>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>

                        <!-- DATES -->

                        <?php if (
                            !empty($publishedDate) ||
                            !empty($closedDate)
                        ): ?>

                            <div
                                class="announcement-dates"
                            >

                                <?php if (
                                    !empty($publishedDate)
                                ): ?>

                                    <div
                                        class="announcement-date"
                                    >

                                        <i
                                            class="bi bi-calendar-event"
                                        ></i>

                                        <span>

                                            Published:
                                            <?= formatEthiopianDate(
                                                $publishedDate
                                            ) ?>

                                        </span>

                                    </div>

                                <?php endif; ?>

                                <?php if (
                                    !empty($closedDate)
                                ): ?>

                                    <div
                                        class="
                                            announcement-date
                                            announcement-last-date
                                        "
                                    >

                                        <i
                                            class="bi bi-calendar-x"
                                        ></i>

                                        <span>

                                            Last date:
                                            <?= formatEthiopianDate(
                                                $closedDate
                                            ) ?>

                                        </span>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

            <!-- PAGINATION -->

            <?php if (
                $totalPages > 1
            ): ?>

                <div
                    class="pagination-wrapper"
                >

                    <nav
                        aria-label="Announcement pagination"
                    >

                        <ul
                            class="pagination mb-0"
                        >

                            <?php if (
                                $page > 1
                            ): ?>

                                <li
                                    class="page-item"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $page - 1,
                                                $search
                                            )
                                        ) ?>"
                                        aria-label="Previous"
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-chevron-left
                                            "
                                        ></i>

                                    </a>

                                </li>

                            <?php endif; ?>

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
                                $i = $startPage;
                                $i <= $endPage;
                                $i++
                            ): ?>

                                <li
                                    class="
                                        page-item
                                        <?= $i === $page
                                            ? 'active'
                                            : ''
                                        ?>
                                    "
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $i,
                                                $search
                                            )
                                        ) ?>"
                                    >

                                        <?= $i ?>

                                    </a>

                                </li>

                            <?php endfor; ?>

                            <?php if (
                                $page < $totalPages
                            ): ?>

                                <li
                                    class="page-item"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $page + 1,
                                                $search
                                            )
                                        ) ?>"
                                        aria-label="Next"
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-chevron-right
                                            "
                                        ></i>

                                    </a>

                                </li>

                            <?php endif; ?>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</main>

<!-- =====================================================
     PREVIEW MODAL
===================================================== -->

<div
    class="modal fade preview-modal"
    id="previewModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-dialog-centered"
    >

        <div class="modal-content">

            <div class="modal-header">

                <h5
                    class="modal-title"
                    id="previewModalTitle"
                >
                    Preview
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>

            <div
                class="modal-body"
                id="previewModalBody"
            ></div>

        </div>

    </div>

</div>

<!-- =====================================================
     MOBILE BOTTOM NAVIGATION
===================================================== -->

<nav
    class="mobile-bottom-nav"
    aria-label="Student mobile navigation"
>

    <a
        href="dashboard.php"
        class="mobile-nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        aria-label="Dashboard"
    >

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Home
        </span>

    </a>

    <a
        href="subjects.php"
        class="mobile-nav-item <?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
        aria-label="My Subjects"
    >

        <i class="bi bi-book-fill"></i>

        <span>
            Subjects
        </span>

    </a>

    <a
        href="materials.php"
        class="mobile-nav-item <?= $currentPage === 'materials.php' ? 'active' : '' ?>"
        aria-label="Materials"
    >

        <i class="bi bi-folder-fill"></i>

        <span>
            Materials
        </span>

    </a>

    <a
        href="homework.php"
        class="mobile-nav-item <?= $currentPage === 'homework.php' ? 'active' : '' ?>"
        aria-label="Homework"
    >

        <i class="bi bi-journal-text"></i>

        <span>
            Homework
        </span>

    </a>

    <a
        href="result.php"
        class="mobile-nav-item <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        aria-label="Results"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Results
        </span>

    </a>

</nav>

<!-- =====================================================
     BOOTSTRAP JS
===================================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById(
        'sidebar'
    );

const overlay =
    document.getElementById(
        'sidebarOverlay'
    );

const menuButton =
    document.getElementById(
        'menuButton'
    );

function openSidebar()
{
    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (overlay) {
        overlay.classList.add('show');
    }

    document.body.style.overflow =
        'hidden';
}

function closeSidebar()
{
    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (overlay) {
        overlay.classList.remove('show');
    }

    document.body.style.overflow =
        '';
}

if (menuButton) {

    menuButton.addEventListener(
        'click',
        openSidebar
    );

}

if (overlay) {

    overlay.addEventListener(
        'click',
        closeSidebar
    );

}

document
    .querySelectorAll('.sidebar-link')
    .forEach(
        link => {

            link.addEventListener(
                'click',
                () => {

                    if (
                        window.innerWidth < 992
                    ) {
                        closeSidebar();
                    }

                }
            );

        }
    );

window.addEventListener(
    'resize',
    () => {

        if (
            window.innerWidth >= 992
        ) {
            closeSidebar();
        }

    }
);

/*
|--------------------------------------------------------------------------
| Preview Modal
|--------------------------------------------------------------------------
*/

let previewModalInstance = null;

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const modalElement =
            document.getElementById(
                'previewModal'
            );

        if (modalElement) {

            previewModalInstance =
                new bootstrap.Modal(
                    modalElement
                );

        }

    }
);

/*
|--------------------------------------------------------------------------
| Image Preview
|--------------------------------------------------------------------------
*/

function openImagePreview(
    imageUrl,
    title
) {

    const titleElement =
        document.getElementById(
            'previewModalTitle'
        );

    const bodyElement =
        document.getElementById(
            'previewModalBody'
        );

    if (
        !titleElement ||
        !bodyElement
    ) {
        return;
    }

    titleElement.textContent =
        title ||
        'Image preview';

    bodyElement.innerHTML =
        '';

    const image =
        document.createElement(
            'img'
        );

    image.src =
        imageUrl;

    image.alt =
        title ||
        'Announcement image';

    image.className =
        'preview-image';

    image.onerror =
        function () {

            bodyElement.innerHTML = `
                <div class="preview-fallback">

                    <div class="preview-fallback-icon">
                        <i class="bi bi-image"></i>
                    </div>

                    <h4>
                        Image could not be loaded
                    </h4>

                    <p>
                        The image file could not be found.
                    </p>

                </div>
            `;

        };

    bodyElement.appendChild(
        image
    );

    if (
        previewModalInstance
    ) {
        previewModalInstance.show();
    }
}

/*
|--------------------------------------------------------------------------
| Attachment Preview
|--------------------------------------------------------------------------
*/

function openAttachmentPreview(
    fileUrl,
    fileName,
    mimeType,
    previewable
) {

    const titleElement =
        document.getElementById(
            'previewModalTitle'
        );

    const bodyElement =
        document.getElementById(
            'previewModalBody'
        );

    if (
        !titleElement ||
        !bodyElement
    ) {
        return;
    }

    titleElement.textContent =
        fileName ||
        'Attachment';

    bodyElement.innerHTML =
        '';

    const mime =
        String(
            mimeType || ''
        ).toLowerCase();

    const name =
        String(
            fileName || ''
        ).toLowerCase();

    const isPdf =
        mime.includes('pdf') ||
        name.endsWith('.pdf');

    const isImage =
        mime.startsWith('image/');

    if (
        previewable &&
        (isPdf || isImage)
    ) {

        if (isImage) {

            const image =
                document.createElement(
                    'img'
                );

            image.src =
                fileUrl;

            image.alt =
                fileName ||
                'Attachment';

            image.className =
                'preview-image';

            image.onerror =
                function () {

                    showPreviewError(
                        bodyElement,
                        fileName
                    );

                };

            bodyElement.appendChild(
                image
            );

        } else {

            const frame =
                document.createElement(
                    'iframe'
                );

            frame.src =
                fileUrl;

            frame.title =
                fileName ||
                'Attachment preview';

            frame.className =
                'preview-frame';

            bodyElement.appendChild(
                frame
            );
        }

    } else {

        const fallback =
            document.createElement(
                'div'
            );

        fallback.className =
            'preview-fallback';

        fallback.innerHTML = `
            <div class="preview-fallback-icon">
                <i class="bi bi-file-earmark"></i>
            </div>

            <h4>
                ${escapeHtml(
                    fileName ||
                    'Attachment'
                )}
            </h4>

            <p>
                This file type cannot be previewed directly
                in the browser.
            </p>

            <a
                href="${escapeAttribute(fileUrl)}"
                download
                class="btn btn-light btn-sm"
            >
                <i class="bi bi-download"></i>
                Download File
            </a>
        `;

        bodyElement.appendChild(
            fallback
        );
    }

    if (
        previewModalInstance
    ) {
        previewModalInstance.show();
    }
}

/*
|--------------------------------------------------------------------------
| Preview Error
|--------------------------------------------------------------------------
*/

function showPreviewError(
    bodyElement,
    fileName
) {

    bodyElement.innerHTML = `
        <div class="preview-fallback">

            <div class="preview-fallback-icon">
                <i class="bi bi-file-earmark-x"></i>
            </div>

            <h4>
                File could not be loaded
            </h4>

            <p>
                ${escapeHtml(
                    fileName ||
                    'The requested file'
                )}
                could not be found.
            </p>

        </div>
    `;
}

/*
|--------------------------------------------------------------------------
| Escape Helpers
|--------------------------------------------------------------------------
*/

function escapeHtml(value)
{
    return String(value)
        .replace(
            /&/g,
            '&amp;'
        )
        .replace(
            /</g,
            '&lt;'
        )
        .replace(
            />/g,
            '&gt;'
        )
        .replace(
            /"/g,
            '&quot;'
        )
        .replace(
            /'/g,
            '&#039;'
        );
}

function escapeAttribute(value)
{
    return escapeHtml(value);
}

/*
|--------------------------------------------------------------------------
| Clear Modal
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'hidden.bs.modal',
    function (event) {

        if (
            event.target.id ===
            'previewModal'
        ) {

            const bodyElement =
                document.getElementById(
                    'previewModalBody'
                );

            if (bodyElement) {

                bodyElement.innerHTML =
                    '';

            }

        }

    }
);

</script>

</body>

</html>