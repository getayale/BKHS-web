<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

$conn->set_charset('utf8mb4');

/* =========================
   Helpers
========================= */

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function message(string $type, string $text): void
{
    $_SESSION['profile_message'] = [
        'type' => $type,
        'text' => $text
    ];

    header('Location: profile.php');
    exit;
}

function uploadFile(
    string $field,
    string $folder,
    array $extensions
): ?string {
    if (
        !isset($_FILES[$field]) ||
        $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('File size must not exceed 5 MB.');
    }

    $extension = strtolower(
        pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION)
    );

    if (!in_array($extension, $extensions, true)) {
        throw new RuntimeException('Invalid file type.');
    }

    $directory = __DIR__ . '/../uploads/principals/' . $folder . '/';

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $filename = uniqid('principal_', true) . '.' . $extension;
    $destination = $directory . $filename;

    if (!move_uploaded_file(
        $_FILES[$field]['tmp_name'],
        $destination
    )) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return 'uploads/principals/' . $folder . '/' . $filename;
}

function deleteFile(?string $path): void
{
    if (!$path) {
        return;
    }

    $fullPath = __DIR__ . '/../' . ltrim($path, '/');

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/* =========================
   CSRF
========================= */

if (empty($_SESSION['principal_profile_csrf'])) {
    $_SESSION['principal_profile_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['principal_profile_csrf'];

/* =========================
   Load User
========================= */

$stmt = $conn->prepare("
    SELECT id, full_name, email, phone, password
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'principal'
      AND is_deleted = 0
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();

$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

/* =========================
   Ensure Principal Row
========================= */

$stmt = $conn->prepare("
    SELECT id
    FROM principals
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$principalExists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$principalExists) {
    $stmt = $conn->prepare("
        INSERT INTO principals (user_id)
        VALUES (?)
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/* =========================
   Handle Forms
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['principal_profile_csrf'],
                $_POST['csrf_token']
            )
        ) {
            throw new RuntimeException('Invalid security token.');
        }

        $formType = $_POST['form_type'] ?? '';

        /* =========================
           Profile Information
        ========================= */

        if ($formType === 'profile_information') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $gender = trim($_POST['gender'] ?? '');
            $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
            $region = trim($_POST['region'] ?? '');
            $zone = trim($_POST['zone'] ?? '');
            $woreda = trim($_POST['woreda'] ?? '');

            $educationLevel =
                trim($_POST['education_level'] ?? '');

            $department = trim($_POST['department'] ?? '');

            $collegeUniversity =
                trim($_POST['college_university'] ?? '');

            $experience = trim($_POST['experience'] ?? '');

            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }

            if (
                $email !== '' &&
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException('Invalid email address.');
            }

            $allowedGenders = ['Male', 'Female'];

            if (
                $gender !== '' &&
                !in_array($gender, $allowedGenders, true)
            ) {
                throw new RuntimeException('Invalid gender selected.');
            }

            $allowedZones = [
                'Addis Ketema',
                'Akaki Kality',
                'Arada',
                'Bole',
                'Gullele',
                'Kirkos',
                'Kolfe Keranio',
                'Lideta',
                'Nifas Silk-Lafto',
                'Yeka',
                'Lemi Kura'
            ];

            if (
                $zone !== '' &&
                !in_array($zone, $allowedZones, true)
            ) {
                throw new RuntimeException('Invalid sub-city selected.');
            }

            $allowedEducation = [
                'Diploma',
                'Bachelor Degree',
                'Master Degree',
                'Doctorate Degree',
                'Other'
            ];

            if (
                $educationLevel !== '' &&
                !in_array($educationLevel, $allowedEducation, true)
            ) {
                throw new RuntimeException(
                    'Invalid education level selected.'
                );
            }

            $stmt = $conn->prepare("
                SELECT photo, educational_file, experience_file
                FROM principals
                WHERE user_id = ?
                LIMIT 1
            ");

            $stmt->bind_param('i', $userId);
            $stmt->execute();

            $oldFiles = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $photo = uploadFile(
                'photo',
                'photos',
                ['jpg', 'jpeg', 'png', 'webp']
            );

            $educationalFile = uploadFile(
                'educational_file',
                'documents',
                ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']
            );

            $experienceFile = uploadFile(
                'experience_file',
                'documents',
                ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']
            );

            $photo ??= $oldFiles['photo'] ?? null;
            $educationalFile ??=
                $oldFiles['educational_file'] ?? null;
            $experienceFile ??=
                $oldFiles['experience_file'] ?? null;

            $stmt = $conn->prepare("
                UPDATE users
                SET full_name = ?, email = ?, phone = ?
                WHERE id = ?
                  AND LOWER(role) = 'principal'
                  AND is_deleted = 0
            ");

            $stmt->bind_param(
                'sssi',
                $fullName,
                $email,
                $phone,
                $userId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Account information could not be updated.'
                );
            }

            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE principals
                SET
                    photo = ?,
                    gender = NULLIF(?, ''),
                    date_of_birth = NULLIF(?, ''),
                    region = NULLIF(?, ''),
                    zone = NULLIF(?, ''),
                    woreda = NULLIF(?, ''),
                    education_level = NULLIF(?, ''),
                    department = NULLIF(?, ''),
                    college_university = NULLIF(?, ''),
                    educational_file = ?,
                    experience = NULLIF(?, ''),
                    experience_file = ?
                WHERE user_id = ?
            ");

            $stmt->bind_param(
                'ssssssssssssi',
                $photo,
                $gender,
                $dateOfBirth,
                $region,
                $zone,
                $woreda,
                $educationLevel,
                $department,
                $collegeUniversity,
                $educationalFile,
                $experience,
                $experienceFile,
                $userId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Principal information could not be updated.'
                );
            }

            $stmt->close();

            if (
                $photo !== ($oldFiles['photo'] ?? null) &&
                !empty($oldFiles['photo'])
            ) {
                deleteFile($oldFiles['photo']);
            }

            if (
                $educationalFile !==
                ($oldFiles['educational_file'] ?? null) &&
                !empty($oldFiles['educational_file'])
            ) {
                deleteFile($oldFiles['educational_file']);
            }

            if (
                $experienceFile !==
                ($oldFiles['experience_file'] ?? null) &&
                !empty($oldFiles['experience_file'])
            ) {
                deleteFile($oldFiles['experience_file']);
            }

            message(
                'success',
                'Profile information updated successfully.'
            );
        }

        /* =========================
           Change Password
        ========================= */

        if ($formType === 'change_password') {
            $currentPassword =
                $_POST['current_password'] ?? '';

            $newPassword =
                $_POST['new_password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password'] ?? '';

            if (
                $currentPassword === '' ||
                $newPassword === '' ||
                $confirmPassword === ''
            ) {
                throw new RuntimeException(
                    'Please complete all password fields.'
                );
            }

            if (
                !password_verify(
                    $currentPassword,
                    $user['password']
                )
            ) {
                throw new RuntimeException(
                    'Current password is incorrect.'
                );
            }

            if (strlen($newPassword) < 8) {
                throw new RuntimeException(
                    'New password must contain at least 8 characters.'
                );
            }

            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException(
                    'New password and confirm password must match.'
                );
            }

            if (
                password_verify(
                    $newPassword,
                    $user['password']
                )
            ) {
                throw new RuntimeException(
                    'New password must be different from the current password.'
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
                  AND LOWER(role) = 'principal'
                  AND is_deleted = 0
            ");

            $stmt->bind_param(
                'si',
                $hashedPassword,
                $userId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Password could not be changed.'
                );
            }

            $stmt->close();

            message(
                'success',
                'Password changed successfully.'
            );
        }

        throw new RuntimeException('Invalid form submission.');

    } catch (Throwable $error) {
        message('danger', $error->getMessage());
    }
}

/* =========================
   Load Profile
========================= */

$stmt = $conn->prepare("
    SELECT
        u.full_name,
        u.email,
        u.phone,
        p.photo,
        p.gender,
        p.date_of_birth,
        p.region,
        p.zone,
        p.woreda,
        p.education_level,
        p.department,
        p.college_university,
        p.educational_file,
        p.experience,
        p.experience_file
    FROM users u
    LEFT JOIN principals p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'principal'
      AND u.is_deleted = 0
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$photoUrl = !empty($profile['photo'])
    ? '../' . ltrim($profile['photo'], '/')
    : '';

$educationalFileUrl = !empty($profile['educational_file'])
    ? '../' . ltrim($profile['educational_file'], '/')
    : '';

$experienceFileUrl = !empty($profile['experience_file'])
    ? '../' . ltrim($profile['experience_file'], '/')
    : '';

$alert = $_SESSION['profile_message'] ?? null;
unset($_SESSION['profile_message']);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Principal Profile | BKHS</title>
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

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f7fb;
            font-family: Inter, sans-serif;
            color: #111827;
        }

        .sidebar {
            width: 260px;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: #111827;
            color: white;
            z-index: 1000;
        }

        .brand {
            padding: 25px;
            border-bottom: 1px solid #374151;
            font-weight: 800;
        }

        .menu {
            padding: 20px 14px;
        }

        .menu a {
            display: block;
            padding: 12px 15px;
            margin-bottom: 5px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 13px;
        }

        .menu a:hover,
        .menu a.active {
            background: #4f46e5;
            color: white;
        }

        .main {
            margin-left: 260px;
        }

        .topbar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 22px 30px;
        }

        .content {
            padding: 30px;
        }

        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(15, 23, 42, .05);
        }

        .card-header {
            background: white;
            padding: 22px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 800;
        }

        .card-body {
            padding: 25px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-radius: 10px;
            font-size: 13px;
        }

        .btn-primary {
            background: #4f46e5;
            border-color: #4f46e5;
            border-radius: 10px;
            font-weight: 700;
            padding: 11px 20px;
        }

        .photo {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: #eef2ff;
            color: #4f46e5;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: auto;
            overflow: hidden;
            font-size: 55px;
        }

        .photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        @media (max-width: 991px) {
            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .content {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        <i class="bi bi-mortarboard-fill me-2"></i>
        BKHS Principal
    </div>

    <div class="menu">

        <a href="dashboard.php">
            <i class="bi bi-grid me-2"></i>
            Dashboard
        </a>

        <a href="announcements.php">
            <i class="bi bi-megaphone me-2"></i>
            Announcement
        </a>

        <a href="subject-assignment.php">
            <i class="bi bi-book me-2"></i>
            Subject Assignment
        </a>

        <a href="homeroom-assignment.php">
            <i class="bi bi-people me-2"></i>
            Homeroom Assignment
        </a>

        <a href="student-assignment.php">
            <i class="bi bi-person-check me-2"></i>
            Student Assignment
        </a>

        <a href="attendance.php">
            <i class="bi bi-calendar-check me-2"></i>
            Attendance
        </a>

        <a href="roster.php">
            <i class="bi bi-list-ul me-2"></i>
            Roster
        </a>

        <a href="certificate.php">
            <i class="bi bi-award me-2"></i>
            Certificate
        </a>

        <a href="result.php">
            <i class="bi bi-bar-chart me-2"></i>
            Result
        </a>

        <a href="profile.php" class="active">
            <i class="bi bi-person-circle me-2"></i>
            Profile
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i>
            Logout
        </a>

    </div>

</aside>

<main class="main">

    <header class="topbar">
        <h4 class="mb-1 fw-bold">Principal Profile</h4>
        <small class="text-muted">
            Manage your profile and password
        </small>
    </header>

    <section class="content">

        <?php if ($alert): ?>

            <div class="alert alert-<?= e($alert['type']) ?> alert-dismissible fade show">
                <?= e($alert['text']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>
            </div>

        <?php endif; ?>

        <div class="row g-4">

            <!-- Profile Information -->
            <div class="col-lg-8">

                <div class="card">

                    <div class="card-header">
                        <i class="bi bi-person-vcard me-2"></i>
                        Profile Information
                    </div>

                    <div class="card-body">

                        <form
                            method="POST"
                            enctype="multipart/form-data"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="form_type"
                                value="profile_information"
                            >

                            <div class="row g-3">

                                <div class="col-md-4 text-center">

                                    <div class="photo mb-3">

                                        <?php if ($photoUrl): ?>

                                            <img
                                                src="<?= e($photoUrl) ?>"
                                                alt="Profile Photo"
                                            >

                                        <?php else: ?>

                                            <i class="bi bi-person"></i>

                                        <?php endif; ?>

                                    </div>

                                    <label
                                        for="photo"
                                        class="form-label"
                                    >
                                        Profile Photo
                                    </label>

                                    <input
                                        type="file"
                                        name="photo"
                                        id="photo"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp"
                                    >

                                </div>

                                <div class="col-md-8">

                                    <div class="mb-3">

                                        <label
                                            for="full_name"
                                            class="form-label"
                                        >
                                            Full Name
                                        </label>

                                        <input
                                            type="text"
                                            name="full_name"
                                            id="full_name"
                                            class="form-control"
                                            value="<?= e($profile['full_name']) ?>"
                                            required
                                        >

                                    </div>

                                    <div class="mb-3">

                                        <label
                                            for="email"
                                            class="form-label"
                                        >
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            name="email"
                                            id="email"
                                            class="form-control"
                                            value="<?= e($profile['email']) ?>"
                                        >

                                    </div>

                                    <div class="mb-3">

                                        <label
                                            for="phone"
                                            class="form-label"
                                        >
                                            Phone Number
                                        </label>

                                        <input
                                            type="text"
                                            name="phone"
                                            id="phone"
                                            class="form-control"
                                            value="<?= e($profile['phone']) ?>"
                                        >

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="gender"
                                        class="form-label"
                                    >
                                        Gender
                                    </label>

                                    <select
                                        name="gender"
                                        id="gender"
                                        class="form-select"
                                    >
                                        <option value="">Select Gender</option>

                                        <option
                                            value="Male"
                                            <?= $profile['gender'] === 'Male'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Male
                                        </option>

                                        <option
                                            value="Female"
                                            <?= $profile['gender'] === 'Female'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Female
                                        </option>
                                    </select>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="date_of_birth"
                                        class="form-label"
                                    >
                                        Date of Birth
                                    </label>

                                    <input
                                        type="date"
                                        name="date_of_birth"
                                        id="date_of_birth"
                                        class="form-control"
                                        value="<?= e($profile['date_of_birth']) ?>"
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label
                                        for="region"
                                        class="form-label"
                                    >
                                        Region
                                    </label>

                                    <select
                                        name="region"
                                        id="region"
                                        class="form-select"
                                    >
                                        <option value="">
                                            Select Region
                                        </option>

                                        <option
                                            value="Addis Ababa"
                                            <?= $profile['region'] === 'Addis Ababa'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Addis Ababa
                                        </option>
                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        for="zone"
                                        class="form-label"
                                    >
                                        Sub-City
                                    </label>

                                    <select
                                        name="zone"
                                        id="zone"
                                        class="form-select"
                                    >
                                        <option value="">
                                            Select Sub-City
                                        </option>

                                        <?php
                                        $zones = [
                                            'Addis Ketema',
                                            'Akaki Kality',
                                            'Arada',
                                            'Bole',
                                            'Gullele',
                                            'Kirkos',
                                            'Kolfe Keranio',
                                            'Lideta',
                                            'Nifas Silk-Lafto',
                                            'Yeka',
                                            'Lemi Kura'
                                        ];
                                        ?>

                                        <?php foreach ($zones as $zone): ?>

                                            <option
                                                value="<?= e($zone) ?>"
                                                <?= $profile['zone'] === $zone
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= e($zone) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        for="woreda"
                                        class="form-label"
                                    >
                                        Woreda
                                    </label>

                                    <input
                                        type="text"
                                        name="woreda"
                                        id="woreda"
                                        class="form-control"
                                        value="<?= e($profile['woreda']) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="education_level"
                                        class="form-label"
                                    >
                                        Education Level
                                    </label>

                                    <select
                                        name="education_level"
                                        id="education_level"
                                        class="form-select"
                                    >
                                        <option value="">
                                            Select Education
                                        </option>

                                        <?php
                                        $educationLevels = [
                                            'Diploma',
                                            'Bachelor Degree',
                                            'Master Degree',
                                            'Doctorate Degree',
                                            'Other'
                                        ];
                                        ?>

                                        <?php foreach ($educationLevels as $level): ?>

                                            <option
                                                value="<?= e($level) ?>"
                                                <?= $profile['education_level'] === $level
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= e($level) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="department"
                                        class="form-label"
                                    >
                                        Department
                                    </label>

                                    <input
                                        type="text"
                                        name="department"
                                        id="department"
                                        class="form-control"
                                        value="<?= e($profile['department']) ?>"
                                    >

                                </div>

                                <div class="col-md-12">

                                    <label
                                        for="college_university"
                                        class="form-label"
                                    >
                                        College / University
                                    </label>

                                    <input
                                        type="text"
                                        name="college_university"
                                        id="college_university"
                                        class="form-control"
                                        value="<?= e($profile['college_university']) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="educational_file"
                                        class="form-label"
                                    >
                                        Educational File
                                    </label>

                                    <input
                                        type="file"
                                        name="educational_file"
                                        id="educational_file"
                                        class="form-control"
                                    >

                                    <?php if ($educationalFileUrl): ?>

                                        <a
                                            href="<?= e($educationalFileUrl) ?>"
                                            target="_blank"
                                            class="small"
                                        >
                                            View current file
                                        </a>

                                    <?php endif; ?>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="experience_file"
                                        class="form-label"
                                    >
                                        Experience File
                                    </label>

                                    <input
                                        type="file"
                                        name="experience_file"
                                        id="experience_file"
                                        class="form-control"
                                    >

                                    <?php if ($experienceFileUrl): ?>

                                        <a
                                            href="<?= e($experienceFileUrl) ?>"
                                            target="_blank"
                                            class="small"
                                        >
                                            View current file
                                        </a>

                                    <?php endif; ?>

                                </div>

                                <div class="col-md-12">

                                    <label
                                        for="experience"
                                        class="form-label"
                                    >
                                        Experience
                                    </label>

                                    <textarea
                                        name="experience"
                                        id="experience"
                                        class="form-control"
                                        rows="5"
                                    ><?= e($profile['experience']) ?></textarea>

                                </div>

                                <div class="col-12 text-end">

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        <i class="bi bi-check-circle me-2"></i>
                                        Save Profile
                                    </button>

                                </div>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

            <!-- Password Section -->
            <div class="col-lg-4">

                <div class="card">

                    <div class="card-header">
                        <i class="bi bi-shield-lock me-2"></i>
                        Change Password
                    </div>

                    <div class="card-body">

                        <div class="alert alert-warning small">
                            Your password must contain at least
                            <strong>8 characters</strong>.
                        </div>

                        <form method="POST" id="passwordForm">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="form_type"
                                value="change_password"
                            >

                            <div class="mb-3">

                                <label
                                    for="current_password"
                                    class="form-label"
                                >
                                    Current Password
                                </label>

                                <input
                                    type="password"
                                    name="current_password"
                                    id="current_password"
                                    class="form-control"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label
                                    for="new_password"
                                    class="form-label"
                                >
                                    New Password
                                </label>

                                <input
                                    type="password"
                                    name="new_password"
                                    id="new_password"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label
                                    for="confirm_password"
                                    class="form-label"
                                >
                                    Confirm New Password
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    id="confirm_password"
                                    class="form-control"
                                    minlength="8"
                                    required
                                >

                                <div
                                    id="passwordMessage"
                                    class="small mt-2"
                                ></div>

                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                id="passwordButton"
                            >
                                <i class="bi bi-key me-2"></i>
                                Change Password
                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
const passwordForm = document.getElementById('passwordForm');
const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const passwordMessage = document.getElementById('passwordMessage');

function checkPasswords() {
    if (confirmPassword.value === '') {
        passwordMessage.textContent = '';
        confirmPassword.classList.remove('is-valid', 'is-invalid');
        return;
    }

    if (newPassword.value === confirmPassword.value) {
        passwordMessage.textContent = 'Passwords match.';
        passwordMessage.className = 'small mt-2 text-success';

        confirmPassword.classList.remove('is-invalid');
        confirmPassword.classList.add('is-valid');
    } else {
        passwordMessage.textContent =
            'New password and confirm password must match.';

        passwordMessage.className = 'small mt-2 text-danger';

        confirmPassword.classList.remove('is-valid');
        confirmPassword.classList.add('is-invalid');
    }
}

newPassword.addEventListener('input', checkPasswords);
confirmPassword.addEventListener('input', checkPasswords);

passwordForm.addEventListener('submit', function (event) {
    if (newPassword.value !== confirmPassword.value) {
        event.preventDefault();

        passwordMessage.textContent =
            'New password and confirm password must match.';

        passwordMessage.className = 'small mt-2 text-danger';

        confirmPassword.classList.add('is-invalid');
        confirmPassword.focus();
    }
});
</script>

</body>
</html>