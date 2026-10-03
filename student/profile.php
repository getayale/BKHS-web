<?php

declare(strict_types=1);

session_start();

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

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

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function formatEthiopianDate(?string $gregorianDate): string
{
    if (
        $gregorianDate === null ||
        trim($gregorianDate) === ''
    ) {
        return 'Not available';
    }

    try {
        $date = substr(
            trim($gregorianDate),
            0,
            10
        );

        $ethiopian = EthiopianCalendar::fromGregorian($date);

        return sprintf(
            '%s %d, %d',
            $ethiopian['month_name'],
            $ethiopian['day'],
            $ethiopian['year']
        );
    } catch (Throwable $e) {
        return 'Not available';
    }
}

function setFlash(
    string $type,
    string $message
): void {
    $_SESSION['student_profile_flash_type'] = $type;
    $_SESSION['student_profile_flash_message'] = $message;
}

function redirectToProfile(): never
{
    header('Location: profile.php');
    exit;
}

function getStoredPhotoPhysicalPath(
    string $photoPath
): ?string {
    $photoPath = ltrim(
        trim($photoPath),
        '/\\'
    );

    if ($photoPath === '') {
        return null;
    }

    /*
     * Only allow deleting files inside the student upload directory.
     */
    $allowedPrefix = 'uploads/students/';

    if (
        strpos(
            $photoPath,
            $allowedPrefix
        ) !== 0
    ) {
        return null;
    }

    $projectRoot = dirname(__DIR__);

    return $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $photoPath
        );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['student_profile_csrf']) ||
    !is_string($_SESSION['student_profile_csrf'])
) {
    $_SESSION['student_profile_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['student_profile_csrf'];

/*
|--------------------------------------------------------------------------
| Handle profile photo upload
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'change_photo'
) {

    /*
     * CSRF check
     */
    $submittedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === '' ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {
        setFlash(
            'danger',
            'Invalid security token. Please try again.'
        );

        redirectToProfile();
    }

    /*
     * Check file
     */
    if (
        !isset($_FILES['profile_photo']) ||
        !is_array($_FILES['profile_photo'])
    ) {
        setFlash(
            'danger',
            'Please select a photo.'
        );

        redirectToProfile();
    }

    $file = $_FILES['profile_photo'];

    if (
        !isset($file['error']) ||
        (int) $file['error'] !== UPLOAD_ERR_OK
    ) {
        setFlash(
            'danger',
            'The photo could not be uploaded.'
        );

        redirectToProfile();
    }

    $fileSize =
        (int) ($file['size'] ?? 0);

    /*
     * Maximum 5 MB
     */
    if (
        $fileSize <= 0 ||
        $fileSize > 5 * 1024 * 1024
    ) {
        setFlash(
            'danger',
            'Photo size must be 5 MB or less.'
        );

        redirectToProfile();
    }

    $tmpName =
        (string) ($file['tmp_name'] ?? '');

    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        setFlash(
            'danger',
            'Invalid uploaded file.'
        );

        redirectToProfile();
    }

    /*
     * Validate MIME type using file contents.
     */
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType =
        $finfo->file($tmpName);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    if (
        !isset(
            $allowedMimeTypes[$mimeType]
        )
    ) {
        setFlash(
            'danger',
            'Only JPG, JPEG, and PNG photos are allowed.'
        );

        redirectToProfile();
    }

    $extension =
        $allowedMimeTypes[$mimeType];

    /*
     * Verify it is actually an image.
     */
    if (@getimagesize($tmpName) === false) {
        setFlash(
            'danger',
            'The selected file is not a valid image.'
        );

        redirectToProfile();
    }

    /*
     * Get current student photo.
     */
    $stmt = $conn->prepare(
        "
        SELECT
            photo_path
        FROM students
        WHERE user_id = ?
          AND is_deleted = 0
        LIMIT 1
        "
    );

    if (!$stmt) {
        setFlash(
            'danger',
            'Unable to load your profile.'
        );

        redirectToProfile();
    }

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $currentPhoto = $result->fetch_assoc();

    $stmt->close();

    if (!$currentPhoto) {
        setFlash(
            'danger',
            'Student profile could not be found.'
        );

        redirectToProfile();
    }

    $oldPhotoPath =
        (string) ($currentPhoto['photo_path'] ?? '');

    /*
     * Upload directory:
     *
     * C:\xampp\htdocs\BKHS\uploads\students
     */
    $projectRoot =
        dirname(__DIR__);

    $uploadDirectory =
        $projectRoot .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'students';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir(
            $uploadDirectory,
            0755,
            true
        )
    ) {
        setFlash(
            'danger',
            'Unable to create the photo upload directory.'
        );

        redirectToProfile();
    }

    /*
     * Generate safe filename.
     */
    $randomName =
        bin2hex(random_bytes(16));

    $newFileName =
        'student_' .
        $userId .
        '_' .
        $randomName .
        '.' .
        $extension;

    $newPhysicalPath =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $newFileName;

    $newRelativePath =
        'uploads/students/' .
        $newFileName;

    /*
     * Move uploaded photo.
     */
    if (
        !move_uploaded_file(
            $tmpName,
            $newPhysicalPath
        )
    ) {
        setFlash(
            'danger',
            'Unable to save the uploaded photo.'
        );

        redirectToProfile();
    }

    /*
     * Update database.
     */
    $stmt = $conn->prepare(
        "
        UPDATE students
        SET photo_path = ?
        WHERE user_id = ?
          AND is_deleted = 0
        "
    );

    if (!$stmt) {

        @unlink($newPhysicalPath);

        setFlash(
            'danger',
            'Unable to update your profile photo.'
        );

        redirectToProfile();
    }

    $stmt->bind_param(
        'si',
        $newRelativePath,
        $userId
    );

    if (!$stmt->execute()) {

        $stmt->close();

        @unlink($newPhysicalPath);

        setFlash(
            'danger',
            'Unable to update your profile photo.'
        );

        redirectToProfile();
    }

    $stmt->close();

    /*
     * Delete old photo only after successful DB update.
     */
    if ($oldPhotoPath !== '') {

        $oldPhysicalPath =
            getStoredPhotoPhysicalPath(
                $oldPhotoPath
            );

        if (
            $oldPhysicalPath !== null &&
            $oldPhysicalPath !== $newPhysicalPath &&
            is_file($oldPhysicalPath)
        ) {
            @unlink($oldPhysicalPath);
        }
    }

    setFlash(
        'success',
        'Your profile photo has been updated successfully.'
    );

    redirectToProfile();
}

/*
|--------------------------------------------------------------------------
| Handle password change
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'change_password'
) {

    /*
     * CSRF check
     */
    $submittedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === '' ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {
        setFlash(
            'danger',
            'Invalid security token. Please try again.'
        );

        redirectToProfile();
    }

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

    /*
     * Basic validation.
     */
    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        setFlash(
            'danger',
            'Please fill in all password fields.'
        );

        redirectToProfile();
    }

    /*
     * Minimum password length.
     */
    if (strlen($newPassword) < 8) {
        setFlash(
            'danger',
            'The new password must be at least 8 characters.'
        );

        redirectToProfile();
    }

    /*
     * Confirm password.
     */
    if ($newPassword !== $confirmPassword) {
        setFlash(
            'danger',
            'The new password and confirmation do not match.'
        );

        redirectToProfile();
    }

    /*
     * Get current password hash.
     */
    $stmt = $conn->prepare(
        "
        SELECT
            password
        FROM users
        WHERE id = ?
          AND LOWER(role) = 'student'
          AND is_deleted = 0
        LIMIT 1
        "
    );

    if (!$stmt) {
        setFlash(
            'danger',
            'Unable to verify your account.'
        );

        redirectToProfile();
    }

    $stmt->bind_param(
        'i',
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();

    if (!$user) {
        setFlash(
            'danger',
            'Student account could not be found.'
        );

        redirectToProfile();
    }

    $storedPassword =
        (string) ($user['password'] ?? '');

    /*
     * Verify current password.
     */
    if (
        $storedPassword === '' ||
        !password_verify(
            $currentPassword,
            $storedPassword
        )
    ) {
        setFlash(
            'danger',
            'Your current password is incorrect.'
        );

        redirectToProfile();
    }

    /*
     * Do not allow the same password.
     */
    if (
        password_verify(
            $newPassword,
            $storedPassword
        )
    ) {
        setFlash(
            'danger',
            'Your new password must be different from your current password.'
        );

        redirectToProfile();
    }

    /*
     * Hash new password.
     */
    $newPasswordHash =
        password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

    if ($newPasswordHash === false) {
        setFlash(
            'danger',
            'Unable to secure the new password.'
        );

        redirectToProfile();
    }

    /*
     * Update password.
     */
    $stmt = $conn->prepare(
        "
        UPDATE users
        SET password = ?
        WHERE id = ?
          AND LOWER(role) = 'student'
          AND is_deleted = 0
        "
    );

    if (!$stmt) {
        setFlash(
            'danger',
            'Unable to update your password.'
        );

        redirectToProfile();
    }

    $stmt->bind_param(
        'si',
        $newPasswordHash,
        $userId
    );

    if (!$stmt->execute()) {

        $stmt->close();

        setFlash(
            'danger',
            'Unable to update your password.'
        );

        redirectToProfile();
    }

    $stmt->close();

    setFlash(
        'success',
        'Your password has been changed successfully.'
    );

    redirectToProfile();
}

/*
|--------------------------------------------------------------------------
| Get student profile
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "
    SELECT
        s.id AS student_id,
        s.user_id,
        s.student_code,
        s.full_name,
        s.date_of_birth,
        s.gender,
        s.region,
        s.zone,
        s.woreda,
        s.fyda_number,
        s.photo_path,

        u.email,
        u.phone,

        sr.id AS registration_id,

        g.grade_number,

        sec.code AS section,

        ay.id AS academic_year_id,
        ay.name AS academic_year

    FROM students s

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN student_registrations sr
        ON sr.student_id = s.id

    LEFT JOIN grades g
        ON g.id = sr.grade_id

    LEFT JOIN sections sec
        ON sec.id = sr.section_id

    LEFT JOIN academic_years ay
        ON ay.id = sr.academic_year_id
        AND ay.status = 'Active'

    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0

    ORDER BY
        CASE
            WHEN ay.id IS NOT NULL THEN 0
            ELSE 1
        END,
        sr.id DESC

    LIMIT 1
    "
);

if (!$stmt) {
    die('Unable to prepare student profile query.');
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
    die('Student profile could not be found.');
}

/*
|--------------------------------------------------------------------------
| Student data
|--------------------------------------------------------------------------
*/

$studentName =
    (string) $student['full_name'];

$studentCode =
    (string) $student['student_code'];

$gender =
    (string) $student['gender'];

$dateOfBirth =
    (string) $student['date_of_birth'];

$region =
    (string) ($student['region'] ?? '');

$zone =
    (string) ($student['zone'] ?? '');

$woreda =
    (string) ($student['woreda'] ?? '');

$fydaNumber =
    (string) ($student['fyda_number'] ?? '');

$email =
    (string) ($student['email'] ?? '');

$phone =
    (string) ($student['phone'] ?? '');

$photoPath =
    trim((string) ($student['photo_path'] ?? ''));

$gradeNumber =
    $student['grade_number'] !== null
        ? (int) $student['grade_number']
        : null;

$section =
    $student['section'] !== null
        ? (string) $student['section']
        : null;

$academicYear =
    $student['academic_year'] !== null
        ? (string) $student['academic_year']
        : 'No active academic year';

/*
|--------------------------------------------------------------------------
| Photo URL
|--------------------------------------------------------------------------
*/

$photoUrl = null;

if ($photoPath !== '') {

    $photoPath = ltrim(
        $photoPath,
        '/\\'
    );

    $photoUrl = '../' . $photoPath;
}

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flashType =
    $_SESSION['student_profile_flash_type'] ?? null;

$flashMessage =
    $_SESSION['student_profile_flash_message'] ?? null;

unset(
    $_SESSION['student_profile_flash_type'],
    $_SESSION['student_profile_flash_message']
);

$pageTitle = 'My Profile';

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
        <?= e($pageTitle) ?> - BKHS
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

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f5f7fb;
            --border: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;

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

        .app-wrapper {
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: #ffffff;
            border-right: 1px solid var(--border);
            z-index: 1050;
            overflow-y: auto;
            transition: transform 0.25s ease;
        }

        .sidebar-header {
            height: 72px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-logo {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .sidebar-title {
            font-weight: 700;
            font-size: 17px;
        }

        .sidebar-subtitle {
            font-size: 12px;
            color: var(--muted);
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 5px;
            color: #4b5563;
            text-decoration: none;
            border-radius: 9px;
            font-size: 14px;
            transition: 0.2s;
        }

        .sidebar-nav a:hover {
            background: #eff6ff;
            color: var(--primary);
        }

        .sidebar-nav a.active {
            background: #dbeafe;
            color: var(--primary);
            font-weight: 600;
        }

        .sidebar-nav i {
            width: 20px;
            font-size: 17px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            min-height: 72px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 28px;
        }

        .menu-button {
            display: none;
        }

        .page-content {
            padding: 28px;
            max-width: 1250px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 27px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | Profile header
        |--------------------------------------------------------------------------
        */

        .profile-header {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .profile-photo-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .profile-photo {
            width: 115px;
            height: 115px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #eff6ff;
        }

        .profile-photo-placeholder {
            width: 115px;
            height: 115px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            border: 4px solid #dbeafe;
        }

        .profile-name {
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-code {
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .profile-meta {
            color: var(--muted);
            font-size: 13px;
        }

        .photo-actions {
            margin-top: 15px;
        }

        /*
        |--------------------------------------------------------------------------
        | Cards
        |--------------------------------------------------------------------------
        */

        .info-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .info-card-header {
            padding: 17px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card-header-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .info-card-title {
            font-size: 16px;
            font-weight: 700;
        }

        .info-card-body {
            padding: 20px;
        }

        .info-item {
            margin-bottom: 20px;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .empty-value {
            color: #9ca3af;
            font-weight: 400;
        }

        /*
        |--------------------------------------------------------------------------
        | Academic
        |--------------------------------------------------------------------------
        */

        .academic-box {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 15px;
            height: 100%;
        }

        .academic-box-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .academic-box-value {
            font-size: 18px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Notice
        |--------------------------------------------------------------------------
        */

        .profile-notice {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 11px;
            padding: 13px 15px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /*
        |--------------------------------------------------------------------------
        | Password
        |--------------------------------------------------------------------------
        */

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .password-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--muted);
            width: 35px;
            height: 35px;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Overlay
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: 1040;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-nav-item {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Tablet / Mobile Sidebar
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
            }

            .menu-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 42px;
                height: 42px;
                border: 1px solid var(--border);
                background: #ffffff;
                border-radius: 9px;
                font-size: 20px;
            }

            .page-content {
                padding: 20px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media screen and (max-width: 767.98px) {

            body {
                padding-bottom: calc(
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

                height: calc(
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
                    1px solid #e5e7eb !important;

                box-shadow:
                    0 -4px 20px rgba(0, 0, 0, .10) !important;

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

                -webkit-tap-highlight-color:
                    transparent;
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

            .topbar {
                padding: 10px 15px;
            }

            .page-content {
                padding: 15px;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .profile-header {
                padding: 20px;
            }

            .profile-header-content {
                flex-direction: column;
                text-align: center;
            }

            .profile-name {
                font-size: 22px;
            }

            .photo-actions form {
                justify-content: center;
            }

            .photo-actions input[type="file"] {
                max-width: 100% !important;
                width: 100%;
            }

            .photo-actions .btn {
                width: 100%;
            }

            .info-card-body {
                padding: 16px;
            }

            .academic-box-value {
                font-size: 16px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Very Small Screens
        |--------------------------------------------------------------------------
        */

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

    </style>

</head>

<body>

<div class="app-wrapper">

    <!-- Sidebar -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="sidebar-header">

            <img
                src="../public/image/logo.webp"
                alt="BKHS"
                class="sidebar-logo"
            >

            <div>

                <div class="sidebar-title">
                    BKHS
                </div>

                <div class="sidebar-subtitle">
                    Student Portal
                </div>

            </div>

        </div>

        <nav class="sidebar-nav">

            <a
                href="dashboard.php"
                class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="subjects.php"
                class="<?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-book"></i>
                <span>My Subjects</span>
            </a>

            <a
                href="materials.php"
                class="<?= $currentPage === 'materials.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-folder2-open"></i>
                <span>Materials</span>
            </a>

            <a
                href="result.php"
                class="<?= $currentPage === 'result.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-bar-chart"></i>
                <span>Results</span>
            </a>

            <a
                href="attendance.php"
                class="<?= $currentPage === 'attendance.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-calendar-check"></i>
                <span>Attendance</span>
            </a>

            <a
                href="homework.php"
                class="<?= $currentPage === 'homework.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-journal-text"></i>
                <span>Homework</span>
            </a>

            <a
                href="announcements.php"
                class="<?= $currentPage === 'announcements.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-megaphone"></i>
                <span>Announcements</span>
            </a>

            <a
                href="profile.php"
                class="<?= $currentPage === 'profile.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-person"></i>
                <span>Profile</span>
            </a>

            <hr>

            <a href="../auth/logout.php">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </nav>

    </aside>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <!-- Main -->

    <main class="main-content">

        <!-- Topbar -->

        <header class="topbar">

            <div class="d-flex align-items-center gap-3">

                <button
                    type="button"
                    class="menu-button"
                    id="menuButton"
                    aria-label="Open menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>

                    <div class="fw-semibold">
                        My Profile
                    </div>

                    <small class="text-muted">
                        Student Information
                    </small>

                </div>

            </div>

            <div class="d-flex align-items-center gap-2">

                <i class="bi bi-person-circle fs-4 text-secondary"></i>

                <div class="d-none d-sm-block">

                    <div class="small fw-semibold">
                        <?= e($studentName) ?>
                    </div>

                    <div class="small text-muted">
                        <?= e($studentCode) ?>
                    </div>

                </div>

            </div>

        </header>

        <div class="page-content">

            <!-- Heading -->

            <div class="page-heading">

                <h1>
                    My Profile
                </h1>

                <p>
                    View and manage your profile settings.
                </p>

            </div>

            <!-- Flash -->

            <?php if ($flashMessage !== null): ?>

                <div
                    class="alert alert-<?= $flashType === 'danger' ? 'danger' : 'success' ?> alert-dismissible fade show"
                    role="alert"
                >

                    <i
                        class="bi bi-<?= $flashType === 'danger'
                            ? 'exclamation-triangle'
                            : 'check-circle' ?> me-2"
                    ></i>

                    <?= e((string) $flashMessage) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <!-- Profile Header -->

            <div class="profile-header">

                <div class="profile-header-content">

                    <div class="profile-photo-wrapper">

                        <?php if ($photoUrl !== null): ?>

                            <img
                                src="<?= e($photoUrl) ?>"
                                alt="<?= e($studentName) ?>"
                                class="profile-photo"
                                id="profilePhotoPreview"
                            >

                        <?php else: ?>

                            <div
                                class="profile-photo-placeholder"
                                id="profilePhotoPlaceholder"
                            >
                                <i class="bi bi-person"></i>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="flex-grow-1">

                        <div class="profile-name">
                            <?= e($studentName) ?>
                        </div>

                        <div class="profile-code">

                            <i class="bi bi-upc-scan me-1"></i>

                            <?= e($studentCode) ?>

                        </div>

                        <div class="profile-meta">

                            <?php if ($gradeNumber !== null): ?>

                                Grade <?= $gradeNumber ?>

                                <?php if ($section !== null): ?>

                                    · Section <?= e($section) ?>

                                <?php endif; ?>

                            <?php else: ?>

                                No active registration

                            <?php endif; ?>

                        </div>

                        <!-- Change Photo -->

                        <div class="photo-actions">

                            <form
                                action="profile.php"
                                method="POST"
                                enctype="multipart/form-data"
                                class="d-flex flex-wrap align-items-center gap-2"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_photo"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="file"
                                    name="profile_photo"
                                    id="profilePhotoInput"
                                    class="form-control form-control-sm"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                    style="max-width: 280px;"
                                    required
                                >

                                <button
                                    type="submit"
                                    class="btn btn-primary btn-sm"
                                >

                                    <i class="bi bi-camera me-1"></i>

                                    Change Photo

                                </button>

                            </form>

                            <small class="text-muted d-block mt-2">
                                JPG, JPEG, or PNG. Maximum 5 MB.
                            </small>

                        </div>

                    </div>

                </div>

            </div>

            <div class="row">

                <!-- Personal Information -->

                <div class="col-lg-6">

                    <div class="info-card">

                        <div class="info-card-header">

                            <div class="info-card-header-icon">
                                <i class="bi bi-person-vcard"></i>
                            </div>

                            <div class="info-card-title">
                                Personal Information
                            </div>

                        </div>

                        <div class="info-card-body">

                            <div class="row">

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Full Name
                                        </div>

                                        <div class="info-value">
                                            <?= e($studentName) ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Student Code
                                        </div>

                                        <div class="info-value">
                                            <?= e($studentCode) ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Gender
                                        </div>

                                        <div class="info-value">

                                            <?= $gender !== ''
                                                ? e($gender)
                                                : '<span class="empty-value">Not provided</span>' ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Date of Birth
                                        </div>

                                        <div class="info-value">

                                            <?= e(
                                                formatEthiopianDate(
                                                    $dateOfBirth
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Region
                                        </div>

                                        <div class="info-value">

                                            <?= $region !== ''
                                                ? e($region)
                                                : '<span class="empty-value">Not provided</span>' ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Zone
                                        </div>

                                        <div class="info-value">

                                            <?= $zone !== ''
                                                ? e($zone)
                                                : '<span class="empty-value">Not provided</span>' ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            Woreda
                                        </div>

                                        <div class="info-value">

                                            <?= $woreda !== ''
                                                ? e($woreda)
                                                : '<span class="empty-value">Not provided</span>' ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-sm-6">

                                    <div class="info-item">

                                        <div class="info-label">
                                            FYDA Number
                                        </div>

                                        <div class="info-value">

                                            <?= $fydaNumber !== ''
                                                ? e($fydaNumber)
                                                : '<span class="empty-value">Not provided</span>' ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Contact Information -->

                <div class="col-lg-6">

                    <div class="info-card">

                        <div class="info-card-header">

                            <div class="info-card-header-icon">
                                <i class="bi bi-telephone"></i>
                            </div>

                            <div class="info-card-title">
                                Contact Information
                            </div>

                        </div>

                        <div class="info-card-body">

                            <div class="info-item">

                                <div class="info-label">
                                    Phone Number
                                </div>

                                <div class="info-value">

                                    <?php if ($phone !== ''): ?>

                                        <a
                                            href="tel:<?= e($phone) ?>"
                                            class="text-decoration-none"
                                        >

                                            <i
                                                class="bi bi-telephone me-1"
                                            ></i>

                                            <?= e($phone) ?>

                                        </a>

                                    <?php else: ?>

                                        <span class="empty-value">
                                            Not provided
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">
                                    Email Address
                                </div>

                                <div class="info-value">

                                    <?php if ($email !== ''): ?>

                                        <a
                                            href="mailto:<?= e($email) ?>"
                                            class="text-decoration-none"
                                        >

                                            <i
                                                class="bi bi-envelope me-1"
                                            ></i>

                                            <?= e($email) ?>

                                        </a>

                                    <?php else: ?>

                                        <span class="empty-value">
                                            Not provided
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Academic Information -->

            <div class="info-card">

                <div class="info-card-header">

                    <div class="info-card-header-icon">
                        <i class="bi bi-mortarboard"></i>
                    </div>

                    <div class="info-card-title">
                        Current Academic Information
                    </div>

                </div>

                <div class="info-card-body">

                    <div class="row g-3">

                        <div class="col-12 col-md-4">

                            <div class="academic-box">

                                <div class="academic-box-label">
                                    Academic Year
                                </div>

                                <div class="academic-box-value">
                                    <?= e($academicYear) ?>
                                </div>

                            </div>

                        </div>

                        <div class="col-6 col-md-4">

                            <div class="academic-box">

                                <div class="academic-box-label">
                                    Grade
                                </div>

                                <div class="academic-box-value">

                                    <?php if ($gradeNumber !== null): ?>

                                        Grade <?= $gradeNumber ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                        <div class="col-6 col-md-4">

                            <div class="academic-box">

                                <div class="academic-box-label">
                                    Section
                                </div>

                                <div class="academic-box-value">

                                    <?php if ($section !== null): ?>

                                        <?= e($section) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Change Password -->

            <div class="info-card">

                <div class="info-card-header">

                    <div class="info-card-header-icon">
                        <i class="bi bi-shield-lock"></i>
                    </div>

                    <div class="info-card-title">
                        Change Password
                    </div>

                </div>

                <div class="info-card-body">

                    <div class="profile-notice mb-4">

                        <i class="bi bi-info-circle me-2"></i>

                        Enter your current password before creating
                        a new password.

                    </div>

                    <form
                        action="profile.php"
                        method="POST"
                        autocomplete="off"
                        id="passwordChangeForm"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="change_password"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <div class="row g-3">

                            <div class="col-12 col-md-4">

                                <label
                                    for="currentPassword"
                                    class="form-label"
                                >
                                    Current Password
                                </label>

                                <div class="password-wrapper">

                                    <input
                                        type="password"
                                        class="form-control"
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

                            <div class="col-12 col-md-4">

                                <label
                                    for="newPassword"
                                    class="form-label"
                                >
                                    New Password
                                </label>

                                <div class="password-wrapper">

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="newPassword"
                                        name="new_password"
                                        minlength="8"
                                        autocomplete="new-password"
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

                                <div class="form-text">
                                    Minimum 8 characters.
                                </div>

                            </div>

                            <div class="col-12 col-md-4">

                                <label
                                    for="confirmPassword"
                                    class="form-label"
                                >
                                    Confirm New Password
                                </label>

                                <div class="password-wrapper">

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="confirmPassword"
                                        name="confirm_password"
                                        minlength="8"
                                        autocomplete="new-password"
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

                        </div>

                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >

                                <i class="bi bi-key me-1"></i>

                                Change Password

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </main>

</div>

<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
|
| Five primary student navigation items:
| Home / Subjects / Materials / Homework / Results
|
| Profile remains available from the mobile sidebar.
|--------------------------------------------------------------------------
-->

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

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Mobile sidebar
|--------------------------------------------------------------------------
*/

const menuButton =
    document.getElementById('menuButton');

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

function openSidebar() {

    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('show');
    }

}

function closeSidebar() {

    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('show');
    }

}

if (menuButton) {

    menuButton.addEventListener(
        'click',
        openSidebar
    );

}

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );

}

document.querySelectorAll(
    '.sidebar a'
).forEach(function (link) {

    link.addEventListener(
        'click',
        function () {

            if (window.innerWidth <= 991) {
                closeSidebar();
            }

        }
    );

});

/*
|--------------------------------------------------------------------------
| Password visibility
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    '.password-toggle'
).forEach(function (button) {

    button.addEventListener(
        'click',
        function () {

            const targetId =
                button.getAttribute('data-target');

            const input =
                document.getElementById(targetId);

            const icon =
                button.querySelector('i');

            if (!input) {
                return;
            }

            if (input.type === 'password') {

                input.type = 'text';

                if (icon) {
                    icon.className =
                        'bi bi-eye-slash';
                }

                button.setAttribute(
                    'aria-label',
                    'Hide password'
                );

            } else {

                input.type = 'password';

                if (icon) {
                    icon.className =
                        'bi bi-eye';
                }

                button.setAttribute(
                    'aria-label',
                    'Show password'
                );

            }

        }
    );

});

/*
|--------------------------------------------------------------------------
| Photo preview
|--------------------------------------------------------------------------
*/

const photoInput =
    document.getElementById(
        'profilePhotoInput'
    );

if (photoInput) {

    photoInput.addEventListener(
        'change',
        function () {

            const file =
                photoInput.files[0];

            if (!file) {
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
                    'Please select a JPG, JPEG, or PNG image.'
                );

                photoInput.value = '';

                return;
            }

            if (
                file.size >
                5 * 1024 * 1024
            ) {

                alert(
                    'Photo size must be 5 MB or less.'
                );

                photoInput.value = '';

                return;
            }

            const reader =
                new FileReader();

            reader.onload =
                function (event) {

                    const preview =
                        document.getElementById(
                            'profilePhotoPreview'
                        );

                    const placeholder =
                        document.getElementById(
                            'profilePhotoPlaceholder'
                        );

                    if (preview) {

                        preview.src =
                            event.target.result;

                    } else if (placeholder) {

                        const image =
                            document.createElement(
                                'img'
                            );

                        image.src =
                            event.target.result;

                        image.alt =
                            'Profile photo';

                        image.className =
                            'profile-photo';

                        image.id =
                            'profilePhotoPreview';

                        placeholder.replaceWith(
                            image
                        );

                    }

                };

            reader.readAsDataURL(file);

        }
    );

}

/*
|--------------------------------------------------------------------------
| Password confirmation
|--------------------------------------------------------------------------
*/

const passwordForm =
    document.getElementById(
        'passwordChangeForm'
    );

const newPassword =
    document.getElementById(
        'newPassword'
    );

const confirmPassword =
    document.getElementById(
        'confirmPassword'
    );

function validatePasswordConfirmation() {

    if (
        !newPassword ||
        !confirmPassword
    ) {
        return;
    }

    if (
        confirmPassword.value === ''
    ) {

        confirmPassword.setCustomValidity('');

        return;
    }

    if (
        confirmPassword.value !==
        newPassword.value
    ) {

        confirmPassword.setCustomValidity(
            'Passwords do not match.'
        );

    } else {

        confirmPassword.setCustomValidity('');

    }

}

if (
    newPassword &&
    confirmPassword
) {

    confirmPassword.addEventListener(
        'input',
        validatePasswordConfirmation
    );

    newPassword.addEventListener(
        'input',
        validatePasswordConfirmation
    );

}

if (passwordForm) {

    passwordForm.addEventListener(
        'submit',
        function (event) {

            validatePasswordConfirmation();

            if (
                !passwordForm.checkValidity()
            ) {

                event.preventDefault();

                passwordForm.classList.add(
                    'was-validated'
                );

            }

        }
    );

}

/*
|--------------------------------------------------------------------------
| Close sidebar with Escape
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function (event) {

        if (event.key === 'Escape') {
            closeSidebar();
        }

    }
);

</script>

</body>

</html>