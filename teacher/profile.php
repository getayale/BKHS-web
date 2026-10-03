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
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'T';
}

function displayValue(?string $value): string
{
    $value = trim((string) $value);

    return $value !== ''
        ? $value
        : 'Not provided';
}

function getEthiopianMonthName(?int $month): string
{
    $months = [
        1  => 'Meskerem',
        2  => 'Tikimt',
        3  => 'Hidar',
        4  => 'Tahsas',
        5  => 'Tir',
        6  => 'Yekatit',
        7  => 'Megabit',
        8  => 'Miyazya',
        9  => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehasse',
        13 => 'Pagume',
    ];

    return $months[$month ?? 0] ?? '';
}

function formatEthiopianBirthDate(
    ?int $year,
    ?int $month,
    ?int $day
): string {
    if (
        empty($year) ||
        empty($month) ||
        empty($day)
    ) {
        return 'Not provided';
    }

    $monthName = getEthiopianMonthName($month);

    if ($monthName === '') {
        return 'Not provided';
    }

    return $year . ' ' . $monthName . ' ' . $day;
}

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['teacher_profile_csrf']) ||
    !is_string($_SESSION['teacher_profile_csrf']) ||
    $_SESSION['teacher_profile_csrf'] === ''
) {
    $_SESSION['teacher_profile_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['teacher_profile_csrf'];

/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$successMessage = $_SESSION['teacher_profile_success'] ?? '';
$errorMessage = $_SESSION['teacher_profile_error'] ?? '';

unset(
    $_SESSION['teacher_profile_success'],
    $_SESSION['teacher_profile_error']
);

/*
|--------------------------------------------------------------------------
| Load Teacher Profile
|--------------------------------------------------------------------------
*/

$teacher = null;

$teacherSql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        u.role,
        u.is_logged_in,
        u.last_login_at,
        u.created_at AS user_created_at,
        u.updated_at AS user_updated_at,

        t.id AS teacher_id,
        t.photo_path,
        t.gender,
        t.birth_eth_year,
        t.birth_eth_month,
        t.birth_eth_day,
        t.region,
        t.zone,
        t.woreda,
        t.marital_status,
        t.education_level,
        t.department,
        t.college_university_institution,
        t.created_at AS teacher_created_at,
        t.updated_at AS teacher_updated_at

    FROM users u

    LEFT JOIN teachers t
        ON t.user_id = u.id

    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0

    LIMIT 1
";

$teacherStmt = $conn->prepare($teacherSql);

if ($teacherStmt) {
    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult = $teacherStmt->get_result();

    $teacher = $teacherResult->fetch_assoc();

    $teacherStmt->close();
}

if (!$teacher) {
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Teacher Basic Information
|--------------------------------------------------------------------------
*/

$teacherName = (string) (
    $teacher['full_name'] ?? 'Teacher'
);

$teacherInitials = getInitials(
    $teacherName
);

/*
|--------------------------------------------------------------------------
| Teacher Photo URL
|--------------------------------------------------------------------------
*/

$teacherPhoto = '';

if (!empty($teacher['photo_path'])) {

    $photoPath = trim(
        (string) $teacher['photo_path']
    );

    /*
    | New profile photos are stored as:
    | public/uploads/profiles/filename.ext
    |
    | The database stores only the filename.
    */

    if (
        !str_starts_with($photoPath, '/') &&
        !str_contains($photoPath, '/')
    ) {
        $teacherPhoto =
            '../public/uploads/profiles/' .
            rawurlencode(basename($photoPath));
    }

    /*
    | Support existing uploads/ paths.
    */

    elseif (
        str_starts_with(
            $photoPath,
            'uploads/'
        )
    ) {
        $teacherPhoto =
            '../' . ltrim(
                $photoPath,
                '/'
            );
    }

    /*
    | Support an already-relative public path.
    */

    elseif (
        str_starts_with(
            $photoPath,
            'public/'
        )
    ) {
        $teacherPhoto =
            '../' . ltrim(
                $photoPath,
                '/'
            );
    }

    /*
    | Absolute browser path.
    */

    elseif (
        str_starts_with(
            $photoPath,
            '/'
        )
    ) {
        $teacherPhoto = $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian Birth Date
|--------------------------------------------------------------------------
*/

$birthYear = !empty($teacher['birth_eth_year'])
    ? (int) $teacher['birth_eth_year']
    : null;

$birthMonth = !empty($teacher['birth_eth_month'])
    ? (int) $teacher['birth_eth_month']
    : null;

$birthDay = !empty($teacher['birth_eth_day'])
    ? (int) $teacher['birth_eth_day']
    : null;

$birthDate = formatEthiopianBirthDate(
    $birthYear,
    $birthMonth,
    $birthDay
);

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = EthiopianCalendar::todayFormatted();

/*
|--------------------------------------------------------------------------
| Handle Profile Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {
        $_SESSION['teacher_profile_error'] =
            'Invalid security token. Please try again.';

        header('Location: profile.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | UPDATE PHOTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_photo') {

        if (
            !isset($_FILES['profile_photo']) ||
            !is_array($_FILES['profile_photo'])
        ) {
            $_SESSION['teacher_profile_error'] =
                'Please select a photo.';

            header('Location: profile.php');
            exit;
        }

        $file = $_FILES['profile_photo'];

        $uploadError = (int) (
            $file['error'] ?? UPLOAD_ERR_NO_FILE
        );

        if ($uploadError !== UPLOAD_ERR_OK) {

            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE =>
                    'The selected photo is too large.',

                UPLOAD_ERR_NO_FILE =>
                    'Please select a photo.',

                default =>
                    'The photo could not be uploaded.'
            };

            $_SESSION['teacher_profile_error'] =
                $message;

            header('Location: profile.php');
            exit;
        }

        if (
            !isset($file['tmp_name']) ||
            !is_string($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $_SESSION['teacher_profile_error'] =
                'Invalid uploaded file.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum 5 MB
        |--------------------------------------------------------------------------
        */

        $maxFileSize = 5 * 1024 * 1024;

        $fileSize = (int) (
            $file['size'] ?? 0
        );

        if ($fileSize <= 0) {
            $_SESSION['teacher_profile_error'] =
                'The selected photo is empty.';

            header('Location: profile.php');
            exit;
        }

        if ($fileSize > $maxFileSize) {
            $_SESSION['teacher_profile_error'] =
                'Photo size must not exceed 5 MB.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Detect MIME Type
        |--------------------------------------------------------------------------
        */

        $finfo = new finfo(
            FILEINFO_MIME_TYPE
        );

        $mimeType = $finfo->file(
            $file['tmp_name']
        );

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (
            !is_string($mimeType) ||
            !isset($allowedTypes[$mimeType])
        ) {
            $_SESSION['teacher_profile_error'] =
                'Only JPG, PNG, and WebP images are allowed.';

            header('Location: profile.php');
            exit;
        }

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
            'profiles';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir(
                $uploadDirectory,
                0755,
                true
            ) &&
            !is_dir($uploadDirectory)
        ) {
            $_SESSION['teacher_profile_error'] =
                'The profile photo directory could not be created.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Safe Filename
        |--------------------------------------------------------------------------
        */

        try {
            $randomName = bin2hex(
                random_bytes(16)
            );
        } catch (Throwable $exception) {
            $_SESSION['teacher_profile_error'] =
                'Could not generate a secure photo name.';

            header('Location: profile.php');
            exit;
        }

        $extension =
            $allowedTypes[$mimeType];

        $newFileName =
            'teacher_' .
            $teacherUserId .
            '_' .
            $randomName .
            '.' .
            $extension;

        $destination =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $newFileName;

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $destination
            )
        ) {
            $_SESSION['teacher_profile_error'] =
                'The profile photo could not be saved.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Update teachers.photo_path
        |--------------------------------------------------------------------------
        */

        $oldPhotoPath =
            trim(
                (string) (
                    $teacher['photo_path'] ?? ''
                )
            );

        $teacherExists =
            !empty($teacher['teacher_id']);

        if ($teacherExists) {

            $updatePhotoSql = "
                UPDATE teachers
                SET photo_path = ?
                WHERE user_id = ?
                LIMIT 1
            ";

            $updatePhotoStmt =
                $conn->prepare(
                    $updatePhotoSql
                );

            if (!$updatePhotoStmt) {

                @unlink($destination);

                $_SESSION['teacher_profile_error'] =
                    'Could not prepare the profile photo update.';

                header('Location: profile.php');
                exit;
            }

            $updatePhotoStmt->bind_param(
                'si',
                $newFileName,
                $teacherUserId
            );

            if (
                !$updatePhotoStmt->execute()
            ) {

                $updatePhotoStmt->close();

                @unlink($destination);

                $_SESSION['teacher_profile_error'] =
                    'Could not update the profile photo.';

                header('Location: profile.php');
                exit;
            }

            $updatePhotoStmt->close();

        } else {

            /*
            | Teacher record does not exist.
            | Create it with the photo.
            */

            $insertTeacherSql = "
                INSERT INTO teachers (
                    user_id,
                    photo_path
                )
                VALUES (?, ?)
            ";

            $insertTeacherStmt =
                $conn->prepare(
                    $insertTeacherSql
                );

            if (!$insertTeacherStmt) {

                @unlink($destination);

                $_SESSION['teacher_profile_error'] =
                    'Could not create the teacher profile record.';

                header('Location: profile.php');
                exit;
            }

            $insertTeacherStmt->bind_param(
                'is',
                $teacherUserId,
                $newFileName
            );

            if (
                !$insertTeacherStmt->execute()
            ) {

                $insertTeacherStmt->close();

                @unlink($destination);

                $_SESSION['teacher_profile_error'] =
                    'Could not save the profile photo.';

                header('Location: profile.php');
                exit;
            }

            $insertTeacherStmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Previous Profile Photo
        |--------------------------------------------------------------------------
        |
        | Only delete files from our profile upload directory.
        */

        if ($oldPhotoPath !== '') {

            $oldFileName =
                basename($oldPhotoPath);

            $oldFile =
                $uploadDirectory .
                DIRECTORY_SEPARATOR .
                $oldFileName;

            $realUploadDirectory =
                realpath(
                    $uploadDirectory
                );

            $realOldFile =
                is_file($oldFile)
                    ? realpath($oldFile)
                    : false;

            if (
                $realUploadDirectory !== false &&
                $realOldFile !== false &&
                str_starts_with(
                    $realOldFile,
                    $realUploadDirectory .
                    DIRECTORY_SEPARATOR
                )
            ) {
                @unlink($realOldFile);
            }
        }

        $_SESSION['teacher_profile_success'] =
            'Profile photo updated successfully.';

        header('Location: profile.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_password') {

        $currentPassword =
            (string) (
                $_POST['current_password'] ?? ''
            );

        $newPassword =
            (string) (
                $_POST['new_password'] ?? ''
            );

        $confirmPassword =
            (string) (
                $_POST['confirm_password'] ?? ''
            );

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $_SESSION['teacher_profile_error'] =
                'Please fill in all password fields.';

            header('Location: profile.php');
            exit;
        }

        if (strlen($newPassword) < 8) {
            $_SESSION['teacher_profile_error'] =
                'New password must be at least 8 characters long.';

            header('Location: profile.php');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['teacher_profile_error'] =
                'New password and confirmation password do not match.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Current Password Hash
        |--------------------------------------------------------------------------
        */

        $passwordSql = "
            SELECT password
            FROM users
            WHERE id = ?
              AND LOWER(role) = 'teacher'
              AND is_deleted = 0
            LIMIT 1
        ";

        $passwordStmt =
            $conn->prepare(
                $passwordSql
            );

        if (!$passwordStmt) {
            $_SESSION['teacher_profile_error'] =
                'Could not verify your current password.';

            header('Location: profile.php');
            exit;
        }

        $passwordStmt->bind_param(
            'i',
            $teacherUserId
        );

        $passwordStmt->execute();

        $passwordResult =
            $passwordStmt->get_result();

        $passwordRow =
            $passwordResult->fetch_assoc();

        $passwordStmt->close();

        $storedPassword =
            (string) (
                $passwordRow['password'] ?? ''
            );

        if (
            $storedPassword === '' ||
            !password_verify(
                $currentPassword,
                $storedPassword
            )
        ) {
            $_SESSION['teacher_profile_error'] =
                'Current password is incorrect.';

            header('Location: profile.php');
            exit;
        }

        if (
            password_verify(
                $newPassword,
                $storedPassword
            )
        ) {
            $_SESSION['teacher_profile_error'] =
                'Your new password must be different from your current password.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Hash New Password
        |--------------------------------------------------------------------------
        */

        $newPasswordHash =
            password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

        if (
            !is_string($newPasswordHash)
        ) {
            $_SESSION['teacher_profile_error'] =
                'Could not securely create the new password.';

            header('Location: profile.php');
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Update Password
        |--------------------------------------------------------------------------
        */

        $updatePasswordSql = "
            UPDATE users
            SET password = ?,
                updated_at = NOW()
            WHERE id = ?
              AND LOWER(role) = 'teacher'
              AND is_deleted = 0
            LIMIT 1
        ";

        $updatePasswordStmt =
            $conn->prepare(
                $updatePasswordSql
            );

        if (!$updatePasswordStmt) {
            $_SESSION['teacher_profile_error'] =
                'Could not prepare the password update.';

            header('Location: profile.php');
            exit;
        }

        $updatePasswordStmt->bind_param(
            'si',
            $newPasswordHash,
            $teacherUserId
        );

        if (
            !$updatePasswordStmt->execute()
        ) {
            $updatePasswordStmt->close();

            $_SESSION['teacher_profile_error'] =
                'Could not update your password.';

            header('Location: profile.php');
            exit;
        }

        $updatePasswordStmt->close();

        $_SESSION['teacher_profile_success'] =
            'Password updated successfully.';

        header('Location: profile.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Unknown Action
    |--------------------------------------------------------------------------
    */

    $_SESSION['teacher_profile_error'] =
        'Invalid profile action.';

    header('Location: profile.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Display Values
|--------------------------------------------------------------------------
*/

$email = displayValue(
    $teacher['email'] ?? null
);

$phone = displayValue(
    $teacher['phone'] ?? null
);

$gender = displayValue(
    $teacher['gender'] ?? null
);

$region = displayValue(
    $teacher['region'] ?? null
);

$zone = displayValue(
    $teacher['zone'] ?? null
);

$woreda = displayValue(
    $teacher['woreda'] ?? null
);

$maritalStatus = displayValue(
    $teacher['marital_status'] ?? null
);

$educationLevel = displayValue(
    $teacher['education_level'] ?? null
);

$department = displayValue(
    $teacher['department'] ?? null
);

$college = displayValue(
    $teacher['college_university_institution'] ?? null
);

$teacherId = displayValue(
    isset($teacher['teacher_id'])
        ? (string) $teacher['teacher_id']
        : null
);

$accountCreated = displayValue(
    $teacher['user_created_at'] ?? null
);

$lastLogin = displayValue(
    $teacher['last_login_at'] ?? null
);

$accountStatus =
    !empty($teacher['is_logged_in'])
        ? 'Currently Active'
        : 'Not Active';

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

    <meta
        name="description"
        content="Teacher profile - BKHS School Management System."
    >

    <title>My Profile | BKHS</title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        rel="shortcut icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <!-- Inter -->
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
            --body-bg: #f5f7fb;
            --text-dark: #111827;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
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

        /* =========================================================
           SIDEBAR
           ========================================================= */

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

        /* =========================================================
           MAIN
           ========================================================= */

        .main {
            min-height: 100vh;
            margin-left: 260px;
            width: calc(100% - 260px);
        }

        /* =========================================================
           TOPBAR
           ========================================================= */

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

        /* =========================================================
           CONTENT
           ========================================================= */

        .content {
            width: 100%;
            max-width: 1450px;
            margin: 0 auto;
            padding: 25px 28px 35px;
        }

        /* =========================================================
           PROFILE HEADER
           ========================================================= */

        .profile-hero {
            position: relative;
            overflow: hidden;
            padding: 24px;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(
                135deg,
                #1d4ed8,
                #2563eb 55%,
                #3b82f6
            );
        }

        .profile-hero::after {
            content: '';
            position: absolute;
            width: 230px;
            height: 230px;
            right: -80px;
            top: -100px;
            border: 35px solid rgba(255,255,255,.08);
            border-radius: 50%;
        }

        .profile-hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 20px;
            min-width: 0;
        }

        .profile-photo-wrapper {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }

        .profile-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: #1d4ed8;
            border: 4px solid rgba(255,255,255,.85);
            font-size: 27px;
            font-weight: 800;
        }

        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-upload-button {
            position: absolute;
            right: -2px;
            bottom: -2px;
            width: 34px;
            height: 34px;
            border: 3px solid #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            transition: .2s ease;
            z-index: 5;
        }

        .photo-upload-button:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        .photo-upload-button i {
            font-size: 15px;
        }

        .profile-hero-text {
            min-width: 0;
        }

        .profile-hero-text h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .profile-hero-text p {
            margin: 6px 0 0;
            color: #dbeafe;
            font-size: 12px;
        }

        .profile-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 11px;
            padding: 6px 10px;
            border-radius: 8px;
            color: #eff6ff;
            background: rgba(255,255,255,.15);
            font-size: 10px;
            font-weight: 700;
        }

        .hero-upload-form {
            display: none;
        }

        /* =========================================================
           ALERTS
           ========================================================= */

        .alert-custom {
            margin-top: 18px;
            border-radius: 11px;
            border: 1px solid;
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .alert-success-custom {
            color: #166534;
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .alert-error-custom {
            color: #991b1b;
            background: #fef2f2;
            border-color: #fecaca;
        }

        .alert-custom i {
            font-size: 16px;
            flex-shrink: 0;
        }

        /* =========================================================
           PROFILE GRID
           ========================================================= */

        .profile-grid {
            display: grid;
            grid-template-columns:
                minmax(0, 1.35fr)
                minmax(0, 1fr);
            gap: 18px;
            margin-top: 18px;
        }

        .panel {
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 4px 16px rgba(15,23,42,.035);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px 19px;
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 10px;
        }

        .panel-body {
            padding: 17px 19px;
        }

        /* =========================================================
           INFORMATION ROWS
           ========================================================= */

        .info-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 1px;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: var(--border);
        }

        .info-item {
            min-width: 0;
            padding: 13px;
            background: #fff;
        }

        .info-label {
            color: var(--text-muted);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .info-value {
            margin-top: 5px;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .info-value.muted {
            color: #9ca3af;
            font-weight: 500;
        }

        /* =========================================================
           PASSWORD
           ========================================================= */

        .password-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .form-label-custom {
            display: block;
            margin-bottom: 6px;
            color: #374151;
            font-size: 10px;
            font-weight: 700;
        }

        .input-group-custom {
            position: relative;
        }

        .form-control-custom {
            width: 100%;
            min-height: 43px;
            padding: 10px 42px 10px 12px;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: #111827;
            font-family: inherit;
            font-size: 11px;
            transition: .2s ease;
        }

        .form-control-custom:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37,99,235,.08);
        }

        .password-toggle {
            position: absolute;
            right: 0;
            top: 0;
            width: 42px;
            height: 43px;
            border: 0;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .password-help {
            margin-top: 5px;
            color: var(--text-muted);
            font-size: 9px;
            line-height: 1.5;
        }

        .btn-primary-custom {
            width: 100%;
            min-height: 43px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
        }

        /* =========================================================
           ACCOUNT STATUS
           ========================================================= */

        .account-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border-radius: 7px;
            color: #166534;
            background: #dcfce7;
            font-size: 9px;
            font-weight: 800;
        }

        .account-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #16a34a;
        }

        /* =========================================================
           LOCATION
           ========================================================= */

        .location-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f9fafb;
        }

        .location-icon {
            width: 37px;
            height: 37px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #dbeafe;
            color: var(--primary);
            font-size: 17px;
        }

        .location-title {
            color: #111827;
            font-size: 11px;
            font-weight: 800;
        }

        .location-text {
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 10px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        /* =========================================================
           PHOTO NOTE
           ========================================================= */

        .photo-note {
            margin-top: 11px;
            color: var(--text-muted);
            font-size: 9px;
            line-height: 1.5;
        }

        /* =========================================================
           MOBILE BOTTOM NAVIGATION
           ========================================================= */

        .mobile-bottom-nav {
            display: none;
        }

        /* =========================================================
           TABLET
           ========================================================= */

        @media (max-width: 1199px) {

            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        /* =========================================================
           MOBILE
           ========================================================= */

        @media (max-width: 991px) {

            /*
            Hide desktop sidebar completely.
            No hamburger menu.
            */

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .topbar {
                min-height: 66px;
                padding: 11px 18px;
            }

            .content {
                padding: 20px 18px 100px;
            }

            /*
            Hide any possible hamburger.
            */

            .menu-toggle {
                display: none !important;
            }

            /*
            Bottom navigation.
            */

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 1100;

                display: grid;
                grid-template-columns:
                    repeat(5, 1fr);

                min-height: 68px;

                padding:
                    7px
                    6px
                    max(7px, env(safe-area-inset-bottom));

                background: rgba(255,255,255,.98);
                border-top: 1px solid var(--border);

                box-shadow:
                    0 -5px 25px
                    rgba(15,23,42,.10);

                backdrop-filter: blur(12px);
            }

            .mobile-nav-item {
                position: relative;
                min-width: 0;

                border: 0;
                background: transparent;

                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;

                gap: 4px;
                padding: 5px 2px;

                color: #6b7280;

                font-family: inherit;
                font-size: 9px;
                font-weight: 700;
                line-height: 1.1;

                cursor: pointer;
                border-radius: 10px;

                transition:
                    background .18s ease,
                    color .18s ease;
            }

            .mobile-nav-item i {
                font-size: 19px;
                line-height: 1;
            }

            .mobile-nav-item.active {
                color: var(--primary);
                background: #eff6ff;
            }

            .mobile-nav-item.active::before {
                content: '';

                position: absolute;
                top: -7px;
                left: 50%;

                transform:
                    translateX(-50%);

                width: 25px;
                height: 3px;

                border-radius:
                    0 0 5px 5px;

                background:
                    var(--primary);
            }
        }

        /* =========================================================
           TABLET
           ========================================================= */

        @media (min-width: 576px)
            and (max-width: 991px) {

            .profile-hero {
                padding: 23px;
            }

            .profile-photo-wrapper,
            .profile-photo {
                width: 90px;
                height: 90px;
            }
        }

        /* =========================================================
           PHONE
           ========================================================= */

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

            .content {
                padding:
                    14px
                    12px
                    105px;
            }

            .profile-hero {
                padding: 18px;
                border-radius: 13px;
            }

            .profile-hero-content {
                align-items: flex-start;
                gap: 13px;
            }

            .profile-photo-wrapper,
            .profile-photo {
                width: 72px;
                height: 72px;
            }

            .profile-photo {
                border-width: 3px;
                font-size: 20px;
            }

            .photo-upload-button {
                width: 28px;
                height: 28px;
                border-width: 2px;
            }

            .photo-upload-button i {
                font-size: 12px;
            }

            .profile-hero-text h2 {
                font-size: 17px;
                line-height: 1.35;
            }

            .profile-hero-text p {
                font-size: 9px;
            }

            .profile-role-badge {
                margin-top: 8px;
                padding: 5px 7px;
                font-size: 8px;
            }

            .profile-grid {
                gap: 12px;
                margin-top: 12px;
            }

            .panel {
                border-radius: 11px;
            }

            .panel-header {
                padding: 13px 14px;
            }

            .panel-body {
                padding: 13px 14px;
            }

            .panel-title {
                font-size: 12px;
            }

            .panel-subtitle {
                font-size: 9px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .info-item {
                padding: 11px;
            }

            .info-label {
                font-size: 8px;
            }

            .info-value {
                font-size: 10px;
            }

            .alert-custom {
                margin-top: 12px;
                padding: 10px 11px;
                font-size: 9px;
            }

            .password-form {
                gap: 12px;
            }

            .form-label-custom {
                font-size: 9px;
            }

            .form-control-custom {
                min-height: 41px;
                font-size: 10px;
            }

            .password-toggle {
                height: 41px;
            }

            .password-help {
                font-size: 8px;
            }

            .btn-primary-custom {
                min-height: 41px;
                font-size: 10px;
            }

            .location-card {
                padding: 11px;
            }

            .location-icon {
                width: 34px;
                height: 34px;
            }

            .location-title {
                font-size: 10px;
            }

            .location-text {
                font-size: 9px;
            }

            .mobile-bottom-nav {
                min-height: 67px;
                padding-left: 4px;
                padding-right: 4px;
            }

            .mobile-nav-item {
                font-size: 8px;
                gap: 4px;
            }

            .mobile-nav-item i {
                font-size: 18px;
            }
        }

        /* =========================================================
           VERY SMALL PHONES
           ========================================================= */

        @media (max-width: 360px) {

            .profile-hero-content {
                gap: 10px;
            }

            .profile-photo-wrapper,
            .profile-photo {
                width: 62px;
                height: 62px;
            }

            .profile-hero-text h2 {
                font-size: 15px;
            }

            .mobile-nav-item {
                font-size: 7px;
            }

            .mobile-nav-item i {
                font-size: 17px;
            }
        }

        /* =========================================================
           SAFE AREA
           ========================================================= */

        @supports (padding: env(safe-area-inset-bottom)) {

            .mobile-bottom-nav {
                padding-bottom:
                    max(
                        7px,
                        env(safe-area-inset-bottom)
                    );
            }
        }

    </style>

</head>

<body>

<!-- =========================================================
     DESKTOP SIDEBAR
     ========================================================= -->

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
            href="homework.php"
            class="nav-link-custom"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="announcements.php"
            class="nav-link-custom"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

      

        <div class="sidebar-label">
            Account
        </div>

        <a
            href="profile.php"
            class="nav-link-custom active"
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


<!-- =========================================================
     MAIN
     ========================================================= -->

<main class="main">

    <!-- =====================================================
         TOPBAR
         ===================================================== -->

    <header class="topbar">

        <div class="page-heading">

            <h1>
                My Profile
            </h1>

            <p>
                View and manage your teacher account
            </p>

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


    <!-- =====================================================
         CONTENT
         ===================================================== -->

    <div class="content">

        <!-- =================================================
             PROFILE HERO
             ================================================= -->

        <section class="profile-hero">

            <div class="profile-hero-content">

                <div class="profile-photo-wrapper">

                    <div class="profile-photo">

                        <?php if ($teacherPhoto !== ''): ?>

                            <img
                                src="<?= e($teacherPhoto) ?>"
                                alt="<?= e($teacherName) ?>"
                            >

                        <?php else: ?>

                            <?= e($teacherInitials) ?>

                        <?php endif; ?>

                    </div>

                    <label
                        for="profilePhotoInput"
                        class="photo-upload-button"
                        title="Change profile photo"
                    >
                        <i class="bi bi-camera-fill"></i>
                    </label>

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                        class="hero-upload-form"
                        id="photoUploadForm"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="update_photo"
                        >

                        <input
                            type="file"
                            name="profile_photo"
                            id="profilePhotoInput"
                            accept="image/jpeg,image/png,image/webp"
                        >

                    </form>

                </div>

                <div class="profile-hero-text">

                    <h2>
                        <?= e($teacherName) ?>
                    </h2>

                    <p>
                        Teacher account
                        <?php if ($teacherId !== 'Not provided'): ?>
                            · Teacher ID #<?= e($teacherId) ?>
                        <?php endif; ?>
                    </p>

                    <div class="profile-role-badge">

                        <i class="bi bi-shield-check"></i>

                        Teacher

                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             FLASH MESSAGES
             ================================================= -->

        <?php if ($successMessage !== ''): ?>

            <div class="alert-custom alert-success-custom">

                <i class="bi bi-check-circle-fill"></i>

                <div>
                    <?= e((string) $successMessage) ?>
                </div>

            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ''): ?>

            <div class="alert-custom alert-error-custom">

                <i class="bi bi-exclamation-circle-fill"></i>

                <div>
                    <?= e((string) $errorMessage) ?>
                </div>

            </div>

        <?php endif; ?>


        <!-- =================================================
             PROFILE INFORMATION
             ================================================= -->

        <section class="profile-grid">

            <!-- =============================================
                 PERSONAL INFORMATION
                 ============================================= -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h3 class="panel-title">
                            Personal Information
                        </h3>

                        <p class="panel-subtitle">
                            Your registered teacher information
                        </p>

                    </div>

                    <i
                        class="bi bi-person-vcard-fill"
                        style="
                            color:#2563eb;
                            font-size:19px;
                        "
                    ></i>

                </div>

                <div class="panel-body">

                    <div class="info-grid">

                        <div class="info-item">

                            <div class="info-label">
                                Full Name
                            </div>

                            <div class="info-value">
                                <?= e($teacherName) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Gender
                            </div>

                            <div
                                class="info-value
                                <?= $gender === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($gender) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Birth Date
                            </div>

                            <div
                                class="info-value
                                <?= $birthDate === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($birthDate) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Marital Status
                            </div>

                            <div
                                class="info-value
                                <?= $maritalStatus === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($maritalStatus) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Region
                            </div>

                            <div
                                class="info-value
                                <?= $region === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($region) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Zone
                            </div>

                            <div
                                class="info-value
                                <?= $zone === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($zone) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Woreda
                            </div>

                            <div
                                class="info-value
                                <?= $woreda === 'Not provided'
                                    ? 'muted'
                                    : '' ?>"
                            >
                                <?= e($woreda) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Phone
                            </div>

                            <div class="info-value">
                                <?= e($phone) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                Email
                            </div>

                            <div class="info-value">
                                <?= e($email) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =============================================
                 PASSWORD
                 ============================================= -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h3 class="panel-title">
                            Change Password
                        </h3>

                        <p class="panel-subtitle">
                            Update your account password
                        </p>

                    </div>

                    <i
                        class="bi bi-lock-fill"
                        style="
                            color:#2563eb;
                            font-size:19px;
                        "
                    ></i>

                </div>

                <div class="panel-body">

                    <form
                        method="POST"
                        class="password-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="update_password"
                        >

                        <!-- Current Password -->

                        <div>

                            <label
                                class="form-label-custom"
                                for="currentPassword"
                            >
                                Current Password
                            </label>

                            <div class="input-group-custom">

                                <input
                                    type="password"
                                    class="form-control-custom password-field"
                                    id="currentPassword"
                                    name="current_password"
                                    autocomplete="current-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="currentPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>


                        <!-- New Password -->

                        <div>

                            <label
                                class="form-label-custom"
                                for="newPassword"
                            >
                                New Password
                            </label>

                            <div class="input-group-custom">

                                <input
                                    type="password"
                                    class="form-control-custom password-field"
                                    id="newPassword"
                                    name="new_password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="newPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                            <div class="password-help">
                                Password must contain at least 8 characters.
                            </div>

                        </div>


                        <!-- Confirm Password -->

                        <div>

                            <label
                                class="form-label-custom"
                                for="confirmPassword"
                            >
                                Confirm New Password
                            </label>

                            <div class="input-group-custom">

                                <input
                                    type="password"
                                    class="form-control-custom password-field"
                                    id="confirmPassword"
                                    name="confirm_password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="confirmPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>


                        <button
                            type="submit"
                            class="btn-primary-custom"
                        >

                            <i class="bi bi-shield-lock-fill me-1"></i>

                            Update Password

                        </button>

                    </form>

                </div>

            </div>

        </section>


        <!-- =================================================
             PROFESSIONAL INFORMATION
             ================================================= -->

        <section class="panel mt-3">

            <div class="panel-header">

                <div>

                    <h3 class="panel-title">
                        Professional Information
                    </h3>

                    <p class="panel-subtitle">
                        Education and professional details
                    </p>

                </div>

                <i
                    class="bi bi-mortarboard-fill"
                    style="
                        color:#2563eb;
                        font-size:19px;
                    "
                ></i>

            </div>

            <div class="panel-body">

                <div class="info-grid">

                    <div class="info-item">

                        <div class="info-label">
                            Education Level
                        </div>

                        <div
                            class="info-value
                            <?= $educationLevel === 'Not provided'
                                ? 'muted'
                                : '' ?>"
                        >
                            <?= e($educationLevel) ?>
                        </div>

                    </div>

                    <div class="info-item">

                        <div class="info-label">
                            Department
                        </div>

                        <div
                            class="info-value
                            <?= $department === 'Not provided'
                                ? 'muted'
                                : '' ?>"
                        >
                            <?= e($department) ?>
                        </div>

                    </div>

                    <div
                        class="info-item"
                        style="grid-column: 1 / -1;"
                    >

                        <div class="info-label">
                            College / University / Institution
                        </div>

                        <div
                            class="info-value
                            <?= $college === 'Not provided'
                                ? 'muted'
                                : '' ?>"
                        >
                            <?= e($college) ?>
                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             ADDRESS
             ================================================= -->

        <section class="panel mt-3">

            <div class="panel-header">

                <div>

                    <h3 class="panel-title">
                        Address
                    </h3>

                    <p class="panel-subtitle">
                        Registered residential location
                    </p>

                </div>

                <i
                    class="bi bi-geo-alt-fill"
                    style="
                        color:#2563eb;
                        font-size:19px;
                    "
                ></i>

            </div>

            <div class="panel-body">

                <div class="location-card">

                    <div class="location-icon">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>

                    <div>

                        <div class="location-title">
                            Location
                        </div>

                        <div class="location-text">

                            <?php

                            $locationParts = [];

                            if ($region !== 'Not provided') {
                                $locationParts[] = $region;
                            }

                            if ($zone !== 'Not provided') {
                                $locationParts[] = $zone;
                            }

                            if ($woreda !== 'Not provided') {
                                $locationParts[] = $woreda;
                            }

                            $locationText =
                                $locationParts
                                    ? implode(
                                        ' · ',
                                        $locationParts
                                    )
                                    : 'Address information not provided.';

                            ?>

                            <?= e($locationText) ?>

                        </div>

                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             ACCOUNT INFORMATION
             ================================================= -->

     


<!-- =========================================================
     MOBILE BOTTOM NAVIGATION
     ========================================================= -->

<nav
    class="mobile-bottom-nav"
    aria-label="Teacher mobile navigation"
>

    <a
        href="daily-attendance.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-calendar-check-fill"></i>

        <span>
            Daily Attendance
        </span>

    </a>


    <a
        href="homework.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-journal-text"></i>

        <span>
            Homework
        </span>

    </a>


    <a
        href="result.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>
            Result
        </span>

    </a>


    <a
        href="profile.php"
        class="mobile-nav-item active"
    >

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>


    <a
        href="../auth/logout.php"
        class="mobile-nav-item"
    >

        <i class="bi bi-box-arrow-right"></i>

        <span>
            Logout
        </span>

    </a>

</nav>


<script>

/*
|--------------------------------------------------------------------------
| Profile Photo Upload
|--------------------------------------------------------------------------
*/

const profilePhotoInput =
    document.getElementById(
        'profilePhotoInput'
    );

const photoUploadForm =
    document.getElementById(
        'photoUploadForm'
    );

profilePhotoInput?.addEventListener(
    'change',
    function () {

        if (!this.files || !this.files.length) {
            return;
        }

        const file = this.files[0];

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {

            alert(
                'Only JPG, PNG, and WebP images are allowed.'
            );

            this.value = '';

            return;
        }

        const maxSize =
            5 * 1024 * 1024;

        if (file.size > maxSize) {

            alert(
                'Photo size must not exceed 5 MB.'
            );

            this.value = '';

            return;
        }

        photoUploadForm?.submit();
    }
);


/*
|--------------------------------------------------------------------------
| Password Visibility
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.password-toggle')
    .forEach(
        function (button) {

            button.addEventListener(
                'click',
                function () {

                    const targetId =
                        this.getAttribute(
                            'data-target'
                        );

                    const input =
                        document.getElementById(
                            targetId
                        );

                    if (!input) {
                        return;
                    }

                    const icon =
                        this.querySelector('i');

                    if (
                        input.type === 'password'
                    ) {

                        input.type = 'text';

                        if (icon) {
                            icon.className =
                                'bi bi-eye-slash';
                        }

                        this.setAttribute(
                            'aria-label',
                            'Hide password'
                        );

                    } else {

                        input.type = 'password';

                        if (icon) {
                            icon.className =
                                'bi bi-eye';
                        }

                        this.setAttribute(
                            'aria-label',
                            'Show password'
                        );
                    }
                }
            );
        }
    );


/*
|--------------------------------------------------------------------------
| Password Confirmation
|--------------------------------------------------------------------------
*/

const passwordForm =
    document.querySelector(
        '.password-form'
    );

passwordForm?.addEventListener(
    'submit',
    function (event) {

        const newPassword =
            document.getElementById(
                'newPassword'
            );

        const confirmPassword =
            document.getElementById(
                'confirmPassword'
            );

        if (
            !newPassword ||
            !confirmPassword
        ) {
            return;
        }

        if (
            newPassword.value !==
            confirmPassword.value
        ) {

            event.preventDefault();

            alert(
                'New password and confirmation password do not match.'
            );

            confirmPassword.focus();

            return;
        }

        if (
            newPassword.value.length < 8
        ) {

            event.preventDefault();

            alert(
                'New password must be at least 8 characters long.'
            );

            newPassword.focus();
        }
    }
);


/*
|--------------------------------------------------------------------------
| Prevent Accidental Double Submit
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        '.password-form'
    )
    .forEach(
        function (form) {

            form.addEventListener(
                'submit',
                function () {

                    const submitButton =
                        form.querySelector(
                            'button[type="submit"]'
                        );

                    if (!submitButton) {
                        return;
                    }

                    setTimeout(
                        function () {

                            submitButton.disabled =
                                true;

                            submitButton.innerHTML =
                                '<i class="bi bi-arrow-repeat me-1"></i> Updating...';

                        },
                        10
                    );
                }
            );
        }
    );

</script>

</body>

</html>