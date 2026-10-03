<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'parent'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$parentUserId = (int) $_SESSION['user_id'];

$parentName = 'Parent';
$parentPhoto = '';
$parentPhotoUrl = '';

$student = null;
$announcements = [];

$errorMessage = '';
$academicYearName = '';

date_default_timezone_set('Africa/Addis_Ababa');


/*
 * -------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------
 */

function h(?string $value): string
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
        $dateOnly = substr(
            trim($date),
            0,
            10
        );

        $ethiopian = EthiopianCalendar::fromGregorian(
            $dateOnly
        );

        return (string) $ethiopian['formatted'];
    } catch (Throwable $e) {
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

function getFileIcon(string $fileName): string
{
    $extension = strtolower(
        pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );

    return match ($extension) {
        'pdf' => '📄',
        'doc', 'docx' => '📝',
        'xls', 'xlsx' => '📊',
        'ppt', 'pptx' => '📑',
        'zip', 'rar' => '🗜️',
        default => '📎',
    };
}


/*
 * -------------------------------------------------------------
 * Selected student
 * -------------------------------------------------------------
 */

$studentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);


/*
 * -------------------------------------------------------------
 * Load parent + active academic year
 * -------------------------------------------------------------
 */

try {

    /*
     * ---------------------------------------------------------
     * Parent information
     * ---------------------------------------------------------
     */

    $parentStmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.phone,
            u.email,
            p.photo
        FROM users AS u

        INNER JOIN parents AS p
            ON p.user_id = u.id

        WHERE u.id = ?
          AND LOWER(u.role) = 'parent'
          AND u.is_deleted = 0

        LIMIT 1
    ");

    if (!$parentStmt) {
        throw new RuntimeException(
            'Failed to prepare parent query.'
        );
    }

    $parentStmt->bind_param(
        'i',
        $parentUserId
    );

    $parentStmt->execute();

    $parentResult =
        $parentStmt->get_result();

    $parent =
        $parentResult->fetch_assoc();

    $parentStmt->close();

    if (!$parent) {

        session_unset();
        session_destroy();

        header(
            'Location: ../auth/login.php'
        );

        exit;
    }

    $parentName = trim(
        (string) (
            $parent['full_name']
            ?? 'Parent'
        )
    );

    if ($parentName === '') {
        $parentName = 'Parent';
    }


    /*
     * ---------------------------------------------------------
     * Parent photo
     * ---------------------------------------------------------
     */

    $parentPhoto = trim(
        (string) (
            $parent['photo']
            ?? ''
        )
    );

    if ($parentPhoto !== '') {

        $parentPhotoUrl =
            '../' .
            ltrim(
                $parentPhoto,
                '/'
            );
    }


    /*
     * ---------------------------------------------------------
     * Active academic year
     * ---------------------------------------------------------
     */

    $academicYearStmt = $conn->prepare("
        SELECT
            id,
            name

        FROM academic_years

        WHERE status = 'Active'

        ORDER BY id DESC

        LIMIT 1
    ");

    if (!$academicYearStmt) {
        throw new RuntimeException(
            'Failed to prepare academic year query.'
        );
    }

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    $activeAcademicYear =
        $academicYearResult->fetch_assoc();

    $academicYearStmt->close();

    if (!$activeAcademicYear) {
        throw new RuntimeException(
            'No active academic year was found.'
        );
    }

    $academicYearId =
        (int) $activeAcademicYear['id'];

    $academicYearName =
        (string) $activeAcademicYear['name'];


    /*
     * ---------------------------------------------------------
     * Selected child
     * ---------------------------------------------------------
     *
     * If no student_id is supplied, use the first child.
     */

    if (!$studentId) {

        $firstChildStmt = $conn->prepare("
            SELECT
                s.id AS student_id

            FROM parents AS p

            INNER JOIN student_parents AS sp
                ON sp.parent_id = p.id

            INNER JOIN students AS s
                ON s.id = sp.student_id

            INNER JOIN student_registrations AS sr
                ON sr.student_id = s.id
               AND sr.academic_year_id = ?

            INNER JOIN grades AS g
                ON g.id = sr.grade_id

            INNER JOIN sections AS sec
                ON sec.id = sr.section_id

            INNER JOIN users AS u
                ON u.id = s.user_id

            WHERE p.user_id = ?
              AND sp.is_account_access = 1
              AND s.is_deleted = 0
              AND u.is_deleted = 0
              AND LOWER(u.role) = 'student'

            ORDER BY
                g.grade_number ASC,
                sec.code ASC,
                s.full_name ASC

            LIMIT 1
        ");

        if (!$firstChildStmt) {
            throw new RuntimeException(
                'Failed to prepare child query.'
            );
        }

        $firstChildStmt->bind_param(
            'ii',
            $academicYearId,
            $parentUserId
        );

        $firstChildStmt->execute();

        $firstChildResult =
            $firstChildStmt->get_result();

        $firstChild =
            $firstChildResult->fetch_assoc();

        $firstChildStmt->close();

        if ($firstChild) {

            $studentId =
                (int) $firstChild['student_id'];

        } else {

            throw new RuntimeException(
                'No child was found.'
            );
        }
    }


    /*
     * ---------------------------------------------------------
     * Verify and load selected child
     * ---------------------------------------------------------
     */

    $studentStmt = $conn->prepare("
        SELECT
            s.id AS student_id,
            s.student_code,
            s.full_name,
            sp.relationship,

            sr.id AS registration_id,

            g.grade_number,
            sec.code AS section

        FROM parents AS p

        INNER JOIN student_parents AS sp
            ON sp.parent_id = p.id

        INNER JOIN students AS s
            ON s.id = sp.student_id

        INNER JOIN student_registrations AS sr
            ON sr.student_id = s.id
           AND sr.academic_year_id = ?

        INNER JOIN grades AS g
            ON g.id = sr.grade_id

        INNER JOIN sections AS sec
            ON sec.id = sr.section_id

        INNER JOIN users AS u
            ON u.id = s.user_id

        WHERE p.user_id = ?
          AND sp.student_id = ?
          AND sp.is_account_access = 1
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'

        LIMIT 1
    ");

    if (!$studentStmt) {
        throw new RuntimeException(
            'Failed to prepare selected child query.'
        );
    }

    $studentStmt->bind_param(
        'iii',
        $academicYearId,
        $parentUserId,
        $studentId
    );

    $studentStmt->execute();

    $studentResult =
        $studentStmt->get_result();

    $student =
        $studentResult->fetch_assoc();

    $studentStmt->close();

    if (!$student) {
        throw new RuntimeException(
            'The selected child could not be found.'
        );
    }

    $gradeNumber =
        (int) $student['grade_number'];


    /*
     * ---------------------------------------------------------
     * Automatically close expired announcements
     * ---------------------------------------------------------
     */

    $conn->query("
        UPDATE announcements
        SET status = 'Closed'
        WHERE status = 'Published'
          AND closed_at IS NOT NULL
          AND closed_at < CURDATE()
    ");


    /*
     * ---------------------------------------------------------
     * Get relevant announcements
     * ---------------------------------------------------------
     */

    $announcementStmt = $conn->prepare("
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

          AND (
                a.closed_at IS NULL
                OR a.closed_at >= CURDATE()
              )

          AND (
                aa.audience_type = 'Public'

                OR (
                    aa.audience_type = 'Parent'

                    AND (
                        aa.grade IS NULL
                        OR aa.grade = ?
                    )
                )
              )

        ORDER BY
            a.published_at DESC,
            a.created_at DESC,
            a.id DESC
    ");

    if (!$announcementStmt) {
        throw new RuntimeException(
            'Failed to prepare announcement query.'
        );
    }

    $announcementStmt->bind_param(
        'i',
        $gradeNumber
    );

    $announcementStmt->execute();

    $announcementResult =
        $announcementStmt->get_result();

    while (
        $row =
        $announcementResult->fetch_assoc()
    ) {

        $announcements[] = $row;
    }

    $announcementStmt->close();


    /*
     * ---------------------------------------------------------
     * Get media
     * ---------------------------------------------------------
     */

    $mediaByAnnouncement = [];

    if (!empty($announcements)) {

        $announcementIds = [];

        foreach (
            $announcements
            as $announcement
        ) {

            $announcementIds[] =
                (int) $announcement['id'];
        }

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($announcementIds),
                '?'
            )
        );

        $types = str_repeat(
            'i',
            count($announcementIds)
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
                display_order ASC,
                id ASC
        ";

        $mediaStmt =
            $conn->prepare($mediaSql);

        if (!$mediaStmt) {
            throw new RuntimeException(
                'Failed to prepare media query.'
            );
        }

        $bindParams = [
            $types
        ];

        foreach (
            $announcementIds
            as $key => &$value
        ) {

            $bindParams[] = &$value;
        }

        unset($value);

        call_user_func_array(
            [
                $mediaStmt,
                'bind_param'
            ],
            $bindParams
        );

        $mediaStmt->execute();

        $mediaResult =
            $mediaStmt->get_result();

        while (
            $media =
            $mediaResult->fetch_assoc()
        ) {

            $announcementId =
                (int) $media['announcement_id'];

            if (
                !isset(
                    $mediaByAnnouncement[
                        $announcementId
                    ]
                )
            ) {

                $mediaByAnnouncement[
                    $announcementId
                ] = [
                    'images' => [],
                    'attachments' => []
                ];
            }

            if (
                $media['media_type']
                === 'Image'
            ) {

                $mediaByAnnouncement[
                    $announcementId
                ]['images'][] = $media;

            } elseif (
                $media['media_type']
                === 'Attachment'
            ) {

                $mediaByAnnouncement[
                    $announcementId
                ]['attachments'][] = $media;
            }
        }

        $mediaStmt->close();
    }

} catch (Throwable $e) {

    error_log(
        'Parent announcements error: ' .
        $e->getMessage()
    );

    $errorMessage =
        'Unable to load announcements right now.';
}


/*
 * -------------------------------------------------------------
 * Today
 * -------------------------------------------------------------
 */

$todayEthiopian =
    formatEthiopianDate(
        date('Y-m-d')
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
        Announcements - BKHS Parent Portal
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {

            --primary: #2563eb;
            --primary-dark: #1d4ed8;

            --background: #f5f7fb;
            --white: #ffffff;

            --text: #111827;
            --text-secondary: #6b7280;

            --border: #e5e7eb;

            --sidebar-width: 250px;
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                var(--background);

            color:
                var(--text);

            min-height: 100vh;
        }

        .app {
            min-height: 100vh;
        }


        /*
         * ------------------------------------------------------
         * Sidebar
         * ------------------------------------------------------
         */

        .sidebar {

            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            width:
                var(--sidebar-width);

            background:
                var(--white);

            border-right:
                1px solid var(--border);

            z-index: 1000;
        }

        .main {

            margin-left:
                var(--sidebar-width);

            min-height: 100vh;
        }

        .sidebar-header {

            height: 72px;

            padding: 0 20px;

            display: flex;
            align-items: center;

            border-bottom:
                1px solid var(--border);
        }

        .brand {

            display: flex;
            align-items: center;

            gap: 11px;

            text-decoration: none;

            color:
                var(--text);
        }

        .brand-logo {

            width: 38px;
            height: 38px;

            border-radius: 9px;

            background:
                var(--primary);

            color:
                #ffffff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 15px;
            font-weight: 800;
        }

        .brand-text {

            display: flex;
            flex-direction: column;
        }

        .brand-name {

            font-size: 16px;
            font-weight: 800;
        }

        .brand-role {

            font-size: 11px;

            color:
                var(--text-secondary);

            margin-top: 2px;
        }

        .nav {

            padding: 18px 12px;
        }

        .nav-label {

            padding:
                0 10px 9px;

            font-size: 11px;
            font-weight: 700;

            color: #9ca3af;

            text-transform:
                uppercase;

            letter-spacing:
                0.05em;
        }

        .nav-item {

            display: flex;
            align-items: center;

            gap: 12px;

            width: 100%;

            padding:
                11px 12px;

            margin-bottom: 4px;

            border-radius: 8px;

            color: #4b5563;

            text-decoration: none;

            font-size: 14px;
            font-weight: 600;

            transition:
                0.2s ease;
        }

        .nav-item:hover {

            background:
                #f3f4f6;

            color:
                var(--text);
        }

        .nav-item.active {

            background:
                #eff6ff;

            color:
                var(--primary);
        }

        .nav-icon {

            width: 20px;

            text-align: center;

            font-size: 17px;
        }

        .logout {

            position: absolute;

            left: 12px;
            right: 12px;
            bottom: 18px;

            color: #dc2626;
        }

        .logout:hover {

            background:
                #fef2f2;

            color:
                #b91c1c;
        }


        /*
         * ------------------------------------------------------
         * Topbar
         * ------------------------------------------------------
         */

        .topbar {

            height: 72px;

            padding: 0 28px;

            background:
                var(--white);

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            gap: 20px;
        }

        .topbar-left {

            display: flex;

            align-items: center;

            gap: 12px;
        }

        .menu-button {

            display: none;

            border: none;

            background:
                transparent;

            font-size: 24px;

            cursor: pointer;

            color:
                var(--text);
        }

        .topbar-title {

            font-size: 18px;

            font-weight: 700;
        }

        .parent-info {

            display: flex;

            align-items: center;

            gap: 10px;
        }

        .parent-avatar {

            width: 38px;
            height: 38px;

            border-radius: 50%;

            background:
                #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 14px;
            font-weight: 800;

            overflow: hidden;
        }

        .parent-avatar img {

            width: 100%;
            height: 100%;

            object-fit: cover;

            display: block;
        }

        .parent-name {

            font-size: 14px;

            font-weight: 600;
        }


        /*
         * ------------------------------------------------------
         * Mobile dashboard button
         * ------------------------------------------------------
         */

        .mobile-dashboard {

            display: none;

            align-items: center;

            justify-content: center;

            gap: 5px;

            padding: 6px 9px;

            border-radius: 7px;

            background:
                #eff6ff;

            color:
                var(--primary);

            text-decoration: none;

            font-size: 10px;

            font-weight: 700;
        }

        .mobile-dashboard-icon {

            font-size: 15px;

            line-height: 1;
        }


        /*
         * ------------------------------------------------------
         * Content
         * ------------------------------------------------------
         */

        .content {

            width:
                min(
                    calc(100% - 48px),
                    1000px
                );

            margin: 0 auto;

            padding:
                25px 0 45px;
        }


        /*
         * ------------------------------------------------------
         * Child header
         * ------------------------------------------------------
         */

        .child-header {

            background:
                var(--white);

            border:
                1px solid var(--border);

            border-radius: 10px;

            padding:
                11px 14px;

            margin-bottom: 18px;

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            gap: 12px;
        }

        .child-info {

            display: flex;

            align-items: center;

            gap: 10px;
        }

        .child-avatar {

            width: 40px;
            height: 40px;

            flex-shrink: 0;

            border-radius: 50%;

            background:
                #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 15px;

            font-weight: 800;
        }

        .child-name {

            font-size: 14px;

            font-weight: 700;

            margin-bottom: 2px;
        }

        .child-meta {

            font-size: 11px;

            color:
                var(--text-secondary);
        }

        .child-year {

            font-size: 11px;

            color:
                var(--text-secondary);

            white-space: nowrap;
        }


        /*
         * ------------------------------------------------------
         * Page heading
         * ------------------------------------------------------
         */

        .section-header {

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            margin-bottom: 12px;
        }

        .section-title {

            font-size: 18px;

            font-weight: 700;
        }

        .section-subtitle {

            color:
                var(--text-secondary);

            font-size: 11px;

            margin-top: 3px;
        }


        /*
         * ------------------------------------------------------
         * Announcement cards
         * ------------------------------------------------------
         */

        .announcements {

            display: flex;

            flex-direction: column;

            gap: 10px;

            max-width: 900px;
        }

        .announcement-card {

            background:
                var(--white);

            border:
                1px solid var(--border);

            border-radius: 9px;

            padding:
                12px 14px;

            box-shadow:
                0 1px 5px
                rgba(0, 0, 0, 0.025);

            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .announcement-card:hover {

            transform:
                translateY(-1px);

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.06);
        }

        .announcement-title {

            color:
                var(--primary);

            font-size: 15px;

            line-height: 1.35;

            font-weight: 700;

            margin-bottom: 4px;
        }

        .announcement-title:hover {

            color:
                var(--primary-dark);
        }

        .announcement-dates {

            display: flex;

            flex-wrap: wrap;

            gap: 8px;

            margin-bottom: 5px;
        }

        .date-item {

            display: inline-flex;

            align-items: center;

            gap: 4px;

            color:
                var(--text-secondary);

            font-size: 10px;
        }

        .date-item strong {

            color:
                #4b5563;

            font-weight: 600;
        }

        .announcement-content {

            color:
                #4b5563;

            font-size: 12px;

            line-height: 1.55;

            overflow-wrap:
                anywhere;
        }

        .announcement-content p {

            margin:
                0 0 4px;
        }

        .announcement-content p:last-child {

            margin-bottom: 0;
        }


        /*
         * ------------------------------------------------------
         * Images
         * ------------------------------------------------------
         */

        .announcement-images {

            margin-top: 8px;

            display: flex;

            flex-direction: column;

            gap: 6px;
        }

        .announcement-image {

            display: block;

            width: 100%;

            max-height: 260px;

            object-fit: contain;

            object-position: center;

            background:
                #f8fafc;

            border:
                1px solid var(--border);

            border-radius: 7px;
        }


        /*
         * ------------------------------------------------------
         * Attachments
         * ------------------------------------------------------
         */

        .attachments {

            margin-top: 8px;

            display: flex;

            flex-direction: column;

            gap: 5px;
        }

        .attachment-heading {

            color:
                var(--text-secondary);

            font-size: 10px;

            font-weight: 700;

            text-transform:
                uppercase;

            letter-spacing:
                .04em;
        }

        .attachment-item {

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            gap: 8px;

            padding:
                6px 8px;

            background:
                #f9fafb;

            border:
                1px solid var(--border);

            border-radius: 6px;
        }

        .attachment-info {

            display: flex;

            align-items: center;

            gap: 7px;

            min-width: 0;
        }

        .attachment-icon {

            width: 25px;
            height: 25px;

            flex-shrink: 0;

            border-radius: 5px;

            background:
                #eff6ff;

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 12px;
        }

        .attachment-name {

            font-size: 11px;

            font-weight: 600;

            color:
                #374151;

            overflow: hidden;

            text-overflow:
                ellipsis;

            white-space:
                nowrap;
        }

        .attachment-size {

            color:
                var(--text-secondary);

            font-size: 9px;

            margin-top: 1px;
        }

        .open-attachment {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            padding:
                5px 8px;

            border-radius: 5px;

            background:
                var(--primary);

            color:
                #ffffff;

            text-decoration: none;

            font-size: 10px;

            font-weight: 600;

            flex-shrink: 0;
        }

        .open-attachment:hover {

            background:
                var(--primary-dark);

            color:
                #ffffff;
        }


        /*
         * ------------------------------------------------------
         * Empty
         * ------------------------------------------------------
         */

        .empty-card {

            background:
                var(--white);

            border:
                1px solid var(--border);

            border-radius: 9px;

            padding:
                32px 18px;

            text-align:
                center;
        }

        .empty-icon {

            font-size: 25px;

            margin-bottom: 8px;
        }

        .empty-card h3 {

            font-size: 15px;

            margin-bottom: 4px;
        }

        .empty-card p {

            font-size: 11px;

            color:
                var(--text-secondary);
        }


        /*
         * ------------------------------------------------------
         * Error
         * ------------------------------------------------------
         */

        .error-card {

            background:
                #fef2f2;

            border:
                1px solid #fecaca;

            color:
                #b91c1c;

            padding:
                12px 14px;

            border-radius: 8px;

            font-size: 13px;
        }


        /*
         * ------------------------------------------------------
         * Mobile bottom navigation
         * ------------------------------------------------------
         */

        .mobile-nav {

            display: none;
        }

        .more-menu {

            display: none;

            position: fixed;

            right: 10px;
            bottom: 76px;

            width: 145px;

            background:
                var(--white);

            border:
                1px solid var(--border);

            border-radius: 9px;

            box-shadow:
                0 8px 25px
                rgba(0, 0, 0, 0.14);

            padding: 6px;

            z-index: 1100;
        }

        .more-menu.show {
            display: block;
        }

        .more-menu-item {

            display: flex;

            align-items: center;

            gap: 9px;

            width: 100%;

            padding:
                9px 10px;

            border-radius: 7px;

            color:
                #374151;

            text-decoration: none;

            font-size: 11px;

            font-weight: 600;
        }

        .more-menu-item:hover {

            background:
                #f3f4f6;
        }

        .more-menu-item.logout-mobile {

            color:
                #dc2626;
        }


        /*
         * ------------------------------------------------------
         * Mobile
         * ------------------------------------------------------
         */

        @media (max-width: 800px) {

            /*
             * Desktop sidebar is hidden.
             */

            .sidebar {

                display: none;
            }

            .main {

                margin-left: 0;

                min-height: 100vh;

                padding-bottom: 68px;
            }

            /*
             * Dashboard moves to the top-right.
             */

            .mobile-dashboard {

                display: inline-flex;
            }

            /*
             * Parent desktop name remains hidden.
             */

            .parent-name {

                display: none;
            }

            /*
             * Mobile bottom navigation.
             */

            .mobile-nav {

                position: fixed;

                left: 0;
                right: 0;
                bottom: 0;

                height: 68px;

                background:
                    var(--white);

                border-top:
                    1px solid var(--border);

                z-index: 1000;

                display: flex;

                align-items: stretch;

                justify-content:
                    space-around;

                padding:
                    5px 5px;
            }

            .mobile-nav-item {

                flex: 1;

                min-width: 0;

                height: 58px;

                display: flex;

                flex-direction: column;

                align-items: center;

                justify-content: center;

                gap: 2px;

                padding:
                    4px 2px;

                border-radius: 7px;

                color:
                    #6b7280;

                text-decoration: none;

                font-size: 9px;

                font-weight: 600;

                text-align: center;
            }

            .mobile-nav-item:hover {

                background:
                    transparent;
            }

            .mobile-nav-item.active {

                background:
                    #eff6ff;

                color:
                    var(--primary);
            }

            .mobile-nav-icon {

                font-size: 18px;

                line-height: 20px;
            }

            .mobile-nav-label {

                white-space:
                    nowrap;
            }

            .mobile-more-button {

                border: none;

                background:
                    transparent;

                cursor: pointer;

                font-family:
                    inherit;
            }

            .topbar {

                padding:
                    0 16px;
            }

            .content {

                width:
                    min(
                        calc(100% - 28px),
                        1000px
                    );

                padding-top:
                    18px;

                padding-bottom:
                    25px;
            }

            .child-header {

                padding:
                    10px 11px;
            }

            .child-year {

                display: none;
            }

            /*
             * Hide desktop sidebar overlay and hamburger.
             */

            .menu-button {

                display: none;
            }

            .sidebar-overlay {

                display: none !important;
            }
        }


        @media (max-width: 550px) {

            .topbar {

                height: 65px;
            }

            .topbar-title {

                font-size: 16px;
            }

            .mobile-dashboard {

                font-size: 9px;

                padding:
                    5px 8px;
            }

            .mobile-dashboard-icon {

                font-size: 14px;
            }

            .content {

                width:
                    calc(100% - 20px);

                padding-bottom:
                    30px;
            }

            .section-title {

                font-size: 16px;
            }

            .section-subtitle {

                font-size: 10px;
            }

            .announcement-card {

                padding:
                    10px 11px;

                border-radius: 8px;
            }

            .announcement-title {

                font-size: 14px;
            }

            .announcement-content {

                font-size: 11px;

                line-height:
                    1.5;
            }

            .announcement-image {

                max-height: 190px;
            }

            .announcement-dates {

                gap: 6px;
            }

            .date-item {

                font-size: 9px;
            }

            .attachment-name {

                max-width: 150px;
            }

            .mobile-nav {

                height: 66px;
            }

            .mobile-nav-item {

                height: 56px;

                font-size: 8px;
            }

            .mobile-nav-icon {

                font-size: 17px;
            }

            .more-menu {

                bottom: 73px;
            }
        }

    </style>

</head>

<body>

<div class="app">


    <!-- =====================================================
         Desktop Sidebar
         ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="sidebar-header">

            <a
                href="dashboard.php"
                class="brand"
            >

                <div class="brand-logo">
                    BK
                </div>

                <div class="brand-text">

                    <span class="brand-name">
                        BKHS
                    </span>

                    <span class="brand-role">
                        Parent Portal
                    </span>

                </div>

            </a>

        </div>


        <nav class="nav">

            <div class="nav-label">
                Menu
            </div>


            <a
                href="dashboard.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    🏠
                </span>

                <span>
                    Dashboard
                </span>

            </a>


            <a
                href="children.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    👨‍👩‍👧
                </span>

                <span>
                    My Children
                </span>

            </a>


            <a
                href="homework.php?student_id=<?= (int) $studentId ?>"
                class="nav-item"
            >

                <span class="nav-icon">
                    📝
                </span>

                <span>
                    Homework
                </span>

            </a>


            <a
                href="announcements.php?student_id=<?= (int) $studentId ?>"
                class="nav-item active"
            >

                <span class="nav-icon">
                    📢
                </span>

                <span>
                    Announcements
                </span>

            </a>


            <a
                href="profile.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    👤
                </span>

                <span>
                    Profile
                </span>

            </a>


            <a
                href="../auth/logout.php"
                class="nav-item logout"
            >

                <span class="nav-icon">
                    🚪
                </span>

                <span>
                    Logout
                </span>

            </a>

        </nav>

    </aside>


    <!-- =====================================================
         Main
         ====================================================== -->

    <main class="main">


        <!-- =================================================
             Topbar
             ================================================== -->

        <header class="topbar">

            <div class="topbar-left">

                <span class="topbar-title">
                    Announcements
                </span>

            </div>


            <div class="parent-info">

                <a
                    href="dashboard.php?student_id=<?= (int) $studentId ?>"
                    class="mobile-dashboard"
                >

                    <span class="mobile-dashboard-icon">
                        🏠
                    </span>

                    <span>
                        Dashboard
                    </span>

                </a>


                <div class="parent-avatar">

                    <?php if ($parentPhotoUrl !== ''): ?>

                        <img
                            src="<?= h($parentPhotoUrl) ?>"
                            alt="Parent photo"
                        >

                    <?php else: ?>

                        <?= h(
                            strtoupper(
                                substr(
                                    $parentName,
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    <?php endif; ?>

                </div>

                <span class="parent-name">
                    <?= h($parentName) ?>
                </span>

            </div>

        </header>


        <!-- =================================================
             Content
             ================================================== -->

        <div class="content">

            <?php if ($errorMessage !== ''): ?>

                <div class="error-card">
                    <?= h($errorMessage) ?>
                </div>

            <?php elseif ($student): ?>


                <!-- =================================================
                     Selected child
                     ================================================== -->

                <div class="child-header">

                    <div class="child-info">

                        <div class="child-avatar">

                            <?= h(
                                strtoupper(
                                    substr(
                                        (string) $student['full_name'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>

                        </div>

                        <div>

                            <div class="child-name">
                                <?= h(
                                    (string) $student['full_name']
                                ) ?>
                            </div>

                            <div class="child-meta">

                                <?= h(
                                    (string) $student['student_code']
                                ) ?>

                                ·

                                Grade
                                <?= (int) $student['grade_number'] ?>

                                ·

                                Section
                                <?= h(
                                    (string) $student['section']
                                ) ?>

                            </div>

                        </div>

                    </div>


                    <div class="child-year">

                        Academic Year:
                        <?= h($academicYearName) ?>

                    </div>

                </div>


                <!-- =================================================
                     Heading
                     ================================================== -->

                <div class="section-header">

                    <div>

                        <div class="section-title">
                            School Announcements
                        </div>

                        <div class="section-subtitle">
                            Today:
                            <?= h($todayEthiopian) ?>
                        </div>

                    </div>

                </div>


                <!-- =================================================
                     Announcements
                     ================================================== -->

                <?php if (empty($announcements)): ?>

                    <div class="empty-card">

                        <div class="empty-icon">
                            📢
                        </div>

                        <h3>
                            No announcements
                        </h3>

                        <p>
                            There are no announcements
                            available at this time.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="announcements">

                        <?php foreach (
                            $announcements
                            as $announcement
                        ): ?>

                            <?php

                            $announcementId =
                                (int) $announcement['id'];

                            $media =
                                $mediaByAnnouncement[
                                    $announcementId
                                ]
                                ?? [
                                    'images' => [],
                                    'attachments' => []
                                ];

                            $content =
                                trim(
                                    (string)
                                    (
                                        $announcement['content']
                                        ?? ''
                                    )
                                );

                            ?>

                            <article
                                class="announcement-card"
                            >

                                <!-- TITLE -->

                                <div
                                    class="announcement-title"
                                >

                                    <?= h(
                                        (string)
                                        $announcement['title']
                                    ) ?>

                                </div>


                                <!-- DATES -->

                                <div
                                    class="announcement-dates"
                                >

                                    <?php if (
                                        !empty(
                                            $announcement[
                                                'published_at'
                                            ]
                                        )
                                    ): ?>

                                        <span
                                            class="date-item"
                                        >

                                            Published:

                                            <strong>
                                                <?= h(
                                                    formatEthiopianDate(
                                                        (string)
                                                        $announcement[
                                                            'published_at'
                                                        ]
                                                    )
                                                ) ?>
                                            </strong>

                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $announcement[
                                                'closed_at'
                                            ]
                                        )
                                    ): ?>

                                        <span
                                            class="date-item"
                                        >

                                            Last Date:

                                            <strong>
                                                <?= h(
                                                    formatEthiopianDate(
                                                        (string)
                                                        $announcement[
                                                            'closed_at'
                                                        ]
                                                    )
                                                ) ?>
                                            </strong>

                                        </span>

                                    <?php endif; ?>

                                </div>


                                <!-- CONTENT -->

                                <?php if ($content !== ''): ?>

                                    <div
                                        class="announcement-content"
                                    >

                                        <?= nl2br(
                                            h($content)
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <!-- IMAGES -->

                                <?php if (
                                    !empty(
                                        $media['images']
                                    )
                                ): ?>

                                    <div
                                        class="announcement-images"
                                    >

                                        <?php foreach (
                                            $media['images']
                                            as $image
                                        ): ?>

                                            <?php

                                            $imagePath =
                                                '../' .
                                                ltrim(
                                                    (string)
                                                    $image[
                                                        'file_path'
                                                    ],
                                                    '/'
                                                );

                                            ?>

                                            <img
                                                src="<?= h(
                                                    $imagePath
                                                ) ?>"
                                                alt="<?= h(
                                                    (string)
                                                    $announcement[
                                                        'title'
                                                    ]
                                                ) ?>"
                                                class="announcement-image"
                                                loading="lazy"
                                            >

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>


                                <!-- ATTACHMENTS -->

                                <?php if (
                                    !empty(
                                        $media[
                                            'attachments'
                                        ]
                                    )
                                ): ?>

                                    <div
                                        class="attachments"
                                    >

                                        <div
                                            class="attachment-heading"
                                        >
                                            Attachments
                                        </div>


                                        <?php foreach (
                                            $media[
                                                'attachments'
                                            ]
                                            as $attachment
                                        ): ?>

                                            <?php

                                            $attachmentPath =
                                                '../' .
                                                ltrim(
                                                    (string)
                                                    $attachment[
                                                        'file_path'
                                                    ],
                                                    '/'
                                                );

                                            $attachmentName =
                                                (string)
                                                $attachment[
                                                    'original_name'
                                                ];

                                            $attachmentSize =
                                                formatFileSize(
                                                    isset(
                                                        $attachment[
                                                            'file_size'
                                                        ]
                                                    )
                                                        ? (int)
                                                        $attachment[
                                                            'file_size'
                                                        ]
                                                        : null
                                                );

                                            ?>

                                            <div
                                                class="attachment-item"
                                            >

                                                <div
                                                    class="attachment-info"
                                                >

                                                    <div
                                                        class="attachment-icon"
                                                    >
                                                        <?= h(
                                                            getFileIcon(
                                                                $attachmentName
                                                            )
                                                        ) ?>
                                                    </div>


                                                    <div>

                                                        <div
                                                            class="attachment-name"
                                                        >
                                                            <?= h(
                                                                $attachmentName
                                                            ) ?>
                                                        </div>

                                                        <?php if (
                                                            $attachmentSize
                                                            !== ''
                                                        ): ?>

                                                            <div
                                                                class="attachment-size"
                                                            >
                                                                <?= h(
                                                                    $attachmentSize
                                                                ) ?>
                                                            </div>

                                                        <?php endif; ?>

                                                    </div>

                                                </div>


                                                <a
                                                    href="<?= h(
                                                        $attachmentPath
                                                    ) ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="open-attachment"
                                                >
                                                    Open
                                                </a>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </main>


    <!-- =====================================================
         Mobile Bottom Navigation
         ====================================================== -->

    <nav
        class="mobile-nav"
        id="mobileNav"
    >

        <a
            href="results.php?student_id=<?= (int) $studentId ?>"
            class="mobile-nav-item"
        >

            <span class="mobile-nav-icon">
                📊
            </span>

            <span class="mobile-nav-label">
                Results
            </span>

        </a>


        <a
            href="attendance.php?student_id=<?= (int) $studentId ?>"
            class="mobile-nav-item"
        >

            <span class="mobile-nav-icon">
                📅
            </span>

            <span class="mobile-nav-label">
                Attendance
            </span>

        </a>


        <a
            href="homework.php?student_id=<?= (int) $studentId ?>"
            class="mobile-nav-item"
        >

            <span class="mobile-nav-icon">
                📝
            </span>

            <span class="mobile-nav-label">
                Homework
            </span>

        </a>


        <a
            href="announcements.php?student_id=<?= (int) $studentId ?>"
            class="mobile-nav-item active"
        >

            <span class="mobile-nav-icon">
                📢
            </span>

            <span class="mobile-nav-label">
                Announcements
            </span>

        </a>


        <button
            type="button"
            class="mobile-nav-item mobile-more-button"
            id="moreButton"
            aria-label="More"
            aria-expanded="false"
        >

            <span class="mobile-nav-icon">
                ⋯
            </span>

            <span class="mobile-nav-label">
                More
            </span>

        </button>

    </nav>


    <!-- =====================================================
         Mobile More Menu
         ====================================================== -->

    <div
        class="more-menu"
        id="moreMenu"
    >

        <a
            href="profile.php"
            class="more-menu-item"
        >

            <span>
                👤
            </span>

            <span>
                Profile
            </span>

        </a>


        <a
            href="../auth/logout.php"
            class="more-menu-item logout-mobile"
        >

            <span>
                🚪
            </span>

            <span>
                Logout
            </span>

        </a>

    </div>


</div>


<script>

    const moreButton =
        document.getElementById(
            'moreButton'
        );

    const moreMenu =
        document.getElementById(
            'moreMenu'
        );


    if (moreButton && moreMenu) {

        moreButton.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

                const isOpen =
                    moreMenu.classList.toggle(
                        'show'
                    );

                moreButton.setAttribute(
                    'aria-expanded',
                    isOpen
                        ? 'true'
                        : 'false'
                );

            }
        );


        document.addEventListener(
            'click',
            function (event) {

                if (
                    !moreMenu.contains(event.target) &&
                    !moreButton.contains(event.target)
                ) {

                    moreMenu.classList.remove(
                        'show'
                    );

                    moreButton.setAttribute(
                        'aria-expanded',
                        'false'
                    );
                }

            }
        );

    }

</script>

</body>

</html>