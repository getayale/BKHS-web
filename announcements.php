<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    die('Database connection is not available.');
}

$db = $conn;

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Addis_Ababa');

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

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(?string $date): string
{
    if (empty($date)) {
        return '—';
    }

    try {
        return EthiopianCalendar::fromGregorian(
            substr($date, 0, 10)
        )['formatted'];
    } catch (Throwable) {
        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Public Media URL
|--------------------------------------------------------------------------
|
| Actual filesystem:
|
| BKHS/
| └── uploads/
|     ├── images/
|     └── attachments/
|
*/

function mediaUrlByType(
    string $path,
    string $mediaType
): string {

    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    /*
    | External URL
    */
    if (
        preg_match(
            '~^(https?:)?//~i',
            $path
        )
    ) {
        return $path;
    }

    /*
    | Remove leading slash
    */
    $path = ltrim($path, '/');

    /*
    | Remove BKHS prefix
    */
    if (
        str_starts_with(
            strtolower($path),
            'bkhs/'
        )
    ) {
        $path = substr($path, 5);
    }

    /*
    | Already uploads/...
    */
    if (
        str_starts_with(
            strtolower($path),
            'uploads/'
        )
    ) {
        return '/BKHS/' . $path;
    }

    /*
    | images/...
    */
    if (
        str_starts_with(
            strtolower($path),
            'images/'
        )
    ) {
        return '/BKHS/uploads/' . $path;
    }

    /*
    | attachments/...
    */
    if (
        str_starts_with(
            strtolower($path),
            'attachments/'
        )
    ) {
        return '/BKHS/uploads/' . $path;
    }

    /*
    | Bare filename
    */
    if (
        strtolower($mediaType) === 'image'
    ) {
        return '/BKHS/uploads/images/' . $path;
    }

    return '/BKHS/uploads/attachments/' . $path;
}

/*
|--------------------------------------------------------------------------
| File Size
|--------------------------------------------------------------------------
*/

function formatFileSize(?int $bytes): string
{
    $bytes = (int) $bytes;

    if ($bytes <= 0) {
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
| File Extension
|--------------------------------------------------------------------------
*/

function getFileExtension(string $filename): string
{
    return strtolower(
        pathinfo(
            $filename,
            PATHINFO_EXTENSION
        )
    );
}

/*
|--------------------------------------------------------------------------
| Attachment Icon
|--------------------------------------------------------------------------
*/

function attachmentIcon(
    string $filename,
    ?string $mimeType = null
): string {

    $extension = getFileExtension($filename);

    if (
        $mimeType &&
        str_starts_with(
            strtolower($mimeType),
            'image/'
        )
    ) {
        return 'bi-image';
    }

    return match ($extension) {

        'pdf'
            => 'bi-file-earmark-pdf-fill',

        'doc',
        'docx'
            => 'bi-file-earmark-word-fill',

        'xls',
        'xlsx'
            => 'bi-file-earmark-excel-fill',

        'ppt',
        'pptx'
            => 'bi-file-earmark-ppt-fill',

        'zip',
        'rar',
        '7z'
            => 'bi-file-earmark-zip-fill',

        'txt'
            => 'bi-file-earmark-text-fill',

        'csv'
            => 'bi-file-earmark-spreadsheet-fill',

        default
            => 'bi-paperclip',
    };
}

/*
|--------------------------------------------------------------------------
| Attachment Preview Type
|--------------------------------------------------------------------------
*/

function attachmentPreviewType(
    string $filename,
    ?string $mimeType = null
): string {

    $extension = getFileExtension($filename);

    $mime = strtolower(
        trim((string) $mimeType)
    );

    /*
    | PDF
    */
    if (
        $extension === 'pdf' ||
        $mime === 'application/pdf'
    ) {
        return 'pdf';
    }

    /*
    | Images
    */
    if (
        str_starts_with(
            $mime,
            'image/'
        ) ||
        in_array(
            $extension,
            [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',
                'bmp',
                'svg'
            ],
            true
        )
    ) {
        return 'image';
    }

    /*
    | Text / CSV
    */
    if (
        $extension === 'txt' ||
        $extension === 'csv' ||
        $mime === 'text/plain' ||
        $mime === 'text/csv'
    ) {
        return 'text';
    }

    /*
    | Office files
    */
    if (
        in_array(
            $extension,
            [
                'doc',
                'docx',
                'xls',
                'xlsx',
                'ppt',
                'pptx'
            ],
            true
        )
    ) {
        return 'office';
    }

    return 'file';
}

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) (
        $_GET['search'] ?? ''
    )
);

/*
|--------------------------------------------------------------------------
| Load Public Announcements
|--------------------------------------------------------------------------
|
| Public announcements:
|
| status = Published
| audience = Public
|
| Closed announcements receive one-day grace period.
|
*/

$announcements = [];

if ($search !== '') {

    $searchValue =
        '%' . $search . '%';

    $stmt = $db->prepare("
        SELECT DISTINCT
            a.id,
            a.title,
            a.content,
            a.published_at,
            a.closed_at,
            a.created_at

        FROM announcements AS a

        INNER JOIN announcement_audiences AS aa
            ON aa.announcement_id = a.id

        WHERE a.status = 'Published'

          AND aa.audience_type = 'Public'

          AND (
                a.closed_at IS NULL
                OR a.closed_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 1 DAY
                )
          )

          AND (
                a.title LIKE ?
                OR a.content LIKE ?
          )

        ORDER BY
            COALESCE(
                a.published_at,
                a.created_at
            ) DESC,

            a.id DESC
    ");

    if ($stmt) {

        $stmt->bind_param(
            'ss',
            $searchValue,
            $searchValue
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {
            $announcements[] =
                $row;
        }

        $stmt->close();
    }

} else {

    $stmt = $db->prepare("
        SELECT DISTINCT
            a.id,
            a.title,
            a.content,
            a.published_at,
            a.closed_at,
            a.created_at

        FROM announcements AS a

        INNER JOIN announcement_audiences AS aa
            ON aa.announcement_id = a.id

        WHERE a.status = 'Published'

          AND aa.audience_type = 'Public'

          AND (
                a.closed_at IS NULL
                OR a.closed_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 1 DAY
                )
          )

        ORDER BY
            COALESCE(
                a.published_at,
                a.created_at
            ) DESC,

            a.id DESC
    ");

    if ($stmt) {

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {
            $announcements[] =
                $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Announcement IDs
|--------------------------------------------------------------------------
*/

$announcementIds = [];

foreach (
    $announcements
    as $announcement
) {

    $announcementIds[] =
        (int) $announcement['id'];
}

/*
|--------------------------------------------------------------------------
| Load Media
|--------------------------------------------------------------------------
*/

$mediaByAnnouncement = [];

if (!empty($announcementIds)) {

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($announcementIds),
                '?'
            )
        );

    $types =
        str_repeat(
            'i',
            count($announcementIds)
        );

    $sql = "
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

    $stmt =
        $db->prepare($sql);

    if ($stmt) {

        $params = [];

        $params[] =
            $types;

        foreach (
            $announcementIds
            as $key => $id
        ) {

            $params[] =
                &$announcementIds[$key];
        }

        call_user_func_array(
            [
                $stmt,
                'bind_param'
            ],
            $params
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $announcementId =
                (int) $row['announcement_id'];

            if (
                !isset(
                    $mediaByAnnouncement[
                        $announcementId
                    ]
                )
            ) {

                $mediaByAnnouncement[
                    $announcementId
                ] = [];
            }

            $mediaByAnnouncement[
                $announcementId
            ][] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Load Content Blocks
|--------------------------------------------------------------------------
*/

$blocksByAnnouncement = [];

if (!empty($announcementIds)) {

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($announcementIds),
                '?'
            )
        );

    $types =
        str_repeat(
            'i',
            count($announcementIds)
        );

    $sql = "
        SELECT
            id,
            announcement_id,
            block_type,
            content,
            media_id,
            display_order

        FROM announcement_content_blocks

        WHERE announcement_id IN (
            $placeholders
        )

        ORDER BY
            announcement_id ASC,
            display_order ASC,
            id ASC
    ";

    $stmt =
        $db->prepare($sql);

    if ($stmt) {

        $params = [];

        $params[] =
            $types;

        foreach (
            $announcementIds
            as $key => $id
        ) {

            $params[] =
                &$announcementIds[$key];
        }

        call_user_func_array(
            [
                $stmt,
                'bind_param'
            ],
            $params
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $announcementId =
                (int) $row['announcement_id'];

            if (
                !isset(
                    $blocksByAnnouncement[
                        $announcementId
                    ]
                )
            ) {

                $blocksByAnnouncement[
                    $announcementId
                ] = [];
            }

            $blocksByAnnouncement[
                $announcementId
            ][] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Media Lookup
|--------------------------------------------------------------------------
*/

$mediaLookup = [];

foreach (
    $mediaByAnnouncement
    as $items
) {

    foreach (
        $items
        as $media
    ) {

        $mediaLookup[
            (int) $media['id']
        ] = $media;
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

    <meta
        name="description"
        content="Latest announcements from Bole Kale Hiwot School."
    >

    <title>
        Announcements | Bole Kale Hiwot School
    </title>
    <link rel="icon" type="image/webp" href="public/image/logo.webp">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;
            --card: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        a {
            text-decoration: none;
        }

        /* =========================================================
           NAVBAR
        ========================================================= */

        .site-navbar {
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800;
        }

        .navbar-brand img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .brand-name {
            line-height: 1.1;
        }

        .brand-name strong {
            display: block;
            font-size: 15px;
        }

        .brand-name span {
            display: block;
            color: var(--muted);
            font-size: 10px;
            font-weight: 500;
            margin-top: 3px;
        }

        .navbar-nav .nav-link {
            color: #4b5563;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 12px;
            border-radius: 8px;
            transition: .2s ease;
        }

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            color: var(--primary);
            background: var(--primary-light);
        }

        .login-btn {
            background: var(--primary);
            color: #fff !important;
            padding: 9px 17px !important;
            border-radius: 9px !important;
        }

        .login-btn:hover {
            background: var(--primary-dark) !important;
            color: #fff !important;
        }

        /* =========================================================
           HERO
        ========================================================= */

        .announcement-hero {
            position: relative;
            overflow: hidden;
            padding: 80px 0 70px;
            background:
                linear-gradient(
                    135deg,
                    #eef2ff 0%,
                    #ffffff 55%,
                    #f8fafc 100%
                );
        }

        .announcement-hero::before {
            content: '';
            position: absolute;
            width: 330px;
            height: 330px;
            border-radius: 50%;
            background: rgba(79,70,229,.08);
            top: -170px;
            right: -90px;
        }

        .announcement-hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(99,102,241,.06);
            bottom: -120px;
            left: -70px;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 30px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .hero-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: clamp(32px, 5vw, 52px);
            line-height: 1.1;
            font-weight: 800;
            letter-spacing: -.035em;
            margin: 0;
        }

        .hero-description {
            max-width: 680px;
            margin: 17px auto 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.8;
        }

        .hero-count {
            margin-top: 22px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .hero-count i {
            color: var(--primary);
        }

        /* =========================================================
           SEARCH
        ========================================================= */

        .search-section {
            margin-top: -27px;
            position: relative;
            z-index: 10;
        }

        .search-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 12px;
            box-shadow: 0 15px 40px rgba(15,23,42,.08);
        }

        .search-form {
            display: flex;
            gap: 10px;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
        }

        .search-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-input {
            width: 100%;
            height: 48px;
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 0 15px 0 43px;
            font-size: 13px;
            outline: none;
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,.08);
        }

        .search-submit {
            height: 48px;
            border: 0;
            border-radius: 11px;
            padding: 0 20px;
            background: var(--primary);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
        }

        .search-submit:hover {
            background: var(--primary-dark);
        }

        .clear-search {
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .announcements-section {
            padding: 65px 0 90px;
        }

        .section-heading {
            margin-bottom: 28px;
        }

        .section-heading h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -.025em;
            margin: 0;
        }

        .section-heading p {
            color: var(--muted);
            font-size: 13px;
            margin: 7px 0 0;
        }

        /*
        |--------------------------------------------------------------------------
        | TWO CARDS PER ROW
        |--------------------------------------------------------------------------
        */

        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
            align-items: start;
        }

        /* =========================================================
           ANNOUNCEMENT CARD
        ========================================================= */

        .announcement-card {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 7px 25px rgba(15,23,42,.05);
            transition:
                transform .25s ease,
                box-shadow .25s ease;
        }

        .announcement-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 45px rgba(15,23,42,.09);
        }

        .announcement-header {
            padding: 22px 22px 15px;
        }

        .announcement-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 20px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 10px;
            font-weight: 700;
        }

        .meta-date {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
        }

        .announcement-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: clamp(18px, 2vw, 23px);
            font-weight: 800;
            letter-spacing: -.025em;
            line-height: 1.3;
            margin: 0;
        }

        .announcement-body {
            padding: 0 22px 20px;
        }

        .announcement-text {
            color: #374151;
            font-size: 13px;
            line-height: 1.8;
            text-align: justify;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .announcement-text p:last-child {
            margin-bottom: 0;
        }

        /* =========================================================
           CONTENT BLOCKS
        ========================================================= */

        .content-heading {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 800;
            margin: 17px 0 9px;
        }

        .content-quote {
            border-left: 4px solid var(--primary);
            background: var(--primary-light);
            padding: 12px 15px;
            border-radius: 0 10px 10px 0;
            color: #374151;
            font-size: 13px;
            line-height: 1.7;
            font-style: italic;
            margin: 15px 0;
        }

        .content-block-image {
            margin: 17px 0;
            border-radius: 12px;
            overflow: hidden;
            background: #f3f4f6;
        }

        .content-block-image img {
            width: 100%;
            max-height: 350px;
            object-fit: contain;
            display: block;
            margin: auto;
        }

        /* =========================================================
           MEDIA
        ========================================================= */

        .announcement-media {
            border-top: 1px solid var(--border);
            padding: 18px 22px 21px;
            background: #fafbff;
        }

        .media-title {
            display: flex;
            align-items: center;
            gap: 7px;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 11px;
        }

        .media-title i {
            color: var(--primary);
            font-size: 15px;
        }

        /* =========================================================
           IMAGE GALLERY
        ========================================================= */

        .announcement-images {
            display: grid;
            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(160px, 1fr)
                );
            gap: 10px;
        }

        .announcement-image {
            position: relative;
            overflow: hidden;
            border-radius: 11px;
            background: #e5e7eb;
            cursor: pointer;
            aspect-ratio: 16 / 10;
        }

        .announcement-image img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            transition: transform .35s ease;
        }

        .announcement-image:hover img {
            transform: scale(1.04);
        }

        .image-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,.38);
            color: #fff;
            opacity: 0;
            transition: opacity .25s ease;
        }

        .announcement-image:hover .image-overlay {
            opacity: 1;
        }

        .image-overlay i {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.95);
            color: var(--primary);
            font-size: 17px;
        }

        /* =========================================================
           ATTACHMENTS
        ========================================================= */

        .attachment-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        /*
        | The whole attachment is clickable.
        */

        .attachment-item {
            display: block;
            width: 100%;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition:
                border-color .2s ease,
                background .2s ease,
                transform .2s ease;
        }

        .attachment-item:hover {
            border-color: #c7d2fe;
            background: #fafaff;
            transform: translateY(-1px);
        }

        .attachment-header {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 13px;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 9px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .attachment-info {
            min-width: 0;
            flex: 1;
        }

        .attachment-name {
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
            overflow-wrap: anywhere;
            line-height: 1.4;
        }

        .attachment-size {
            color: var(--muted);
            font-size: 9px;
            margin-top: 3px;
        }

        .attachment-preview-hint {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--primary);
            font-size: 10px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .attachment-preview-hint i {
            font-size: 12px;
        }

        /* =========================================================
           ATTACHMENT PREVIEW MODAL
        ========================================================= */

        .preview-modal .modal-dialog {
            max-width: 1100px;
            width: calc(100% - 30px);
            margin: 1.25rem auto;
        }

        .preview-modal .modal-content {
            border: 0;
            border-radius: 15px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 25px 80px rgba(0,0,0,.25);
        }

        .preview-modal-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .preview-modal-title {
            min-width: 0;
            flex: 1;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            overflow-wrap: anywhere;
        }

        .preview-modal-close {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            border: 0;
            border-radius: 8px;
            background: #f3f4f6;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .preview-modal-close:hover {
            background: #e5e7eb;
        }

        .preview-content {
            position: relative;
            background: #e5e7eb;
            min-height: 300px;
        }

        .preview-content iframe {
            width: 100%;
            height: 78vh;
            min-height: 500px;
            display: block;
            border: 0;
            background: #fff;
        }

        .preview-image {
            display: block;
            width: 100%;
            height: auto;
            max-height: 82vh;
            object-fit: contain;
            background: #111827;
        }

        .preview-text {
            width: 100%;
            height: 70vh;
            min-height: 400px;
            overflow: auto;
            padding: 20px;
            background: #fff;
            color: #1f2937;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            line-height: 1.7;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .preview-office-message {
            min-height: 300px;
            padding: 45px 25px;
            text-align: center;
            background: #fff;
        }

        .preview-office-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 15px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 27px;
        }

        .preview-office-message h4 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .preview-office-message p {
            max-width: 500px;
            margin: 0 auto;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .preview-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            padding: 11px 15px;
            border-top: 1px solid var(--border);
            background: #fff;
        }

        .preview-download-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
        }

        .preview-download-btn:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        /* =========================================================
           FOOTER
        ========================================================= */

        .site-footer {
            background: #111827;
            color: #d1d5db;
            padding: 60px 0 25px;
        }

        .footer-brand {
            color: #fff;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800;
            font-size: 17px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .footer-brand img {
            width: 44px;
            height: 44px;
            object-fit: contain;
        }

        .footer-description {
            color: #9ca3af;
            font-size: 12px;
            line-height: 1.8;
            max-width: 350px;
            margin-top: 14px;
        }

        .footer-title {
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 9px;
        }

        .footer-links a {
            color: #9ca3af;
            font-size: 12px;
            transition: color .2s ease;
        }

        .footer-links a:hover {
            color: #fff;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,.08);
            margin-top: 40px;
            padding-top: 20px;
            color: #6b7280;
            font-size: 11px;
        }

        /* =========================================================
           EMPTY
        ========================================================= */

        .empty-state {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 65px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .empty-state h3 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        /* =========================================================
           IMAGE LIGHTBOX
        ========================================================= */

        .lightbox-modal .modal-content {
            background: #000;
            border: 0;
            border-radius: 12px;
            overflow: hidden;
        }

        .lightbox-modal .modal-body {
            padding: 0;
            position: relative;
        }

        .lightbox-image {
            width: 100%;
            max-height: 88vh;
            object-fit: contain;
            display: block;
            background: #000;
        }

        .lightbox-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            border: 0;
            border-radius: 50%;
            background: rgba(255,255,255,.9);
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            font-size: 18px;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        /*
        | This is the SAME page.
        | There is no separate mobile page or mobile section.
        */

        @media (max-width: 991.98px) {

            .announcement-grid {
                grid-template-columns: 1fr;
            }

            .announcement-card {
                max-width: 820px;
                margin-left: auto;
                margin-right: auto;
            }

        }

        @media (max-width: 767.98px) {

            .announcement-hero {
                padding: 55px 0;
            }

            .hero-description {
                font-size: 13px;
                line-height: 1.7;
            }

            .search-section {
                margin-top: -22px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-submit,
            .clear-search {
                width: 100%;
            }

            .announcements-section {
                padding: 45px 0 65px;
            }

            .announcement-grid {
                gap: 18px;
            }

            .announcement-card {
                border-radius: 14px;
            }

            .announcement-header {
                padding: 19px 17px 14px;
            }

            .announcement-body {
                padding: 0 17px 18px;
            }

            .announcement-media {
                padding: 17px;
            }

            .announcement-images {
                grid-template-columns: 1fr;
            }

            .announcement-image {
                aspect-ratio: 16 / 10;
            }

            .announcement-text {
                font-size: 13px;
                line-height: 1.8;
            }

            .attachment-preview-hint span {
                display: none;
            }

            .preview-modal .modal-dialog {
                width: calc(100% - 12px);
                margin: .4rem auto;
            }

            .preview-content iframe {
                height: 78vh;
                min-height: 400px;
            }

            .preview-text {
                height: 70vh;
                min-height: 350px;
            }

            .navbar-brand img {
                width: 42px;
                height: 42px;
            }

            .brand-name strong {
                font-size: 13px;
            }

            .brand-name span {
                font-size: 9px;
            }
        }

        @media (max-width: 400px) {

            .announcement-title {
                font-size: 18px;
            }

            .announcement-header {
                padding-left: 15px;
                padding-right: 15px;
            }

            .announcement-body {
                padding-left: 15px;
                padding-right: 15px;
            }

            .announcement-media {
                padding-left: 15px;
                padding-right: 15px;
            }

            .attachment-header {
                padding: 10px;
            }

            .attachment-icon {
                width: 37px;
                height: 37px;
                font-size: 17px;
            }

        }

    </style>

</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg site-navbar">

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand"
        >

            <img
                src="public/image/logo.webp"
                alt="Bole Kale Hiwot School"
            >

            <div class="brand-name">

                <strong>
                    Bole Kale Hiwot School
                </strong>

                <span>
                    School Management System
                </span>

            </div>

        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
            aria-controls="mainNavbar"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >

            <span class="navbar-toggler-icon"></span>

        </button>

        <div
            class="collapse navbar-collapse"
            id="mainNavbar"
        >

            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="index.php"
                    >
                        Home
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="about.php"
                    >
                        About
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="admission.php"
                    >
                        Admission
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link active"
                        href="announcements.php"
                    >
                        Announcement
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="gallery.php"
                    >
                        Gallery
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="contact.php"
                    >
                        Contact
                    </a>
                </li>

                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">

                    <a
                        class="nav-link login-btn"
                        href="auth/login.php"
                    >
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Login
                    </a>

                </li>

            </ul>

        </div>

    </div>

</nav>


<!-- =========================================================
     HERO
========================================================= -->

<section class="announcement-hero">

    <div class="container text-center hero-content">

        <div class="hero-badge">

            <i class="bi bi-megaphone-fill"></i>

            Stay Updated

        </div>

        <h1 class="hero-title">
            School Announcements
        </h1>

        <p class="hero-description">

            Stay informed about important school news,
            events, notices, activities, and updates
            from Bole Kale Hiwot School.

        </p>

        <div class="hero-count">

            <i class="bi bi-bell-fill"></i>

            <?= number_format(
                count($announcements)
            ) ?>

            public announcement<?= count($announcements) === 1 ? '' : 's' ?>

        </div>

    </div>

</section>


<!-- =========================================================
     SEARCH
========================================================= -->

<section class="search-section">

    <div class="container">

        <div class="search-card">

            <form
                method="GET"
                action="announcements.php"
                class="search-form"
            >

                <div class="search-wrapper">

                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="search"
                        class="search-input"
                        placeholder="Search announcements..."
                        value="<?= e($search) ?>"
                    >

                </div>

                <button
                    type="submit"
                    class="search-submit"
                >

                    <i class="bi bi-search me-1"></i>

                    Search

                </button>

                <?php if ($search !== ''): ?>

                    <a
                        href="announcements.php"
                        class="clear-search"
                    >

                        <i class="bi bi-x-circle me-1"></i>

                        Clear

                    </a>

                <?php endif; ?>

            </form>

        </div>

    </div>

</section>


<!-- =========================================================
     ANNOUNCEMENTS
========================================================= -->

<section class="announcements-section">

    <div class="container">

        <div class="section-heading">

            <h2>
                Latest Updates
            </h2>

            <p>
                Important information published by the school.
            </p>

        </div>


        <?php if (empty($announcements)): ?>

            <div class="empty-state">

                <div class="empty-icon">

                    <i class="bi bi-megaphone"></i>

                </div>

                <h3>
                    No announcements available
                </h3>

                <p>

                    <?php if ($search !== ''): ?>

                        No public announcement matched
                        your search.

                    <?php else: ?>

                        There are currently no public
                        announcements from the school.

                    <?php endif; ?>

                </p>

            </div>

        <?php else: ?>


            <!-- =================================================
                 TWO-COLUMN ANNOUNCEMENT GRID
            ================================================== -->

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
                        (string) (
                            $announcement['content']
                            ?? ''
                        );

                    $publishedDate =
                        formatEthiopianDate(
                            $announcement['published_at']
                        );

                    $media =
                        $mediaByAnnouncement[
                            $announcementId
                        ] ?? [];

                    $blocks =
                        $blocksByAnnouncement[
                            $announcementId
                        ] ?? [];

                    $images = [];

                    $attachments = [];

                    foreach (
                        $media
                        as $mediaItem
                    ) {

                        if (
                            strtolower(
                                (string) $mediaItem['media_type']
                            ) === 'image'
                        ) {

                            $images[] =
                                $mediaItem;

                        } else {

                            $attachments[] =
                                $mediaItem;
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Track images rendered by Image blocks
                    |--------------------------------------------------------------------------
                    */

                    $usedImageIds = [];

                    ?>

                    <article
                        class="announcement-card"
                        id="announcement-<?= $announcementId ?>"
                    >

                        <!-- =============================================
                             HEADER
                        ============================================== -->

                        <div class="announcement-header">

                            <div class="announcement-meta">

                                <span class="meta-badge">

                                    <i class="bi bi-megaphone-fill"></i>

                                    Announcement

                                </span>

                                <span class="meta-date">

                                    <i class="bi bi-calendar3"></i>

                                    <?= e(
                                        $publishedDate
                                    ) ?>

                                </span>

                            </div>

                            <h2 class="announcement-title">

                                <?= e(
                                    $title
                                ) ?>

                            </h2>

                        </div>


                        <!-- =============================================
                             BODY
                        ============================================== -->

                        <div class="announcement-body">

                            <?php if (!empty($blocks)): ?>

                                <?php foreach (
                                    $blocks
                                    as $block
                                ): ?>

                                    <?php

                                    $blockType =
                                        (string)
                                        $block['block_type'];

                                    $blockContent =
                                        (string) (
                                            $block['content']
                                            ?? ''
                                        );

                                    $mediaId =
                                        !empty(
                                            $block['media_id']
                                        )
                                            ? (int)
                                                $block['media_id']
                                            : 0;

                                    ?>


                                    <?php if (
                                        $blockType === 'Text'
                                    ): ?>

                                        <?php if (
                                            trim($blockContent) !== ''
                                        ): ?>

                                            <div class="announcement-text mb-3">

                                                <?= nl2br(
                                                    e($blockContent)
                                                ) ?>

                                            </div>

                                        <?php endif; ?>


                                    <?php elseif (
                                        $blockType === 'Heading'
                                    ): ?>

                                        <?php if (
                                            trim($blockContent) !== ''
                                        ): ?>

                                            <h3 class="content-heading">

                                                <?= e(
                                                    $blockContent
                                                ) ?>

                                            </h3>

                                        <?php endif; ?>


                                    <?php elseif (
                                        $blockType === 'Quote'
                                    ): ?>

                                        <?php if (
                                            trim($blockContent) !== ''
                                        ): ?>

                                            <blockquote class="content-quote">

                                                <?= nl2br(
                                                    e($blockContent)
                                                ) ?>

                                            </blockquote>

                                        <?php endif; ?>


                                    <?php elseif (
                                        $blockType === 'Image'
                                    ): ?>

                                        <?php

                                        $blockMedia =
                                            $mediaLookup[
                                                $mediaId
                                            ] ?? null;

                                        ?>

                                        <?php if (
                                            $blockMedia &&
                                            strtolower(
                                                (string)
                                                $blockMedia['media_type']
                                            ) === 'image'
                                        ): ?>

                                            <?php

                                            /*
                                            | Mark as used.
                                            | It will NOT be rendered again
                                            | in the remaining image gallery.
                                            */

                                            $usedImageIds[] =
                                                (int)
                                                $blockMedia['id'];

                                            $imageUrl =
                                                mediaUrlByType(
                                                    (string)
                                                    $blockMedia['file_path'],
                                                    'Image'
                                                );

                                            $imageName =
                                                (string) (
                                                    $blockMedia[
                                                        'original_name'
                                                    ]
                                                    ?: basename(
                                                        (string)
                                                        $blockMedia[
                                                            'file_path'
                                                        ]
                                                    )
                                                );

                                            ?>

                                            <?php if (
                                                $imageUrl !== ''
                                            ): ?>

                                                <div
                                                    class="content-block-image"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#imageLightbox"
                                                    data-image="<?= e(
                                                        $imageUrl
                                                    ) ?>"
                                                    data-title="<?= e(
                                                        $imageName
                                                    ) ?>"
                                                    style="cursor:pointer;"
                                                >

                                                    <img
                                                        src="<?= e(
                                                            $imageUrl
                                                        ) ?>"
                                                        alt="<?= e(
                                                            $imageName
                                                        ) ?>"
                                                        loading="lazy"
                                                        onerror="this.closest('.content-block-image').style.display='none';"
                                                    >

                                                </div>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                <?php endforeach; ?>


                            <?php elseif (
                                trim($content) !== ''
                            ): ?>

                                <div class="announcement-text">

                                    <?= nl2br(
                                        e($content)
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        </div>


                        <?php

                        /*
                        |--------------------------------------------------------------------------
                        | Remaining Images
                        |--------------------------------------------------------------------------
                        |
                        | Images already used by Image blocks are removed.
                        |--------------------------------------------------------------------------
                        */

                        $remainingImages = [];

                        foreach (
                            $images
                            as $image
                        ) {

                            $imageId =
                                (int) $image['id'];

                            if (
                                !in_array(
                                    $imageId,
                                    $usedImageIds,
                                    true
                                )
                            ) {

                                $remainingImages[] =
                                    $image;
                            }
                        }

                        $hasRemainingMedia =
                            !empty($remainingImages) ||
                            !empty($attachments);

                        ?>


                        <?php if (
                            $hasRemainingMedia
                        ): ?>

                            <!-- =============================================
                                 MEDIA
                            ============================================== -->

                            <div class="announcement-media">


                                <!-- =============================================
                                     REMAINING IMAGES
                                ============================================== -->

                                <?php if (
                                    !empty($remainingImages)
                                ): ?>

                                    <div class="media-title">

                                        <i class="bi bi-images"></i>

                                        Photos

                                    </div>

                                    <div class="announcement-images">

                                        <?php foreach (
                                            $remainingImages
                                            as $image
                                        ): ?>

                                            <?php

                                            $imageUrl =
                                                mediaUrlByType(
                                                    (string)
                                                    $image['file_path'],
                                                    'Image'
                                                );

                                            $imageName =
                                                (string) (
                                                    $image[
                                                        'original_name'
                                                    ]
                                                    ?: basename(
                                                        (string)
                                                        $image[
                                                            'file_path'
                                                        ]
                                                    )
                                                );

                                            ?>

                                            <?php if (
                                                $imageUrl !== ''
                                            ): ?>

                                                <div
                                                    class="announcement-image"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#imageLightbox"
                                                    data-image="<?= e(
                                                        $imageUrl
                                                    ) ?>"
                                                    data-title="<?= e(
                                                        $imageName
                                                    ) ?>"
                                                >

                                                    <img
                                                        src="<?= e(
                                                            $imageUrl
                                                        ) ?>"
                                                        alt="<?= e(
                                                            $imageName
                                                        ) ?>"
                                                        loading="lazy"
                                                        onerror="this.closest('.announcement-image').style.display='none';"
                                                    >

                                                    <div class="image-overlay">

                                                        <div>

                                                            <i class="bi bi-arrows-fullscreen"></i>

                                                        </div>

                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>


                                <!-- =============================================
                                     ATTACHMENTS
                                ============================================== -->

                                <?php if (
                                    !empty($attachments)
                                ): ?>

                                    <div
                                        class="media-title <?= !empty($remainingImages) ? 'mt-4' : '' ?>"
                                    >

                                        <i class="bi bi-paperclip"></i>

                                        Attachments

                                    </div>


                                    <div class="attachment-list">

                                        <?php foreach (
                                            $attachments
                                            as $attachment
                                        ): ?>

                                            <?php

                                            $fileUrl =
                                                mediaUrlByType(
                                                    (string)
                                                    $attachment[
                                                        'file_path'
                                                    ],
                                                    'Attachment'
                                                );

                                            $originalName =
                                                (string) (
                                                    $attachment[
                                                        'original_name'
                                                    ]
                                                    ?: basename(
                                                        (string)
                                                        $attachment[
                                                            'file_path'
                                                        ]
                                                    )
                                                );

                                            $mimeType =
                                                (string) (
                                                    $attachment[
                                                        'mime_type'
                                                    ] ?? ''
                                                );

                                            $fileSize =
                                                formatFileSize(
                                                    (int) (
                                                        $attachment[
                                                            'file_size'
                                                        ] ?? 0
                                                    )
                                                );

                                            $extension =
                                                getFileExtension(
                                                    $originalName
                                                );

                                            $previewType =
                                                attachmentPreviewType(
                                                    $originalName,
                                                    $mimeType
                                                );

                                            ?>

                                            <?php if (
                                                $fileUrl !== ''
                                            ): ?>

                                                <!--
                                                The entire attachment is clickable.
                                                No Open button.
                                                No Download button on the card.
                                                -->

                                                <div
                                                    class="attachment-item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#attachmentPreview"
                                                    data-file-url="<?= e(
                                                        $fileUrl
                                                    ) ?>"
                                                    data-file-name="<?= e(
                                                        $originalName
                                                    ) ?>"
                                                    data-preview-type="<?= e(
                                                        $previewType
                                                    ) ?>"
                                                    data-mime-type="<?= e(
                                                        $mimeType
                                                    ) ?>"
                                                >

                                                    <div class="attachment-header">

                                                        <div class="attachment-icon">

                                                            <i
                                                                class="bi <?= e(
                                                                    attachmentIcon(
                                                                        $originalName,
                                                                        $mimeType
                                                                    )
                                                                ) ?>"
                                                            ></i>

                                                        </div>


                                                        <div class="attachment-info">

                                                            <div class="attachment-name">

                                                                <?= e(
                                                                    $originalName
                                                                ) ?>

                                                            </div>

                                                            <div class="attachment-size">

                                                                <?= e(
                                                                    strtoupper(
                                                                        $extension
                                                                    )
                                                                ) ?>

                                                                <?php if (
                                                                    $fileSize !== ''
                                                                ): ?>

                                                                    •
                                                                    <?= e(
                                                                        $fileSize
                                                                    ) ?>

                                                                <?php endif; ?>

                                                            </div>

                                                        </div>


                                                        <div class="attachment-preview-hint">

                                                            <i class="bi bi-eye"></i>

                                                            <span>
                                                                Preview
                                                            </span>

                                                        </div>

                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</section>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="site-footer">

    <div class="container">

        <div class="row g-4">

            <div class="col-lg-5">

                <a
                    href="index.php"
                    class="footer-brand"
                >

                    <img
                        src="public/image/logo.webp"
                        alt="BKHS"
                    >

                    Bole Kale Hiwot School

                </a>

                <p class="footer-description">

                    Bole Kale Hiwot School is committed
                    to providing quality education and
                    creating a supportive environment
                    where students can learn, grow,
                    and achieve their potential.

                </p>

            </div>


            <div class="col-6 col-lg-2">

                <div class="footer-title">
                    Quick Links
                </div>

                <ul class="footer-links">

                    <li>
                        <a href="index.php">
                            Home
                        </a>
                    </li>

                    <li>
                        <a href="about.php">
                            About
                        </a>
                    </li>

                    <li>
                        <a href="admission.php">
                            Admission
                        </a>
                    </li>

                    <li>
                        <a href="gallery.php">
                            Gallery
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-6 col-lg-2">

                <div class="footer-title">
                    Information
                </div>

                <ul class="footer-links">

                    <li>
                        <a href="announcements.php">
                            Announcements
                        </a>
                    </li>

                    <li>
                        <a href="contact.php">
                            Contact
                        </a>
                    </li>

                    <li>
                        <a href="auth/login.php">
                            Login
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-lg-3">

                <div class="footer-title">
                    Connect With Us
                </div>

                <ul class="footer-links">

                    <li>
                        <a href="contact.php">
                            <i class="bi bi-geo-alt me-2"></i>
                            Addis Ababa, Ethiopia
                        </a>
                    </li>

                    <li>
                        <a href="contact.php">
                            <i class="bi bi-envelope me-2"></i>
                            Contact School
                        </a>
                    </li>

                </ul>

            </div>

        </div>


        <div class="footer-bottom text-center">

            © <?= date('Y') ?>
            Bole Kale Hiwot School.
            All rights reserved.

        </div>

    </div>

</footer>


<!-- =========================================================
     IMAGE LIGHTBOX
========================================================= -->

<div
    class="modal fade lightbox-modal"
    id="imageLightbox"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-xl">

        <div class="modal-content">

            <div class="modal-body">

                <button
                    type="button"
                    class="lightbox-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                >

                    <i class="bi bi-x-lg"></i>

                </button>

                <img
                    id="lightboxImage"
                    class="lightbox-image"
                    src=""
                    alt=""
                >

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     ATTACHMENT PREVIEW MODAL
========================================================= -->

<div
    class="modal fade preview-modal"
    id="attachmentPreview"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="preview-modal-header">

                <div
                    class="preview-modal-title"
                    id="previewTitle"
                >
                    Attachment Preview
                </div>

                <button
                    type="button"
                    class="preview-modal-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                >

                    <i class="bi bi-x-lg"></i>

                </button>

            </div>


            <div
                class="preview-content"
                id="previewContent"
            >
                <!-- JavaScript inserts preview here -->
            </div>


            <div class="preview-modal-footer">

                <a
                    href="#"
                    id="previewDownload"
                    class="preview-download-btn"
                    download
                >

                    <i class="bi bi-download"></i>

                    Download

                </a>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| Image Lightbox
|--------------------------------------------------------------------------
*/

const imageLightbox =
    document.getElementById(
        'imageLightbox'
    );

const lightboxImage =
    document.getElementById(
        'lightboxImage'
    );

if (imageLightbox) {

    imageLightbox.addEventListener(
        'show.bs.modal',
        function (event) {

            const trigger =
                event.relatedTarget;

            if (!trigger) {
                return;
            }

            const image =
                trigger.getAttribute(
                    'data-image'
                );

            const title =
                trigger.getAttribute(
                    'data-title'
                ) || 'Announcement image';

            if (lightboxImage) {

                lightboxImage.src =
                    image || '';

                lightboxImage.alt =
                    title;
            }

        }
    );


    imageLightbox.addEventListener(
        'hidden.bs.modal',
        function () {

            if (lightboxImage) {

                lightboxImage.src =
                    '';

                lightboxImage.alt =
                    '';
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Attachment Preview
|--------------------------------------------------------------------------
*/

const attachmentPreview =
    document.getElementById(
        'attachmentPreview'
    );

const previewTitle =
    document.getElementById(
        'previewTitle'
    );

const previewContent =
    document.getElementById(
        'previewContent'
    );

const previewDownload =
    document.getElementById(
        'previewDownload'
    );


if (attachmentPreview) {

    attachmentPreview.addEventListener(
        'show.bs.modal',
        function (event) {

            const trigger =
                event.relatedTarget;

            if (!trigger) {
                return;
            }

            const fileUrl =
                trigger.getAttribute(
                    'data-file-url'
                ) || '';

            const fileName =
                trigger.getAttribute(
                    'data-file-name'
                ) || 'Attachment';

            const previewType =
                trigger.getAttribute(
                    'data-preview-type'
                ) || 'file';

            const mimeType =
                trigger.getAttribute(
                    'data-mime-type'
                ) || '';

            if (previewTitle) {

                previewTitle.textContent =
                    fileName;
            }


            if (previewDownload) {

                previewDownload.href =
                    fileUrl;

                previewDownload.setAttribute(
                    'download',
                    fileName
                );
            }


            if (!previewContent) {
                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Clear previous preview
            |--------------------------------------------------------------------------
            */

            previewContent.innerHTML =
                '';


            /*
            |--------------------------------------------------------------------------
            | PDF
            |--------------------------------------------------------------------------
            */

            if (
                previewType === 'pdf'
            ) {

                const iframe =
                    document.createElement(
                        'iframe'
                    );

                iframe.src =
                    fileUrl +
                    '#toolbar=1&navpanes=0&scrollbar=1';

                iframe.title =
                    fileName;

                iframe.loading =
                    'eager';

                previewContent.appendChild(
                    iframe
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Image
            |--------------------------------------------------------------------------
            */

            if (
                previewType === 'image'
            ) {

                const image =
                    document.createElement(
                        'img'
                    );

                image.src =
                    fileUrl;

                image.alt =
                    fileName;

                image.className =
                    'preview-image';

                previewContent.appendChild(
                    image
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Text / CSV
            |--------------------------------------------------------------------------
            */

            if (
                previewType === 'text'
            ) {

                const iframe =
                    document.createElement(
                        'iframe'
                    );

                iframe.src =
                    fileUrl;

                iframe.title =
                    fileName;

                iframe.loading =
                    'eager';

                previewContent.appendChild(
                    iframe
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Office Documents
            |--------------------------------------------------------------------------
            |
            | Browser cannot reliably display DOC/DOCX/XLS/XLSX/PPT/PPTX
            | directly.
            |
            | We therefore show a clean preview message rather than
            | showing an Open button on the announcement card.
            |
            */

            if (
                previewType === 'office'
            ) {

                const wrapper =
                    document.createElement(
                        'div'
                    );

                wrapper.className =
                    'preview-office-message';

                wrapper.innerHTML = `
                    <div class="preview-office-icon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>

                    <h4>
                        ${escapeHtml(fileName)}
                    </h4>

                    <p>
                        This document format cannot be displayed
                        directly by the browser. You can use the
                        Download button below to view it with the
                        appropriate application.
                    </p>
                `;

                previewContent.appendChild(
                    wrapper
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Other Files
            |--------------------------------------------------------------------------
            */

            const wrapper =
                document.createElement(
                    'div'
                );

            wrapper.className =
                'preview-office-message';

            wrapper.innerHTML = `
                <div class="preview-office-icon">
                    <i class="bi bi-paperclip"></i>
                </div>

                <h4>
                    ${escapeHtml(fileName)}
                </h4>

                <p>
                    This file type cannot be previewed
                    directly in the browser.
                    Use the Download button below to
                    access the file.
                </p>
            `;

            previewContent.appendChild(
                wrapper
            );

        }
    );


    attachmentPreview.addEventListener(
        'hidden.bs.modal',
        function () {

            if (previewContent) {

                previewContent.innerHTML =
                    '';
            }

            if (previewTitle) {

                previewTitle.textContent =
                    'Attachment Preview';
            }

            if (previewDownload) {

                previewDownload.href =
                    '#';
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Small HTML Escape Helper
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

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

</script>

</body>

</html>

