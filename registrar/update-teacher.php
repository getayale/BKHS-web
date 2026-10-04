<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$registrarId = (int) $_SESSION['user_id'];

$errorMessage = '';
$successMessage = '';

$search = trim((string) ($_GET['search'] ?? ''));
$teacherId = (int) ($_GET['teacher_id'] ?? $_POST['teacher_id'] ?? 0);

$registrar = null;
$teacher = null;
$searchResults = [];

$transactionStarted = false;

/*
|--------------------------------------------------------------------------
| Upload Directories
|--------------------------------------------------------------------------
*/

$uploadBase = '../public/uploads/teachers/';
$photoDirectory = $uploadBase . 'photos/';
$educationDirectory = $uploadBase . 'education/';
$experienceDirectory = $uploadBase . 'experience/';
$pgdtDirectory = $uploadBase . 'pgdt/';

$directories = [
    $uploadBase,
    $photoDirectory,
    $educationDirectory,
    $experienceDirectory,
    $pgdtDirectory
];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
}

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function oldFileExists(?string $path): bool
{
    if (!$path) {
        return false;
    }

    $path = ltrim($path, '/\\');

    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    return is_file($fullPath);
}

function deleteTeacherFile(?string $path): void
{
    if (!$path) {
        return;
    }

    $path = ltrim($path, '/\\');

    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function uploadTeacherFile(
    string $inputName,
    string $directory,
    string $databaseDirectory,
    string $prefix,
    array $allowedExtensions,
    int $maxSize = 5242880
): array {

    if (
        !isset($_FILES[$inputName]) ||
        !is_array($_FILES[$inputName])
    ) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => null
        ];
    }

    $file = $_FILES[$inputName];

    if (
        !isset($file['error']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => null
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'There was an error uploading the file.'
        ];
    }

    if (
        !isset($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'Invalid uploaded file.'
        ];
    }

    if ((int) $file['size'] > $maxSize) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'File size must not exceed 5 MB.'
        ];
    }

    $extension = strtolower(
        pathinfo(
            (string) $file['name'],
            PATHINFO_EXTENSION
        )
    );

    if (!in_array($extension, $allowedExtensions, true)) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'Invalid file type.'
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowedMimes = [
        'pdf' => [
            'application/pdf'
        ],
        'jpg' => [
            'image/jpeg'
        ],
        'jpeg' => [
            'image/jpeg'
        ],
        'png' => [
            'image/png'
        ],
        'webp' => [
            'image/webp'
        ]
    ];

    if (
        !isset($allowedMimes[$extension]) ||
        !in_array(
            $mime,
            $allowedMimes[$extension],
            true
        )
    ) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'The uploaded file type is invalid.'
        ];
    }

    $safePrefix = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        $prefix
    );

    $fileName =
        $safePrefix .
        '_' .
        bin2hex(random_bytes(12)) .
        '.' .
        $extension;

    $destination =
        rtrim($directory, '/\\') .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {
        return [
            'uploaded' => false,
            'path' => null,
            'error' => 'Unable to save the uploaded file.'
        ];
    }

    return [
        'uploaded' => true,
        'path' => rtrim($databaseDirectory, '/') .
            '/' .
            $fileName,
        'error' => null
    ];
}

function getTeacherById(
    mysqli $conn,
    int $teacherId
): ?array {

    $sql = "
        SELECT
            t.id AS teacher_id,
            t.user_id,
            t.employment_status,
            t.fayda_number,
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
            t.education_credential_path,
            t.has_experience,
            t.experience_file_path,
            t.college_university_institution,
            t.has_pgdt,
            t.pgdt_file_path,

            u.full_name,
            u.email,
            u.phone,
            u.role,
            u.is_deleted

        FROM teachers t

        INNER JOIN users u
            ON u.id = t.user_id

        WHERE t.id = ?
          AND LOWER(u.role) = 'teacher'
          AND u.is_deleted = 0

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare teacher query.'
        );
    }

    $stmt->bind_param(
        'i',
        $teacherId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $teacher = $result->fetch_assoc();

    $stmt->close();

    return $teacher ?: null;
}

/*
|--------------------------------------------------------------------------
| Load Registrar
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            r.photo
        FROM users u
        LEFT JOIN registrars r
            ON r.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'registrar'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to load registrar information.'
        );
    }

    $stmt->bind_param(
        'i',
        $registrarId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $registrar = $result->fetch_assoc();

    $stmt->close();

    if (!$registrar) {
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }

} catch (Throwable $e) {

    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Search Teachers
|--------------------------------------------------------------------------
*/

if (
    $errorMessage === '' &&
    $search !== ''
) {

    try {

        $searchValue = '%' . $search . '%';

        $stmt = $conn->prepare("
            SELECT
                t.id AS teacher_id,
                t.user_id,
                t.employment_status,
                t.fayda_number,
                t.department,
                t.gender,
                u.full_name,
                u.email,
                u.phone
            FROM teachers t
            INNER JOIN users u
                ON u.id = t.user_id
            WHERE LOWER(u.role) = 'teacher'
              AND u.is_deleted = 0
              AND (
                    u.full_name LIKE ?
                    OR u.email LIKE ?
                    OR u.phone LIKE ?
              )
            ORDER BY u.full_name ASC
            LIMIT 50
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to search teachers.'
            );
        }

        $stmt->bind_param(
            'sss',
            $searchValue,
            $searchValue,
            $searchValue
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $searchResults[] = $row;
        }

        $stmt->close();

    } catch (Throwable $e) {

        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Update Teacher
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_teacher'])
) {

    $teacherId = (int) ($_POST['teacher_id'] ?? 0);

    if ($teacherId <= 0) {

        $errorMessage = 'Invalid teacher selected.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Get Current Teacher
            |--------------------------------------------------------------------------
            */

            $currentTeacher = getTeacherById(
                $conn,
                $teacherId
            );

            if (!$currentTeacher) {
                throw new RuntimeException(
                    'Teacher not found.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Editable Teacher Fields
            |--------------------------------------------------------------------------
            */

            $employmentStatus = trim(
                (string) ($_POST['employment_status'] ?? 'Active')
            );

            $faydaNumber = trim(
                (string) ($_POST['fayda_number'] ?? '')
            );

            $gender = trim(
                (string) ($_POST['gender'] ?? '')
            );

            $birthEthYear = trim(
                (string) ($_POST['birth_eth_year'] ?? '')
            );

            $birthEthMonth = trim(
                (string) ($_POST['birth_eth_month'] ?? '')
            );

            $birthEthDay = trim(
                (string) ($_POST['birth_eth_day'] ?? '')
            );

            $region = trim(
                (string) ($_POST['region'] ?? '')
            );

            $zone = trim(
                (string) ($_POST['zone'] ?? '')
            );

            $woreda = trim(
                (string) ($_POST['woreda'] ?? '')
            );

            $maritalStatus = trim(
                (string) ($_POST['marital_status'] ?? '')
            );

            $educationLevel = trim(
                (string) ($_POST['education_level'] ?? '')
            );

            $department = trim(
                (string) ($_POST['department'] ?? '')
            );

            $collegeUniversity = trim(
                (string) (
                    $_POST['college_university_institution']
                    ?? ''
                )
            );

            $hasExperience = trim(
                (string) (
                    $_POST['has_experience']
                    ?? 'No'
                )
            );

            $hasPgdt = trim(
                (string) (
                    $_POST['has_pgdt']
                    ?? 'No'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Validation
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $employmentStatus,
                    ['Active', 'Withdrawn'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid employment status.'
                );
            }

            if (
                $faydaNumber === '' ||
                strlen($faydaNumber) > 16
            ) {
                throw new RuntimeException(
                    'Fayda number is required and must not exceed 16 characters.'
                );
            }

            if (
                !in_array(
                    $gender,
                    ['', 'Male', 'Female'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid gender.'
                );
            }

            if (
                !in_array(
                    $maritalStatus,
                    [
                        '',
                        'Single',
                        'Married',
                        'Divorced',
                        'Widowed'
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid marital status.'
                );
            }

            if (
                !in_array(
                    $hasExperience,
                    ['Yes', 'No'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid experience selection.'
                );
            }

            if (
                !in_array(
                    $hasPgdt,
                    ['Yes', 'No'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid PGDT selection.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Ethiopian Birth Date
            |--------------------------------------------------------------------------
            */

            $birthYear = null;
            $birthMonth = null;
            $birthDay = null;

            if ($birthEthYear !== '') {

                if (
                    !ctype_digit($birthEthYear) ||
                    (int) $birthEthYear < 1900 ||
                    (int) $birthEthYear > 2100
                ) {
                    throw new RuntimeException(
                        'Invalid Ethiopian birth year.'
                    );
                }

                $birthYear = (int) $birthEthYear;
            }

            if ($birthEthMonth !== '') {

                if (
                    !ctype_digit($birthEthMonth) ||
                    (int) $birthEthMonth < 1 ||
                    (int) $birthEthMonth > 13
                ) {
                    throw new RuntimeException(
                        'Birth month must be between 1 and 13.'
                    );
                }

                $birthMonth = (int) $birthEthMonth;
            }

            if ($birthEthDay !== '') {

                if (
                    !ctype_digit($birthEthDay) ||
                    (int) $birthEthDay < 1 ||
                    (int) $birthEthDay > 30
                ) {
                    throw new RuntimeException(
                        'Birth day must be between 1 and 30.'
                    );
                }

                $birthDay = (int) $birthEthDay;
            }

            /*
            |--------------------------------------------------------------------------
            | Existing Files
            |--------------------------------------------------------------------------
            */

            $oldPhotoPath =
                $currentTeacher['photo_path'] ?? null;

            $oldEducationPath =
                $currentTeacher['education_credential_path']
                ?? null;

            $oldExperiencePath =
                $currentTeacher['experience_file_path']
                ?? null;

            $oldPgdtPath =
                $currentTeacher['pgdt_file_path']
                ?? null;

            $photoPath = $oldPhotoPath;
            $educationPath = $oldEducationPath;
            $experiencePath = $oldExperiencePath;
            $pgdtPath = $oldPgdtPath;

            /*
            |--------------------------------------------------------------------------
            | Upload Teacher Photo
            |--------------------------------------------------------------------------
            */

            $photoUpload = uploadTeacherFile(
                'teacher_photo',
                $photoDirectory,
                'public/uploads/teachers/photos',
                'teacher_' . $teacherId,
                [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ]
            );

            if ($photoUpload['error']) {
                throw new RuntimeException(
                    $photoUpload['error']
                );
            }

            if ($photoUpload['uploaded']) {

                $photoPath = $photoUpload['path'];

            }

            /*
            |--------------------------------------------------------------------------
            | Education Credential
            |--------------------------------------------------------------------------
            */

            $educationUpload = uploadTeacherFile(
                'education_credential_file',
                $educationDirectory,
                'public/uploads/teachers/education',
                'education_' . $teacherId,
                [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ]
            );

            if ($educationUpload['error']) {
                throw new RuntimeException(
                    $educationUpload['error']
                );
            }

            if ($educationUpload['uploaded']) {

                $educationPath =
                    $educationUpload['path'];
            }

            /*
            |--------------------------------------------------------------------------
            | Experience
            |--------------------------------------------------------------------------
            */

            if ($hasExperience === 'Yes') {

                $experienceUpload = uploadTeacherFile(
                    'experience_file',
                    $experienceDirectory,
                    'public/uploads/teachers/experience',
                    'experience_' . $teacherId,
                    [
                        'pdf',
                        'jpg',
                        'jpeg',
                        'png',
                        'webp'
                    ]
                );

                if ($experienceUpload['error']) {
                    throw new RuntimeException(
                        $experienceUpload['error']
                    );
                }

                if ($experienceUpload['uploaded']) {

                    $experiencePath =
                        $experienceUpload['path'];
                }

            } else {

                $experiencePath = null;
            }

            /*
            |--------------------------------------------------------------------------
            | PGDT
            |--------------------------------------------------------------------------
            */

            if ($hasPgdt === 'Yes') {

                $pgdtUpload = uploadTeacherFile(
                    'pgdt_file',
                    $pgdtDirectory,
                    'public/uploads/teachers/pgdt',
                    'pgdt_' . $teacherId,
                    [
                        'pdf',
                        'jpg',
                        'jpeg',
                        'png',
                        'webp'
                    ]
                );

                if ($pgdtUpload['error']) {
                    throw new RuntimeException(
                        $pgdtUpload['error']
                    );
                }

                if ($pgdtUpload['uploaded']) {

                    $pgdtPath =
                        $pgdtUpload['path'];
                }

            } else {

                $pgdtPath = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Start Transaction
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();
            $transactionStarted = true;

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            |
            | ONLY teachers table is updated.
            |
            | users table is NOT touched.
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE teachers
                SET
                    employment_status = ?,
                    fayda_number = ?,
                    photo_path = NULLIF(?, ''),
                    gender = NULLIF(?, ''),
                    birth_eth_year = ?,
                    birth_eth_month = ?,
                    birth_eth_day = ?,
                    region = NULLIF(?, ''),
                    zone = NULLIF(?, ''),
                    woreda = NULLIF(?, ''),
                    marital_status = NULLIF(?, ''),
                    education_level = NULLIF(?, ''),
                    department = NULLIF(?, ''),
                    education_credential_path = NULLIF(?, ''),
                    has_experience = ?,
                    experience_file_path = NULLIF(?, ''),
                    college_university_institution = NULLIF(?, ''),
                    has_pgdt = ?,
                    pgdt_file_path = NULLIF(?, '')
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Failed to prepare teacher update.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Use strings for nullable fields.
            | MySQL safely converts numeric birth fields.
            |--------------------------------------------------------------------------
            */

            $birthYearString =
                $birthYear === null
                    ? ''
                    : (string) $birthYear;

            $birthMonthString =
                $birthMonth === null
                    ? ''
                    : (string) $birthMonth;

            $birthDayString =
                $birthDay === null
                    ? ''
                    : (string) $birthDay;

            $photoPathValue =
                $photoPath ?? '';

            $educationPathValue =
                $educationPath ?? '';

            $experiencePathValue =
                $experiencePath ?? '';

            $pgdtPathValue =
                $pgdtPath ?? '';

            $stmt->bind_param(
                'sssssssssssssssssssi',
                $employmentStatus,
                $faydaNumber,
                $photoPathValue,
                $gender,
                $birthYearString,
                $birthMonthString,
                $birthDayString,
                $region,
                $zone,
                $woreda,
                $maritalStatus,
                $educationLevel,
                $department,
                $educationPathValue,
                $hasExperience,
                $experiencePathValue,
                $collegeUniversity,
                $hasPgdt,
                $pgdtPathValue,
                $teacherId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Teacher information could not be updated.'
                );
            }

            $stmt->close();

            $conn->commit();
            $transactionStarted = false;

            /*
            |--------------------------------------------------------------------------
            | Delete old files AFTER successful DB update
            |--------------------------------------------------------------------------
            */

            if (
                $photoUpload['uploaded'] &&
                $oldPhotoPath &&
                $oldPhotoPath !== $photoPath
            ) {
                deleteTeacherFile($oldPhotoPath);
            }

            if (
                $educationUpload['uploaded'] &&
                $oldEducationPath &&
                $oldEducationPath !== $educationPath
            ) {
                deleteTeacherFile($oldEducationPath);
            }

            if (
                $hasExperience === 'No' &&
                $oldExperiencePath
            ) {
                deleteTeacherFile($oldExperiencePath);
            }

            if (
                $hasExperience === 'Yes' &&
                $experienceUpload['uploaded'] &&
                $oldExperiencePath &&
                $oldExperiencePath !== $experiencePath
            ) {
                deleteTeacherFile($oldExperiencePath);
            }

            if (
                $hasPgdt === 'No' &&
                $oldPgdtPath
            ) {
                deleteTeacherFile($oldPgdtPath);
            }

            if (
                $hasPgdt === 'Yes' &&
                $pgdtUpload['uploaded'] &&
                $oldPgdtPath &&
                $oldPgdtPath !== $pgdtPath
            ) {
                deleteTeacherFile($oldPgdtPath);
            }

            $successMessage =
                'Teacher information updated successfully.';

            $teacher = getTeacherById(
                $conn,
                $teacherId
            );

        } catch (Throwable $e) {

            if ($transactionStarted) {
                $conn->rollback();
                $transactionStarted = false;
            }

            $errorMessage = $e->getMessage();

            /*
            |--------------------------------------------------------------------------
            | Remove newly uploaded files if database update failed
            |--------------------------------------------------------------------------
            */

            if (
                isset($photoUpload) &&
                $photoUpload['uploaded'] &&
                isset($photoUpload['path'])
            ) {
                deleteTeacherFile(
                    $photoUpload['path']
                );
            }

            if (
                isset($educationUpload) &&
                $educationUpload['uploaded'] &&
                isset($educationUpload['path'])
            ) {
                deleteTeacherFile(
                    $educationUpload['path']
                );
            }

            if (
                isset($experienceUpload) &&
                $experienceUpload['uploaded'] &&
                isset($experienceUpload['path'])
            ) {
                deleteTeacherFile(
                    $experienceUpload['path']
                );
            }

            if (
                isset($pgdtUpload) &&
                $pgdtUpload['uploaded'] &&
                isset($pgdtUpload['path'])
            ) {
                deleteTeacherFile(
                    $pgdtUpload['path']
                );
            }

            try {
                $teacher = getTeacherById(
                    $conn,
                    $teacherId
                );
            } catch (Throwable $ignored) {
                $teacher = null;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Teacher When Selected Through GET
|--------------------------------------------------------------------------
*/

if (
    $teacher === null &&
    $teacherId > 0 &&
    $errorMessage === ''
) {

    try {

        $teacher = getTeacherById(
            $conn,
            $teacherId
        );

        if (!$teacher) {
            $errorMessage = 'Teacher not found.';
            $teacherId = 0;
        }

    } catch (Throwable $e) {

        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Registrar Photo
|--------------------------------------------------------------------------
*/

$registrarPhoto =
    '../public/images/default-avatar.png';

if (!empty($registrar['photo'])) {

    $candidate =
        '../' .
        ltrim(
            (string) $registrar['photo'],
            '/\\'
        );

    if (is_file($candidate)) {
        $registrarPhoto = $candidate;
    }
}

/*
|--------------------------------------------------------------------------
| Teacher Files
|--------------------------------------------------------------------------
*/

$teacherPhotoUrl = '';

$educationFileUrl = '';

$experienceFileUrl = '';

$pgdtFileUrl = '';

if ($teacher) {

    if (!empty($teacher['photo_path'])) {

        $candidate =
            '../' .
            ltrim(
                (string) $teacher['photo_path'],
                '/\\'
            );

        if (is_file($candidate)) {
            $teacherPhotoUrl = $candidate;
        }
    }

    if (!empty($teacher['education_credential_path'])) {

        $candidate =
            '../' .
            ltrim(
                (string) $teacher['education_credential_path'],
                '/\\'
            );

        if (is_file($candidate)) {
            $educationFileUrl = $candidate;
        }
    }

    if (!empty($teacher['experience_file_path'])) {

        $candidate =
            '../' .
            ltrim(
                (string) $teacher['experience_file_path'],
                '/\\'
            );

        if (is_file($candidate)) {
            $experienceFileUrl = $candidate;
        }
    }

    if (!empty($teacher['pgdt_file_path'])) {

        $candidate =
            '../' .
            ltrim(
                (string) $teacher['pgdt_file_path'],
                '/\\'
            );

        if (is_file($candidate)) {
            $pgdtFileUrl = $candidate;
        }
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
        content="BKHS Registrar - Update Teacher"
    >

    <title>Update Teacher | BKHS Registrar</title>

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

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --background: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease;
        }

        .brand {
            height: 78px;
            padding: 0 22px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,.08);
            flex-shrink: 0;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37,99,235,.18);
            color: #60a5fa;
            font-size: 20px;
            margin-right: 11px;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 3px;
        }

        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding: 18px 12px;
        }

        .menu-label {
            padding: 0 12px 9px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            transition: .2s ease;
        }

        .nav-link i:first-child {
            width: 20px;
            text-align: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .nav-link.active {
            background: var(--primary);
            color: #fff;
        }

        .menu-parent {
            cursor: pointer;
        }

        .menu-parent .menu-arrow {
            margin-left: auto;
            font-size: 11px;
            transition: transform .2s ease;
        }

        .menu-parent.open .menu-arrow {
            transform: rotate(180deg);
        }

        .submenu {
            display: none;
            margin-bottom: 6px;
        }

        .submenu.show {
            display: block;
        }

        .submenu .nav-link {
            padding: 9px 13px 9px 45px;
            color: #94a3b8;
            font-size: 12px;
            position: relative;
        }

        .submenu .nav-link i {
            position: absolute;
            left: 20px;
            font-size: 13px;
            width: 14px;
        }

        .submenu .nav-link.active {
            background: rgba(37,99,235,.22);
            color: #fff;
        }

        .logout-link {
            color: #fca5a5;
            margin-top: 8px;
        }

        .logout-link:hover {
            background: rgba(220,38,38,.12);
            color: #fecaca;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: transparent;
            font-size: 25px;
            color: #111827;
        }

        .page-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .page-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .top-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-profile img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #eff6ff;
        }

        .top-profile-name {
            font-size: 13px;
            font-weight: 600;
        }

        .top-profile-role {
            color: var(--muted);
            font-size: 11px;
            margin-top: 2px;
        }

        .content {
            padding: 30px 32px;
        }

        .page-header {
            margin-bottom: 22px;
        }

        .page-header h2 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .page-header p {
            color: var(--muted);
            font-size: 13px;
            margin: 0;
        }

        .card-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 23px;
            margin-bottom: 22px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .card-description {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .section {
            border-bottom: 1px solid var(--border);
            padding-bottom: 24px;
            margin-bottom: 24px;
        }

        .section:last-child {
            border-bottom: 0;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 17px;
        }

        .section-title i {
            color: var(--primary);
        }

        .form-label {
            color: #374151;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-radius: 9px;
            border-color: var(--border);
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .readonly-box {
            min-height: 76px;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 13px;
        }

        .readonly-label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .readonly-value {
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            word-break: break-word;
        }

        .search-wrapper {
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            z-index: 2;
        }

        .search-input {
            height: 46px;
            padding-left: 43px;
        }

        .btn-primary-custom {
            min-height: 44px;
            padding: 0 18px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .btn-secondary-custom {
            min-height: 44px;
            padding: 0 18px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-secondary-custom:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .teacher-result {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 15px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .teacher-result:hover {
            border-color: #bfdbfe;
            background: #f8fbff;
        }

        .teacher-result-name {
            font-size: 13px;
            font-weight: 700;
        }

        .teacher-result-info {
            color: var(--muted);
            font-size: 11px;
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .teacher-result-info i {
            margin-right: 4px;
        }

        .btn-update {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            background: #eff6ff;
            color: var(--primary);
            border: 1px solid #dbeafe;
            border-radius: 8px;
            padding: 8px 13px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-update:hover {
            background: var(--primary);
            color: #fff;
        }

        .file-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 14px;
        }

        .file-box .form-control {
            background: #fff;
        }

        .form-text {
            font-size: 11px;
        }

        .current-file {
            margin-top: 9px;
            font-size: 11px;
        }

        .current-file a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .current-file a:hover {
            text-decoration: underline;
        }

        .teacher-photo-preview {
            width: 95px;
            height: 95px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #eff6ff;
            background: #f3f4f6;
        }

        .conditional-file {
            display: none;
        }

        .conditional-file.show {
            display: block;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            display: block;
            color: #cbd5e1;
            font-size: 40px;
            margin-bottom: 12px;
        }

        .empty-state-title {
            color: #374151;
            font-size: 14px;
            font-weight: 600;
        }

        .empty-state-text {
            color: var(--muted);
            font-size: 12px;
            margin-top: 5px;
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 900px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: block;
            }
        }

        @media (max-width: 650px) {

            .topbar {
                padding: 0 16px;
            }

            .content {
                padding: 20px 15px;
            }

            .top-profile-name,
            .top-profile-role {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .card-box {
                padding: 17px;
            }

            .teacher-result {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-update {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>
            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                Registrar Portal
            </div>
        </div>

    </div>

    <nav class="sidebar-menu">

        <div class="menu-label">
            Main Menu
        </div>

        <a
            href="dashboard.php"
            class="nav-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>


        <!-- Students -->

        <div
            class="nav-link menu-parent"
            data-menu="studentsMenu"
        >

            <i class="bi bi-people-fill"></i>

            <span>Students</span>

            <i class="bi bi-chevron-down menu-arrow"></i>

        </div>

        <div
            class="submenu"
            id="studentsMenu"
        >

            <a
                href="register.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="students.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="update-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="delete-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete</span>
            </a>

            <a
                href="withdraw-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

        </div>


        <!-- Teachers -->

        <div
            class="nav-link menu-parent open"
            data-menu="teachersMenu"
        >

            <i class="bi bi-person-video3"></i>

            <span>Teachers</span>

            <i class="bi bi-chevron-down menu-arrow"></i>

        </div>

        <div
            class="submenu show"
            id="teachersMenu"
        >

            <a
                href="teachers.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="update-teacher.php"
                class="nav-link active"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update</span>
            </a>

            <a
                href="withdraw-teacher.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

            <a
                href="homeroom-teachers.php"
                class="nav-link"
            >
                <i class="bi bi-house-door-fill"></i>
                <span>Homeroom</span>
            </a>

            <a
                href="subject-teachers.php"
                class="nav-link"
            >
                <i class="bi bi-book-fill"></i>
                <span>Subject</span>
            </a>

        </div>


        <!-- Other Staff -->

        <div
            class="nav-link menu-parent"
            data-menu="staffMenu"
        >

            <i class="bi bi-person-badge-fill"></i>

            <span>Other Staff</span>

            <i class="bi bi-chevron-down menu-arrow"></i>

        </div>

        <div
            class="submenu"
            id="staffMenu"
        >

            <a
                href="add-staff.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Add Staff</span>
            </a>

            <a
                href="staff.php"
                class="nav-link"
            >
                <i class="bi bi-list-ul"></i>
                <span>List</span>
            </a>

            <a
                href="withdraw-staff.php"
                class="nav-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw</span>
            </a>

        </div>


        <a
            href="certificate.php"
            class="nav-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="nav-link"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="nav-link"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <a
            href="profile.php"
            class="nav-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="nav-link logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Update Teacher
                </h1>

                <div class="page-subtitle">
                    Search and update teacher information
                </div>

            </div>

        </div>

        <div class="top-profile">

            <img
                src="<?= e($registrarPhoto) ?>"
                alt="Registrar"
            >

            <div>

                <div class="top-profile-name">
                    <?= e($registrar['full_name'] ?? 'Registrar') ?>
                </div>

                <div class="top-profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>


    <!-- Content -->

    <div class="content">

        <div class="page-header">

            <h2>
                Teacher Information
            </h2>

            <p>
                Search for a teacher using their full name,
                email address, or phone number.
            </p>

        </div>


        <?php if ($errorMessage !== ''): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>


        <?php if ($successMessage !== ''): ?>

            <div class="alert alert-success">

                <i class="bi bi-check-circle-fill me-2"></i>

                <?= e($successMessage) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             SEARCH CARD
        ====================================================== -->

        <section class="card-box">

            <div class="mb-3">

                <h3 class="card-title">
                    Find Teacher
                </h3>

                <div class="card-description">
                    Search by full name, email, or phone number.
                </div>

            </div>

            <form
                method="GET"
                action="update-teacher.php"
                autocomplete="off"
            >

                <div class="row g-2">

                    <div class="col-md-9">

                        <div class="search-wrapper">

                            <i class="bi bi-search search-icon"></i>

                            <input
                                type="search"
                                name="search"
                                class="form-control search-input"
                                placeholder="Full name, email, or phone..."
                                value="<?= e($search) ?>"
                                autocomplete="off"
                                spellcheck="false"
                            >

                        </div>

                    </div>

                    <div class="col-md-3">

                        <button
                            type="submit"
                            class="btn-primary-custom w-100"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                    </div>

                </div>

            </form>


            <?php if ($search !== ''): ?>

                <div class="mt-3">

                    <?php if (count($searchResults) > 0): ?>

                        <?php foreach ($searchResults as $result): ?>

                            <div class="teacher-result">

                                <div>

                                    <div class="teacher-result-name">

                                        <?= e(
                                            $result['full_name']
                                        ) ?>

                                    </div>

                                    <div class="teacher-result-info">

                                        <?php if (
                                            !empty($result['email'])
                                        ): ?>

                                            <span>
                                                <i class="bi bi-envelope"></i>
                                                <?= e($result['email']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if (
                                            !empty($result['phone'])
                                        ): ?>

                                            <span>
                                                <i class="bi bi-telephone"></i>
                                                <?= e($result['phone']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if (
                                            !empty($result['department'])
                                        ): ?>

                                            <span>
                                                <i class="bi bi-building"></i>
                                                <?= e($result['department']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <span>
                                            <i class="bi bi-circle-fill"></i>
                                            <?= e(
                                                $result['employment_status']
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                                <a
                                    href="update-teacher.php?search=<?= urlencode($search) ?>&teacher_id=<?= (int) $result['teacher_id'] ?>"
                                    class="btn-update"
                                >

                                    <i class="bi bi-pencil-square me-1"></i>

                                    Update

                                </a>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-person-x"></i>

                            <div class="empty-state-title">
                                No Teacher Found
                            </div>

                            <div class="empty-state-text">
                                Try another name, email, or phone number.
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>


        <?php if ($teacher): ?>

            <!-- =================================================
                 UPDATE FORM
            ================================================== -->

            <form
                method="POST"
                action="update-teacher.php?teacher_id=<?= (int) $teacher['teacher_id'] ?>"
                enctype="multipart/form-data"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    name="teacher_id"
                    value="<?= (int) $teacher['teacher_id'] ?>"
                >

                <input
                    type="hidden"
                    name="update_teacher"
                    value="1"
                >


                <!-- =================================================
                     ACCOUNT INFORMATION
                ================================================== -->

                <section class="card-box">

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-person-lock"></i>

                            User Account Information

                        </div>

                        <div class="alert alert-info">

                            <i class="bi bi-info-circle-fill me-2"></i>

                            Full name, email, phone, password and other
                            account information belong to the
                            <strong>users</strong> table and cannot be
                            changed here.

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <div class="readonly-box">

                                    <div class="readonly-label">
                                        Full Name
                                    </div>

                                    <div class="readonly-value">
                                        <?= e(
                                            $teacher['full_name']
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="readonly-box">

                                    <div class="readonly-label">
                                        Email
                                    </div>

                                    <div class="readonly-value">

                                        <?= !empty($teacher['email'])
                                            ? e($teacher['email'])
                                            : 'Not provided'
                                        ?>

                                    </div>

                                </div>

                            </div>

                            <div class="col-md-4">

                                <div class="readonly-box">

                                    <div class="readonly-label">
                                        Phone
                                    </div>

                                    <div class="readonly-value">

                                        <?= !empty($teacher['phone'])
                                            ? e($teacher['phone'])
                                            : 'Not provided'
                                        ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         TEACHER PHOTO
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-camera-fill"></i>

                            Teacher Photo

                        </div>

                        <div class="row g-4 align-items-center">

                            <div class="col-auto">

                                <?php if ($teacherPhotoUrl !== ''): ?>

                                    <img
                                        src="<?= e($teacherPhotoUrl) ?>"
                                        alt="Teacher Photo"
                                        class="teacher-photo-preview"
                                    >

                                <?php else: ?>

                                    <div
                                        class="teacher-photo-preview d-flex align-items-center justify-content-center"
                                    >

                                        <i class="bi bi-person-fill fs-1 text-secondary"></i>

                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="col-md-7">

                                <label
                                    for="teacher_photo"
                                    class="form-label"
                                >
                                    Change Photo
                                </label>

                                <input
                                    type="file"
                                    name="teacher_photo"
                                    id="teacher_photo"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp"
                                >

                                <div class="form-text">
                                    JPG, JPEG, PNG or WEBP.
                                    Maximum 5 MB.
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         EMPLOYMENT
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-briefcase-fill"></i>

                            Employment Information

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label
                                    for="employment_status"
                                    class="form-label"
                                >
                                    Employment Status
                                </label>

                                <select
                                    name="employment_status"
                                    id="employment_status"
                                    class="form-select"
                                    autocomplete="off"
                                    required
                                >

                                    <option
                                        value="Active"
                                        <?= (
                                            $teacher['employment_status']
                                            === 'Active'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Active
                                    </option>

                                    <option
                                        value="Withdrawn"
                                        <?= (
                                            $teacher['employment_status']
                                            === 'Withdrawn'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Withdrawn
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label
                                    for="fayda_number"
                                    class="form-label"
                                >
                                    Fayda Number
                                </label>

                                <input
                                    type="text"
                                    name="fayda_number"
                                    id="fayda_number"
                                    class="form-control"
                                    maxlength="16"
                                    value="<?= e(
                                        $teacher['fayda_number']
                                    ) ?>"
                                    autocomplete="off"
                                    required
                                >

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         PERSONAL INFORMATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-person-vcard-fill"></i>

                            Personal Information

                        </div>

                        <div class="row g-3">

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
                                    autocomplete="off"
                                >

                                    <option value="">
                                        Select Gender
                                    </option>

                                    <option
                                        value="Male"
                                        <?= (
                                            $teacher['gender']
                                            === 'Male'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Male
                                    </option>

                                    <option
                                        value="Female"
                                        <?= (
                                            $teacher['gender']
                                            === 'Female'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Female
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label
                                    for="marital_status"
                                    class="form-label"
                                >
                                    Marital Status
                                </label>

                                <select
                                    name="marital_status"
                                    id="marital_status"
                                    class="form-select"
                                    autocomplete="off"
                                >

                                    <option value="">
                                        Select Marital Status
                                    </option>

                                    <option
                                        value="Single"
                                        <?= (
                                            $teacher['marital_status']
                                            === 'Single'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Single
                                    </option>

                                    <option
                                        value="Married"
                                        <?= (
                                            $teacher['marital_status']
                                            === 'Married'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Married
                                    </option>

                                    <option
                                        value="Divorced"
                                        <?= (
                                            $teacher['marital_status']
                                            === 'Divorced'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Divorced
                                    </option>

                                    <option
                                        value="Widowed"
                                        <?= (
                                            $teacher['marital_status']
                                            === 'Widowed'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Widowed
                                    </option>

                                </select>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         BIRTH DATE
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-calendar3"></i>

                            Ethiopian Birth Date

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label
                                    for="birth_eth_year"
                                    class="form-label"
                                >
                                    Birth Year
                                </label>

                                <input
                                    type="number"
                                    name="birth_eth_year"
                                    id="birth_eth_year"
                                    class="form-control"
                                    min="1900"
                                    max="2100"
                                    value="<?= e(
                                        $teacher['birth_eth_year']
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="birth_eth_month"
                                    class="form-label"
                                >
                                    Birth Month
                                </label>

                                <select
                                    name="birth_eth_month"
                                    id="birth_eth_month"
                                    class="form-select"
                                    autocomplete="off"
                                >

                                    <option value="">
                                        Select Month
                                    </option>

                                    <?php

                                    $ethiopianMonths = [
                                        1 => 'Meskerem',
                                        2 => 'Tikimt',
                                        3 => 'Hidar',
                                        4 => 'Tahsas',
                                        5 => 'Tir',
                                        6 => 'Yekatit',
                                        7 => 'Megabit',
                                        8 => 'Miazia',
                                        9 => 'Ginbot',
                                        10 => 'Sene',
                                        11 => 'Hamle',
                                        12 => 'Nehase',
                                        13 => 'Pagume'
                                    ];

                                    foreach (
                                        $ethiopianMonths
                                        as $monthNumber => $monthName
                                    ):

                                    ?>

                                        <option
                                            value="<?= $monthNumber ?>"
                                            <?= (
                                                (int) $teacher[
                                                    'birth_eth_month'
                                                ] === $monthNumber
                                            )
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= $monthName ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="birth_eth_day"
                                    class="form-label"
                                >
                                    Birth Day
                                </label>

                                <input
                                    type="number"
                                    name="birth_eth_day"
                                    id="birth_eth_day"
                                    class="form-control"
                                    min="1"
                                    max="30"
                                    value="<?= e(
                                        $teacher['birth_eth_day']
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         ADDRESS
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-geo-alt-fill"></i>

                            Address Information

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label
                                    for="region"
                                    class="form-label"
                                >
                                    Region
                                </label>

                                <input
                                    type="text"
                                    name="region"
                                    id="region"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= e(
                                        $teacher['region']
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="zone"
                                    class="form-label"
                                >
                                    Zone
                                </label>

                                <input
                                    type="text"
                                    name="zone"
                                    id="zone"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= e(
                                        $teacher['zone']
                                    ) ?>"
                                    autocomplete="off"
                                >

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
                                    maxlength="100"
                                    value="<?= e(
                                        $teacher['woreda']
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         EDUCATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-mortarboard-fill"></i>

                            Education Information

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label
                                    for="education_level"
                                    class="form-label"
                                >
                                    Education Level
                                </label>

                                <input
                                    type="text"
                                    name="education_level"
                                    id="education_level"
                                    class="form-control"
                                    maxlength="150"
                                    value="<?= e(
                                        $teacher['education_level']
                                    ) ?>"
                                    autocomplete="off"
                                >

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
                                    maxlength="150"
                                    value="<?= e(
                                        $teacher['department']
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                            <div class="col-12">

                                <div class="file-box">

                                    <label
                                        for="education_credential_file"
                                        class="form-label"
                                    >
                                        Education Credential
                                    </label>

                                    <input
                                        type="file"
                                        name="education_credential_file"
                                        id="education_credential_file"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        PDF, JPG, JPEG, PNG or WEBP.
                                        Maximum 5 MB.
                                    </div>

                                    <?php if (
                                        $educationFileUrl !== ''
                                    ): ?>

                                        <div class="current-file">

                                            <i class="bi bi-file-earmark-check-fill text-success me-1"></i>

                                            Current file:

                                            <a
                                                href="<?= e(
                                                    $educationFileUrl
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                View Education Credential
                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         EXPERIENCE
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-person-workspace"></i>

                            Work Experience

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label
                                    for="has_experience"
                                    class="form-label"
                                >
                                    Has Experience?
                                </label>

                                <select
                                    name="has_experience"
                                    id="has_experience"
                                    class="form-select"
                                    autocomplete="off"
                                >

                                    <option
                                        value="No"
                                        <?= (
                                            $teacher['has_experience']
                                            === 'No'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        No
                                    </option>

                                    <option
                                        value="Yes"
                                        <?= (
                                            $teacher['has_experience']
                                            === 'Yes'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Yes
                                    </option>

                                </select>

                            </div>

                            <div
                                class="col-md-8 conditional-file"
                                id="experienceFileContainer"
                            >

                                <div class="file-box">

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
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        Upload experience document
                                        when the answer is Yes.
                                    </div>

                                    <?php if (
                                        $experienceFileUrl !== ''
                                    ): ?>

                                        <div class="current-file">

                                            <i class="bi bi-file-earmark-check-fill text-success me-1"></i>

                                            Current file:

                                            <a
                                                href="<?= e(
                                                    $experienceFileUrl
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                View Experience File
                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         COLLEGE / UNIVERSITY
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-building-fill"></i>

                            College / University

                        </div>

                        <div class="row g-3">

                            <div class="col-12">

                                <label
                                    for="college_university_institution"
                                    class="form-label"
                                >
                                    College / University / Institution
                                </label>

                                <input
                                    type="text"
                                    name="college_university_institution"
                                    id="college_university_institution"
                                    class="form-control"
                                    maxlength="255"
                                    value="<?= e(
                                        $teacher[
                                            'college_university_institution'
                                        ]
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         PGDT
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">

                            <i class="bi bi-award-fill"></i>

                            PGDT Information

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label
                                    for="has_pgdt"
                                    class="form-label"
                                >
                                    Has PGDT?
                                </label>

                                <select
                                    name="has_pgdt"
                                    id="has_pgdt"
                                    class="form-select"
                                    autocomplete="off"
                                >

                                    <option
                                        value="No"
                                        <?= (
                                            $teacher['has_pgdt']
                                            === 'No'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        No
                                    </option>

                                    <option
                                        value="Yes"
                                        <?= (
                                            $teacher['has_pgdt']
                                            === 'Yes'
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Yes
                                    </option>

                                </select>

                            </div>

                            <div
                                class="col-md-8 conditional-file"
                                id="pgdtFileContainer"
                            >

                                <div class="file-box">

                                    <label
                                        for="pgdt_file"
                                        class="form-label"
                                    >
                                        PGDT File
                                    </label>

                                    <input
                                        type="file"
                                        name="pgdt_file"
                                        id="pgdt_file"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        Upload PGDT document when
                                        the answer is Yes.
                                    </div>

                                    <?php if (
                                        $pgdtFileUrl !== ''
                                    ): ?>

                                        <div class="current-file">

                                            <i class="bi bi-file-earmark-check-fill text-success me-1"></i>

                                            Current file:

                                            <a
                                                href="<?= e(
                                                    $pgdtFileUrl
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                View PGDT File
                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         BUTTONS
                    ================================================== -->

                    <div
                        class="d-flex flex-wrap justify-content-end gap-2"
                    >

                        <a
                            href="update-teacher.php"
                            class="btn-secondary-custom"
                        >

                            <i class="bi bi-x-lg me-1"></i>

                            Cancel

                        </a>

                        <button
                            type="submit"
                            class="btn-primary-custom"
                            id="updateButton"
                        >

                            <i class="bi bi-check-circle me-1"></i>

                            Update Teacher

                        </button>

                    </div>

                </section>

            </form>

        <?php elseif ($search === ''): ?>

            <section class="card-box">

                <div class="empty-state">

                    <i class="bi bi-person-gear"></i>

                    <div class="empty-state-title">
                        Search for a Teacher
                    </div>

                    <div class="empty-state-text">
                        Enter the teacher's full name,
                        email, or phone number above.
                    </div>

                </div>

            </section>

        <?php endif; ?>

    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const mobileMenu =
    document.getElementById('mobileMenu');

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

if (mobileMenu) {

    mobileMenu.addEventListener(
        'click',
        function () {

            sidebar.classList.toggle('show');

            sidebarOverlay.classList.toggle('show');

        }
    );

}

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        function () {

            sidebar.classList.remove('show');

            sidebarOverlay.classList.remove('show');

        }
    );

}


/*
|--------------------------------------------------------------------------
| Sidebar Dropdowns
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.menu-parent')
    .forEach(
        function (parent) {

            parent.addEventListener(
                'click',
                function () {

                    const menuId =
                        parent.getAttribute('data-menu');

                    const menu =
                        document.getElementById(menuId);

                    if (!menu) {
                        return;
                    }

                    const currentlyOpen =
                        menu.classList.contains('show');

                    document
                        .querySelectorAll('.submenu')
                        .forEach(
                            function (submenu) {
                                submenu.classList.remove('show');
                            }
                        );

                    document
                        .querySelectorAll('.menu-parent')
                        .forEach(
                            function (item) {
                                item.classList.remove('open');
                            }
                        );

                    if (!currentlyOpen) {

                        menu.classList.add('show');

                        parent.classList.add('open');

                    }

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| Experience File
|--------------------------------------------------------------------------
*/

const experienceSelect =
    document.getElementById('has_experience');

const experienceContainer =
    document.getElementById(
        'experienceFileContainer'
    );

function updateExperienceVisibility() {

    if (
        !experienceSelect ||
        !experienceContainer
    ) {
        return;
    }

    if (
        experienceSelect.value === 'Yes'
    ) {

        experienceContainer.classList.add('show');

    } else {

        experienceContainer.classList.remove('show');

    }

}

if (experienceSelect) {

    experienceSelect.addEventListener(
        'change',
        updateExperienceVisibility
    );

    updateExperienceVisibility();

}


/*
|--------------------------------------------------------------------------
| PGDT File
|--------------------------------------------------------------------------
*/

const pgdtSelect =
    document.getElementById('has_pgdt');

const pgdtContainer =
    document.getElementById(
        'pgdtFileContainer'
    );

function updatePgdtVisibility() {

    if (
        !pgdtSelect ||
        !pgdtContainer
    ) {
        return;
    }

    if (
        pgdtSelect.value === 'Yes'
    ) {

        pgdtContainer.classList.add('show');

    } else {

        pgdtContainer.classList.remove('show');

    }

}

if (pgdtSelect) {

    pgdtSelect.addEventListener(
        'change',
        updatePgdtVisibility
    );

    updatePgdtVisibility();

}


/*
|--------------------------------------------------------------------------
| Ethiopian Birth Day Limit
|--------------------------------------------------------------------------
*/

const birthMonth =
    document.getElementById('birth_eth_month');

const birthDay =
    document.getElementById('birth_eth_day');

function updateBirthDayLimit() {

    if (!birthMonth || !birthDay) {
        return;
    }

    if (birthMonth.value === '13') {

        birthDay.max = '6';

        if (
            parseInt(birthDay.value || '0', 10) > 6
        ) {
            birthDay.value = '';
        }

    } else {

        birthDay.max = '30';

    }

}

if (birthMonth) {

    birthMonth.addEventListener(
        'change',
        updateBirthDayLimit
    );

    updateBirthDayLimit();

}


/*
|--------------------------------------------------------------------------
| Form Submission
|--------------------------------------------------------------------------
*/

const updateForm =
    document.querySelector(
        'form[enctype="multipart/form-data"]'
    );

if (updateForm) {

    updateForm.addEventListener(
        'submit',
        function () {

            const button =
                document.getElementById(
                    'updateButton'
                );

            if (!button) {
                return;
            }

            button.disabled = true;

            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                'Updating...';

        }
    );

}


/*
|--------------------------------------------------------------------------
| Close Sidebar On Mobile Link Click
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.sidebar a')
    .forEach(
        function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <= 900
                    ) {

                        sidebar.classList.remove('show');

                        sidebarOverlay.classList.remove('show');

                    }

                }
            );

        }
    );

</script>

</body>

</html>