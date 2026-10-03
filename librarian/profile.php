<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Librarian Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'librarian'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Helper
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
| Librarian ID
|--------------------------------------------------------------------------
*/

$librarianId = (int) $_SESSION['user_id'];

$successMessage = '';
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Librarian Data
|--------------------------------------------------------------------------
|
| Librarian photo is stored directly in users.photo_path.
| No teachers table is required.
|
*/

$fullName = '';
$email = '';
$phone = '';
$photoPath = '';

$stmt = $conn->prepare("
    SELECT
        u.full_name,
        u.email,
        u.phone,
        u.photo_path
    FROM users u
    WHERE u.id = ?
      AND LOWER(u.role) = 'librarian'
      AND u.is_deleted = 0
    LIMIT 1
");

if (!$stmt) {
    die('Unable to prepare profile query.');
}

$stmt->bind_param(
    'i',
    $librarianId
);

$stmt->execute();

$result = $stmt->get_result();

$librarian = $result->fetch_assoc();

$stmt->close();

if (!$librarian) {
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

$fullName = (string) ($librarian['full_name'] ?? '');
$email = (string) ($librarian['email'] ?? '');
$phone = (string) ($librarian['phone'] ?? '');
$photoPath = (string) ($librarian['photo_path'] ?? '');

/*
|--------------------------------------------------------------------------
| Update Personal Information
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_profile'])
) {

    $submittedName = trim(
        (string) ($_POST['full_name'] ?? '')
    );

    $submittedEmail = trim(
        (string) ($_POST['email'] ?? '')
    );

    $submittedPhone = trim(
        (string) ($_POST['phone'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($submittedName === '') {

        $errorMessage =
            'Full name is required.';

    } elseif (mb_strlen($submittedName) > 150) {

        $errorMessage =
            'Full name cannot exceed 150 characters.';

    } elseif (
        $submittedEmail !== '' &&
        !filter_var(
            $submittedEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errorMessage =
            'Please enter a valid email address.';

    } elseif (
        $submittedEmail !== '' &&
        mb_strlen($submittedEmail) > 150
    ) {

        $errorMessage =
            'Email cannot exceed 150 characters.';

    } elseif (
        $submittedPhone !== '' &&
        mb_strlen($submittedPhone) > 150
    ) {

        $errorMessage =
            'Phone number cannot exceed 150 characters.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check Email Uniqueness
        |--------------------------------------------------------------------------
        */

        $emailConflict = false;

        if ($submittedEmail !== '') {

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                  AND id <> ?
                  AND is_deleted = 0
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'si',
                    $submittedEmail,
                    $librarianId
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $emailConflict =
                    $result->num_rows > 0;

                $stmt->close();
            }
        }

        if ($emailConflict) {

            $errorMessage =
                'This email address is already used by another account.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Check Phone Uniqueness
            |--------------------------------------------------------------------------
            */

            $phoneConflict = false;

            if ($submittedPhone !== '') {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE phone = ?
                      AND id <> ?
                      AND is_deleted = 0
                    LIMIT 1
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        'si',
                        $submittedPhone,
                        $librarianId
                    );

                    $stmt->execute();

                    $result = $stmt->get_result();

                    $phoneConflict =
                        $result->num_rows > 0;

                    $stmt->close();
                }
            }

            if ($phoneConflict) {

                $errorMessage =
                    'This phone number is already used by another account.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Update Users Table
                |--------------------------------------------------------------------------
                */

                $emailValue =
                    $submittedEmail !== ''
                        ? $submittedEmail
                        : null;

                $phoneValue =
                    $submittedPhone !== ''
                        ? $submittedPhone
                        : null;

                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        full_name = ?,
                        email = ?,
                        phone = ?
                    WHERE id = ?
                      AND LOWER(role) = 'librarian'
                      AND is_deleted = 0
                ");

                if (!$stmt) {

                    $errorMessage =
                        'Unable to prepare profile update.';

                } else {

                    $stmt->bind_param(
                        'sssi',
                        $submittedName,
                        $emailValue,
                        $phoneValue,
                        $librarianId
                    );

                    if ($stmt->execute()) {

                        $successMessage =
                            'Profile updated successfully.';

                        $fullName =
                            $submittedName;

                        $email =
                            $submittedEmail;

                        $phone =
                            $submittedPhone;

                        $_SESSION['full_name'] =
                            $submittedName;

                    } else {

                        if ($conn->errno === 1062) {

                            $errorMessage =
                                'The email or phone number is already in use.';

                        } else {

                            $errorMessage =
                                'Unable to update profile.';
                        }
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Upload Profile Photo
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_photo'])
) {

    if (
        !isset($_FILES['profile_photo']) ||
        !is_array($_FILES['profile_photo'])
    ) {

        $errorMessage =
            'Please select a profile photo.';

    } else {

        $file = $_FILES['profile_photo'];

        if (
            !isset($file['error']) ||
            (int) $file['error'] !== UPLOAD_ERR_OK
        ) {

            $errorMessage =
                'Please select a valid profile photo.';

        } elseif (
            !isset($file['size']) ||
            (int) $file['size'] > 2 * 1024 * 1024
        ) {

            $errorMessage =
                'Profile photo must not exceed 2 MB.';

        } else {

            $tmpName =
                (string) $file['tmp_name'];

            /*
            |--------------------------------------------------------------------------
            | Validate Image
            |--------------------------------------------------------------------------
            */

            $imageInfo =
                @getimagesize($tmpName);

            if ($imageInfo === false) {

                $errorMessage =
                    'The selected file is not a valid image.';

            } else {

                $mimeType =
                    (string) ($imageInfo['mime'] ?? '');

                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                if (
                    !isset(
                        $allowedMimeTypes[$mimeType]
                    )
                ) {

                    $errorMessage =
                        'Only JPG, JPEG, PNG, and WEBP images are allowed.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Upload Directory
                    |--------------------------------------------------------------------------
                    */

                    $uploadDirectory =
                        dirname(__DIR__) .
                        DIRECTORY_SEPARATOR .
                        'public' .
                        DIRECTORY_SEPARATOR .
                        'uploads' .
                        DIRECTORY_SEPARATOR .
                        'librarians';

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir(
                            $uploadDirectory,
                            0755,
                            true
                        )
                    ) {

                        $errorMessage =
                            'Unable to create the photo upload directory.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Generate Safe File Name
                        |--------------------------------------------------------------------------
                        */

                        $extension =
                            $allowedMimeTypes[$mimeType];

                        try {

                            $randomPart =
                                bin2hex(
                                    random_bytes(8)
                                );

                        } catch (
                            Throwable $exception
                        ) {

                            $randomPart =
                                uniqid(
                                    '',
                                    true
                                );
                        }

                        $fileName =
                            'librarian_' .
                            $librarianId .
                            '_' .
                            $randomPart .
                            '.' .
                            $extension;

                        $destination =
                            $uploadDirectory .
                            DIRECTORY_SEPARATOR .
                            $fileName;

                        /*
                        |--------------------------------------------------------------------------
                        | Move Uploaded File
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !move_uploaded_file(
                                $tmpName,
                                $destination
                            )
                        ) {

                            $errorMessage =
                                'Unable to upload the profile photo.';

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | Path Stored in Database
                            |--------------------------------------------------------------------------
                            */

                            $newPhotoPath =
                                'public/uploads/librarians/' .
                                $fileName;

                            $oldPhotoPath =
                                $photoPath;

                            /*
                            |--------------------------------------------------------------------------
                            | Update Users Table
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $conn->prepare("
                                UPDATE users
                                SET photo_path = ?
                                WHERE id = ?
                                  AND LOWER(role) = 'librarian'
                                  AND is_deleted = 0
                            ");

                            if (!$stmt) {

                                @unlink($destination);

                                $errorMessage =
                                    'Unable to prepare photo update.';

                            } else {

                                $stmt->bind_param(
                                    'si',
                                    $newPhotoPath,
                                    $librarianId
                                );

                                if ($stmt->execute()) {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | Update Current Photo
                                    |--------------------------------------------------------------------------
                                    */

                                    $photoPath =
                                        $newPhotoPath;

                                    $successMessage =
                                        'Profile photo updated successfully.';

                                    /*
                                    |--------------------------------------------------------------------------
                                    | Delete Previous Photo
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        $oldPhotoPath !== ''
                                    ) {

                                        $oldPhotoFile =
                                            dirname(__DIR__) .
                                            DIRECTORY_SEPARATOR .
                                            ltrim(
                                                str_replace(
                                                    [
                                                        '/',
                                                        '\\'
                                                    ],
                                                    DIRECTORY_SEPARATOR,
                                                    $oldPhotoPath
                                                ),
                                                DIRECTORY_SEPARATOR
                                            );

                                        $oldRealPath =
                                            realpath(
                                                $oldPhotoFile
                                            );

                                        $newRealPath =
                                            realpath(
                                                $destination
                                            );

                                        if (
                                            $oldRealPath !== false &&
                                            is_file(
                                                $oldRealPath
                                            ) &&
                                            $newRealPath !== false &&
                                            $oldRealPath !== $newRealPath
                                        ) {

                                            @unlink(
                                                $oldRealPath
                                            );
                                        }
                                    }

                                } else {

                                    @unlink($destination);

                                    $errorMessage =
                                        'Unable to save the profile photo.';
                                }

                                $stmt->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEth =
    EthiopianCalendar::today();

$todayFormatted =
    EthiopianCalendar::format(
        $todayEth['year'],
        $todayEth['month'],
        $todayEth['day'],
        'en'
    );

$dayName =
    $todayEth['day_name'];

/*
|--------------------------------------------------------------------------
| Profile Photo URL
|--------------------------------------------------------------------------
*/

$photoUrl =
    '../public/images/default-avatar.png';

if ($photoPath !== '') {

    $cleanPhotoPath =
        ltrim(
            str_replace(
                '\\',
                '/',
                $photoPath
            ),
            '/'
        );

    $photoUrl =
        '../' . $cleanPhotoPath;
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

    <title>Librarian Profile | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
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
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;
            --white: #ffffff;
            --danger: #dc2626;
            --success: #16a34a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
        }

        /* Sidebar */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            transition: transform 0.25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 3px;
        }

        .brand-text {
            line-height: 1.15;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
        }

        .brand-subtitle {
            margin-top: 4px;
            color: #9ca3af;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding: 18px 12px;
        }

        .sidebar-section-title {
            padding: 10px 12px 7px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .menu-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            min-height: 44px;
            padding: 10px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .menu-item:hover {
            background: var(--sidebar-hover);
            color: #fff;
            transform: translateX(2px);
        }

        .menu-item.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 8px;
            bottom: 8px;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: #fff;
        }

        .logout-item:hover {
            background: rgba(220,38,38,0.14);
            color: #fca5a5;
        }

        .sidebar-footer {
            padding: 14px 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 10px;
            background: rgba(255,255,255,0.04);
        }

        .sidebar-user img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.15);
        }

        .sidebar-user-name {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            color: #9ca3af;
            font-size: 10px;
            margin-top: 2px;
        }

        /* Main */

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }

        /* Topbar */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 900;
            height: 76px;
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }

        .page-heading h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .date-box {
            text-align: right;
        }

        .date-day {
            font-size: 12px;
            font-weight: 700;
        }

        .date-eth {
            margin-top: 2px;
            color: var(--muted);
            font-size: 10px;
        }

        .topbar-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 16px;
            border-left: 1px solid var(--border);
        }

        .topbar-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .topbar-profile-name {
            font-size: 12px;
            font-weight: 700;
        }

        .topbar-profile-role {
            margin-top: 2px;
            color: var(--muted);
            font-size: 10px;
        }

        /* Content */

        .content {
            padding: 28px 30px 45px;
        }

        .profile-header {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .profile-header-photo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #eff6ff;
            box-shadow: 0 3px 12px rgba(15,23,42,0.08);
        }

        .profile-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .profile-header p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            padding: 6px 10px;
            border-radius: 7px;
            background: #eff6ff;
            color: var(--primary);
            font-size: 10px;
            font-weight: 700;
        }

        .profile-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header-custom {
            padding: 18px 21px;
            border-bottom: 1px solid var(--border);
        }

        .card-header-custom h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .card-header-custom p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 11px;
        }

        .card-body-custom {
            padding: 22px;
        }

        .form-label {
            margin-bottom: 7px;
            font-size: 11px;
            font-weight: 700;
            color: #374151;
        }

        .form-control {
            min-height: 43px;
            border-color: #dbe2ea;
            border-radius: 9px;
            font-size: 12px;
            box-shadow: none !important;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

        .form-control[readonly] {
            background: #f8fafc;
            color: #6b7280;
        }

        .btn-primary-custom {
            min-height: 42px;
            padding: 9px 17px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-row:last-child {
            border-bottom: 0;
        }

        .info-label {
            color: var(--muted);
            font-size: 11px;
        }

        .info-value {
            text-align: right;
            font-size: 12px;
            font-weight: 600;
        }

        .photo-upload-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .photo-preview {
            width: 105px;
            height: 105px;
            flex-shrink: 0;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #eff6ff;
            box-shadow: 0 3px 12px rgba(15,23,42,0.08);
        }

        .photo-upload-content {
            flex: 1;
        }

        .photo-help {
            margin-top: 7px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.5;
        }

        .alert {
            border-radius: 10px;
            font-size: 12px;
            border: 0;
        }

        /* Mobile */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-menu-button {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 9px;
            align-items: center;
            justify-content: center;
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1050;
            background: rgba(15,23,42,0.45);
        }

        @media (max-width: 1100px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-button {
                display: inline-flex;
            }

            .mobile-overlay.show {
                display: block;
            }
        }

        @media (max-width: 767px) {

            .topbar {
                height: 68px;
                padding: 0 15px;
            }

            .page-heading h1 {
                font-size: 17px;
            }

            .page-heading p,
            .date-box,
            .topbar-profile div {
                display: none;
            }

            .topbar-profile {
                border-left: 0;
                padding-left: 0;
            }

            .topbar-profile img {
                width: 37px;
                height: 37px;
            }

            .content {
                padding: 18px 14px 105px;
            }

            .profile-header {
                padding: 20px;
                flex-direction: column;
                text-align: center;
            }

            .profile-header-photo {
                width: 85px;
                height: 85px;
            }

            .card-body-custom {
                padding: 18px;
            }

            .photo-upload-area {
                flex-direction: column;
                text-align: center;
            }

            .photo-preview {
                width: 115px;
                height: 115px;
            }

            .photo-upload-content {
                width: 100%;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                background: rgba(255,255,255,0.98);
                backdrop-filter: blur(12px);
                border-top: 1px solid var(--border);
                z-index: 1200;
                padding-bottom: env(safe-area-inset-bottom);
            }

            .bottom-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                color: #6b7280;
                text-decoration: none;
                font-size: 9px;
                font-weight: 600;
            }

            .bottom-nav-item i {
                font-size: 19px;
            }

            .bottom-nav-item.active {
                color: var(--primary);
            }
        }

    </style>

</head>

<body>

<!-- Sidebar -->

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS Logo"
            class="brand-logo"
            onerror="this.src='../public/images/default-avatar.png'"
        >

        <div class="brand-text">

            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                School Management
            </div>

        </div>

    </div>

    <nav class="sidebar-menu">

        <div class="sidebar-section-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="menu-item"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <div class="sidebar-section-title">
            Library
        </div>

        <a
            href="books.php"
            class="menu-item"
        >
            <i class="bi bi-book-fill"></i>
            <span>Books</span>
        </a>

        <a
            href="categories.php"
            class="menu-item"
        >
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a
            href="study-attendance.php"
            class="menu-item"
        >
            <i class="bi bi-person-check-fill"></i>
            <span>Study Attendance</span>
        </a>

        <a
            href="borrow-book.php"
            class="menu-item"
        >
            <i class="bi bi-journal-plus"></i>
            <span>Borrow Book</span>
        </a>

        <a
            href="return-book.php"
            class="menu-item"
        >
            <i class="bi bi-journal-check"></i>
            <span>Return Book</span>
        </a>

        <a
            href="borrowing-control.php"
            class="menu-item"
        >
            <i class="bi bi-arrow-left-right"></i>
            <span>Borrowing Control</span>
        </a>

        <a
            href="overdue-books.php"
            class="menu-item"
        >
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Overdue Books</span>
        </a>

        <a
            href="reservations.php"
            class="menu-item"
        >
            <i class="bi bi-bookmark-star-fill"></i>
            <span>Reservations</span>
        </a>

        <a
            href="fines.php"
            class="menu-item"
        >
            <i class="bi bi-cash-stack"></i>
            <span>Fines</span>
        </a>

        <a
            href="reports.php"
            class="menu-item"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

        <div class="sidebar-section-title">
            Account
        </div>

        <a
            href="profile.php"
            class="menu-item active"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="menu-item logout-item"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-footer">

        <div class="sidebar-user">

            <img
                src="<?= e($photoUrl) ?>"
                alt="Librarian"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div style="min-width:0;">

                <div class="sidebar-user-name">
                    <?= e($fullName) ?>
                </div>

                <div class="sidebar-user-role">
                    Librarian
                </div>

            </div>

        </div>

    </div>

</aside>


<div
    class="mobile-overlay"
    id="mobileOverlay"
></div>


<!-- Main -->

<main class="main-content">

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open menu"
            >
                <i class="bi bi-list fs-5"></i>
            </button>

            <div class="page-heading">

                <h1>
                    Profile
                </h1>

                <p>
                    Manage your librarian account
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="date-box">

                <div class="date-day">
                    <?= e($dayName) ?>
                </div>

                <div class="date-eth">
                    <?= e($todayFormatted) ?>
                </div>

            </div>

            <div class="topbar-profile">

                <img
                    src="<?= e($photoUrl) ?>"
                    alt="<?= e($fullName) ?>"
                    onerror="this.src='../public/images/default-avatar.png'"
                >

                <div>

                    <div class="topbar-profile-name">
                        <?= e($fullName) ?>
                    </div>

                    <div class="topbar-profile-role">
                        Librarian
                    </div>

                </div>

            </div>

        </div>

    </header>


    <section class="content">

        <!-- Profile Header -->

        <div class="profile-header">

            <img
                src="<?= e($photoUrl) ?>"
                alt="<?= e($fullName) ?>"
                class="profile-header-photo"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div>

                <h2>
                    <?= e($fullName) ?>
                </h2>

                <p>
                    BKHS Library
                </p>

                <div class="role-badge">

                    <i class="bi bi-book"></i>

                    Librarian

                </div>

            </div>

        </div>


        <!-- Messages -->

        <?php if ($successMessage !== ''): ?>

            <div
                class="alert alert-success d-flex align-items-center gap-2 mb-3"
                role="alert"
            >

                <i class="bi bi-check-circle-fill"></i>

                <span>
                    <?= e($successMessage) ?>
                </span>

            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ''): ?>

            <div
                class="alert alert-danger d-flex align-items-center gap-2 mb-3"
                role="alert"
            >

                <i class="bi bi-exclamation-circle-fill"></i>

                <span>
                    <?= e($errorMessage) ?>
                </span>

            </div>

        <?php endif; ?>


        <div class="row g-3">

            <!-- Personal Information -->

            <div class="col-lg-7">

                <div class="profile-card">

                    <div class="card-header-custom">

                        <h3>
                            Personal Information
                        </h3>

                        <p>
                            Update your basic account information.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <form
                            method="POST"
                            action=""
                        >

                            <div class="row g-3">

                                <div class="col-12">

                                    <label class="form-label">
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        name="full_name"
                                        class="form-control"
                                        value="<?= e($fullName) ?>"
                                        maxlength="150"
                                        required
                                    >

                                </div>


                                <div class="col-md-6">

                                    <label class="form-label">
                                        Email
                                    </label>

                                    <input
                                        type="email"
                                        name="email"
                                        class="form-control"
                                        value="<?= e($email) ?>"
                                        maxlength="150"
                                        placeholder="Enter email address"
                                    >

                                </div>


                                <div class="col-md-6">

                                    <label class="form-label">
                                        Phone
                                    </label>

                                    <input
                                        type="text"
                                        name="phone"
                                        class="form-control"
                                        value="<?= e($phone) ?>"
                                        maxlength="150"
                                        placeholder="Enter phone number"
                                    >

                                </div>


                                <div class="col-12 pt-2">

                                    <button
                                        type="submit"
                                        name="update_profile"
                                        value="1"
                                        class="btn-primary-custom"
                                    >

                                        <i class="bi bi-check2-circle me-1"></i>

                                        Save Changes

                                    </button>

                                </div>

                            </div>

                        </form>

                    </div>

                </div>


                <!-- Profile Photo -->

                <div class="profile-card">

                    <div class="card-header-custom">

                        <h3>
                            Profile Photo
                        </h3>

                        <p>
                            Upload a photo that will be displayed across your librarian portal.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <form
                            method="POST"
                            action=""
                            enctype="multipart/form-data"
                        >

                            <div class="photo-upload-area">

                                <img
                                    src="<?= e($photoUrl) ?>"
                                    alt="Profile Photo"
                                    class="photo-preview"
                                    id="photoPreview"
                                    onerror="this.src='../public/images/default-avatar.png'"
                                >

                                <div class="photo-upload-content">

                                    <label class="form-label">
                                        Choose Profile Photo
                                    </label>

                                    <input
                                        type="file"
                                        name="profile_photo"
                                        id="profilePhoto"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                        required
                                    >

                                    <div class="photo-help">
                                        JPG, JPEG, PNG or WEBP.
                                        Maximum file size: 2 MB.
                                    </div>

                                    <div class="mt-3">

                                        <button
                                            type="submit"
                                            name="upload_photo"
                                            value="1"
                                            class="btn-primary-custom"
                                        >

                                            <i class="bi bi-camera-fill me-1"></i>

                                            Upload Photo

                                        </button>

                                    </div>

                                </div>

                            </div>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Account Information -->

            <div class="col-lg-5">

                <div class="profile-card">

                    <div class="card-header-custom">

                        <h3>
                            Account Information
                        </h3>

                        <p>
                            Current account details.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="info-row">

                            <span class="info-label">
                                Role
                            </span>

                            <span class="info-value">
                                Librarian
                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Account ID
                            </span>

                            <span class="info-value">
                                #<?= $librarianId ?>
                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Email
                            </span>

                            <span class="info-value">

                                <?= e(
                                    $email !== ''
                                        ? $email
                                        : 'Not provided'
                                ) ?>

                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Phone
                            </span>

                            <span class="info-value">

                                <?= e(
                                    $phone !== ''
                                        ? $phone
                                        : 'Not provided'
                                ) ?>

                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Today
                            </span>

                            <span class="info-value">
                                <?= e($todayFormatted) ?>
                            </span>

                        </div>

                    </div>

                </div>


                <!-- Photo Information -->

                <div class="profile-card">

                    <div class="card-header-custom">

                        <h3>
                            Photo Information
                        </h3>

                        <p>
                            Profile photo requirements.
                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="info-row">

                            <span class="info-label">
                                Status
                            </span>

                            <span class="info-value">

                                <?php if ($photoPath !== ''): ?>

                                    <span class="text-success">

                                        <i class="bi bi-check-circle-fill me-1"></i>

                                        Uploaded

                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">

                                        <i class="bi bi-image me-1"></i>

                                        Not uploaded

                                    </span>

                                <?php endif; ?>

                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Maximum Size
                            </span>

                            <span class="info-value">
                                2 MB
                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Formats
                            </span>

                            <span class="info-value">
                                JPG, PNG, WEBP
                            </span>

                        </div>


                        <div class="info-row">

                            <span class="info-label">
                                Used In
                            </span>

                            <span class="info-value">
                                Portal Profile
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>


<!-- Mobile Bottom Navigation -->

<nav class="mobile-bottom-nav">

    <a
        href="dashboard.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Dashboard
        </span>

    </a>


    <a
        href="books.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-book-fill"></i>

        <span>
            Books
        </span>

    </a>


    <a
        href="study-attendance.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-person-check-fill"></i>

        <span>
            Study
        </span>

    </a>


    <a
        href="borrow-book.php"
        class="bottom-nav-item"
    >

        <i class="bi bi-journal-plus"></i>

        <span>
            Borrow
        </span>

    </a>


    <a
        href="profile.php"
        class="bottom-nav-item active"
    >

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>

</nav>


<script>

/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('mobileOverlay');

const menuButton =
    document.getElementById('mobileMenuButton');


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
    .querySelectorAll('.menu-item')
    .forEach(function (item) {

        item.addEventListener(
            'click',
            function () {

                if (
                    window.innerWidth <= 1100
                ) {

                    closeSidebar();

                }

            }
        );

    });


window.addEventListener(
    'resize',
    function () {

        if (
            window.innerWidth > 1100
        ) {

            closeSidebar();

        }

    }
);


/*
|--------------------------------------------------------------------------
| Photo Preview
|--------------------------------------------------------------------------
*/

const photoInput =
    document.getElementById('profilePhoto');

const photoPreview =
    document.getElementById('photoPreview');


if (
    photoInput &&
    photoPreview
) {

    photoInput.addEventListener(
        'change',
        function (event) {

            const file =
                event.target.files[0];

            if (!file) {
                return;
            }

            if (
                !file.type.startsWith('image/')
            ) {

                return;
            }

            const reader =
                new FileReader();

            reader.onload =
                function (e) {

                    photoPreview.src =
                        e.target.result;

                };

            reader.readAsDataURL(file);

        }
    );

}

</script>

</body>

</html>