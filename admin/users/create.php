<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Admin Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/AuditLogger.php';

/*
|--------------------------------------------------------------------------
| Allowed Roles
|--------------------------------------------------------------------------
*/

$roles = [
    'Admin',
    'Principal',
    'Teacher',
    'Registrar',
    'Librarian'
];

$errors = [];

$full_name = '';
$email = '';
$phone = '';
$role = '';

/*
|--------------------------------------------------------------------------
| Signature Roles
|--------------------------------------------------------------------------
*/

$signatureRoles = [
    'Admin',
    'Principal',
    'Teacher',
    'Registrar',
    'Librarian'
];

/*
|--------------------------------------------------------------------------
| Signature Upload Directory
|--------------------------------------------------------------------------
*/

$signatureDirectory = '../../uploads/signatures/';

if (!is_dir($signatureDirectory)) {
    @mkdir($signatureDirectory, 0755, true);
}

/*
|--------------------------------------------------------------------------
| Helper: Save Drawn Signature
|--------------------------------------------------------------------------
*/

function saveDrawnSignature(
    string $base64Data,
    string $directory,
    int $userId
): ?string {

    if ($base64Data === '') {
        return null;
    }

    if (
        !preg_match(
            '/^data:image\/png;base64,(.+)$/',
            $base64Data,
            $matches
        )
    ) {
        return null;
    }

    $imageData = base64_decode(
        $matches[1],
        true
    );

    if ($imageData === false) {
        return null;
    }

    if (!function_exists('getimagesizefromstring')) {
        return null;
    }

    $imageInfo = @getimagesizefromstring($imageData);

    if ($imageInfo === false) {
        return null;
    }

    if (($imageInfo['mime'] ?? '') !== 'image/png') {
        return null;
    }

    if (!is_dir($directory)) {
        if (!@mkdir($directory, 0755, true)) {
            return null;
        }
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = uniqid('', true);
    }

    $filename =
        'user_' .
        $userId .
        '_' .
        time() .
        '_' .
        $random .
        '.png';

    $filePath =
        rtrim(
            $directory,
            '/\\'
        ) .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        file_put_contents(
            $filePath,
            $imageData
        ) === false
    ) {
        return null;
    }

    return 'uploads/signatures/' . $filename;
}

/*
|--------------------------------------------------------------------------
| Helper: Process Uploaded Signature
|--------------------------------------------------------------------------
*/

function processUploadedSignature(
    array $file,
    string $directory,
    int $userId
): ?string {

    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        return null;
    }

    if (
        !isset($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        return null;
    }

    $maxSize = 5 * 1024 * 1024;

    if (($file['size'] ?? 0) > $maxSize) {
        return null;
    }

    if (!function_exists('getimagesize')) {
        return null;
    }

    $imageInfo = @getimagesize(
        $file['tmp_name']
    );

    if ($imageInfo === false) {
        return null;
    }

    $mime = $imageInfo['mime'] ?? '';

    if (
        !in_array(
            $mime,
            [
                'image/jpeg',
                'image/png'
            ],
            true
        )
    ) {
        return null;
    }

    if (
        !function_exists('imagecreatetruecolor') ||
        !function_exists('imagecolorallocatealpha') ||
        !function_exists('imagepng') ||
        !function_exists('imagealphablending') ||
        !function_exists('imagesavealpha') ||
        !function_exists('imagefill') ||
        !function_exists('imagecolorat') ||
        !function_exists('imagesetpixel') ||
        !function_exists('imagesx') ||
        !function_exists('imagesy') ||
        !function_exists('imagedestroy')
    ) {
        return null;
    }

    if ($mime === 'image/png') {

        if (!function_exists('imagecreatefrompng')) {
            return null;
        }

        $source = @imagecreatefrompng(
            $file['tmp_name']
        );

    } else {

        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }

        $source = @imagecreatefromjpeg(
            $file['tmp_name']
        );
    }

    if ($source === false) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);

    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return null;
    }

    $output = imagecreatetruecolor(
        $width,
        $height
    );

    if ($output === false) {
        imagedestroy($source);
        return null;
    }

    imagealphablending(
        $output,
        false
    );

    imagesavealpha(
        $output,
        true
    );

    $transparent = imagecolorallocatealpha(
        $output,
        255,
        255,
        255,
        127
    );

    imagefill(
        $output,
        0,
        0,
        $transparent
    );

    imagealphablending(
        $output,
        true
    );

    for ($y = 0; $y < $height; $y++) {

        for ($x = 0; $x < $width; $x++) {

            $rgb = imagecolorat(
                $source,
                $x,
                $y
            );

            $red = ($rgb >> 16) & 0xFF;
            $green = ($rgb >> 8) & 0xFF;
            $blue = $rgb & 0xFF;

            $brightness =
                0.299 * $red +
                0.587 * $green +
                0.114 * $blue;

            if ($brightness >= 245) {

                imagesetpixel(
                    $output,
                    $x,
                    $y,
                    $transparent
                );

            } elseif ($brightness >= 180) {

                $alpha = (int) (
                    127 -
                    (
                        (245 - $brightness) /
                        65
                    ) * 127
                );

                $alpha = max(
                    0,
                    min(
                        127,
                        $alpha
                    )
                );

                $color = imagecolorallocatealpha(
                    $output,
                    $red,
                    $green,
                    $blue,
                    $alpha
                );

                imagesetpixel(
                    $output,
                    $x,
                    $y,
                    $color
                );

            } else {

                $color = imagecolorallocatealpha(
                    $output,
                    $red,
                    $green,
                    $blue,
                    0
                );

                imagesetpixel(
                    $output,
                    $x,
                    $y,
                    $color
                );
            }
        }
    }

    if (!is_dir($directory)) {

        if (!@mkdir($directory, 0755, true)) {

            imagedestroy($source);
            imagedestroy($output);

            return null;
        }
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = uniqid('', true);
    }

    $filename =
        'user_' .
        $userId .
        '_' .
        time() .
        '_' .
        $random .
        '.png';

    $filePath =
        rtrim(
            $directory,
            '/\\'
        ) .
        DIRECTORY_SEPARATOR .
        $filename;

    $saved = imagepng(
        $output,
        $filePath,
        6
    );

    imagedestroy($source);
    imagedestroy($output);

    if (!$saved) {
        return null;
    }

    return 'uploads/signatures/' . $filename;
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim(
        $_POST['full_name'] ?? ''
    );

    $email = trim(
        $_POST['email'] ?? ''
    );

    $phone = trim(
        $_POST['phone'] ?? ''
    );

    $role = trim(
        $_POST['role'] ?? ''
    );

    $password = $_POST['password'] ?? '';

    $confirm_password =
        $_POST['confirm_password'] ?? '';

    $drawn_signature =
        $_POST['drawn_signature'] ?? '';

    $signatureMethod =
        $_POST['signature_method'] ?? 'none';

    $signatureFile =
        $_FILES['signature_image'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Full Name Validation
    |--------------------------------------------------------------------------
    */

    if ($full_name === '') {

        $errors[] =
            'Full name is required.';

    } elseif (strlen($full_name) < 3) {

        $errors[] =
            'Full name must be at least 3 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Email Validation
    |--------------------------------------------------------------------------
    */

    if ($email === '') {

        $errors[] =
            'Email is required.';

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            'Please enter a valid email address.';
    }

    /*
    |--------------------------------------------------------------------------
    | Phone Validation
    |--------------------------------------------------------------------------
    */

    if ($phone === '') {

        $errors[] =
            'Phone number is required.';

    } elseif (
        !preg_match(
            '/^09[0-9]{8}$/',
            $phone
        )
    ) {

        $errors[] =
            'Phone number must be exactly 10 digits and start with 09.';
    }

    /*
    |--------------------------------------------------------------------------
    | Role Validation
    |--------------------------------------------------------------------------
    */

    if ($role === '') {

        $errors[] =
            'Role is required.';

    } elseif (
        !in_array(
            $role,
            $roles,
            true
        )
    ) {

        $errors[] =
            'Please select a valid role.';
    }

    /*
    |--------------------------------------------------------------------------
    | Password Validation
    |--------------------------------------------------------------------------
    */

    if ($password === '') {

        $errors[] =
            'Password is required.';

    } elseif (strlen($password) < 6) {

        $errors[] =
            'Password must be at least 6 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Password Validation
    |--------------------------------------------------------------------------
    */

    if ($confirm_password === '') {

        $errors[] =
            'Confirm password is required.';

    } elseif (
        $password !== $confirm_password
    ) {

        $errors[] =
            'Password and confirm password must exactly match.';
    }

    /*
    |--------------------------------------------------------------------------
    | Signature Validation
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $role,
            $signatureRoles,
            true
        )
    ) {

        if ($signatureMethod === 'draw') {

            if ($drawn_signature !== '') {

                if (
                    !preg_match(
                        '/^data:image\/png;base64,(.+)$/',
                        $drawn_signature
                    )
                ) {

                    $errors[] =
                        'The drawn signature is invalid.';
                }
            }

        } elseif ($signatureMethod === 'upload') {

            if (
                is_array($signatureFile) &&
                isset($signatureFile['error']) &&
                $signatureFile['error'] !== UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $signatureFile['error'] !==
                    UPLOAD_ERR_OK
                ) {

                    $errors[] =
                        'The signature image could not be uploaded.';
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Check Existing Email And Phone
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $stmt = $conn->prepare(
            "SELECT id, email, phone
             FROM users
             WHERE email = ? OR phone = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            'ss',
            $email,
            $phone
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $existingUser =
                $result->fetch_assoc();

            if (
                isset($existingUser['email']) &&
                strcasecmp(
                    (string) $existingUser['email'],
                    $email
                ) === 0
            ) {

                $errors[] =
                    'A user with this email already exists.';
            }

            if (
                isset($existingUser['phone']) &&
                $existingUser['phone'] === $phone
            ) {

                $errors[] =
                    'A user with this phone number already exists.';
            }
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Create User
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $hashed_password =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $is_logged_in = 0;
        $signature_path = null;
        $stmt = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | Insert User
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "INSERT INTO users
                (
                    full_name,
                    email,
                    phone,
                    password,
                    signature_path,
                    role,
                    is_logged_in
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                'ssssssi',
                $full_name,
                $email,
                $phone,
                $hashed_password,
                $signature_path,
                $role,
                $is_logged_in
            );

            $stmt->execute();

            $userId =
                (int) $conn->insert_id;

            $stmt->close();
            $stmt = null;

            /*
            |--------------------------------------------------------------------------
            | Process Signature
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $role,
                    $signatureRoles,
                    true
                )
            ) {

                /*
                |--------------------------------------------------------------------------
                | Draw Signature
                |--------------------------------------------------------------------------
                */

                if (
                    $signatureMethod === 'draw' &&
                    $drawn_signature !== ''
                ) {

                    $signature_path =
                        saveDrawnSignature(
                            $drawn_signature,
                            $signatureDirectory,
                            $userId
                        );

                /*
                |--------------------------------------------------------------------------
                | Upload Signature
                |--------------------------------------------------------------------------
                */

                } elseif (
                    $signatureMethod === 'upload' &&
                    is_array($signatureFile) &&
                    isset($signatureFile['error']) &&
                    $signatureFile['error'] !== UPLOAD_ERR_NO_FILE
                ) {

                    $signature_path =
                        processUploadedSignature(
                            $signatureFile,
                            $signatureDirectory,
                            $userId
                        );
                }

                /*
                |--------------------------------------------------------------------------
                | Update Signature Path
                |--------------------------------------------------------------------------
                */

                if ($signature_path !== null) {

                    $stmt = $conn->prepare(
                        "UPDATE users
                         SET signature_path = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        'si',
                        $signature_path,
                        $userId
                    );

                    $stmt->execute();

                    $stmt->close();
                    $stmt = null;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Audit Log
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Password and password hash are intentionally NOT logged.
            |
            */

            AuditLogger::log(
                $conn,
                'USER_CREATED',
                'Created a new user account: ' .
                    $full_name .
                    ' (' .
                    $role .
                    ')',
                'user',
                (string) $userId,
                null,
                [
                    'user_id' => $userId,
                    'full_name' => $full_name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'signature_path' => $signature_path
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            $_SESSION['success'] =
                'User created successfully.';

            header('Location: index.php');
            exit;

        } catch (mysqli_sql_exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Close Statement
            |--------------------------------------------------------------------------
            */

            if (
                $stmt instanceof mysqli_stmt
            ) {

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate Entry
            |--------------------------------------------------------------------------
            */

            if ($e->getCode() === 1062) {

                $message =
                    $e->getMessage();

                if (
                    str_contains(
                        strtolower($message),
                        'phone'
                    )
                ) {

                    $errors[] =
                        'A user with this phone number already exists.';

                } elseif (
                    str_contains(
                        strtolower($message),
                        'email'
                    )
                ) {

                    $errors[] =
                        'A user with this email already exists.';

                } else {

                    $errors[] =
                        'A user with the provided information already exists.';
                }

            } else {

                $errors[] =
                    'Unable to create the user. Please try again.';
            }
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Create User | Admin</title>

<link
    rel="icon"
    type="image/webp"
    href="/BKHS/public/logo.webp?v=1"
>

<link
    rel="shortcut icon"
    type="image/webp"
    href="/BKHS/public/logo.webp?v=1"
>

<link
    rel="apple-touch-icon"
    href="/BKHS/public/logo.webp?v=1"
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
    rel="stylesheet"
    href="../../public/css/admin-users.css"
>

<style>

    .form-page {
        max-width: 1000px;
        margin: 0 auto;
    }

    .form-card {
        background: #fff;
        border: 1px solid #e8ebf0;
        border-radius: 18px;
        padding: 28px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06);
    }

    .form-section {
        margin-bottom: 30px;
    }

    .form-section:last-child {
        margin-bottom: 0;
    }

    .form-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        font-size: 17px;
        font-weight: 700;
        color: #172033;
    }

    .form-section-title i {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #eef4ff;
        color: #2563eb;
    }

    .form-label {
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
    }

    .form-control,
    .form-select {
        min-height: 48px;
        border-radius: 10px;
        border: 1px solid #d9dee7;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
    }

    .password-wrapper {
        position: relative;
    }

    .password-wrapper .form-control {
        padding-right: 48px;
    }

    .password-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #64748b;
    }

    .form-help {
        font-size: 13px;
        color: #6b7280;
        margin-top: 6px;
    }

    .required {
        color: #dc2626;
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding-top: 24px;
        border-top: 1px solid #edf0f4;
    }

    .signature-section {
        display: none;
    }

    .signature-methods {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .signature-method {
        position: relative;
    }

    .signature-method input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .signature-method label {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 11px 16px;
        border: 1px solid #d9dee7;
        border-radius: 10px;
        background: #fff;
        color: #475569;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .signature-method label:hover {
        border-color: #2563eb;
        color: #2563eb;
    }

    .signature-method input:checked + label {
        border-color: #2563eb;
        background: #eef4ff;
        color: #2563eb;
    }

    .signature-panel {
        display: none;
    }

    .signature-panel.active {
        display: block;
    }

    .signature-pad-wrapper {
        position: relative;
        width: 100%;
        border: 1px solid #d9dee7;
        border-radius: 12px;
        background: #fff;
        overflow: hidden;
    }

    #signatureCanvas {
        display: block;
        width: 100%;
        height: 220px;
        cursor: crosshair;
        touch-action: none;
        background: #fff;
    }

    .signature-pad-label {
        position: absolute;
        top: 12px;
        left: 15px;
        color: #94a3b8;
        font-size: 13px;
        pointer-events: none;
    }

    .signature-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-top: 10px;
    }

    .signature-upload-box {
        border: 2px dashed #d9dee7;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        background: #f8fafc;
    }

    .signature-upload-box i {
        font-size: 34px;
        color: #64748b;
    }

    .signature-upload-box p {
        margin: 8px 0 14px;
        color: #64748b;
        font-size: 14px;
    }

    .signature-preview {
        display: none;
        margin-top: 15px;
        padding: 15px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background:
            linear-gradient(
                45deg,
                #f1f5f9 25%,
                transparent 25%
            ),
            linear-gradient(
                -45deg,
                #f1f5f9 25%,
                transparent 25%
            ),
            linear-gradient(
                45deg,
                transparent 75%,
                #f1f5f9 75%
            ),
            linear-gradient(
                -45deg,
                transparent 75%,
                #f1f5f9 75%
            );
        background-size: 20px 20px;
        background-position:
            0 0,
            0 10px,
            10px -10px,
            -10px 0;
    }

    .signature-preview img {
        max-width: 100%;
        max-height: 160px;
        display: block;
        margin: 0 auto;
    }

    .signature-note {
        font-size: 13px;
        color: #64748b;
        margin-top: 8px;
    }

    .signature-role-note {
        padding: 12px 14px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 13px;
        margin-bottom: 18px;
    }

    @media (max-width: 576px) {

        .form-card {
            padding: 20px;
            border-radius: 14px;
        }

        .form-actions {
            flex-direction: column-reverse;
        }

        .form-actions .btn {
            width: 100%;
        }

        #signatureCanvas {
            height: 180px;
        }

        .signature-methods {
            flex-direction: column;
        }

        .signature-method label {
            width: 100%;
        }

        .signature-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .signature-actions .btn {
            width: 100%;
        }
    }

</style>
```

</head>

<body>

<div class="admin-layout">

```
<aside class="admin-sidebar">

    <div class="sidebar-brand">

        <div class="brand-mark">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div class="brand-text">
            <strong>BKHS</strong>
            <span>School Management</span>
        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>

        <a
            href="../dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="index.php"
            class="sidebar-link active"
        >
            <i class="bi bi-people"></i>
            <span>Users</span>
        </a>

        <a
            href="../students/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-mortarboard"></i>
            <span>Students</span>
        </a>

        <a
            href="../teachers/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Teachers</span>
        </a>

    </nav>

    <div class="sidebar-footer">

        <a
            href="../../auth/logout.php"
            class="sidebar-link logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<div class="sidebar-overlay"></div>

<main class="admin-main">

    <header class="admin-topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="topbar-title">
                    Create User
                </h1>

                <p class="mb-0 text-muted">
                    Add a new staff or system user
                </p>

            </div>

        </div>

        <div class="topbar-actions">

            <div class="admin-profile">

                <div class="profile-avatar">

                    <?= htmlspecialchars(
                        strtoupper(
                            substr(
                                $_SESSION['full_name'] ?? 'A',
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>

                <div class="profile-info">

                    <strong>
                        <?= htmlspecialchars(
                            $_SESSION['full_name'] ?? 'Admin'
                        ) ?>
                    </strong>

                    <span>
                        Administrator
                    </span>

                </div>

            </div>

        </div>

    </header>

    <section class="admin-content">

        <div class="form-page">

            <?php if (!empty($errors)): ?>

                <div
                    class="alert alert-danger border-0 shadow-sm"
                    role="alert"
                >

                    <div class="d-flex gap-2">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <div>

                            <strong>
                                Please fix the following:
                            </strong>

                            <ul class="mb-0 mt-2">

                                <?php foreach ($errors as $error): ?>

                                    <li>
                                        <?= htmlspecialchars($error) ?>
                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

            <div class="form-card">

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    novalidate
                    id="createUserForm"
                >

                    <div class="form-section">

                        <div class="form-section-title">

                            <i class="bi bi-person"></i>

                            <span>
                                Personal Information
                            </span>

                        </div>

                        <div class="row g-4">

                            <div class="col-12">

                                <label class="form-label">

                                    Full Name

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control"
                                    value="<?= htmlspecialchars($full_name) ?>"
                                    placeholder="Enter full name"
                                    required
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">

                                    Email

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars($email) ?>"
                                    placeholder="example@email.com"
                                    required
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">

                                    Phone Number

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="tel"
                                    name="phone"
                                    class="form-control"
                                    value="<?= htmlspecialchars($phone) ?>"
                                    placeholder="0912345678"
                                    pattern="09[0-9]{8}"
                                    maxlength="10"
                                    minlength="10"
                                    inputmode="numeric"
                                    required
                                >

                                <div class="form-help">
                                    Must be 10 digits and start with 09.
                                </div>

                            </div>

                            <div class="col-12">

                                <label class="form-label">

                                    Role

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="role"
                                    id="role"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select role
                                    </option>

                                    <?php foreach ($roles as $item): ?>

                                        <option
                                            value="<?= htmlspecialchars($item) ?>"
                                            <?= $role === $item ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($item) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <div class="form-help">
                                    Student and Parent accounts are managed by the Registrar.
                                </div>

                            </div>

                        </div>

                    </div>

                    <div
                        class="form-section signature-section"
                        id="signatureSection"
                    >

                        <div class="form-section-title">

                            <i class="bi bi-pen"></i>

                            <span>
                                Signature
                            </span>

                        </div>

                        <div class="form-help mb-3">

                            Add the user's signature for report cards,
                            certificates, attendance documents,
                            and other official school documents.

                        </div>

                        <div class="signature-role-note">

                            <i class="bi bi-info-circle me-1"></i>

                            Signature is available for
                            <strong>Admin</strong>,
                            <strong>Principal</strong>,
                            <strong>Teacher</strong>,
                            <strong>Registrar</strong>, and
                            <strong>Librarian</strong> accounts.

                        </div>

                        <input
                            type="hidden"
                            name="signature_method"
                            id="signatureMethod"
                            value="none"
                        >

                        <input
                            type="hidden"
                            name="drawn_signature"
                            id="drawnSignature"
                            value=""
                        >

                        <div class="signature-methods">

                            <div class="signature-method">

                                <input
                                    type="radio"
                                    name="signature_choice"
                                    id="drawSignatureChoice"
                                    value="draw"
                                >

                                <label
                                    for="drawSignatureChoice"
                                >

                                    <i class="bi bi-pencil"></i>

                                    Draw Signature

                                </label>

                            </div>

                            <div class="signature-method">

                                <input
                                    type="radio"
                                    name="signature_choice"
                                    id="uploadSignatureChoice"
                                    value="upload"
                                >

                                <label
                                    for="uploadSignatureChoice"
                                >

                                    <i class="bi bi-upload"></i>

                                    Upload Signature

                                </label>

                            </div>

                        </div>

                        <div
                            class="signature-panel"
                            id="drawPanel"
                        >

                            <div class="signature-pad-wrapper">

                                <span class="signature-pad-label">
                                    Sign here
                                </span>

                                <canvas
                                    id="signatureCanvas"
                                ></canvas>

                            </div>

                            <div class="signature-actions">

                                <span class="signature-note">
                                    Use your mouse, touchscreen,
                                    or stylus.
                                </span>

                                <button
                                    type="button"
                                    class="btn btn-light border"
                                    id="clearSignature"
                                >

                                    <i class="bi bi-eraser me-1"></i>

                                    Clear

                                </button>

                            </div>

                        </div>

                        <div
                            class="signature-panel"
                            id="uploadPanel"
                        >

                            <div class="signature-upload-box">

                                <i class="bi bi-image"></i>

                                <p>
                                    Upload a clear signature image.
                                </p>

                                <input
                                    type="file"
                                    name="signature_image"
                                    id="signatureImage"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                >

                                <div class="signature-note">

                                    JPG or PNG, maximum 5 MB.
                                    White backgrounds will be removed automatically.

                                </div>

                            </div>

                            <div
                                class="signature-preview"
                                id="signaturePreview"
                            >

                                <img
                                    id="signaturePreviewImage"
                                    src=""
                                    alt="Signature preview"
                                >

                            </div>

                        </div>

                    </div>

                    <div class="form-section">

                        <div class="form-section-title">

                            <i class="bi bi-shield-lock"></i>

                            <span>
                                Account Security
                            </span>

                        </div>

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label class="form-label">

                                    Password

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <div class="password-wrapper">

                                    <input
                                        type="password"
                                        name="password"
                                        id="password"
                                        class="form-control"
                                        placeholder="Enter password"
                                        minlength="6"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-toggle"
                                        onclick="togglePassword('password', this)"
                                    >

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                                <div class="form-help">
                                    Password must be at least 6 characters.
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">

                                    Confirm Password

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <div class="password-wrapper">

                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirm_password"
                                        class="form-control"
                                        placeholder="Confirm password"
                                        minlength="6"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-toggle"
                                        onclick="togglePassword('confirm_password', this)"
                                    >

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="form-actions">

                        <a
                            href="index.php"
                            class="btn btn-light border px-4"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary px-4"
                        >

                            <i class="bi bi-person-plus me-2"></i>

                            Create User

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </section>

</main>
```

</div>

<script>

function togglePassword(id, button) {

    const input =
        document.getElementById(id);

    const icon =
        button.querySelector('i');

    if (input.type === 'password') {

        input.type = 'text';

        icon.classList.remove(
            'bi-eye'
        );

        icon.classList.add(
            'bi-eye-slash'
        );

    } else {

        input.type = 'password';

        icon.classList.remove(
            'bi-eye-slash'
        );

        icon.classList.add(
            'bi-eye'
        );
    }
}

const roleSelect =
    document.getElementById('role');

const signatureSection =
    document.getElementById('signatureSection');

const drawChoice =
    document.getElementById('drawSignatureChoice');

const uploadChoice =
    document.getElementById('uploadSignatureChoice');

const drawPanel =
    document.getElementById('drawPanel');

const uploadPanel =
    document.getElementById('uploadPanel');

const signatureMethod =
    document.getElementById('signatureMethod');

const drawnSignature =
    document.getElementById('drawnSignature');

function updateSignatureVisibility() {

    const role =
        roleSelect.value.toLowerCase();

    const showSignature =
        role === 'admin' ||
        role === 'principal' ||
        role === 'teacher' ||
        role === 'registrar' ||
        role === 'librarian';

    if (showSignature) {

        signatureSection.style.display =
            'block';

    } else {

        signatureSection.style.display =
            'none';

        drawChoice.checked =
            false;

        uploadChoice.checked =
            false;

        drawPanel.classList.remove(
            'active'
        );

        uploadPanel.classList.remove(
            'active'
        );

        signatureMethod.value =
            'none';

        drawnSignature.value =
            '';
    }
}

roleSelect.addEventListener(
    'change',
    updateSignatureVisibility
);

updateSignatureVisibility();

const canvas =
    document.getElementById(
        'signatureCanvas'
    );

const ctx =
    canvas.getContext('2d');

let drawing = false;
let hasSignature = false;

function resizeCanvas() {

    const rect =
        canvas.getBoundingClientRect();

    if (
        rect.width <= 0 ||
        rect.height <= 0
    ) {
        return;
    }

    const ratio =
        Math.max(
            window.devicePixelRatio || 1,
            1
        );

    let existingImage = null;

    if (
        hasSignature &&
        canvas.width > 0 &&
        canvas.height > 0
    ) {

        existingImage =
            canvas.toDataURL(
                'image/png'
            );
    }

    canvas.width =
        Math.round(
            rect.width * ratio
        );

    canvas.height =
        Math.round(
            rect.height * ratio
        );

    ctx.setTransform(
        ratio,
        0,
        0,
        ratio,
        0,
        0
    );

    ctx.lineWidth =
        2.2;

    ctx.lineCap =
        'round';

    ctx.lineJoin =
        'round';

    ctx.strokeStyle =
        '#111827';

    if (existingImage) {

        const image =
            new Image();

        image.onload =
            function () {

                ctx.drawImage(
                    image,
                    0,
                    0,
                    rect.width,
                    rect.height
                );
            };

        image.src =
            existingImage;
    }
}

resizeCanvas();

window.addEventListener(
    'resize',
    function () {
        resizeCanvas();
    }
);

drawChoice.addEventListener(
    'change',
    function () {

        if (!this.checked) {
            return;
        }

        signatureMethod.value =
            'draw';

        drawPanel.classList.add(
            'active'
        );

        uploadPanel.classList.remove(
            'active'
        );

        requestAnimationFrame(
            function () {
                resizeCanvas();
            }
        );
    }
);

uploadChoice.addEventListener(
    'change',
    function () {

        if (!this.checked) {
            return;
        }

        signatureMethod.value =
            'upload';

        uploadPanel.classList.add(
            'active'
        );

        drawPanel.classList.remove(
            'active'
        );
    }
);

function getCanvasPosition(event) {

    const rect =
        canvas.getBoundingClientRect();

    return {
        x:
            event.clientX -
            rect.left,

        y:
            event.clientY -
            rect.top
    };
}

canvas.addEventListener(
    'pointerdown',
    function (event) {

        event.preventDefault();

        drawing = true;
        hasSignature = true;

        canvas.setPointerCapture(
            event.pointerId
        );

        const position =
            getCanvasPosition(event);

        ctx.beginPath();

        ctx.moveTo(
            position.x,
            position.y
        );
    }
);

canvas.addEventListener(
    'pointermove',
    function (event) {

        if (!drawing) {
            return;
        }

        event.preventDefault();

        const position =
            getCanvasPosition(event);

        ctx.lineTo(
            position.x,
            position.y
        );

        ctx.stroke();
    }
);

function stopDrawing() {

    if (!drawing) {
        return;
    }

    drawing = false;

    ctx.closePath();
}

canvas.addEventListener(
    'pointerup',
    stopDrawing
);

canvas.addEventListener(
    'pointercancel',
    stopDrawing
);

document
    .getElementById('clearSignature')
    .addEventListener(
        'click',
        function () {

            ctx.save();

            ctx.setTransform(
                1,
                0,
                0,
                1,
                0,
                0
            );

            ctx.clearRect(
                0,
                0,
                canvas.width,
                canvas.height
            );

            ctx.restore();

            const ratio =
                Math.max(
                    window.devicePixelRatio || 1,
                    1
                );

            ctx.setTransform(
                ratio,
                0,
                0,
                ratio,
                0,
                0
            );

            ctx.lineWidth =
                2.2;

            ctx.lineCap =
                'round';

            ctx.lineJoin =
                'round';

            ctx.strokeStyle =
                '#111827';

            hasSignature =
                false;

            drawnSignature.value =
                '';
        }
    );

const signatureImage =
    document.getElementById(
        'signatureImage'
    );

const signaturePreview =
    document.getElementById(
        'signaturePreview'
    );

const signaturePreviewImage =
    document.getElementById(
        'signaturePreviewImage'
    );

signatureImage.addEventListener(
    'change',
    function () {

        const file =
            this.files[0];

        if (!file) {

            signaturePreview.style.display =
                'none';

            signaturePreviewImage.src =
                '';

            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png'
        ];

        if (
            !allowedTypes.includes(
                file.type
            )
        ) {

            alert(
                'Please select a JPG or PNG image.'
            );

            this.value =
                '';

            signaturePreview.style.display =
                'none';

            signaturePreviewImage.src =
                '';

            return;
        }

        const maxSize =
            5 * 1024 * 1024;

        if (file.size > maxSize) {

            alert(
                'Signature image must be 5 MB or smaller.'
            );

            this.value =
                '';

            signaturePreview.style.display =
                'none';

            signaturePreviewImage.src =
                '';

            return;
        }

        const reader =
            new FileReader();

        reader.onload =
            function (event) {

                signaturePreviewImage.src =
                    event.target.result;

                signaturePreview.style.display =
                    'block';
            };

        reader.readAsDataURL(file);
    }
);

document
    .getElementById('createUserForm')
    .addEventListener(
        'submit',
        function () {

            if (
                signatureMethod.value === 'draw' &&
                hasSignature
            ) {

                drawnSignature.value =
                    canvas.toDataURL(
                        'image/png'
                    );
            }
        }
    );

const mobileMenuButton =
    document.getElementById(
        'mobileMenuButton'
    );

const adminSidebar =
    document.querySelector(
        '.admin-sidebar'
    );

const sidebarOverlay =
    document.querySelector(
        '.sidebar-overlay'
    );

function closeSidebar() {

    if (adminSidebar) {

        adminSidebar.classList.remove(
            'show'
        );
    }

    if (sidebarOverlay) {

        sidebarOverlay.classList.remove(
            'show'
        );
    }

    document.body.classList.remove(
        'sidebar-open'
    );
}

if (mobileMenuButton) {

    mobileMenuButton.addEventListener(
        'click',
        function () {

            adminSidebar.classList.toggle(
                'show'
            );

            sidebarOverlay.classList.toggle(
                'show'
            );

            document.body.classList.toggle(
                'sidebar-open'
            );
        }
    );
}

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );
}

document
    .querySelectorAll('.sidebar-link')
    .forEach(
        function (link) {

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
        }
    );

</script>

</body>

</html>
