<?php
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): void
{
    $_SESSION['profile_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: profile.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Addis Ababa Subcities + Woreda Counts
|--------------------------------------------------------------------------
|
| Woreda numbering is displayed as Woreda 01, Woreda 02, etc.
| The number of Woredas is determined by the selected subcity.
|
*/

$subcityWoredaCounts = [
    'Addis Ketema'       => 12,
    'Akaki Kaliti'       => 12,
    'Arada'              => 8,
    'Bole'               => 11,
    'Gullele'            => 10,
    'Kirkos'             => 10,
    'Kolfe Keranio'      => 11,
    'Lideta'             => 10,
    'Nifas Silk-Lafto'   => 13,
    'Yeka'               => 12,
    'Lemi Kura'          => 10
];

$subcities = array_keys($subcityWoredaCounts);

/*
|--------------------------------------------------------------------------
| Upload Directories
|--------------------------------------------------------------------------
*/

$photoDirectory = '../uploads/registrars/photos/';
$educationDirectory = '../uploads/registrars/education/';
$experienceDirectory = '../uploads/registrars/experience/';

if (!is_dir($photoDirectory)) {
    mkdir($photoDirectory, 0777, true);
}

if (!is_dir($educationDirectory)) {
    mkdir($educationDirectory, 0777, true);
}

if (!is_dir($experienceDirectory)) {
    mkdir($experienceDirectory, 0777, true);
}

/*
|--------------------------------------------------------------------------
| Session Flash Message
|--------------------------------------------------------------------------
*/

$flashMessage = $_SESSION['profile_message'] ?? null;
unset($_SESSION['profile_message']);

/*
|--------------------------------------------------------------------------
| Handle Profile Update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $region = 'Addis Ababa';

    $subcity = trim($_POST['subcity'] ?? '');
    $woreda = trim($_POST['woreda'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $maritalStatus = trim($_POST['marital_status'] ?? '');

    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $educationLevel = trim($_POST['education_level'] ?? '');
    $collegeUniversity = trim($_POST['college_university'] ?? '');

    $experienceYears = (float) ($_POST['experience_years'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (!in_array($subcity, $subcities, true)) {
        redirectWithMessage('danger', 'Please select a valid Addis Ababa subcity.');
    }

    $woredaCount = $subcityWoredaCounts[$subcity];

    $validWoreda = false;

    for ($i = 1; $i <= $woredaCount; $i++) {
        if ($woreda === 'Woreda ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)) {
            $validWoreda = true;
            break;
        }
    }

    if (!$validWoreda) {
        redirectWithMessage('danger', 'Please select a valid Woreda for the selected subcity.');
    }

    if (!in_array($gender, ['Male', 'Female'], true)) {
        redirectWithMessage('danger', 'Please select a valid gender.');
    }

    if (!in_array(
        $maritalStatus,
        ['Single', 'Married', 'Divorced', 'Widowed'],
        true
    )) {
        redirectWithMessage('danger', 'Please select a valid marital status.');
    }

    if ($dateOfBirth === '') {
        redirectWithMessage('danger', 'Date of birth is required.');
    }

    if ($educationLevel === '') {
        redirectWithMessage('danger', 'Education level is required.');
    }

    if ($collegeUniversity === '') {
        redirectWithMessage('danger', 'College/University is required.');
    }

    if ($experienceYears < 0) {
        redirectWithMessage('danger', 'Experience years cannot be negative.');
    }

    if ($experienceYears > 99.9) {
        redirectWithMessage('danger', 'Experience years cannot be greater than 99.9.');
    }

    /*
    |--------------------------------------------------------------------------
    | Get Existing Registrar
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            region,
            subcity,
            woreda,
            gender,
            marital_status,
            photo,
            date_of_birth,
            education_level,
            college_university,
            educational_file,
            experience_years,
            experience_file
        FROM registrars
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $existingRegistrar = $result->fetch_assoc();

    $stmt->close();

    $oldPhoto = $existingRegistrar['photo'] ?? null;
    $oldEducationalFile = $existingRegistrar['educational_file'] ?? null;
    $oldExperienceFile = $existingRegistrar['experience_file'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Photo Upload
    |--------------------------------------------------------------------------
    */

    $photoValue = $oldPhoto;

    if (
        isset($_FILES['photo']) &&
        $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            redirectWithMessage('danger', 'There was a problem uploading the photo.');
        }

        if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            redirectWithMessage('danger', 'Photo size must not exceed 5 MB.');
        }

        $allowedPhotoTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedPhotoTypes, true)) {
            redirectWithMessage(
                'danger',
                'Only JPG, PNG, and WEBP images are allowed.'
            );
        }

        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        $extension = $extensionMap[$mimeType];

        $newFileName = 'registrar_' . $userId . '_' . time() . '.' . $extension;

        $destination = $photoDirectory . $newFileName;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
            redirectWithMessage('danger', 'Unable to save the uploaded photo.');
        }

        $photoValue = 'uploads/registrars/photos/' . $newFileName;

        /*
        | Delete previous photo
        */

        if (!empty($oldPhoto)) {

            $oldPhotoPath = '../' . ltrim($oldPhoto, '/');

            if (is_file($oldPhotoPath)) {
                @unlink($oldPhotoPath);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Educational File Upload
    |--------------------------------------------------------------------------
    */

    $educationFileValue = $oldEducationalFile;

    if (
        isset($_FILES['educational_file']) &&
        $_FILES['educational_file']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['educational_file']['error'] !== UPLOAD_ERR_OK) {
            redirectWithMessage(
                'danger',
                'There was a problem uploading the educational file.'
            );
        }

        if ($_FILES['educational_file']['size'] > 10 * 1024 * 1024) {
            redirectWithMessage(
                'danger',
                'Educational file size must not exceed 10 MB.'
            );
        }

        $allowedEducationTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file(
            $finfo,
            $_FILES['educational_file']['tmp_name']
        );
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedEducationTypes, true)) {
            redirectWithMessage(
                'danger',
                'Educational file must be PDF, JPG, or PNG.'
            );
        }

        $extensionMap = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];

        $extension = $extensionMap[$mimeType];

        $newFileName =
            'education_' .
            $userId .
            '_' .
            time() .
            '.' .
            $extension;

        $destination = $educationDirectory . $newFileName;

        if (
            !move_uploaded_file(
                $_FILES['educational_file']['tmp_name'],
                $destination
            )
        ) {
            redirectWithMessage(
                'danger',
                'Unable to save the educational file.'
            );
        }

        $educationFileValue =
            'uploads/registrars/education/' . $newFileName;

        if (!empty($oldEducationalFile)) {

            $oldFilePath = '../' . ltrim($oldEducationalFile, '/');

            if (is_file($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Experience File Upload
    |--------------------------------------------------------------------------
    */

    $experienceFileValue = $oldExperienceFile;

    if ($experienceYears <= 0) {
        $experienceFileValue = null;
    }

    if (
        $experienceYears > 0 &&
        isset($_FILES['experience_file']) &&
        $_FILES['experience_file']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['experience_file']['error'] !== UPLOAD_ERR_OK) {
            redirectWithMessage(
                'danger',
                'There was a problem uploading the experience file.'
            );
        }

        if ($_FILES['experience_file']['size'] > 10 * 1024 * 1024) {
            redirectWithMessage(
                'danger',
                'Experience file size must not exceed 10 MB.'
            );
        }

        $allowedExperienceTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file(
            $finfo,
            $_FILES['experience_file']['tmp_name']
        );
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedExperienceTypes, true)) {
            redirectWithMessage(
                'danger',
                'Experience file must be PDF, JPG, or PNG.'
            );
        }

        $extensionMap = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];

        $extension = $extensionMap[$mimeType];

        $newFileName =
            'experience_' .
            $userId .
            '_' .
            time() .
            '.' .
            $extension;

        $destination = $experienceDirectory . $newFileName;

        if (
            !move_uploaded_file(
                $_FILES['experience_file']['tmp_name'],
                $destination
            )
        ) {
            redirectWithMessage(
                'danger',
                'Unable to save the experience file.'
            );
        }

        $experienceFileValue =
            'uploads/registrars/experience/' . $newFileName;

        if (!empty($oldExperienceFile)) {

            $oldFilePath = '../' . ltrim($oldExperienceFile, '/');

            if (is_file($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert or Update Registrar
    |--------------------------------------------------------------------------
    */

    if ($existingRegistrar) {

        $stmt = $conn->prepare("
            UPDATE registrars
            SET
                region = ?,
                subcity = ?,
                woreda = ?,
                gender = ?,
                marital_status = ?,
                photo = ?,
                date_of_birth = ?,
                education_level = ?,
                college_university = ?,
                educational_file = ?,
                experience_years = ?,
                experience_file = ?
            WHERE user_id = ?
        ");

        $stmt->bind_param(
            'ssssssssssdsi',
            $region,
            $subcity,
            $woreda,
            $gender,
            $maritalStatus,
            $photoValue,
            $dateOfBirth,
            $educationLevel,
            $collegeUniversity,
            $educationFileValue,
            $experienceYears,
            $experienceFileValue,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            redirectWithMessage(
                'danger',
                'Unable to update your profile: ' . $error
            );
        }

        $stmt->close();

    } else {

        $stmt = $conn->prepare("
            INSERT INTO registrars (
                user_id,
                region,
                subcity,
                woreda,
                gender,
                marital_status,
                photo,
                date_of_birth,
                education_level,
                college_university,
                educational_file,
                experience_years,
                experience_file
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

     $stmt->bind_param(
    'issssssssssds',
    $userId,
    $region,
    $subcity,
    $woreda,
    $gender,
    $maritalStatus,
    $photoValue,
    $dateOfBirth,
    $educationLevel,
    $collegeUniversity,
    $educationFileValue,
    $experienceYears,
    $experienceFileValue
);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            redirectWithMessage(
                'danger',
                'Unable to create your profile: ' . $error
            );
        }

        $stmt->close();
    }

    redirectWithMessage(
        'success',
        'Your profile has been updated successfully.'
    );
}

/*
|--------------------------------------------------------------------------
| Handle Password Change
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        redirectWithMessage(
            'danger',
            'Please fill in all password fields.'
        );
    }

    if ($newPassword !== $confirmPassword) {
        redirectWithMessage(
            'danger',
            'New password and confirmation password do not match.'
        );
    }

    if (strlen($newPassword) < 8) {
        redirectWithMessage(
            'danger',
            'New password must contain at least 8 characters.'
        );
    }

    $stmt = $conn->prepare("
        SELECT password
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $userPassword = $result->fetch_assoc();

    $stmt->close();

    if (!$userPassword) {
        redirectWithMessage(
            'danger',
            'User account could not be found.'
        );
    }

    if (!password_verify($currentPassword, $userPassword['password'])) {
        redirectWithMessage(
            'danger',
            'Current password is incorrect.'
        );
    }

    $hashedPassword = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    $stmt = $conn->prepare("
        UPDATE users
        SET password = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        'si',
        $hashedPassword,
        $userId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        redirectWithMessage(
            'danger',
            'Unable to change password: ' . $error
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
| Load User + Registrar Profile
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,

        r.id AS registrar_id,
        r.region,
        r.subcity,
        r.woreda,
        r.gender,
        r.marital_status,
        r.photo,
        r.date_of_birth,
        r.education_level,
        r.college_university,
        r.educational_file,
        r.experience_years,
        r.experience_file

    FROM users u

    LEFT JOIN registrars r
        ON r.user_id = u.id

    WHERE u.id = ?

    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$registrar = $result->fetch_assoc();

$stmt->close();

if (!$registrar) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Profile Values
|--------------------------------------------------------------------------
*/

$fullName = $registrar['full_name'] ?? '';
$email = $registrar['email'] ?? '';
$phone = $registrar['phone'] ?? '';

$region = $registrar['region'] ?: 'Addis Ababa';

$selectedSubcity = $registrar['subcity'] ?? '';
$selectedWoreda = $registrar['woreda'] ?? '';

$selectedGender = $registrar['gender'] ?? '';
$selectedMaritalStatus = $registrar['marital_status'] ?? 'Single';

$dateOfBirth = $registrar['date_of_birth'] ?? '';
$educationLevel = $registrar['education_level'] ?? '';
$collegeUniversity = $registrar['college_university'] ?? '';

$experienceYears = $registrar['experience_years'] ?? '0.0';

$photo = $registrar['photo'] ?? null;
$educationalFile = $registrar['educational_file'] ?? null;
$experienceFile = $registrar['experience_file'] ?? null;

/*
|--------------------------------------------------------------------------
| Photo URL
|--------------------------------------------------------------------------
*/

$photoUrl = '../public/images/default-avatar.png';

if (!empty($photo)) {
    $photoUrl = '../' . ltrim($photo, '/');
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

    <title>Registrar Profile | BKHS</title>
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            color: #172033;
        }

        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #111827;
            color: #fff;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 20px;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            margin: 0;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-menu {
            padding: 22px 14px;
            overflow-y: auto;
            flex: 1;
        }

        .menu-label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 13px;
            border-radius: 10px;
            color: #d1d5db;
            text-decoration: none;
            margin-bottom: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .menu-item i {
            font-size: 17px;
            width: 22px;
            text-align: center;
        }

        .menu-item:hover {
            background: rgba(255,255,255,.07);
            color: #fff;
        }

        .menu-item.active {
            background: #2563eb;
            color: #fff;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,.08);
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-mini img {
            width: 38px;
            height: 38px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,.15);
        }

        .profile-mini-name {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
        }

        .profile-mini-role {
            color: #9ca3af;
            font-size: 10px;
            margin-top: 2px;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-user {
            font-size: 13px;
            font-weight: 600;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: transparent;
            font-size: 25px;
            color: #111827;
        }

        .content {
            padding: 30px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .profile-hero {
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            border-radius: 18px;
            padding: 28px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(37,99,235,.15);
        }

        .profile-hero-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .hero-photo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,.35);
            background: #fff;
        }

        .hero-name {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .hero-email {
            color: rgba(255,255,255,.8);
            font-size: 13px;
        }

        .hero-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.15);
            padding: 7px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 12px;
        }

        .card-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            margin-bottom: 22px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(15,23,42,.03);
        }

        .card-header-custom {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .section-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .section-description {
            margin: 3px 0 0;
            color: #6b7280;
            font-size: 11px;
        }

        .card-body-custom {
            padding: 24px 22px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-radius: 9px;
            border-color: #dfe3e8;
            font-size: 13px;
            box-shadow: none !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
        }

        .form-control[readonly] {
            background: #f8fafc;
            color: #64748b;
        }

        .field-note {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 5px;
        }

        .btn-primary-custom {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            border-radius: 9px;
            padding: 11px 18px;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .btn-secondary-custom {
            background: #f1f5f9;
            border-color: #e2e8f0;
            color: #334155;
            border-radius: 9px;
            padding: 11px 18px;
            font-size: 12px;
            font-weight: 600;
        }

        .photo-box {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .photo-preview {
            width: 110px;
            height: 110px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .file-help {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 6px;
        }

        .existing-file {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 8px;
            padding: 7px 10px;
            border-radius: 7px;
            background: #f0fdf4;
            color: #15803d;
            font-size: 10px;
            text-decoration: none;
        }

        .alert {
            border: 0;
            border-radius: 12px;
            font-size: 12px;
        }

        .required {
            color: #dc2626;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle .form-control {
            padding-right: 45px;
        }

        .password-toggle button {
            position: absolute;
            right: 6px;
            top: 5px;
            border: 0;
            background: transparent;
            width: 35px;
            height: 35px;
            color: #64748b;
        }

        .mobile-overlay {
            display: none;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15,23,42,.45);
                z-index: 1040;
            }

            .mobile-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: block;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .topbar-user {
                display: none;
            }
        }

        @media (max-width: 576px) {

            .content {
                padding: 14px;
            }

            .profile-hero {
                padding: 20px;
                align-items: flex-start;
            }

            .profile-hero-left {
                align-items: flex-start;
            }

            .hero-photo {
                width: 70px;
                height: 70px;
            }

            .hero-name {
                font-size: 18px;
            }

            .card-body-custom {
                padding: 18px 15px;
            }

            .photo-box {
                flex-direction: column;
                align-items: flex-start;
            }
        }

    </style>

</head>

<body>

<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- Sidebar -->

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>
            <div class="brand-title">BKHS</div>
            <div class="brand-subtitle">School Management</div>
        </div>

    </div>

    <div class="sidebar-menu">

        <div class="menu-label">
            Main Menu
        </div>

        <a href="dashboard.php" class="menu-item">

            <i class="bi bi-grid-1x2-fill"></i>

            <span>Dashboard</span>

        </a>

        <a href="registration.php" class="menu-item">

            <i class="bi bi-person-plus-fill"></i>

            <span>Register</span>

        </a>

        <a href="admission.php" class="menu-item">

            <i class="bi bi-journal-check"></i>

            <span>Admission</span>

        </a>

        <a href="student-records.php" class="menu-item">

            <i class="bi bi-people-fill"></i>

            <span>Student Record</span>

        </a>

        <a href="certificates.php" class="menu-item">

            <i class="bi bi-award-fill"></i>

            <span>Certificate</span>

        </a>

        <div class="menu-label mt-4">
            Account
        </div>

        <a href="profile.php" class="menu-item active">

            <i class="bi bi-person-circle"></i>

            <span>Profile</span>

        </a>

        <a
            href="../auth/logout.php"
            class="menu-item"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>Logout</span>

        </a>

    </div>

    <div class="sidebar-footer">

        <div class="profile-mini">

            <img
                src="<?= e($photoUrl) ?>"
                alt="Registrar"
            >

            <div>

                <div class="profile-mini-name">
                    <?= e($fullName) ?>
                </div>

                <div class="profile-mini-role">
                    Registrar
                </div>

            </div>

        </div>

    </div>

</aside>

<!-- Main -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    My Profile
                </h1>

                <div class="page-subtitle">
                    Manage your personal and professional information
                </div>

            </div>

        </div>

        <div class="topbar-right">

            <i class="bi bi-person-circle text-secondary"></i>

            <span class="topbar-user">
                <?= e($fullName) ?>
            </span>

        </div>

    </header>

    <!-- Content -->

    <div class="content">

        <?php if ($flashMessage): ?>

            <div
                class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show"
                role="alert"
            >

                <i
                    class="bi
                    <?= $flashMessage['type'] === 'success'
                        ? 'bi-check-circle-fill'
                        : 'bi-exclamation-triangle-fill'
                    ?>
                    me-2"
                ></i>

                <?= e($flashMessage['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Profile Hero -->

        <div class="profile-hero">

            <div class="profile-hero-left">

                <img
                    src="<?= e($photoUrl) ?>"
                    alt="Registrar photo"
                    class="hero-photo"
                    id="heroPhoto"
                >

                <div>

                    <div class="hero-name">
                        <?= e($fullName) ?>
                    </div>

                    <div class="hero-email">
                        <?= e($email) ?>
                    </div>

                    <div class="hero-role">

                        <i class="bi bi-person-badge-fill"></i>

                        Registrar

                    </div>

                </div>

            </div>

        </div>

        <!-- Profile Form -->

        <form
            method="POST"
            enctype="multipart/form-data"
            id="profileForm"
        >

            <!-- Account Information -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-person-vcard-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Account Information
                        </h2>

                        <p class="section-description">
                            Your account information is managed by the school administrator.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <div class="col-md-4">

                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= e($fullName) ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                value="<?= e($email) ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Phone
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= e($phone) ?>"
                                readonly
                            >

                        </div>

                    </div>

                </div>

            </div>

            <!-- Personal Information -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-person-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Personal Information
                        </h2>

                        <p class="section-description">
                            Update your personal information.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <!-- Gender -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Gender <span class="required">*</span>
                            </label>

                            <select
                                name="gender"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select gender
                                </option>

                                <option
                                    value="Male"
                                    <?= $selectedGender === 'Male' ? 'selected' : '' ?>
                                >
                                    Male
                                </option>

                                <option
                                    value="Female"
                                    <?= $selectedGender === 'Female' ? 'selected' : '' ?>
                                >
                                    Female
                                </option>

                            </select>

                        </div>

                        <!-- Marital Status -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Marital Status <span class="required">*</span>
                            </label>

                            <select
                                name="marital_status"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select marital status
                                </option>

                                <option
                                    value="Single"
                                    <?= $selectedMaritalStatus === 'Single' ? 'selected' : '' ?>
                                >
                                    Single
                                </option>

                                <option
                                    value="Married"
                                    <?= $selectedMaritalStatus === 'Married' ? 'selected' : '' ?>
                                >
                                    Married
                                </option>

                                <option
                                    value="Divorced"
                                    <?= $selectedMaritalStatus === 'Divorced' ? 'selected' : '' ?>
                                >
                                    Divorced
                                </option>

                                <option
                                    value="Widowed"
                                    <?= $selectedMaritalStatus === 'Widowed' ? 'selected' : '' ?>
                                >
                                    Widowed
                                </option>

                            </select>

                        </div>

                        <!-- DOB -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Date of Birth <span class="required">*</span>
                            </label>

                            <input
                                type="date"
                                name="date_of_birth"
                                class="form-control"
                                value="<?= e($dateOfBirth) ?>"
                                required
                            >

                        </div>

                    </div>

                </div>

            </div>

            <!-- Address -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Address
                        </h2>

                        <p class="section-description">
                            Select your Addis Ababa subcity and corresponding Woreda.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <!-- Region -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Region
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="Addis Ababa"
                                readonly
                            >

                            <input
                                type="hidden"
                                name="region"
                                value="Addis Ababa"
                            >

                        </div>

                        <!-- Zone/Subcity -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Zone / Subcity <span class="required">*</span>
                            </label>

                            <select
                                name="subcity"
                                id="subcity"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select subcity
                                </option>

                                <?php foreach ($subcityWoredaCounts as $subcityName => $count): ?>

                                    <option
                                        value="<?= e($subcityName) ?>"
                                        <?= $selectedSubcity === $subcityName ? 'selected' : '' ?>
                                    >
                                        <?= e($subcityName) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Woreda -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Woreda <span class="required">*</span>
                            </label>

                            <select
                                name="woreda"
                                id="woreda"
                                class="form-select"
                                required
                                <?= empty($selectedSubcity) ? 'disabled' : '' ?>
                            >

                                <option value="">
                                    Select Woreda
                                </option>

                            </select>

                            <div class="field-note">
                                Woreda numbers are loaded according to the selected subcity.
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Photo -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-camera-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Profile Photo
                        </h2>

                        <p class="section-description">
                            Upload a professional profile photo.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="photo-box">

                        <img
                            src="<?= e($photoUrl) ?>"
                            alt="Profile photo"
                            class="photo-preview"
                            id="photoPreview"
                        >

                        <div class="flex-grow-1">

                            <label class="form-label">
                                Change Photo
                            </label>

                            <input
                                type="file"
                                name="photo"
                                id="photo"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                            >

                            <div class="file-help">
                                JPG, PNG or WEBP. Maximum size: 5 MB.
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Education -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Education
                        </h2>

                        <p class="section-description">
                            Provide your highest education information.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <div class="col-md-5">

                            <label class="form-label">
                                Education Level <span class="required">*</span>
                            </label>

                            <select
                                name="education_level"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select education level
                                </option>

                                <?php
                                $educationLevels = [
                                    'Certificate',
                                    'Diploma',
                                    'Bachelor Degree',
                                    'Master Degree',
                                    'PhD'
                                ];
                                ?>

                                <?php foreach ($educationLevels as $level): ?>

                                    <option
                                        value="<?= e($level) ?>"
                                        <?= $educationLevel === $level ? 'selected' : '' ?>
                                    >
                                        <?= e($level) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-7">

                            <label class="form-label">
                                College / University <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                name="college_university"
                                class="form-control"
                                value="<?= e($collegeUniversity) ?>"
                                placeholder="Enter college or university"
                                required
                            >

                        </div>

                        <div class="col-md-12">

                            <label class="form-label">
                                Educational File
                            </label>

                            <input
                                type="file"
                                name="educational_file"
                                class="form-control"
                                accept=".pdf,.jpg,.jpeg,.png"
                            >

                            <div class="file-help">
                                Upload your educational certificate/document.
                                PDF, JPG or PNG. Maximum size: 10 MB.
                            </div>

                            <?php if (!empty($educationalFile)): ?>

                                <a
                                    href="../<?= e(ltrim($educationalFile, '/')) ?>"
                                    target="_blank"
                                    class="existing-file"
                                >

                                    <i class="bi bi-file-earmark-check-fill"></i>

                                    View current educational file

                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Experience -->

            <div class="card-section">

                <div class="card-header-custom">

                    <div class="section-icon">
                        <i class="bi bi-briefcase-fill"></i>
                    </div>

                    <div>

                        <h2 class="section-title">
                            Professional Experience
                        </h2>

                        <p class="section-description">
                            Enter your total professional experience.
                        </p>

                    </div>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <div class="col-md-4">

                            <label class="form-label">
                                Experience (Years) <span class="required">*</span>
                            </label>

                            <input
                                type="number"
                                name="experience_years"
                                id="experienceYears"
                                class="form-control"
                                value="<?= e((string) $experienceYears) ?>"
                                min="0"
                                max="99.9"
                                step="0.1"
                                required
                            >

                            <div class="field-note">
                                Enter 0 if you have no professional experience.
                            </div>

                        </div>

                        <div class="col-md-8">

                            <label class="form-label">
                                Experience File
                            </label>

                            <input
                                type="file"
                                name="experience_file"
                                id="experienceFile"
                                class="form-control"
                                accept=".pdf,.jpg,.jpeg,.png"
                                <?= ((float)$experienceYears <= 0) ? 'disabled' : '' ?>
                            >

                            <div class="file-help">
                                Upload an experience letter/document.
                                PDF, JPG or PNG. Maximum size: 10 MB.
                            </div>

                            <?php if (!empty($experienceFile)): ?>

                                <a
                                    href="../<?= e(ltrim($experienceFile, '/')) ?>"
                                    target="_blank"
                                    class="existing-file"
                                >

                                    <i class="bi bi-file-earmark-check-fill"></i>

                                    View current experience file

                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Save -->

            <div class="d-flex justify-content-end mb-4">

                <button
                    type="submit"
                    name="update_profile"
                    value="1"
                    class="btn btn-primary-custom"
                >

                    <i class="bi bi-check2-circle me-1"></i>

                    Save Profile

                </button>

            </div>

        </form>

        <!-- Password -->

        <div class="card-section">

            <div class="card-header-custom">

                <div class="section-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>

                <div>

                    <h2 class="section-title">
                        Change Password
                    </h2>

                    <p class="section-description">
                        Change your account password securely.
                    </p>

                </div>

            </div>

            <div class="card-body-custom">

                <form method="POST">

                    <div class="row g-4">

                        <div class="col-md-4">

                            <label class="form-label">
                                Current Password
                            </label>

                            <div class="password-toggle">

                                <input
                                    type="password"
                                    name="current_password"
                                    id="currentPassword"
                                    class="form-control"
                                    required
                                >

                                <button
                                    type="button"
                                    onclick="togglePassword('currentPassword', this)"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                New Password
                            </label>

                            <div class="password-toggle">

                                <input
                                    type="password"
                                    name="new_password"
                                    id="newPassword"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                                <button
                                    type="button"
                                    onclick="togglePassword('newPassword', this)"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Confirm New Password
                            </label>

                            <div class="password-toggle">

                                <input
                                    type="password"
                                    name="confirm_password"
                                    id="confirmPassword"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                                <button
                                    type="button"
                                    onclick="togglePassword('confirmPassword', this)"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>

                    </div>

                    <div class="d-flex justify-content-end mt-4">

                        <button
                            type="submit"
                            name="change_password"
                            value="1"
                            class="btn btn-primary-custom"
                        >

                            <i class="bi bi-key-fill me-1"></i>

                            Change Password

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Woreda Data
|--------------------------------------------------------------------------
*/

const subcityWoredaCounts = <?= json_encode(
    $subcityWoredaCounts,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const selectedSubcity = <?= json_encode(
    $selectedSubcity,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const selectedWoreda = <?= json_encode(
    $selectedWoreda,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const subcitySelect = document.getElementById('subcity');
const woredaSelect = document.getElementById('woreda');

function loadWoredas(subcity, selectedValue = '') {

    woredaSelect.innerHTML = '';

    if (!subcity || !subcityWoredaCounts[subcity]) {

        woredaSelect.disabled = true;

        const option = document.createElement('option');

        option.value = '';
        option.textContent = 'Select subcity first';

        woredaSelect.appendChild(option);

        return;
    }

    woredaSelect.disabled = false;

    const defaultOption = document.createElement('option');

    defaultOption.value = '';
    defaultOption.textContent = 'Select Woreda';

    woredaSelect.appendChild(defaultOption);

    const count = subcityWoredaCounts[subcity];

    for (let i = 1; i <= count; i++) {

        const number = String(i).padStart(2, '0');

        const woredaName = `Woreda ${number}`;

        const option = document.createElement('option');

        option.value = woredaName;
        option.textContent = woredaName;

        if (woredaName === selectedValue) {
            option.selected = true;
        }

        woredaSelect.appendChild(option);
    }
}

subcitySelect.addEventListener('change', function () {

    loadWoredas(
        this.value,
        ''
    );

});

if (selectedSubcity) {

    loadWoredas(
        selectedSubcity,
        selectedWoreda
    );

}

/*
|--------------------------------------------------------------------------
| Photo Preview
|--------------------------------------------------------------------------
*/

const photoInput = document.getElementById('photo');
const photoPreview = document.getElementById('photoPreview');
const heroPhoto = document.getElementById('heroPhoto');

photoInput.addEventListener('change', function () {

    const file = this.files[0];

    if (!file) {
        return;
    }

    if (!file.type.startsWith('image/')) {
        return;
    }

    const imageUrl = URL.createObjectURL(file);

    photoPreview.src = imageUrl;
    heroPhoto.src = imageUrl;

});

/*
|--------------------------------------------------------------------------
| Experience File
|--------------------------------------------------------------------------
*/

const experienceYears = document.getElementById('experienceYears');
const experienceFile = document.getElementById('experienceFile');

function updateExperienceFile() {

    const years = parseFloat(experienceYears.value || '0');

    if (years > 0) {

        experienceFile.disabled = false;

    } else {

        experienceFile.disabled = true;
        experienceFile.value = '';

    }
}

experienceYears.addEventListener(
    'input',
    updateExperienceFile
);

updateExperienceFile();

/*
|--------------------------------------------------------------------------
| Password Visibility
|--------------------------------------------------------------------------
*/

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    const icon = button.querySelector('i');

    if (input.type === 'password') {

        input.type = 'text';

        icon.classList.remove('bi-eye');

        icon.classList.add('bi-eye-slash');

    } else {

        input.type = 'password';

        icon.classList.remove('bi-eye-slash');

        icon.classList.add('bi-eye');

    }
}

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const mobileMenu = document.getElementById('mobileMenu');
const sidebar = document.getElementById('sidebar');
const mobileOverlay = document.getElementById('mobileOverlay');

function openSidebar() {

    sidebar.classList.add('open');

    mobileOverlay.classList.add('show');

}

function closeSidebar() {

    sidebar.classList.remove('open');

    mobileOverlay.classList.remove('show');

}

mobileMenu.addEventListener(
    'click',
    openSidebar
);

mobileOverlay.addEventListener(
    'click',
    closeSidebar
);

</script>

</body>

</html>