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

/*
|--------------------------------------------------------------------------
| Roles That Can Have Signatures
|--------------------------------------------------------------------------
*/

$signatureRoles = [
    'Admin',
    'Principal',
    'Teacher',
    'Registrar'
];

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$errors = [];

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$userId || $userId <= 0) {
    $_SESSION['error'] = 'Invalid user ID.';
    header('Location: index.php');
    exit;
}

$full_name = '';
$email = '';
$phone = '';
$role = '';

$currentSignaturePath = null;

/*
|--------------------------------------------------------------------------
| Signature Directory
|--------------------------------------------------------------------------
*/

$signatureDirectory = '../../uploads/signatures/';

if (!is_dir($signatureDirectory)) {
    @mkdir($signatureDirectory, 0755, true);
}

/*
|--------------------------------------------------------------------------
| Escape Helper
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
| Check Signature Role
|--------------------------------------------------------------------------
*/

function roleCanHaveSignature(
    string $role,
    array $signatureRoles
): bool {
    return in_array(
        $role,
        $signatureRoles,
        true
    );
}

/*
|--------------------------------------------------------------------------
| Delete Signature File
|--------------------------------------------------------------------------
*/

function deleteSignatureFile(
    ?string $signaturePath
): void {

    if (
        $signaturePath === null ||
        trim($signaturePath) === ''
    ) {
        return;
    }

    $relativePath = str_replace(
        ['\\', "\0"],
        ['/', ''],
        trim($signaturePath)
    );

    $relativePath = ltrim(
        $relativePath,
        '/'
    );

    if (
        !str_starts_with(
            $relativePath,
            'uploads/signatures/'
        )
    ) {
        return;
    }

    $projectRoot = realpath(
        __DIR__ . '/../../'
    );

    $signatureRoot = realpath(
        __DIR__ . '/../../uploads/signatures'
    );

    if (
        $projectRoot === false ||
        $signatureRoot === false
    ) {
        return;
    }

    $fullPath = realpath(
        $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        )
    );

    if (
        $fullPath === false ||
        !is_file($fullPath)
    ) {
        return;
    }

    $signatureRootWithSeparator =
        rtrim(
            $signatureRoot,
            DIRECTORY_SEPARATOR
        ) .
        DIRECTORY_SEPARATOR;

    if (
        !str_starts_with(
            $fullPath,
            $signatureRootWithSeparator
        )
    ) {
        return;
    }

    @unlink($fullPath);
}

/*
|--------------------------------------------------------------------------
| Save Drawn Signature
|--------------------------------------------------------------------------
*/

function saveDrawnSignature(
    string $base64Data,
    string $directory,
    int $userId
): ?string {

    if (trim($base64Data) === '') {
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

    $imageInfo = @getimagesizefromstring(
        $imageData
    );

    if ($imageInfo === false) {
        return null;
    }

    if (
        ($imageInfo['mime'] ?? '') !==
        'image/png'
    ) {
        return null;
    }

    $filename =
        'user_' .
        $userId .
        '_' .
        time() .
        '_' .
        bin2hex(random_bytes(4)) .
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
| Process Uploaded Signature
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

    if (
        ($file['size'] ?? 0) > $maxSize
    ) {
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

    $output = imagecreatetruecolor(
        $width,
        $height
    );

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

    /*
    |--------------------------------------------------------------------------
    | Remove White Background
    |--------------------------------------------------------------------------
    */

    for (
        $y = 0;
        $y < $height;
        $y++
    ) {

        for (
            $x = 0;
            $x < $width;
            $x++
        ) {

            $rgb = imagecolorat(
                $source,
                $x,
                $y
            );

            $red =
                ($rgb >> 16) & 0xFF;

            $green =
                ($rgb >> 8) & 0xFF;

            $blue =
                $rgb & 0xFF;

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

                $color =
                    imagecolorallocatealpha(
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

                $color =
                    imagecolorallocatealpha(
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

    $filename =
        'user_' .
        $userId .
        '_' .
        time() .
        '_' .
        bin2hex(random_bytes(4)) .
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
| Load User
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        full_name,
        email,
        phone,
        role,
        signature_path
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    $_SESSION['error'] =
        'User not found.';

    header('Location: index.php');
    exit;
}

$user = $result->fetch_assoc();

$stmt->close();

$full_name =
    (string) ($user['full_name'] ?? '');

$email =
    (string) ($user['email'] ?? '');

$phone =
    (string) ($user['phone'] ?? '');

$role =
    (string) ($user['role'] ?? '');

$currentSignaturePath =
    !empty($user['signature_path'])
        ? (string) $user['signature_path']
        : null;

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

    $password =
        $_POST['password'] ?? '';

    $confirm_password =
        $_POST['confirm_password'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Signature Data
    |--------------------------------------------------------------------------
    */

    $drawn_signature =
        $_POST['drawn_signature'] ?? '';

    $signatureMethod =
        $_POST['signature_method'] ?? 'none';

    $removeSignature =
        isset($_POST['remove_signature']) &&
        $_POST['remove_signature'] === '1';

    $signatureFile =
        $_FILES['signature_image'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Full Name
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
    | Email
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
    | Phone
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
    | Role
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
    | Password
    |--------------------------------------------------------------------------
    */

    if (
        $password !== '' &&
        strlen($password) < 6
    ) {

        $errors[] =
            'Password must be at least 6 characters.';
    }

    if (
        $password !== '' &&
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
        roleCanHaveSignature(
            $role,
            $signatureRoles
        )
    ) {

        if ($signatureMethod === 'draw') {

            if (
                $drawn_signature !== '' &&
                !preg_match(
                    '/^data:image\/png;base64,(.+)$/',
                    $drawn_signature
                )
            ) {

                $errors[] =
                    'The drawn signature is invalid.';
            }

        } elseif ($signatureMethod === 'upload') {

            if (
                is_array($signatureFile) &&
                isset($signatureFile['error']) &&
                $signatureFile['error'] !==
                UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $signatureFile['error'] !==
                    UPLOAD_ERR_OK
                ) {

                    $errors[] =
                        'The signature image could not be uploaded.';
                }

            } else {

                $signatureMethod = 'none';
            }
        }

    } else {

        $signatureMethod = 'none';
        $drawn_signature = '';
        $removeSignature = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email / Phone
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $stmt = $conn->prepare(
            "SELECT
                id,
                email,
                phone
             FROM users
             WHERE
                (email = ? OR phone = ?)
                AND id <> ?
             LIMIT 1"
        );

        $stmt->bind_param(
            'ssi',
            $email,
            $phone,
            $userId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        if ($result->num_rows > 0) {

            $existingUser =
                $result->fetch_assoc();

            if (
                isset(
                    $existingUser['email']
                ) &&
                strcasecmp(
                    (string)
                    $existingUser['email'],
                    $email
                ) === 0
            ) {

                $errors[] =
                    'A user with this email already exists.';
            }

            if (
                isset(
                    $existingUser['phone']
                ) &&
                (string)
                $existingUser['phone'] ===
                $phone
            ) {

                $errors[] =
                    'A user with this phone number already exists.';
            }
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $newSignaturePath =
            $currentSignaturePath;

        $oldSignaturePath =
            $currentSignaturePath;

        $newSignatureCreated = false;

        $stmt = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | New Signature
            |--------------------------------------------------------------------------
            */

            if (
                roleCanHaveSignature(
                    $role,
                    $signatureRoles
                )
            ) {

                /*
                |------------------------------------------------------------------
                | Draw
                |------------------------------------------------------------------
                */

                if (
                    $signatureMethod === 'draw' &&
                    $drawn_signature !== ''
                ) {

                    $newSignaturePath =
                        saveDrawnSignature(
                            $drawn_signature,
                            $signatureDirectory,
                            $userId
                        );

                    if (
                        $newSignaturePath === null
                    ) {

                        throw new RuntimeException(
                            'Unable to save the drawn signature.'
                        );
                    }

                    $newSignatureCreated = true;
                }

                /*
                |------------------------------------------------------------------
                | Upload
                |------------------------------------------------------------------
                */

                elseif (
                    $signatureMethod === 'upload' &&
                    is_array($signatureFile) &&
                    isset($signatureFile['error']) &&
                    $signatureFile['error'] ===
                    UPLOAD_ERR_OK
                ) {

                    $newSignaturePath =
                        processUploadedSignature(
                            $signatureFile,
                            $signatureDirectory,
                            $userId
                        );

                    if (
                        $newSignaturePath === null
                    ) {

                        throw new RuntimeException(
                            'Unable to process the uploaded signature. Make sure PHP GD is enabled and the image is valid.'
                        );
                    }

                    $newSignatureCreated = true;
                }

                /*
                |------------------------------------------------------------------
                | Remove
                |------------------------------------------------------------------
                */

                elseif ($removeSignature) {

                    $newSignaturePath = null;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Admin, Registrar, Principal and Teacher signatures are preserved.
            |--------------------------------------------------------------------------
            |
            | Librarian does not use the signature field.
            |
            */

            else {

                $newSignaturePath = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Update Database
            |--------------------------------------------------------------------------
            */

            if ($password !== '') {

                $hashedPassword =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                $stmt = $conn->prepare(
                    "UPDATE users
                     SET
                        full_name = ?,
                        email = ?,
                        phone = ?,
                        password = ?,
                        signature_path = ?,
                        role = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    'ssssssi',
                    $full_name,
                    $email,
                    $phone,
                    $hashedPassword,
                    $newSignaturePath,
                    $role,
                    $userId
                );

            } else {

                $stmt = $conn->prepare(
                    "UPDATE users
                     SET
                        full_name = ?,
                        email = ?,
                        phone = ?,
                        signature_path = ?,
                        role = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    'sssssi',
                    $full_name,
                    $email,
                    $phone,
                    $newSignaturePath,
                    $role,
                    $userId
                );
            }

            $stmt->execute();
            $stmt->close();
            $stmt = null;

            /*
            |--------------------------------------------------------------------------
            | Delete Old Signature
            |--------------------------------------------------------------------------
            */

            if (
                $oldSignaturePath !== null &&
                $oldSignaturePath !== $newSignaturePath
            ) {

                deleteSignatureFile(
                    $oldSignaturePath
                );
            }

            $_SESSION['success'] =
                'User updated successfully.';

            header('Location: index.php');
            exit;

        } catch (
            mysqli_sql_exception |
            RuntimeException $e
        ) {

            if (
                $stmt instanceof mysqli_stmt
            ) {

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Remove New Signature If Database Update Failed
            |--------------------------------------------------------------------------
            */

            if (
                $newSignatureCreated &&
                $newSignaturePath !== null &&
                $newSignaturePath !== $oldSignaturePath
            ) {

                deleteSignatureFile(
                    $newSignaturePath
                );
            }

            if (
                $e instanceof mysqli_sql_exception &&
                $e->getCode() === 1062
            ) {

                $message =
                    strtolower(
                        $e->getMessage()
                    );

                if (
                    str_contains(
                        $message,
                        'phone'
                    )
                ) {

                    $errors[] =
                        'A user with this phone number already exists.';

                } elseif (
                    str_contains(
                        $message,
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
                    $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Current Signature URL
|--------------------------------------------------------------------------
*/

$signatureUrl = null;

if (
    $currentSignaturePath !== null &&
    trim($currentSignaturePath) !== ''
) {

    $relativeSignature =
        str_replace(
            ['\\', "\0"],
            '/',
            trim($currentSignaturePath)
        );

    $relativeSignature =
        ltrim(
            $relativeSignature,
            '/'
        );

    if (
        str_starts_with(
            $relativeSignature,
            'uploads/signatures/'
        )
    ) {

        $signatureUrl =
            '../../' .
            implode(
                '/',
                array_map(
                    'rawurlencode',
                    explode(
                        '/',
                        $relativeSignature
                    )
                )
            );
    }
}

$showSignature =
    roleCanHaveSignature(
        $role,
        $signatureRoles
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

    <title>Edit User | Admin</title>


    <link
        rel="icon"
        type="image/webp"
        href="../../../public/logo.webp"
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

        /*
        |--------------------------------------------------------------------------
        | Signature
        |--------------------------------------------------------------------------
        */

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

        .current-signature-box {
            margin-bottom: 20px;
            padding: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .current-signature-title {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 12px;
        }

        .signature-image-wrapper {
            min-height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
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

        .signature-image-wrapper img {
            max-width: 100%;
            max-height: 130px;
            object-fit: contain;
        }

        .current-signature-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 12px;
            flex-wrap: wrap;
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

        .signature-remove-box {
            margin-top: 15px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
        }

        .signature-remove-box label {
            color: #9a3412;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
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

            .current-signature-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .current-signature-actions .btn {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->

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

    <!-- Main -->

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
                        Edit User
                    </h1>

                    <p class="mb-0 text-muted">
                        Update staff or system user information
                    </p>

                </div>

            </div>

            <div class="topbar-actions">

                <div class="admin-profile">

                    <div class="profile-avatar">
                        <?= e(
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
                            <?= e(
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
                                            <?= e($error) ?>
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
                        id="editUserForm"
                    >

                        <!-- Personal Information -->

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
                                        value="<?= e($full_name) ?>"
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
                                        value="<?= e($email) ?>"
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
                                        value="<?= e($phone) ?>"
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
                                                value="<?= e($item) ?>"
                                                <?= $role === $item ? 'selected' : '' ?>
                                            >
                                                <?= e($item) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <div class="form-help">
                                        Student and Parent accounts are managed by the Registrar.
                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- Signature -->

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

                                Add or update the user's signature for
                                report cards, certificates, transcripts,
                                and official documents.

                            </div>

                            <?php if ($signatureUrl !== null): ?>

                                <div class="current-signature-box">

                                    <div class="current-signature-title">

                                        <i class="bi bi-check-circle-fill text-success me-1"></i>

                                        Current Signature

                                    </div>

                                    <div class="signature-image-wrapper">

                                        <img
                                            src="<?= e($signatureUrl) ?>"
                                            alt="Current signature"
                                        >

                                    </div>

                                    <div class="current-signature-actions">

                                        <span class="signature-note">

                                            This signature is currently saved
                                            for this user.

                                        </span>

                                        <label
                                            class="btn btn-outline-danger btn-sm mb-0"
                                        >

                                            <input
                                                type="checkbox"
                                                name="remove_signature"
                                                value="1"
                                                id="removeSignature"
                                                class="d-none"
                                            >

                                            <i class="bi bi-trash me-1"></i>

                                            Remove Signature

                                        </label>

                                    </div>

                                </div>

                            <?php endif; ?>

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

                                        Draw New Signature

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

                                        Upload New Signature

                                    </label>

                                </div>

                            </div>

                            <!-- Draw Signature -->

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

                            <!-- Upload Signature -->

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
                                        White backgrounds will be removed
                                        automatically.

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

                        <!-- Account Security -->

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
                                        New Password
                                    </label>

                                    <div class="password-wrapper">

                                        <input
                                            type="password"
                                            name="password"
                                            id="password"
                                            class="form-control"
                                            placeholder="Leave blank to keep current password"
                                            minlength="6"
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

                                        Leave blank if you do not want to
                                        change the password.

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Confirm New Password
                                    </label>

                                    <div class="password-wrapper">

                                        <input
                                            type="password"
                                            name="confirm_password"
                                            id="confirm_password"
                                            class="form-control"
                                            placeholder="Confirm new password"
                                            minlength="6"
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

                        <!-- Actions -->

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

                                <i class="bi bi-check-lg me-2"></i>

                                Update User

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </section>

    </main>

</div>

<script>

/*
|--------------------------------------------------------------------------
| Password Toggle
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Signature Elements
|--------------------------------------------------------------------------
*/

const roleSelect =
    document.getElementById('role');

const signatureSection =
    document.getElementById(
        'signatureSection'
    );

const drawChoice =
    document.getElementById(
        'drawSignatureChoice'
    );

const uploadChoice =
    document.getElementById(
        'uploadSignatureChoice'
    );

const drawPanel =
    document.getElementById(
        'drawPanel'
    );

const uploadPanel =
    document.getElementById(
        'uploadPanel'
    );

const signatureMethod =
    document.getElementById(
        'signatureMethod'
    );

const drawnSignature =
    document.getElementById(
        'drawnSignature'
    );

const removeSignature =
    document.getElementById(
        'removeSignature'
    );

/*
|--------------------------------------------------------------------------
| Signature Roles
|--------------------------------------------------------------------------
|
| Admin
| Principal
| Teacher
| Registrar
|
*/

function canHaveSignature(role) {

    role =
        role.toLowerCase();

    return (
        role === 'admin' ||
        role === 'principal' ||
        role === 'teacher' ||
        role === 'registrar'
    );
}

/*
|--------------------------------------------------------------------------
| Signature Visibility
|--------------------------------------------------------------------------
*/

function updateSignatureVisibility() {

    const showSignature =
        canHaveSignature(
            roleSelect.value
        );

    if (showSignature) {

        signatureSection.style.display =
            'block';

    } else {

        signatureSection.style.display =
            'none';

        if (drawChoice) {
            drawChoice.checked = false;
        }

        if (uploadChoice) {
            uploadChoice.checked = false;
        }

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

        if (removeSignature) {
            removeSignature.checked =
                false;
        }
    }
}

roleSelect.addEventListener(
    'change',
    updateSignatureVisibility
);

updateSignatureVisibility();

/*
|--------------------------------------------------------------------------
| Canvas
|--------------------------------------------------------------------------
*/

const canvas =
    document.getElementById(
        'signatureCanvas'
    );

const ctx =
    canvas.getContext('2d');

let drawing = false;
let hasSignature = false;

/*
|--------------------------------------------------------------------------
| Resize Canvas
|--------------------------------------------------------------------------
*/

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

    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

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

/*
|--------------------------------------------------------------------------
| Draw New Signature
|--------------------------------------------------------------------------
*/

drawChoice.addEventListener(
    'change',
    function () {

        if (!this.checked) {
            return;
        }

        signatureMethod.value =
            'draw';

        if (removeSignature) {
            removeSignature.checked =
                false;
        }

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

/*
|--------------------------------------------------------------------------
| Upload New Signature
|--------------------------------------------------------------------------
*/

uploadChoice.addEventListener(
    'change',
    function () {

        if (!this.checked) {
            return;
        }

        signatureMethod.value =
            'upload';

        if (removeSignature) {
            removeSignature.checked =
                false;
        }

        uploadPanel.classList.add(
            'active'
        );

        drawPanel.classList.remove(
            'active'
        );
    }
);

/*
|--------------------------------------------------------------------------
| Canvas Position
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Pointer Down
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Pointer Move
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Stop Drawing
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Clear Signature
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'clearSignature'
    )
    .addEventListener(
        'click',
        function () {

            const rect =
                canvas.getBoundingClientRect();

            ctx.clearRect(
                0,
                0,
                rect.width,
                rect.height
            );

            hasSignature = false;

            drawnSignature.value =
                '';

            signatureMethod.value =
                'draw';
        }
    );

/*
|--------------------------------------------------------------------------
| Upload Preview
|--------------------------------------------------------------------------
*/

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

            this.value = '';

            signaturePreview.style.display =
                'none';

            signaturePreviewImage.src =
                '';

            return;
        }

        const maxSize =
            5 * 1024 * 1024;

        if (
            file.size > maxSize
        ) {

            alert(
                'Signature image must be 5 MB or smaller.'
            );

            this.value = '';

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

/*
|--------------------------------------------------------------------------
| Remove Signature
|--------------------------------------------------------------------------
*/

if (removeSignature) {

    removeSignature.addEventListener(
        'change',
        function () {

            if (this.checked) {

                if (drawChoice) {
                    drawChoice.checked =
                        false;
                }

                if (uploadChoice) {
                    uploadChoice.checked =
                        false;
                }

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
    );
}

/*
|--------------------------------------------------------------------------
| Form Submit
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'editUserForm'
    )
    .addEventListener(
        'submit',
        function () {

            if (
                signatureMethod.value ===
                    'draw' &&
                hasSignature
            ) {

                drawnSignature.value =
                    canvas.toDataURL(
                        'image/png'
                    );
            }
        }
    );

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const mobileMenuButton =
    document.getElementById(
        'mobileMenuButton'
    );

const sidebar =
    document.querySelector(
        '.admin-sidebar'
    );

const sidebarOverlay =
    document.querySelector(
        '.sidebar-overlay'
    );

if (
    mobileMenuButton &&
    sidebar &&
    sidebarOverlay
) {

    mobileMenuButton.addEventListener(
        'click',
        function () {

            sidebar.classList.toggle(
                'show'
            );

            sidebarOverlay.classList.toggle(
                'show'
            );
        }
    );

    sidebarOverlay.addEventListener(
        'click',
        function () {

            sidebar.classList.remove(
                'show'
            );

            sidebarOverlay.classList.remove(
                'show'
            );
        }
    );
}

</script>

</body>

</html>
