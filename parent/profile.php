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

$userId = (int) $_SESSION['user_id'];

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): never
{
    header(
        'Location: profile.php?' .
        http_build_query([
            $type => $message
        ])
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Photo upload directory
|--------------------------------------------------------------------------
*/

$uploadDirectory = __DIR__ . '/../uploads/parents';

if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        redirectWithMessage(
            'error',
            'Could not create the photo upload directory.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Get parent information
|--------------------------------------------------------------------------
|
| Personal information comes from users.
| Parent-specific information comes from parents.
|
| IMPORTANT:
| There is NO parents.relationship column.
|
*/

$sql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        p.id AS parent_id,
        p.photo
    FROM users AS u
    INNER JOIN parents AS p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'parent'
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Database error.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$parent = $result->fetch_assoc();

$stmt->close();

if (!$parent) {
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

$parentId = (int) $parent['parent_id'];

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_password') {

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            redirectWithMessage(
                'error',
                'Please fill in all password fields.'
            );
        }

        if ($newPassword !== $confirmPassword) {
            redirectWithMessage(
                'error',
                'New password and confirmation password do not match.'
            );
        }

        if (strlen($newPassword) < 6) {
            redirectWithMessage(
                'error',
                'New password must be at least 6 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get current password
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT password
            FROM users
            WHERE id = ?
              AND is_deleted = 0
              AND LOWER(role) = 'parent'
            LIMIT 1
        ");

        if (!$stmt) {
            redirectWithMessage(
                'error',
                'Unable to process the password change.'
            );
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $passwordResult = $stmt->get_result();
        $passwordRow = $passwordResult->fetch_assoc();

        $stmt->close();

        if (!$passwordRow) {
            redirectWithMessage(
                'error',
                'Parent account could not be found.'
            );
        }

        $passwordHash = (string) $passwordRow['password'];

        /*
        |--------------------------------------------------------------------------
        | Verify current password
        |--------------------------------------------------------------------------
        */

        if (!password_verify($currentPassword, $passwordHash)) {
            redirectWithMessage(
                'error',
                'Current password is incorrect.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Hash new password
        |--------------------------------------------------------------------------
        */

        $newPasswordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        /*
        |--------------------------------------------------------------------------
        | Update password
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
              AND is_deleted = 0
              AND LOWER(role) = 'parent'
        ");

        if (!$stmt) {
            redirectWithMessage(
                'error',
                'Unable to update your password.'
            );
        }

        $stmt->bind_param(
            'si',
            $newPasswordHash,
            $userId
        );

        if (!$stmt->execute()) {
            $stmt->close();

            redirectWithMessage(
                'error',
                'Password could not be changed.'
            );
        }

        $stmt->close();

        redirectWithMessage(
            'success',
            'Your password has been changed successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE PROFILE PHOTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_photo') {

        if (
            !isset($_FILES['photo']) ||
            !is_array($_FILES['photo'])
        ) {
            redirectWithMessage(
                'error',
                'Please select a photo.'
            );
        }

        $photo = $_FILES['photo'];

        if (
            !isset($photo['error']) ||
            $photo['error'] !== UPLOAD_ERR_OK
        ) {
            redirectWithMessage(
                'error',
                'Photo upload failed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum size: 5 MB
        |--------------------------------------------------------------------------
        */

        $maxFileSize = 5 * 1024 * 1024;

        if (
            !isset($photo['size']) ||
            (int) $photo['size'] > $maxFileSize
        ) {
            redirectWithMessage(
                'error',
                'Photo size must not exceed 5 MB.'
            );
        }

        $tmpName = (string) $photo['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            redirectWithMessage(
                'error',
                'Invalid photo upload.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate actual image
        |--------------------------------------------------------------------------
        */

        $imageInfo = @getimagesize($tmpName);

        if ($imageInfo === false) {
            redirectWithMessage(
                'error',
                'The selected file is not a valid image.'
            );
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        $mimeType = (string) ($imageInfo['mime'] ?? '');

        if (!isset($allowedMimeTypes[$mimeType])) {
            redirectWithMessage(
                'error',
                'Only JPG, PNG, and WEBP images are allowed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate secure filename
        |--------------------------------------------------------------------------
        */

        $extension = $allowedMimeTypes[$mimeType];

        $fileName =
            'parent_' .
            $parentId .
            '_' .
            bin2hex(random_bytes(8)) .
            '.' .
            $extension;

        $destination =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $fileName;

        /*
        |--------------------------------------------------------------------------
        | Save file
        |--------------------------------------------------------------------------
        */

        if (!move_uploaded_file($tmpName, $destination)) {
            redirectWithMessage(
                'error',
                'Could not save the uploaded photo.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing photo
        |--------------------------------------------------------------------------
        */

        $oldPhoto = trim(
            (string) ($parent['photo'] ?? '')
        );

        /*
        |--------------------------------------------------------------------------
        | Save photo path in database
        |--------------------------------------------------------------------------
        */

        $photoPath =
            'uploads/parents/' .
            $fileName;

        $stmt = $conn->prepare("
            UPDATE parents
            SET photo = ?
            WHERE id = ?
        ");

        if (!$stmt) {

            @unlink($destination);

            redirectWithMessage(
                'error',
                'Could not update your profile photo.'
            );
        }

        $stmt->bind_param(
            'si',
            $photoPath,
            $parentId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            @unlink($destination);

            redirectWithMessage(
                'error',
                'Could not update your profile photo.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Delete old uploaded photo
        |--------------------------------------------------------------------------
        */

        if ($oldPhoto !== '') {

            $oldFileName = basename($oldPhoto);

            $oldFilePath =
                $uploadDirectory .
                DIRECTORY_SEPARATOR .
                $oldFileName;

            if (
                is_file($oldFilePath) &&
                realpath($oldFilePath) !== realpath($destination)
            ) {
                @unlink($oldFilePath);
            }
        }

        redirectWithMessage(
            'success',
            'Your profile photo has been updated successfully.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$successMessage = isset($_GET['success'])
    ? (string) $_GET['success']
    : '';

$errorMessage = isset($_GET['error'])
    ? (string) $_GET['error']
    : '';

/*
|--------------------------------------------------------------------------
| Refresh parent information
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        p.id AS parent_id,
        p.photo
    FROM users AS u
    INNER JOIN parents AS p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'parent'
    LIMIT 1
");

if (!$stmt) {
    die('Database error.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$parent = $result->fetch_assoc();

$stmt->close();

if (!$parent) {
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Profile photo URL
|--------------------------------------------------------------------------
*/

$photoPath = trim(
    (string) ($parent['photo'] ?? '')
);

if ($photoPath !== '') {
    $photoUrl = '../' . ltrim($photoPath, '/');
} else {
    $photoUrl = '';
}

/*
|--------------------------------------------------------------------------
| Initial
|--------------------------------------------------------------------------
*/

$fullName = trim(
    (string) ($parent['full_name'] ?? '')
);

$initial = '';

if ($fullName !== '') {
    $initial = strtoupper(
        substr($fullName, 0, 1)
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

    <title>My Profile | BKHS Parent Portal</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: #f5f7fb;
            color: #111827;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button,
        input {
            font: inherit;
        }

        .app {
            display: flex;
            min-height: 100vh;
        }

        /*
        ============================================================
        SIDEBAR
        ============================================================
        */

        .sidebar {
            width: 250px;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            padding: 24px 16px;

            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;

            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 0 10px 28px;
        }

        .brand-icon {
            width: 42px;
            height: 42px;

            border-radius: 12px;

            background: #eef2ff;
            color: #4338ca;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 21px;
        }

        .brand-text strong {
            display: block;

            font-size: 15px;
            color: #111827;
        }

        .brand-text span {
            display: block;

            margin-top: 2px;

            color: #6b7280;
            font-size: 12px;
        }

        .nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav a {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 12px 13px;

            border-radius: 10px;

            color: #4b5563;
            font-size: 14px;

            transition:
                background .2s ease,
                color .2s ease,
                transform .2s ease;
        }

        .nav a:hover {
            background: #f3f4f6;
            color: #111827;
            transform: translateX(2px);
        }

        .nav a.active {
            background: #eef2ff;
            color: #4338ca;
            font-weight: 600;
        }

        .nav-icon {
            width: 22px;
            text-align: center;
            font-size: 17px;
        }

        /*
        ============================================================
        MAIN
        ============================================================
        */

        .main {
            flex: 1;

            margin-left: 250px;

            min-width: 0;
        }

        .topbar {
            height: 72px;

            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 32px;

            position: sticky;
            top: 0;

            z-index: 50;
        }

        .back-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            color: #4b5563;
            font-size: 14px;

            transition:
                color .2s ease,
                transform .2s ease;
        }

        .back-dashboard:hover {
            color: #4338ca;
            transform: translateX(-2px);
        }

        .top-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .content {
            padding: 30px 32px 50px;

            max-width: 1100px;

            margin: 0 auto;
        }

        /*
        ============================================================
        ALERTS
        ============================================================
        */

        .alert {
            border-radius: 12px;

            padding: 13px 16px;

            margin-bottom: 22px;

            font-size: 14px;

            border: 1px solid transparent;
        }

        .alert-success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .alert-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        /*
        ============================================================
        PROFILE HEADER
        ============================================================
        */

        .profile-header {
            background: #ffffff;

            border: 1px solid #e5e7eb;
            border-radius: 16px;

            padding: 24px;

            display: flex;
            align-items: center;
            gap: 22px;

            margin-bottom: 22px;

            transition:
                box-shadow .2s ease,
                transform .2s ease;
        }

        .profile-header:hover {
            box-shadow:
                0 8px 25px rgba(15, 23, 42, .06);

            transform: translateY(-1px);
        }

        .avatar {
            width: 88px;
            height: 88px;

            border-radius: 50%;

            overflow: hidden;

            background: #eef2ff;
            color: #4338ca;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 34px;
            font-weight: 700;

            border: 3px solid #ffffff;

            box-shadow:
                0 2px 10px rgba(15, 23, 42, .10);

            flex-shrink: 0;
        }

        .avatar img {
            width: 100%;
            height: 100%;

            object-fit: cover;
        }

        .profile-name {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
        }

        .profile-role {
            margin-top: 5px;

            color: #6b7280;
            font-size: 14px;
        }

        /*
        ============================================================
        GRID
        ============================================================
        */

        .grid {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 22px;
        }

        .panel {
            background: #ffffff;

            border: 1px solid #e5e7eb;
            border-radius: 16px;

            padding: 22px;

            transition:
                box-shadow .2s ease,
                transform .2s ease;
        }

        .panel:hover {
            box-shadow:
                0 8px 25px rgba(15, 23, 42, .05);

            transform: translateY(-1px);
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 10px;

            font-size: 16px;
            font-weight: 700;

            margin-bottom: 20px;
        }

        .panel-title-icon {
            width: 36px;
            height: 36px;

            border-radius: 10px;

            background: #eef2ff;
            color: #4338ca;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        /*
        ============================================================
        INFORMATION
        ============================================================
        */

        .info-list {
            display: flex;
            flex-direction: column;
        }

        .info-row {
            display: flex;

            justify-content: space-between;

            gap: 20px;

            padding: 14px 0;

            border-bottom: 1px solid #f0f1f3;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #6b7280;
            font-size: 13px;
        }

        .info-value {
            color: #111827;

            font-size: 14px;
            font-weight: 600;

            text-align: right;

            word-break: break-word;
        }

        .readonly-note {
            margin-top: 16px;

            padding: 11px 13px;

            border-radius: 9px;

            background: #f9fafb;

            color: #6b7280;

            font-size: 12px;

            line-height: 1.5;
        }

        /*
        ============================================================
        FORMS
        ============================================================
        */

        .form-group {
            margin-bottom: 17px;
        }

        .form-label {
            display: block;

            margin-bottom: 7px;

            color: #374151;

            font-size: 13px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;

            border: 1px solid #d1d5db;

            border-radius: 10px;

            padding: 11px 12px;

            outline: none;

            background: #ffffff;

            color: #111827;

            transition:
                border-color .2s ease,
                box-shadow .2s ease;
        }

        .form-control:focus {
            border-color: #6366f1;

            box-shadow:
                0 0 0 3px rgba(99, 102, 241, .10);
        }

        .form-help {
            margin-top: 6px;

            color: #6b7280;

            font-size: 12px;
            line-height: 1.5;
        }

        .btn {
            border: none;

            border-radius: 10px;

            padding: 11px 17px;

            cursor: pointer;

            font-weight: 600;
            font-size: 14px;

            transition:
                background .2s ease,
                transform .2s ease,
                box-shadow .2s ease;
        }

        .btn-primary {
            background: #4338ca;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #3730a3;

            transform: translateY(-1px);

            box-shadow:
                0 5px 14px rgba(67, 56, 202, .20);
        }

        /*
        ============================================================
        PHOTO
        ============================================================
        */

        .photo-upload {
            display: flex;

            align-items: center;

            gap: 16px;

            margin-bottom: 12px;
        }

        .photo-preview {
            width: 64px;
            height: 64px;

            flex-shrink: 0;

            border-radius: 50%;

            overflow: hidden;

            background: #eef2ff;
            color: #4338ca;

            display: flex;
            align-items: center;
            justify-content: center;

            font-weight: 700;
            font-size: 22px;
        }

        .photo-preview img {
            width: 100%;
            height: 100%;

            object-fit: cover;
        }

        .file-input {
            width: 100%;

            font-size: 13px;

            color: #6b7280;
        }

        .file-input::file-selector-button {
            border: none;

            border-radius: 8px;

            padding: 9px 12px;

            margin-right: 10px;

            background: #eef2ff;
            color: #4338ca;

            cursor: pointer;

            font-weight: 600;
        }

        /*
        ============================================================
        MOBILE NAVIGATION
        ============================================================
        */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-more-menu {
            display: none;
        }

        /*
        ============================================================
        RESPONSIVE
        ============================================================
        */

        @media (max-width: 900px) {

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;

                padding-bottom: 76px;
            }

            .topbar {
                height: 64px;

                padding: 0 18px;
            }

            .top-title {
                font-size: 16px;
            }

            .content {
                padding: 20px 16px 30px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .mobile-bottom-nav {
                display: flex;

                position: fixed;

                left: 0;
                right: 0;
                bottom: 0;

                height: 66px;

                background: #ffffff;

                border-top: 1px solid #e5e7eb;

                z-index: 200;

                justify-content: space-around;
            }

            .mobile-bottom-nav a,
            .mobile-more-button {
                flex: 1;

                border: none;

                background: transparent;

                display: flex;
                flex-direction: column;

                align-items: center;
                justify-content: center;

                gap: 3px;

                color: #6b7280;

                font-size: 10px;

                cursor: pointer;

                transition:
                    color .2s ease,
                    background .2s ease;
            }

            .mobile-bottom-nav a.active,
            .mobile-more-button.active {
                color: #4338ca;
            }

            .mobile-nav-icon {
                font-size: 19px;
                line-height: 20px;
            }

            .mobile-more-menu {
                position: fixed;

                right: 12px;
                bottom: 74px;

                width: 190px;

                background: #ffffff;

                border: 1px solid #e5e7eb;

                border-radius: 13px;

                box-shadow:
                    0 10px 30px rgba(15, 23, 42, .14);

                padding: 7px;

                z-index: 210;
            }

            .mobile-more-menu.show {
                display: block;
            }

            .mobile-more-menu a {
                display: flex;
                align-items: center;

                gap: 10px;

                padding: 11px 12px;

                border-radius: 9px;

                font-size: 13px;

                color: #374151;

                transition:
                    background .2s ease,
                    color .2s ease;
            }

            .mobile-more-menu a:hover,
            .mobile-more-menu a.active {
                background: #eef2ff;
                color: #4338ca;
            }
        }

        @media (max-width: 560px) {

            .profile-header {
                padding: 18px;

                gap: 15px;
            }

            .avatar {
                width: 70px;
                height: 70px;

                font-size: 27px;
            }

            .profile-name {
                font-size: 18px;
            }

            .profile-role {
                font-size: 12px;
            }

            .panel {
                padding: 18px;
            }

            .info-row {
                flex-direction: column;

                gap: 4px;
            }

            .info-value {
                text-align: left;
            }

            .photo-upload {
                align-items: flex-start;

                flex-direction: column;
            }

            .file-input {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- =========================================================
         DESKTOP SIDEBAR
    ========================================================== -->

    <aside class="sidebar">

        <div class="brand">

            <div class="brand-icon">
                👨‍👩‍👧
            </div>

            <div class="brand-text">
                <strong>BKHS</strong>
                <span>Parent Portal</span>
            </div>

        </div>

        <nav class="nav">

            <a href="dashboard.php">
                <span class="nav-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a href="children.php">
                <span class="nav-icon">👨‍👩‍👧</span>
                <span>My Children</span>
            </a>

            <a href="result.php">
                <span class="nav-icon">📊</span>
                <span>Results</span>
            </a>

            <a href="attendance.php">
                <span class="nav-icon">✓</span>
                <span>Attendance</span>
            </a>

            <a href="homework.php">
                <span class="nav-icon">📝</span>
                <span>Homework</span>
            </a>

            <a href="announcements.php">
                <span class="nav-icon">📢</span>
                <span>Announcements</span>
            </a>

            <a
                href="profile.php"
                class="active"
            >
                <span class="nav-icon">👤</span>
                <span>Profile</span>
            </a>

            <a href="../auth/logout.php">
                <span class="nav-icon">↪</span>
                <span>Logout</span>
            </a>

        </nav>

    </aside>

    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main">

        <header class="topbar">

            <a
                href="dashboard.php"
                class="back-dashboard"
            >
                ← Dashboard
            </a>

            <div class="top-title">
                My Profile
            </div>

        </header>

        <section class="content">

            <!-- =================================================
                 ALERTS
            ================================================== -->

            <?php if ($successMessage !== ''): ?>

                <div class="alert alert-success">
                    <?= e($successMessage) ?>
                </div>

            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>

                <div class="alert alert-error">
                    <?= e($errorMessage) ?>
                </div>

            <?php endif; ?>

            <!-- =================================================
                 PROFILE HEADER
            ================================================== -->

            <div class="profile-header">

                <div class="avatar">

                    <?php if ($photoUrl !== ''): ?>

                        <img
                            src="<?= e($photoUrl) ?>"
                            alt="Profile photo"
                        >

                    <?php else: ?>

                        <?= e($initial) ?>

                    <?php endif; ?>

                </div>

                <div>

                    <div class="profile-name">
                        <?= e($fullName) ?>
                    </div>

                    <div class="profile-role">
                        Parent
                    </div>

                </div>

            </div>

            <!-- =================================================
                 CONTENT
            ================================================== -->

            <div class="grid">

                <!-- =============================================
                     PERSONAL INFORMATION
                ============================================== -->

                <section class="panel">

                    <div class="panel-title">

                        <div class="panel-title-icon">
                            👤
                        </div>

                        <span>My Information</span>

                    </div>

                    <div class="info-list">

                        <div class="info-row">

                            <div class="info-label">
                                Full Name
                            </div>

                            <div class="info-value">
                                <?= e(
                                    (string) $parent['full_name']
                                ) ?>
                            </div>

                        </div>

                        <div class="info-row">

                            <div class="info-label">
                                Phone
                            </div>

                            <div class="info-value">
                                <?= e(
                                    (string) (
                                        $parent['phone']
                                        ?? 'Not provided'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="info-row">

                            <div class="info-label">
                                Email
                            </div>

                            <div class="info-value">
                                <?= e(
                                    (string) (
                                        $parent['email']
                                        ?? 'Not provided'
                                    )
                                ) ?>
                            </div>

                        </div>

                    </div>

                    <div class="readonly-note">
                        Your personal information is managed by
                        the school. You can view it here, but you
                        cannot edit it from the parent portal.
                    </div>

                </section>

                <!-- =============================================
                     PROFILE PHOTO
                ============================================== -->

                <section class="panel">

                    <div class="panel-title">

                        <div class="panel-title-icon">
                            📷
                        </div>

                        <span>Profile Photo</span>

                    </div>

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="change_photo"
                        >

                        <div class="photo-upload">

                            <div class="photo-preview">

                                <?php if ($photoUrl !== ''): ?>

                                    <img
                                        src="<?= e($photoUrl) ?>"
                                        alt="Profile photo"
                                    >

                                <?php else: ?>

                                    <?= e($initial) ?>

                                <?php endif; ?>

                            </div>

                            <input
                                type="file"
                                name="photo"
                                class="file-input"
                                accept="image/jpeg,image/png,image/webp"
                                required
                            >

                        </div>

                        <div class="form-help">
                            JPG, PNG or WEBP. Maximum size: 5 MB.
                        </div>

                        <br>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            📷 Update Photo
                        </button>

                    </form>

                </section>

                <!-- =============================================
                     CHANGE PASSWORD
                ============================================== -->

                <section class="panel">

                    <div class="panel-title">

                        <div class="panel-title-icon">
                            🔒
                        </div>

                        <span>Change Password</span>

                    </div>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="change_password"
                        >

                        <div class="form-group">

                            <label class="form-label">
                                Current Password
                            </label>

                            <input
                                type="password"
                                name="current_password"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label class="form-label">
                                New Password
                            </label>

                            <input
                                type="password"
                                name="new_password"
                                class="form-control"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label class="form-label">
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                name="confirm_password"
                                class="form-control"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <div class="form-help">
                            Your new password must be at least
                            6 characters.
                        </div>

                        <br>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            🔒 Change Password
                        </button>

                    </form>

                </section>

            </div>

        </section>

    </main>

</div>

<!-- =============================================================
     MOBILE BOTTOM NAVIGATION
============================================================== -->

<nav class="mobile-bottom-nav">

    <a href="result.php">

        <span class="mobile-nav-icon">
            📊
        </span>

        <span>
            Result
        </span>

    </a>

    <a href="attendance.php">

        <span class="mobile-nav-icon">
            ✓
        </span>

        <span>
            Attendance
        </span>

    </a>

    <a href="homework.php">

        <span class="mobile-nav-icon">
            📝
        </span>

        <span>
            Homework
        </span>

    </a>

    <a href="announcements.php">

        <span class="mobile-nav-icon">
            📢
        </span>

        <span>
            Announcement
        </span>

    </a>

    <button
        type="button"
        class="mobile-more-button active"
        id="moreButton"
    >

        <span class="mobile-nav-icon">
            ☰
        </span>

        <span>
            More
        </span>

    </button>

</nav>

<!-- =============================================================
     MOBILE MORE MENU
============================================================== -->

<div
    class="mobile-more-menu"
    id="moreMenu"
>

    <a
        href="profile.php"
        class="active"
    >
        👤
        <span>Profile</span>
    </a>

    <a href="../auth/logout.php">
        ↪
        <span>Logout</span>
    </a>

</div>

<script>

    const moreButton =
        document.getElementById('moreButton');

    const moreMenu =
        document.getElementById('moreMenu');

    moreButton.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

            moreMenu.classList.toggle('show');

        }
    );

    document.addEventListener(
        'click',
        function (event) {

            if (
                !moreMenu.contains(event.target) &&
                !moreButton.contains(event.target)
            ) {
                moreMenu.classList.remove('show');
            }

        }
    );

</script>

</body>

</html>