<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

$teacher = null;
$teacherName = 'Teacher';
$teacherInitials = 'T';
$teacherPhoto = '';

$todayEthiopian = EthiopianCalendar::todayFormatted();

$announcements = [];
$totalAnnouncements = 0;

$search = trim((string) ($_GET['search'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = 6;
$totalPages = 1;
$offset = 0;

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Helpers
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
        $part = trim($part);

        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return $initials ?: 'T';
}

/**
 * Convert a database media path into a browser URL.
 *
 * Supports:
 * uploads/announcements/file.jpg
 * ../uploads/announcements/file.jpg
 * /BKHS/uploads/announcements/file.jpg
 * C:/xampp/htdocs/BKHS/uploads/announcements/file.jpg
 * http://...
 * https://...
 */
function mediaUrl(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    /*
     * External URL.
     */
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    /*
     * Root-relative URL.
     */
    if (str_starts_with($path, '/')) {
        return $path;
    }

    /*
     * If the database contains an absolute Windows path,
     * convert it to a project-relative URL.
     */
    $projectRoot = realpath(__DIR__ . '/..');

    if ($projectRoot !== false) {

        $projectRoot = str_replace(
            '\\',
            '/',
            $projectRoot
        );

        $normalizedRoot = rtrim(
            $projectRoot,
            '/'
        ) . '/';

        if (
            stripos(
                $path,
                $normalizedRoot
            ) === 0
        ) {
            $relativePath = substr(
                $path,
                strlen($normalizedRoot)
            );

            return '../' .
                ltrim(
                    $relativePath,
                    '/'
                );
        }
    }

    /*
     * Handle paths such as:
     * C:/xampp/htdocs/BKHS/uploads/...
     */
    $bkhsMarker = '/BKHS/';

    $bkhsPosition = stripos(
        $path,
        $bkhsMarker
    );

    if ($bkhsPosition !== false) {

        $relativePath = substr(
            $path,
            $bkhsPosition + strlen($bkhsMarker)
        );

        return '../' .
            ltrim(
                $relativePath,
                '/'
            );
    }

    /*
     * Remove ./ and ../ from the beginning.
     */
    $path = preg_replace(
        '#^(?:\./|\.\./)+#',
        '',
        $path
    );

    return '../' .
        ltrim(
            $path,
            '/'
        );
}

/**
 * Resolve teacher profile photo path.
 *
 * Teacher photos are stored in teachers.photo_path.
 *
 * Supports:
 * - External URLs
 * - Absolute BKHS paths
 * - public/uploads/profiles/
 * - uploads/profiles/
 * - public/uploads/teachers/
 * - uploads/teachers/
 * - public/image/
 * - uploads/
 * - existing project-relative paths
 */
function teacherPhotoUrl(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    /*
     * External image URL.
     */
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    /*
     * Root-relative URL.
     */
    if (str_starts_with($path, '/')) {
        return $path;
    }

    /*
     * Project root.
     */
    $projectRoot = realpath(__DIR__ . '/..');

    if ($projectRoot !== false) {

        $projectRoot = str_replace(
            '\\',
            '/',
            $projectRoot
        );

        $normalizedRoot = rtrim(
            $projectRoot,
            '/'
        ) . '/';

        /*
         * Absolute path already inside BKHS.
         */
        if (
            stripos(
                $path,
                $normalizedRoot
            ) === 0
        ) {

            $relativePath = substr(
                $path,
                strlen($normalizedRoot)
            );

            $relativePath = ltrim(
                $relativePath,
                '/'
            );

            if ($relativePath !== '') {
                return '../' . $relativePath;
            }
        }
    }

    /*
     * Handle:
     * C:/xampp/htdocs/BKHS/...
     */
    $bkhsMarker = '/BKHS/';

    $bkhsPosition = stripos(
        $path,
        $bkhsMarker
    );

    if ($bkhsPosition !== false) {

        $relativePath = substr(
            $path,
            $bkhsPosition + strlen($bkhsMarker)
        );

        $relativePath = ltrim(
            $relativePath,
            '/'
        );

        if ($relativePath !== '') {
            return '../' . $relativePath;
        }
    }

    /*
     * Remove leading ./ and ../
     */
    $normalized = preg_replace(
        '#^(?:\./|\.\./)+#',
        '',
        $path
    );

    $normalized = ltrim(
        (string) $normalized,
        '/'
    );

    if ($normalized === '') {
        return '';
    }

    /*
     * If database already contains a known project-relative
     * public/upload path, check it first.
     */
    $directCandidates = [
        $normalized,
    ];

    foreach ($directCandidates as $candidate) {

        $absoluteCandidate =
            __DIR__ .
            '/../' .
            ltrim(
                $candidate,
                '/'
            );

        if (is_file($absoluteCandidate)) {

            return '../' .
                ltrim(
                    $candidate,
                    '/'
                );
        }
    }

    /*
     * For a filename only, check common teacher-photo
     * directories used by BKHS.
     */
    $filename = basename($normalized);

    $photoCandidates = [
        'public/uploads/profiles/' . $filename,
        'uploads/profiles/' . $filename,
        'public/uploads/teachers/' . $filename,
        'uploads/teachers/' . $filename,
        'public/image/teachers/' . $filename,
        'public/image/' . $filename,
        'uploads/' . $filename,
    ];

    foreach ($photoCandidates as $candidate) {

        $absoluteCandidate =
            __DIR__ .
            '/../' .
            ltrim(
                $candidate,
                '/'
            );

        if (is_file($absoluteCandidate)) {

            return '../' .
                ltrim(
                    $candidate,
                    '/'
                );
        }
    }

    /*
     * If the database stores a path such as:
     * public/uploads/profiles/photo.jpg
     * preserve that path even if the file check above failed.
     */
    if (
        str_starts_with(
            $normalized,
            'public/'
        ) ||
        str_starts_with(
            $normalized,
            'uploads/'
        )
    ) {

        return '../' .
            $normalized;
    }

    /*
     * Legacy fallback.
     */
    return '../uploads/teachers/' .
        $filename;
}

/**
 * Format Gregorian date as Ethiopian date.
 */
function formatEthiopianDate(?string $date): string
{
    if (
        $date === null ||
        trim($date) === ''
    ) {
        return '';
    }

    try {

        $gregorianDate = substr(
            trim($date),
            0,
            10
        );

        $ethiopian =
            EthiopianCalendar::fromGregorian(
                $gregorianDate
            );

        return (string) (
            $ethiopian['formatted'] ?? ''
        );

    } catch (Throwable $exception) {

        return '';
    }
}

/**
 * Gregorian display date.
 */
function formatTime(?string $date): string
{
    if (
        $date === null ||
        trim($date) === ''
    ) {
        return '';
    }

    try {

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return date(
            'M d, Y',
            $timestamp
        );

    } catch (Throwable $exception) {

        return '';
    }
}

/**
 * Determine whether media is an image.
 */
function isImageMedia(array $media): bool
{
    $mime = strtolower(
        trim(
            (string) (
                $media['mime_type'] ?? ''
            )
        )
    );

    if (
        str_starts_with(
            $mime,
            'image/'
        )
    ) {
        return true;
    }

    $path = strtolower(
        (string) (
            $media['file_path'] ?? ''
        )
    );

    return (bool) preg_match(
        '/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i',
        $path
    );
}

/**
 * Determine whether media is PDF.
 */
function isPdfMedia(array $media): bool
{
    $mime = strtolower(
        trim(
            (string) (
                $media['mime_type'] ?? ''
            )
        )
    );

    if (
        $mime === 'application/pdf'
    ) {
        return true;
    }

    $path = strtolower(
        (string) (
            $media['file_path'] ?? ''
        )
    );

    return str_ends_with(
        $path,
        '.pdf'
    );
}

/**
 * Pagination URL.
 */
function pageUrl(
    int $pageNumber,
    string $search
): string {

    $params = [
        'page' => $pageNumber
    ];

    if ($search !== '') {
        $params['search'] = $search;
    }

    return '?' .
        http_build_query($params);
}

/*
|--------------------------------------------------------------------------
| Load teacher profile
|--------------------------------------------------------------------------
*/

$teacherSql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.id AS teacher_id,
        t.photo_path
    FROM users u
    LEFT JOIN teachers t
        ON t.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0
    LIMIT 1
";

$teacherStmt = $conn->prepare(
    $teacherSql
);

if ($teacherStmt) {

    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult =
        $teacherStmt->get_result();

    $teacher =
        $teacherResult->fetch_assoc();

    $teacherStmt->close();
}

if (!$teacher) {

    session_destroy();

    header(
        'Location: ../auth/login.php'
    );

    exit;
}

$teacherName = trim(
    (string) (
        $teacher['full_name']
        ?? 'Teacher'
    )
);

if ($teacherName === '') {
    $teacherName = 'Teacher';
}

$teacherInitials =
    getInitials($teacherName);

/*
|--------------------------------------------------------------------------
| Teacher photo
|--------------------------------------------------------------------------
*/

if (
    isset($teacher['photo_path']) &&
    trim(
        (string) $teacher['photo_path']
    ) !== ''
) {

    $teacherPhoto =
        teacherPhotoUrl(
            (string) $teacher['photo_path']
        );
}

/*
|--------------------------------------------------------------------------
| Automatically close expired announcements
|--------------------------------------------------------------------------
*/

try {

    $closeExpiredSql = "
        UPDATE announcements
        SET
            status = 'Closed',
            updated_at = CURRENT_TIMESTAMP
        WHERE status = 'Published'
          AND closed_at IS NOT NULL
          AND closed_at <= NOW()
    ";

    $conn->query(
        $closeExpiredSql
    );

} catch (Throwable $exception) {

    error_log(
        'Teacher announcements expiration error: ' .
        $exception->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Count announcements
|--------------------------------------------------------------------------
*/

try {

    $countSql = "
        SELECT COUNT(*) AS total
        FROM announcements a
        WHERE a.status = 'Published'
          AND EXISTS (
              SELECT 1
              FROM announcement_audiences aa
              WHERE aa.announcement_id = a.id
                AND aa.audience_type IN (
                    'Public',
                    'Teacher'
                )
          )
    ";

    $countParams = [];
    $countTypes = '';

    if ($search !== '') {

        $countSql .= "
            AND (
                a.title LIKE ?
                OR a.content LIKE ?
            )
        ";

        $searchValue =
            '%' . $search . '%';

        $countParams[] =
            $searchValue;

        $countParams[] =
            $searchValue;

        $countTypes = 'ss';
    }

    $countStmt =
        $conn->prepare($countSql);

    if ($countStmt) {

        if ($countParams) {

            $countStmt->bind_param(
                $countTypes,
                ...$countParams
            );
        }

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        $countRow =
            $countResult->fetch_assoc();

        $totalAnnouncements =
            (int) (
                $countRow['total'] ?? 0
            );

        $countStmt->close();
    }

} catch (Throwable $exception) {

    error_log(
        'Teacher announcements count error: ' .
        $exception->getMessage()
    );

    $errorMessage =
        'Unable to load announcements right now.';
}

$totalPages = max(
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
| Load announcements
|--------------------------------------------------------------------------
*/

try {

    $announcementSql = "
        SELECT
            a.id,
            a.title,
            a.content,
            a.status,
            a.published_at,
            a.closed_at,
            a.created_at,
            a.updated_at
        FROM announcements a
        WHERE a.status = 'Published'
          AND EXISTS (
              SELECT 1
              FROM announcement_audiences aa
              WHERE aa.announcement_id = a.id
                AND aa.audience_type IN (
                    'Public',
                    'Teacher'
                )
          )
    ";

    $announcementParams = [];
    $announcementTypes = '';

    if ($search !== '') {

        $announcementSql .= "
            AND (
                a.title LIKE ?
                OR a.content LIKE ?
            )
        ";

        $searchValue =
            '%' . $search . '%';

        $announcementParams[] =
            $searchValue;

        $announcementParams[] =
            $searchValue;

        $announcementTypes = 'ss';
    }

    $announcementSql .= "
        ORDER BY
            COALESCE(
                a.published_at,
                a.created_at
            ) DESC,
            a.id DESC
        LIMIT ? OFFSET ?
    ";

    $announcementParams[] =
        $perPage;

    $announcementParams[] =
        $offset;

    $announcementTypes .= 'ii';

    $announcementStmt =
        $conn->prepare(
            $announcementSql
        );

    if ($announcementStmt) {

        $announcementStmt->bind_param(
            $announcementTypes,
            ...$announcementParams
        );

        $announcementStmt->execute();

        $announcementResult =
            $announcementStmt->get_result();

        while (
            $row =
                $announcementResult->fetch_assoc()
        ) {

            $row['media'] = [];

            $announcements[] =
                $row;
        }

        $announcementStmt->close();
    }

} catch (Throwable $exception) {

    error_log(
        'Teacher announcements fetch error: ' .
        $exception->getMessage()
    );

    $errorMessage =
        'Unable to load announcements right now.';
}

/*
|--------------------------------------------------------------------------
| Load announcement media
|--------------------------------------------------------------------------
*/

if ($announcements) {

    $announcementIds =
        array_map(
            static function (
                array $item
            ): int {

                return (int) $item['id'];
            },
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

        $mediaByAnnouncement = [];

        while (
            $media =
                $mediaResult->fetch_assoc()
        ) {

            $mediaByAnnouncement[
                (int) $media['announcement_id']
            ][] = $media;
        }

        $mediaStmt->close();

        foreach (
            $announcements as &$announcement
        ) {

            $announcementId =
                (int) $announcement['id'];

            $announcement['media'] =
                $mediaByAnnouncement[
                    $announcementId
                ] ?? [];
        }

        unset($announcement);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta
        name="theme-color"
        content="#111827"
    >

    <title>
        Announcements | BKHS Teacher Portal
    </title>

    <!-- Favicon -->
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

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --body-bg: #f5f7fb;
            --text-dark: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--body-bg);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        /* Sidebar */

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 260px;
            height: 100dvh;
            padding: 20px 14px;
            background: var(--sidebar);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 1050;
            transition: transform .25s ease;
            overflow: hidden;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 10px 20px;
            color: #fff;
            flex-shrink: 0;
        }

        .brand-icon {
            width: 43px;
            height: 43px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            font-size: 21px;
            flex-shrink: 0;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 10px;
        }

        .sidebar-label {
            padding: 0 12px;
            margin: 9px 0 7px;
            color: #6b7280;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 3px;
            overflow-y: auto;
            padding-right: 2px;
            scrollbar-width: thin;
        }

        .nav-link-custom {
            min-height: 43px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 12px;
            border-radius: 9px;
            color: #d1d5db;
            font-size: 12px;
            font-weight: 600;
            transition: .2s ease;
        }

        .nav-link-custom i {
            width: 21px;
            text-align: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background: var(--primary);
            color: #fff;
        }

        .nav-link-custom.logout {
            color: #fca5a5;
        }

        .nav-link-custom.logout:hover {
            background: #991b1b;
            color: #fff;
        }

        .sidebar-profile {
            margin-top: auto;
            padding: 13px 8px 0;
            border-top: 1px solid #374151;
            flex-shrink: 0;
        }

        .sidebar-profile-link {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #fff;
            min-width: 0;
        }

        .avatar {
            width: 39px;
            height: 39px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            background: #dbeafe;
            display: block;
        }

        .profile-name {
            max-width: 145px;
            overflow: hidden;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-role {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 10px;
        }

        /* Main */

        .main {
            min-height: 100vh;
            margin-left: 260px;
            width: calc(100% - 260px);
        }

        /* Topbar */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            min-height: 70px;
            padding: 13px 28px;
            background: rgba(255, 255, 255, .97);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .page-heading {
            min-width: 0;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 11px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .date-pill {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: #374151;
            background: #f9fafb;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .menu-toggle {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: #111827;
            font-size: 20px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Content */

        .content {
            width: 100%;
            max-width: 1450px;
            margin: 0 auto;
            padding: 25px 28px 35px;
        }

        .page-intro {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .page-intro h2 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
        }

        .page-intro p {
            margin: 6px 0 0;
            color: var(--text-muted);
            font-size: 11px;
            line-height: 1.5;
        }

        .announcement-count {
            flex-shrink: 0;
            padding: 8px 11px;
            border-radius: 8px;
            color: #1d4ed8;
            background: #dbeafe;
            font-size: 10px;
            font-weight: 800;
        }

        /* Search */

        .search-panel {
            margin-bottom: 18px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
        }

        .search-form {
            display: flex;
            gap: 9px;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
        }

        .search-wrapper i {
            position: absolute;
            top: 50%;
            left: 13px;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 15px;
        }

        .search-input {
            width: 100%;
            height: 42px;
            padding: 0 13px 0 38px;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            color: #111827;
            background: #fff;
            font-size: 11px;
        }

        .search-input:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
        }

        .search-button {
            height: 42px;
            padding: 0 17px;
            border: 0;
            border-radius: 9px;
            color: #fff;
            background: var(--primary);
            font-size: 11px;
            font-weight: 700;
        }

        .search-button:hover {
            background: var(--primary-dark);
        }

        .clear-button {
            height: 42px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: #374151;
            background: #fff;
            font-size: 11px;
            font-weight: 600;
        }

        /* Announcement grid */

        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(
                3,
                minmax(0, 1fr)
            );
            gap: 17px;
        }

        .announcement-card {
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow:
                0 10px 25px rgba(15, 23, 42, .08);
        }

        .announcement-image {
            position: relative;
            width: 100%;
            height: 230px;
            overflow: hidden;
            background: #f3f4f6;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9;
        }

        .announcement-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            cursor: pointer;
            display: block;
        }

        .image-overlay {
            position: absolute;
            right: 10px;
            bottom: 10px;
            padding: 6px 8px;
            border-radius: 7px;
            color: #fff;
            background: rgba(17, 24, 39, .72);
            font-size: 9px;
            font-weight: 700;
            pointer-events: none;
        }

        .announcement-body {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .announcement-main {
            padding: 16px;
        }

        .announcement-title {
            margin: 0;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .announcement-content {
            margin-top: 9px;
            color: #4b5563;
            font-size: 10px;
            line-height: 1.7;
        }

        .announcement-content p {
            margin-bottom: 7px;
        }

        .announcement-content p:last-child {
            margin-bottom: 0;
        }

        /* Attachments */

        .media-section {
            padding: 0 16px 14px;
        }

        .media-label {
            margin-bottom: 7px;
            color: #6b7280;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .media-list {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .media-button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #374151;
            background: #f9fafb;
            font-size: 9px;
            font-weight: 700;
            cursor: pointer;
            text-align: left;
        }

        .media-button:hover {
            color: var(--primary);
            border-color: #93c5fd;
            background: #eff6ff;
        }

        .media-button i {
            color: var(--primary);
            font-size: 14px;
            flex-shrink: 0;
        }

        .media-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Date */

        .announcement-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
            padding: 12px 16px;
            border-top: 1px solid #f1f5f9;
            background: #fafafa;
        }

        .published-date {
            color: #6b7280;
            font-size: 9px;
            font-weight: 600;
        }

        .published-date i {
            margin-right: 4px;
        }

        .read-button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary);
            font-size: 9px;
            font-weight: 800;
        }

        /* Empty */

        .empty-panel {
            padding: 55px 20px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            text-align: center;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #2563eb;
            background: #dbeafe;
            font-size: 25px;
        }

        .empty-panel h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }

        .empty-panel p {
            max-width: 450px;
            margin: 7px auto 0;
            color: var(--text-muted);
            font-size: 10px;
            line-height: 1.6;
        }

        /* Error */

        .error-panel {
            margin-bottom: 18px;
            padding: 13px 15px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            color: #991b1b;
            background: #fef2f2;
            font-size: 10px;
        }

        /* Pagination */

        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 22px;
        }

        .page-link-custom {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #374151;
            background: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .page-link-custom:hover,
        .page-link-custom.active {
            color: #fff;
            border-color: var(--primary);
            background: var(--primary);
        }

        .page-link-custom.disabled {
            color: #9ca3af;
            background: #f9fafb;
            pointer-events: none;
        }

        /* Modal */

        .modal-content {
            border: 0;
            border-radius: 13px;
            overflow: hidden;
        }

        .modal-header {
            padding: 14px 17px;
            border-bottom: 1px solid var(--border);
        }

        .modal-title {
            font-size: 14px;
            font-weight: 800;
        }

        .modal-body {
            padding: 0;
            background: #111827;
        }

        .preview-image {
            display: block;
            width: 100%;
            max-height: 75vh;
            object-fit: contain;
        }

        .preview-frame {
            width: 100%;
            height: 75vh;
            border: 0;
            background: #fff;
        }

        .unsupported-preview {
            padding: 50px 20px;
            color: #d1d5db;
            text-align: center;
        }

        .unsupported-preview i {
            display: block;
            margin-bottom: 10px;
            font-size: 40px;
        }

        .download-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 15px;
            padding: 9px 13px;
            border-radius: 8px;
            color: #fff;
            background: var(--primary);
            font-size: 10px;
            font-weight: 700;
        }

        /* Overlay */

        .overlay {
            display: none;
        }

        /* Mobile Bottom Navigation */

        .mobile-bottom-nav {
            display: none;
        }

        /* Tablet */

        @media (max-width: 1199px) {

            .announcement-grid {
                grid-template-columns:
                    repeat(
                        2,
                        minmax(0, 1fr)
                    );
            }
        }

        /* Mobile */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                box-shadow:
                    10px 0 35px
                    rgba(0, 0, 0, .18);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            /*
             * Hamburger removed on small screens.
             */
            .menu-toggle {
                display: none !important;
            }

            /*
             * Sidebar overlay removed on small screens.
             */
            .overlay,
            .overlay.show {
                display: none !important;
            }

            .topbar {
                min-height: 66px;
                padding: 11px 18px;
            }

            .content {
                padding: 20px 18px 100px;
            }

            /*
             * Mobile bottom navigation.
             */
            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 1100;

                min-height: 64px;

                display: grid;
                grid-template-columns:
                    repeat(5, minmax(0, 1fr));

                padding: 7px 5px
                    env(safe-area-inset-bottom);

                background: rgba(255, 255, 255, .98);
                border-top: 1px solid var(--border);

                box-shadow:
                    0 -5px 20px
                    rgba(15, 23, 42, .08);

                backdrop-filter: blur(10px);
            }

            .mobile-bottom-link {
                min-width: 0;

                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;

                gap: 3px;

                padding: 5px 2px;

                color: #6b7280;

                font-size: 8px;
                font-weight: 700;

                text-align: center;

                transition:
                    color .2s ease,
                    background .2s ease;
            }

            .mobile-bottom-link i {
                font-size: 18px;
                line-height: 1;
            }

            .mobile-bottom-link span {
                display: block;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .mobile-bottom-link:hover {
                color: var(--primary);
            }

            .mobile-bottom-link.logout {
                color: #dc2626;
            }

            .mobile-bottom-link.logout:hover {
                color: #b91c1c;
            }
        }

        /* Phone */

        @media (max-width: 575px) {

            .topbar {
                align-items: center;
                padding: 10px 12px;
                gap: 8px;
            }

            .page-heading h1 {
                font-size: 16px;
            }

            .page-heading p {
                display: none;
            }

            .topbar-right {
                gap: 6px;
            }

            .topbar-right > .avatar {
                width: 35px;
                height: 35px;
                font-size: 10px;
            }

            .date-pill {
                display: none;
            }

            .menu-toggle {
                display: none !important;
            }

            .content {
                padding: 14px 12px 100px;
            }

            .page-intro {
                align-items: flex-start;
                flex-direction: column;
                gap: 9px;
            }

            .page-intro h2 {
                font-size: 18px;
            }

            .announcement-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .announcement-image {
                height: 210px;
            }

            .announcement-main {
                padding: 14px;
            }

            .announcement-title {
                font-size: 14px;
            }

            .announcement-content {
                font-size: 9px;
            }

            .media-section {
                padding: 0 14px 12px;
            }

            .announcement-footer {
                padding: 11px 14px;
            }

            .mobile-bottom-nav {
                min-height: 62px;
            }

            .mobile-bottom-link {
                font-size: 7.5px;
            }

            .mobile-bottom-link i {
                font-size: 17px;
            }
        }

        @media (max-width: 360px) {

            .announcement-image {
                height: 180px;
            }

            .page-link-custom {
                min-width: 30px;
                height: 30px;
                font-size: 9px;
            }

            .mobile-bottom-link {
                font-size: 7px;
                gap: 2px;
            }

            .mobile-bottom-link i {
                font-size: 16px;
            }
        }

        @supports (padding: env(safe-area-inset-top)) {

            .topbar {
                padding-top:
                    max(
                        10px,
                        env(safe-area-inset-top)
                    );
            }

            .content {
                padding-bottom:
                    max(
                        100px,
                        calc(
                            25px +
                            env(safe-area-inset-bottom)
                        )
                    );
            }

        }

    </style>

</head>

<body>

<div
    class="overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <a
        href="dashboard.php"
        class="brand"
    >

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>

            <div class="brand-title">
                BKHS School
            </div>

            <div class="brand-subtitle">
                Teacher Portal
            </div>

        </div>

    </a>

    <div class="sidebar-label">
        Main Menu
    </div>

    <nav class="nav-menu">

        <a
            href="dashboard.php"
            class="nav-link-custom"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="subjects.php"
            class="nav-link-custom"
        >
            <i class="bi bi-book-fill"></i>
            <span>Subjects</span>
        </a>

        <a
            href="classes.php"
            class="nav-link-custom"
        >
            <i class="bi bi-people-fill"></i>
            <span>Classes</span>
        </a>

        <a
            href="result.php"
            class="nav-link-custom"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="sidebar-label">
            Academic
        </div>

        <a
            href="daily-attendance.php"
            class="nav-link-custom"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Daily Attendance</span>
        </a>

        <a
            href="subject-attendance.php"
            class="nav-link-custom"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Subject Attendance</span>
        </a>

        <a
            href="homework.php"
            class="nav-link-custom"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="nav-link-custom active"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <div class="sidebar-label">
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
            class="nav-link-custom logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-profile">

        <a
            href="profile.php"
            class="sidebar-profile-link"
        >

            <div class="avatar">

                <?php if ($teacherPhoto !== ''): ?>

                    <img
                        src="<?= e($teacherPhoto) ?>"
                        alt="Teacher photo"
                    >

                <?php else: ?>

                    <?= e($teacherInitials) ?>

                <?php endif; ?>

            </div>

            <div>

                <div class="profile-name">
                    <?= e($teacherName) ?>
                </div>

                <div class="profile-role">
                    Teacher
                </div>

            </div>

        </a>

    </div>

</aside>

<!-- Mobile Bottom Navigation -->

<nav
    class="mobile-bottom-nav"
    aria-label="Mobile navigation"
>

    <a
        href="daily-attendance.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-calendar-check-fill"></i>
        <span>Daily Attendance</span>
    </a>

    <a
        href="homework.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>

    <a
        href="result.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Result</span>
    </a>

    <a
        href="profile.php"
        class="mobile-bottom-link"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../auth/logout.php"
        class="mobile-bottom-link logout"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</nav>

<!-- Main -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div
            class="d-flex align-items-center gap-2 min-w-0"
        >

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Open menu"
                aria-controls="sidebar"
                aria-expanded="false"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-heading">
            </div>

        </div>

        <div class="topbar-right">

            <div class="date-pill">

                <i class="bi bi-calendar3"></i>

                <span>
                    <?= e($todayEthiopian) ?>
                </span>

            </div>

            <div class="avatar">

                <?php if ($teacherPhoto !== ''): ?>

                    <img
                        src="<?= e($teacherPhoto) ?>"
                        alt="Teacher photo"
                    >

                <?php else: ?>

                    <?= e($teacherInitials) ?>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <section class="page-intro">

            <div>

                <h2>
                    School Announcements
                </h2>

                <p>
                    Stay updated with the latest information
                    from BKHS School.
                </p>

            </div>

            <div class="announcement-count">

                <i class="bi bi-megaphone-fill me-1"></i>

                <?= $totalAnnouncements ?>

                <?= $totalAnnouncements === 1
                    ? 'Announcement'
                    : 'Announcements'
                ?>

            </div>

        </section>

        <?php if ($errorMessage !== ''): ?>

            <div class="error-panel">

                <i class="bi bi-exclamation-circle me-1"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>

        <!-- Search -->

        <section class="search-panel">

            <form
                method="get"
                class="search-form"
            >

                <div class="search-wrapper">

                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="search"
                        class="search-input"
                        value="<?= e($search) ?>"
                        placeholder="Search announcements..."
                        autocomplete="off"
                    >

                </div>

                <button
                    type="submit"
                    class="search-button"
                >
                    <i class="bi bi-search me-1"></i>
                    Search
                </button>

                <?php if ($search !== ''): ?>

                    <a
                        href="announcements.php"
                        class="clear-button"
                    >
                        <i class="bi bi-x-lg me-1"></i>
                        Clear
                    </a>

                <?php endif; ?>

            </form>

        </section>

        <!-- Announcements -->

        <?php if ($announcements): ?>

            <section class="announcement-grid">

                <?php foreach (
                    $announcements as $announcement
                ): ?>

                    <?php

                    $title = trim(
                        (string) (
                            $announcement['title']
                            ?? ''
                        )
                    );

                    $content = (string) (
                        $announcement['content']
                        ?? ''
                    );

                    $publishedAt =
                        $announcement['published_at']
                        ?? $announcement['created_at']
                        ?? '';

                    $ethiopianDate =
                        formatEthiopianDate(
                            (string) $publishedAt
                        );

                    $displayDate =
                        formatTime(
                            (string) $publishedAt
                        );

                    $media =
                        $announcement['media']
                        ?? [];

                    $imageMedia = null;
                    $attachments = [];

                    foreach ($media as $mediaItem) {

                        if (
                            isImageMedia(
                                $mediaItem
                            )
                        ) {

                            if ($imageMedia === null) {

                                $imageMedia =
                                    $mediaItem;
                            }

                        } else {

                            $attachments[] =
                                $mediaItem;
                        }
                    }

                    ?>

                    <article
                        class="announcement-card"
                    >

                        <!-- Title + Description -->

                        <div class="announcement-main">

                            <h3
                                class="announcement-title"
                            >
                                <?= e($title) ?>
                            </h3>

                            <?php if (
                                trim($content) !== ''
                            ): ?>

                                <div
                                    class="announcement-content"
                                >
                                    <?= $content ?>
                                </div>

                            <?php else: ?>

                                <div
                                    class="announcement-content"
                                    style="color:#9ca3af;"
                                >
                                    No description available.
                                </div>

                            <?php endif; ?>

                        </div>

                        <!-- Photo -->

                        <?php if ($imageMedia): ?>

                            <?php

                            $imageUrl =
                                mediaUrl(
                                    (string) (
                                        $imageMedia[
                                            'file_path'
                                        ] ?? ''
                                    )
                                );

                            ?>

                            <?php if (
                                $imageUrl !== ''
                            ): ?>

                                <div
                                    class="announcement-image"
                                >

                                    <img
                                        src="<?= e($imageUrl) ?>"
                                        alt="<?= e($title) ?>"
                                        loading="lazy"
                                        class="preview-trigger"
                                        data-preview-type="image"
                                        data-preview-url="<?= e($imageUrl) ?>"
                                        data-preview-title="<?= e($title) ?>"
                                    >

                                    <div
                                        class="image-overlay"
                                    >
                                        <i class="bi bi-zoom-in me-1"></i>
                                        View image
                                    </div>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                        <!-- Attachments -->

                        <?php if ($attachments): ?>

                            <div class="media-section">

                                <div class="media-label">
                                    <i class="bi bi-paperclip me-1"></i>
                                    Attachment
                                </div>

                                <div class="media-list">

                                    <?php foreach (
                                        $attachments as $attachment
                                    ): ?>

                                        <?php

                                        $attachmentUrl =
                                            mediaUrl(
                                                (string) (
                                                    $attachment[
                                                        'file_path'
                                                    ] ?? ''
                                                )
                                            );

                                        $attachmentName =
                                            trim(
                                                (string) (
                                                    $attachment[
                                                        'original_name'
                                                    ] ?? ''
                                                )
                                            );

                                        if (
                                            $attachmentName === ''
                                        ) {

                                            $attachmentName =
                                                'Attachment';
                                        }

                                        $isPdf =
                                            isPdfMedia(
                                                $attachment
                                            );

                                        $attachmentType =
                                            $isPdf
                                            ? 'pdf'
                                            : 'file';

                                        ?>

                                        <?php if (
                                            $attachmentUrl !== ''
                                        ): ?>

                                            <button
                                                type="button"
                                                class="media-button preview-trigger"
                                                data-preview-type="<?= e($attachmentType) ?>"
                                                data-preview-url="<?= e($attachmentUrl) ?>"
                                                data-preview-title="<?= e($attachmentName) ?>"
                                            >

                                                <i
                                                    class="bi <?= $isPdf
                                                        ? 'bi-file-earmark-pdf-fill'
                                                        : 'bi-paperclip'
                                                    ?>"
                                                ></i>

                                                <span
                                                    class="media-name"
                                                >
                                                    <?= e(
                                                        $attachmentName
                                                    ) ?>
                                                </span>

                                                <i
                                                    class="bi bi-box-arrow-up-right ms-auto"
                                                ></i>

                                            </button>

                                        <?php endif; ?>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endif; ?>

                        <!-- Date -->

                        <div
                            class="announcement-footer"
                        >

                            <span
                                class="published-date"
                            >

                                <i class="bi bi-calendar3"></i>

                                <?= e(
                                    $ethiopianDate
                                ) ?>

                                <?php if (
                                    $displayDate !== ''
                                ): ?>

                                    <span class="ms-1">
                                        (<?= e(
                                            $displayDate
                                        ) ?>)
                                    </span>

                                <?php endif; ?>

                            </span>

                            <span
                                class="read-button"
                            >
                                Published
                                <i class="bi bi-check-circle-fill"></i>
                            </span>

                        </div>

                    </article>

                <?php endforeach; ?>

            </section>

        <?php else: ?>

            <section class="empty-panel">

                <div class="empty-icon">

                    <i class="bi bi-megaphone"></i>

                </div>

                <?php if ($search !== ''): ?>

                    <h3>
                        No announcements found
                    </h3>

                    <p>
                        No published announcement matches
                        your search for
                        "<strong><?= e($search) ?></strong>".
                        Try another search term.
                    </p>

                <?php else: ?>

                    <h3>
                        No announcements yet
                    </h3>

                    <p>
                        There are currently no published
                        announcements available for teachers.
                    </p>

                <?php endif; ?>

            </section>

        <?php endif; ?>

        <!-- Pagination -->

        <?php if ($totalPages > 1): ?>

            <div class="pagination-wrapper">

                <?php if ($page > 1): ?>

                    <a
                        href="<?= e(
                            pageUrl(
                                $page - 1,
                                $search
                            )
                        ) ?>"
                        class="page-link-custom"
                        aria-label="Previous page"
                    >
                        <i class="bi bi-chevron-left"></i>
                    </a>

                <?php else: ?>

                    <span
                        class="page-link-custom disabled"
                    >
                        <i class="bi bi-chevron-left"></i>
                    </span>

                <?php endif; ?>

                <?php

                $startPage = max(
                    1,
                    $page - 2
                );

                $endPage = min(
                    $totalPages,
                    $page + 2
                );

                ?>

                <?php if ($startPage > 1): ?>

                    <a
                        href="<?= e(
                            pageUrl(
                                1,
                                $search
                            )
                        ) ?>"
                        class="page-link-custom"
                    >
                        1
                    </a>

                    <?php if ($startPage > 2): ?>

                        <span
                            class="page-link-custom disabled"
                        >
                            ...
                        </span>

                    <?php endif; ?>

                <?php endif; ?>

                <?php for (
                    $pageNumber = $startPage;
                    $pageNumber <= $endPage;
                    $pageNumber++
                ): ?>

                    <a
                        href="<?= e(
                            pageUrl(
                                $pageNumber,
                                $search
                            )
                        ) ?>"
                        class="page-link-custom <?= $pageNumber === $page
                            ? 'active'
                            : ''
                        ?>"
                    >
                        <?= $pageNumber ?>
                    </a>

                <?php endfor; ?>

                <?php if (
                    $endPage < $totalPages
                ): ?>

                    <?php if (
                        $endPage < $totalPages - 1
                    ): ?>

                        <span
                            class="page-link-custom disabled"
                        >
                            ...
                        </span>

                    <?php endif; ?>

                    <a
                        href="<?= e(
                            pageUrl(
                                $totalPages,
                                $search
                            )
                        ) ?>"
                        class="page-link-custom"
                    >
                        <?= $totalPages ?>
                    </a>

                <?php endif; ?>

                <?php if (
                    $page < $totalPages
                ): ?>

                    <a
                        href="<?= e(
                            pageUrl(
                                $page + 1,
                                $search
                            )
                        ) ?>"
                        class="page-link-custom"
                        aria-label="Next page"
                    >
                        <i class="bi bi-chevron-right"></i>
                    </a>

                <?php else: ?>

                    <span
                        class="page-link-custom disabled"
                    >
                        <i class="bi bi-chevron-right"></i>
                    </span>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<!-- Preview Modal -->

<div
    class="modal fade"
    id="previewModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-xl modal-dialog-centered"
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
    document.getElementById('sidebar');

const menuToggle =
    document.getElementById('menuToggle');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

function openSidebar() {

    sidebar.classList.add('show');

    sidebarOverlay.classList.add('show');

    menuToggle?.setAttribute(
        'aria-expanded',
        'true'
    );

    document.body.style.overflow =
        'hidden';
}

function closeSidebar() {

    sidebar.classList.remove('show');

    sidebarOverlay.classList.remove('show');

    menuToggle?.setAttribute(
        'aria-expanded',
        'false'
    );

    document.body.style.overflow =
        '';
}

menuToggle?.addEventListener(
    'click',
    () => {

        if (
            sidebar.classList.contains('show')
        ) {
            closeSidebar();
        } else {
            openSidebar();
        }

    }
);

sidebarOverlay?.addEventListener(
    'click',
    closeSidebar
);

document
    .querySelectorAll('.nav-link-custom')
    .forEach(link => {

        link.addEventListener(
            'click',
            () => {

                if (
                    window.innerWidth <= 991
                ) {
                    closeSidebar();
                }

            }
        );

    });

window.addEventListener(
    'resize',
    () => {

        if (
            window.innerWidth > 991
        ) {
            closeSidebar();
        }

    }
);

/*
|--------------------------------------------------------------------------
| Announcement media preview
|--------------------------------------------------------------------------
*/

const previewModalElement =
    document.getElementById(
        'previewModal'
    );

const previewModal =
    new bootstrap.Modal(
        previewModalElement
    );

const previewModalTitle =
    document.getElementById(
        'previewModalTitle'
    );

const previewModalBody =
    document.getElementById(
        'previewModalBody'
    );

document
    .querySelectorAll('.preview-trigger')
    .forEach(trigger => {

        trigger.addEventListener(
            'click',
            function () {

                const type =
                    this.dataset.previewType
                    || 'file';

                const url =
                    this.dataset.previewUrl
                    || '';

                const title =
                    this.dataset.previewTitle
                    || 'Preview';

                if (!url) {
                    return;
                }

                previewModalTitle.textContent =
                    title;

                previewModalBody.innerHTML =
                    '';

                /*
                |--------------------------------------------------------------
                | Image
                |--------------------------------------------------------------
                */

                if (type === 'image') {

                    const image =
                        document.createElement(
                            'img'
                        );

                    image.src = url;

                    image.alt = title;

                    image.className =
                        'preview-image';

                    image.addEventListener(
                        'error',
                        () => {

                            previewModalBody.innerHTML = `
                                <div class="unsupported-preview">
                                    <i class="bi bi-image"></i>

                                    <div>
                                        Unable to display this image.
                                    </div>

                                    <a
                                        href="${escapeHtml(url)}"
                                        class="download-button"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        Open Image
                                    </a>
                                </div>
                            `;
                        }
                    );

                    previewModalBody.appendChild(
                        image
                    );
                }

                /*
                |--------------------------------------------------------------
                | PDF
                |--------------------------------------------------------------
                */

                else if (type === 'pdf') {

                    const frame =
                        document.createElement(
                            'iframe'
                        );

                    frame.src = url;

                    frame.className =
                        'preview-frame';

                    frame.title =
                        title;

                    previewModalBody.appendChild(
                        frame
                    );
                }

                /*
                |--------------------------------------------------------------
                | Other files
                |--------------------------------------------------------------
                */

                else {

                    const wrapper =
                        document.createElement(
                            'div'
                        );

                    wrapper.className =
                        'unsupported-preview';

                    wrapper.innerHTML = `
                        <i class="bi bi-file-earmark"></i>

                        <div>
                            This file cannot be previewed in the browser.
                        </div>

                        <a
                            href="${escapeHtml(url)}"
                            class="download-button"
                            target="_blank"
                            rel="noopener"
                            download
                        >
                            <i class="bi bi-download"></i>
                            Download File
                        </a>
                    `;

                    previewModalBody.appendChild(
                        wrapper
                    );
                }

                previewModal.show();
            }
        );

    });

/*
|--------------------------------------------------------------------------
| Safe HTML helper
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    const div =
        document.createElement(
            'div'
        );

    div.textContent =
        String(value ?? '');

    return div.innerHTML;
}

/*
|--------------------------------------------------------------------------
| Clear modal
|--------------------------------------------------------------------------
*/

previewModalElement.addEventListener(
    'hidden.bs.modal',
    () => {

        previewModalBody.innerHTML =
            '';

    }
);

</script>

</body>

</html>