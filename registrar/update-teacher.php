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

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$registrar = [
    'full_name' => $_SESSION['full_name'] ?? 'Registrar',
    'photo' => null,
];

$stmt = $conn->prepare("
    SELECT
        u.full_name,
        r.photo
    FROM users u
    LEFT JOIN registrars r ON r.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $registrarId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $registrar = array_merge($registrar, $row);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function uploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'The file upload was blocked by a server extension.',
        default => 'An unknown file upload error occurred.',
    };
}

function saveUploadedFile(
    array $file,
    string $directory,
    string $relativeDirectory,
    array $allowedMimeTypes,
    int $maxSize,
    string $prefix
): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return [
            'success' => false,
            'message' => 'Invalid uploaded file.',
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => uploadErrorMessage((int) $file['error']),
        ];
    }

    if ((int) $file['size'] <= 0) {
        return [
            'success' => false,
            'message' => 'The uploaded file is empty.',
        ];
    }

    if ((int) $file['size'] > $maxSize) {
        return [
            'success' => false,
            'message' => 'The uploaded file exceeds the allowed size.',
        ];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [
            'success' => false,
            'message' => 'Invalid uploaded file.',
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        return [
            'success' => false,
            'message' => 'This file type is not allowed.',
        ];
    }

    $extension = $allowedMimeTypes[$mimeType];

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            return [
                'success' => false,
                'message' => 'Could not create the upload directory.',
            ];
        }
    }

    $fileName =
        $prefix
        . '_'
        . bin2hex(random_bytes(12))
        . '.'
        . $extension;

    $destination =
        rtrim($directory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return [
            'success' => false,
            'message' => 'Could not save the uploaded file.',
        ];
    }

    return [
        'success' => true,
        'path' =>
            trim($relativeDirectory, '/')
            . '/'
            . $fileName,
        'absolute_path' => $destination,
    ];
}

function deleteStoredFile(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $projectRoot = dirname(__DIR__);

    $relativePath = ltrim(
        str_replace('\\', '/', $relativePath),
        '/'
    );

    if (str_starts_with($relativePath, 'public/')) {
        $fullPath =
            $projectRoot
            . '/'
            . $relativePath;
    } else {
        $fullPath =
            $projectRoot
            . '/public/'
            . $relativePath;
    }

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| Education Levels
|--------------------------------------------------------------------------
*/

$educationLevels = [
    'Certificate',
    'Diploma',
    'BSc',
    'BEd',
    'Doctor',
    'PhD',
];

/*
|--------------------------------------------------------------------------
| Marital Statuses
|--------------------------------------------------------------------------
*/

$maritalStatuses = [
    'Single',
    'Married',
    'Divorced',
    'Widowed',
];

/*
|--------------------------------------------------------------------------
| Ethiopian Months
|--------------------------------------------------------------------------
*/

$ethiopianMonths = [
    1 => 'Meskerem / መስከረም',
    2 => 'Tikimt / ጥቅምት',
    3 => 'Hidar / ኅዳር',
    4 => 'Tahsas / ታኅሣሥ',
    5 => 'Tir / ጥር',
    6 => 'Yekatit / የካቲት',
    7 => 'Megabit / መጋቢት',
    8 => 'Miazia / ሚያዝያ',
    9 => 'Ginbot / ግንቦት',
    10 => 'Sene / ሰኔ',
    11 => 'Hamle / ሐምሌ',
    12 => 'Nehase / ነሐሴ',
    13 => 'Pagume / ጳጉሜን',
];

/*
|--------------------------------------------------------------------------
| Search Teachers
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$teachers = [];

if ($search !== '') {

    $searchLike = '%' . $search . '%';

    $stmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            u.is_deleted,
            t.id AS teacher_id
        FROM users u
        LEFT JOIN teachers t
            ON t.user_id = u.id
        WHERE
            LOWER(u.role) = 'teacher'
            AND u.is_deleted = 0
            AND (
                u.full_name LIKE ?
                OR u.email LIKE ?
                OR u.phone LIKE ?
            )
        ORDER BY u.full_name ASC
        LIMIT 30
    ");

    if ($stmt) {

        $stmt->bind_param(
            'sss',
            $searchLike,
            $searchLike,
            $searchLike
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $teachers[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selected Teacher
|--------------------------------------------------------------------------
*/

$selectedUserId = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : (int) ($_POST['user_id'] ?? 0);

$teacher = null;

if ($selectedUserId > 0) {

    $stmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            u.role,

            t.id AS teacher_id,
            t.fayda_number,
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
            t.education_credential_path,
            t.has_experience,
            t.experience_file_path,
            t.has_pgdt,
            t.pgdt_file_path

        FROM users u

        LEFT JOIN teachers t
            ON t.user_id = u.id

        WHERE
            u.id = ?
            AND LOWER(u.role) = 'teacher'
            AND u.is_deleted = 0

        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $selectedUserId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $teacher = $row;
        }

        $stmt->close();
    }

    if (!$teacher) {

        $error =
            'Teacher account was not found.';

        $selectedUserId = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Form Defaults
|--------------------------------------------------------------------------
*/

$form = [
    'fayda_number' =>
        $teacher['fayda_number'] ?? '',

    'gender' =>
        $teacher['gender'] ?? '',

    'birth_eth_year' =>
        $teacher['birth_eth_year'] ?? '',

    'birth_eth_month' =>
        $teacher['birth_eth_month'] ?? '',

    'birth_eth_day' =>
        $teacher['birth_eth_day'] ?? '',

    'region' =>
        $teacher['region'] ?? '',

    'zone' =>
        $teacher['zone'] ?? '',

    'woreda' =>
        $teacher['woreda'] ?? '',

    'marital_status' =>
        $teacher['marital_status'] ?? '',

    'education_level' =>
        $teacher['education_level'] ?? '',

    'department' =>
        $teacher['department'] ?? '',

    'college_university_institution' =>
        $teacher['college_university_institution'] ?? '',

    'has_experience' =>
        $teacher['has_experience'] ?? 'No',

    'has_pgdt' =>
        $teacher['has_pgdt'] ?? 'No',
];

/*
|--------------------------------------------------------------------------
| Save Teacher Information
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        !hash_equals(
            (string) $_SESSION['csrf_token'],
            $postedToken
        )
    ) {

        $error =
            'Invalid security token. Please refresh the page and try again.';

    } else {

        $userId =
            (int) ($_POST['user_id'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Read Form
        |--------------------------------------------------------------------------
        */

        $form['fayda_number'] =
            trim(
                (string) (
                    $_POST['fayda_number'] ?? ''
                )
            );

        $form['gender'] =
            trim(
                (string) (
                    $_POST['gender'] ?? ''
                )
            );

        $form['birth_eth_year'] =
            trim(
                (string) (
                    $_POST['birth_eth_year'] ?? ''
                )
            );

        $form['birth_eth_month'] =
            trim(
                (string) (
                    $_POST['birth_eth_month'] ?? ''
                )
            );

        $form['birth_eth_day'] =
            trim(
                (string) (
                    $_POST['birth_eth_day'] ?? ''
                )
            );

        $form['region'] =
            trim(
                (string) (
                    $_POST['region'] ?? ''
                )
            );

        $form['zone'] =
            trim(
                (string) (
                    $_POST['zone'] ?? ''
                )
            );

        $form['woreda'] =
            trim(
                (string) (
                    $_POST['woreda'] ?? ''
                )
            );

        $form['marital_status'] =
            trim(
                (string) (
                    $_POST['marital_status'] ?? ''
                )
            );

        $form['education_level'] =
            trim(
                (string) (
                    $_POST['education_level'] ?? ''
                )
            );

        $form['department'] =
            trim(
                (string) (
                    $_POST['department'] ?? ''
                )
            );

        $form['college_university_institution'] =
            trim(
                (string) (
                    $_POST[
                        'college_university_institution'
                    ] ?? ''
                )
            );

        $form['has_experience'] =
            trim(
                (string) (
                    $_POST['has_experience'] ?? 'No'
                )
            );

        $form['has_pgdt'] =
            trim(
                (string) (
                    $_POST['has_pgdt'] ?? 'No'
                )
            );

        $errors = [];

        /*
        |--------------------------------------------------------------------------
        | Validate Teacher Account
        |--------------------------------------------------------------------------
        */

        if ($userId <= 0) {

            $errors[] =
                'Please select a teacher.';
        }

        $account = null;

        if ($userId > 0) {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    role,
                    is_deleted
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'i',
                    $userId
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                $account =
                    $result->fetch_assoc();

                $stmt->close();
            }

            if (
                !$account ||
                strtolower(
                    (string) $account['role']
                ) !== 'teacher' ||
                (int) $account['is_deleted'] === 1
            ) {

                $errors[] =
                    'The selected account is not an active teacher account.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Fayda Number
        |--------------------------------------------------------------------------
        */

        if ($form['fayda_number'] === '') {

            $errors[] =
                'Fayda Number is required.';

        } elseif (
            !preg_match(
                '/^[0-9]{16}$/',
                $form['fayda_number']
            )
        ) {

            $errors[] =
                'Fayda Number must contain exactly 16 digits.';
        }

        /*
        |--------------------------------------------------------------------------
        | Gender
        |--------------------------------------------------------------------------
        */

        if (
            $form['gender'] !== '' &&
            !in_array(
                $form['gender'],
                ['Male', 'Female'],
                true
            )
        ) {

            $errors[] =
                'Invalid gender selected.';
        }

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Birth Date
        |--------------------------------------------------------------------------
        */

        $birthYear = null;
        $birthMonth = null;
        $birthDay = null;

        if (
            $form['birth_eth_year'] !== '' ||
            $form['birth_eth_month'] !== '' ||
            $form['birth_eth_day'] !== ''
        ) {

            if (
                !ctype_digit(
                    $form['birth_eth_year']
                ) ||
                (int) $form['birth_eth_year'] < 1900 ||
                (int) $form['birth_eth_year'] > 2200
            ) {

                $errors[] =
                    'Enter a valid Ethiopian birth year.';

            } else {

                $birthYear =
                    (int) $form['birth_eth_year'];
            }

            if (
                !ctype_digit(
                    $form['birth_eth_month']
                ) ||
                (int) $form['birth_eth_month'] < 1 ||
                (int) $form['birth_eth_month'] > 13
            ) {

                $errors[] =
                    'Select a valid Ethiopian birth month.';

            } else {

                $birthMonth =
                    (int) $form['birth_eth_month'];
            }

            if (
                !ctype_digit(
                    $form['birth_eth_day']
                ) ||
                (int) $form['birth_eth_day'] < 1
            ) {

                $errors[] =
                    'Select a valid Ethiopian birth day.';

            } else {

                $birthDay =
                    (int) $form['birth_eth_day'];

                if ($birthMonth === 13) {

                    if ($birthDay > 6) {

                        $errors[] =
                            'Pagume can have a maximum of 6 days.';
                    }

                } elseif ($birthDay > 30) {

                    $errors[] =
                        'Ethiopian months can have a maximum of 30 days.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Marital Status
        |--------------------------------------------------------------------------
        */

        if (
            $form['marital_status'] !== '' &&
            !in_array(
                $form['marital_status'],
                $maritalStatuses,
                true
            )
        ) {

            $errors[] =
                'Invalid marital status selected.';
        }

        /*
        |--------------------------------------------------------------------------
        | Education
        |--------------------------------------------------------------------------
        */

        if (
            $form['education_level'] === '' ||
            !in_array(
                $form['education_level'],
                $educationLevels,
                true
            )
        ) {

            $errors[] =
                'Please select an education level.';
        }

        if (
            $form['college_university_institution'] === ''
        ) {

            $errors[] =
                'Institution is required.';
        }

        if ($form['department'] === '') {

            $errors[] =
                'Department is required.';
        }

        /*
        |--------------------------------------------------------------------------
        | Experience
        |--------------------------------------------------------------------------
        */

        if (
            !in_array(
                $form['has_experience'],
                ['Yes', 'No'],
                true
            )
        ) {

            $errors[] =
                'Invalid experience selection.';
        }

        if (
            $form['has_experience'] === 'Yes' &&
            (
                !isset(
                    $_FILES['experience_file']
                ) ||
                $_FILES['experience_file']['error']
                    === UPLOAD_ERR_NO_FILE
            ) &&
            empty(
                $teacher['experience_file_path']
            )
        ) {

            $errors[] =
                'Experience document is required when the teacher has experience.';
        }

        /*
        |--------------------------------------------------------------------------
        | PGDT
        |--------------------------------------------------------------------------
        */

        if (
            !in_array(
                $form['has_pgdt'],
                ['Yes', 'No'],
                true
            )
        ) {

            $errors[] =
                'Invalid PGDT selection.';
        }

        if (
            $form['has_pgdt'] === 'Yes' &&
            (
                !isset(
                    $_FILES['pgdt_file']
                ) ||
                $_FILES['pgdt_file']['error']
                    === UPLOAD_ERR_NO_FILE
            ) &&
            empty(
                $teacher['pgdt_file_path']
            )
        ) {

            $errors[] =
                'PGDT document is required when the teacher has PGDT.';
        }

        /*
        |--------------------------------------------------------------------------
        | Education Credential
        |--------------------------------------------------------------------------
        */

        if (
            (
                !isset(
                    $_FILES['education_credential']
                ) ||
                $_FILES['education_credential']['error']
                    === UPLOAD_ERR_NO_FILE
            ) &&
            empty(
                $teacher['education_credential_path']
            )
        ) {

            $errors[] =
                'Education credential document is required.';
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Fayda Number
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $stmt = $conn->prepare("
                SELECT id
                FROM teachers
                WHERE
                    fayda_number = ?
                    AND user_id <> ?
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'si',
                    $form['fayda_number'],
                    $userId
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                if ($result->fetch_assoc()) {

                    $errors[] =
                        'This Fayda Number is already registered to another teacher.';
                }

                $stmt->close();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | File Uploads
        |--------------------------------------------------------------------------
        */

        $newEducationFile = null;
        $newExperienceFile = null;
        $newPgdtFile = null;

        if (empty($errors)) {

            $educationDirectory =
                dirname(__DIR__)
                . '/public/uploads/teachers/education';

            $experienceDirectory =
                dirname(__DIR__)
                . '/public/uploads/teachers/experience';

            $pgdtDirectory =
                dirname(__DIR__)
                . '/public/uploads/teachers/pgdt';

            $allowedFiles = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            /*
            |--------------------------------------------------------------------------
            | Education Credential
            |--------------------------------------------------------------------------
            */

            if (
                isset(
                    $_FILES['education_credential']
                ) &&
                $_FILES['education_credential']['error']
                    !== UPLOAD_ERR_NO_FILE
            ) {

                $newEducationFile =
                    saveUploadedFile(
                        $_FILES['education_credential'],
                        $educationDirectory,
                        'uploads/teachers/education',
                        $allowedFiles,
                        10 * 1024 * 1024,
                        'education_' . $userId
                    );

                if (
                    !$newEducationFile['success']
                ) {

                    $errors[] =
                        'Education credential: '
                        . $newEducationFile['message'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Experience Document
            |--------------------------------------------------------------------------
            */

            if (
                empty($errors) &&
                $form['has_experience'] === 'Yes' &&
                isset(
                    $_FILES['experience_file']
                ) &&
                $_FILES['experience_file']['error']
                    !== UPLOAD_ERR_NO_FILE
            ) {

                $newExperienceFile =
                    saveUploadedFile(
                        $_FILES['experience_file'],
                        $experienceDirectory,
                        'uploads/teachers/experience',
                        $allowedFiles,
                        10 * 1024 * 1024,
                        'experience_' . $userId
                    );

                if (
                    !$newExperienceFile['success']
                ) {

                    $errors[] =
                        'Experience document: '
                        . $newExperienceFile['message'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PGDT Document
            |--------------------------------------------------------------------------
            */

            if (
                empty($errors) &&
                $form['has_pgdt'] === 'Yes' &&
                isset(
                    $_FILES['pgdt_file']
                ) &&
                $_FILES['pgdt_file']['error']
                    !== UPLOAD_ERR_NO_FILE
            ) {

                $newPgdtFile =
                    saveUploadedFile(
                        $_FILES['pgdt_file'],
                        $pgdtDirectory,
                        'uploads/teachers/pgdt',
                        $allowedFiles,
                        10 * 1024 * 1024,
                        'pgdt_' . $userId
                    );

                if (
                    !$newPgdtFile['success']
                ) {

                    $errors[] =
                        'PGDT document: '
                        . $newPgdtFile['message'];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Database
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $oldEducationFile =
                $teacher['education_credential_path']
                ?? null;

            $oldExperienceFile =
                $teacher['experience_file_path']
                ?? null;

            $oldPgdtFile =
                $teacher['pgdt_file_path']
                ?? null;

            $educationPath =
                $newEducationFile['path']
                ?? $oldEducationFile;

            $experiencePath =
                $form['has_experience'] === 'Yes'
                    ? (
                        $newExperienceFile['path']
                        ?? $oldExperienceFile
                    )
                    : null;

            $pgdtPath =
                $form['has_pgdt'] === 'Yes'
                    ? (
                        $newPgdtFile['path']
                        ?? $oldPgdtFile
                    )
                    : null;

            try {

                $conn->begin_transaction();

                /*
                |--------------------------------------------------------------------------
                | Check Teacher Record
                |--------------------------------------------------------------------------
                */

                $teacherExists = false;

                $stmt = $conn->prepare("
                    SELECT id
                    FROM teachers
                    WHERE user_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {

                    throw new RuntimeException(
                        'Could not prepare teacher lookup.'
                    );
                }

                $stmt->bind_param(
                    'i',
                    $userId
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                if ($result->fetch_assoc()) {
                    $teacherExists = true;
                }

                $stmt->close();

                /*
                |--------------------------------------------------------------------------
                | Update Existing Teacher
                |--------------------------------------------------------------------------
                */

                if ($teacherExists) {

                    $stmt = $conn->prepare("
                        UPDATE teachers
                        SET
                            fayda_number = ?,
                            gender = NULLIF(?, ''),
                            birth_eth_year = ?,
                            birth_eth_month = ?,
                            birth_eth_day = ?,
                            region = NULLIF(?, ''),
                            zone = NULLIF(?, ''),
                            woreda = NULLIF(?, ''),
                            marital_status = NULLIF(?, ''),
                            education_level = ?,
                            department = ?,
                            college_university_institution = ?,
                            education_credential_path = ?,
                            has_experience = ?,
                            experience_file_path = ?,
                            has_pgdt = ?,
                            pgdt_file_path = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE user_id = ?
                    ");

                    if (!$stmt) {

                        throw new RuntimeException(
                            'Could not prepare teacher update.'
                        );
                    }

                    $stmt->bind_param(
                        'ssiiissssssssssssi',
                        $form['fayda_number'],
                        $form['gender'],
                        $birthYear,
                        $birthMonth,
                        $birthDay,
                        $form['region'],
                        $form['zone'],
                        $form['woreda'],
                        $form['marital_status'],
                        $form['education_level'],
                        $form['department'],
                        $form['college_university_institution'],
                        $educationPath,
                        $form['has_experience'],
                        $experiencePath,
                        $form['has_pgdt'],
                        $pgdtPath,
                        $userId
                    );

                    if (!$stmt->execute()) {

                        throw new RuntimeException(
                            'Could not update teacher information.'
                        );
                    }

                    $stmt->close();

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Create Teacher Record
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare("
                        INSERT INTO teachers (
                            user_id,
                            fayda_number,
                            gender,
                            birth_eth_year,
                            birth_eth_month,
                            birth_eth_day,
                            region,
                            zone,
                            woreda,
                            marital_status,
                            education_level,
                            department,
                            college_university_institution,
                            education_credential_path,
                            has_experience,
                            experience_file_path,
                            has_pgdt,
                            pgdt_file_path
                        )
                        VALUES (
                            ?,
                            ?,
                            NULLIF(?, ''),
                            ?,
                            ?,
                            ?,
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
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

                    if (!$stmt) {

                        throw new RuntimeException(
                            'Could not prepare teacher creation.'
                        );
                    }

                    $stmt->bind_param(
                        'issiiissssssssssss',
                        $userId,
                        $form['fayda_number'],
                        $form['gender'],
                        $birthYear,
                        $birthMonth,
                        $birthDay,
                        $form['region'],
                        $form['zone'],
                        $form['woreda'],
                        $form['marital_status'],
                        $form['education_level'],
                        $form['department'],
                        $form['college_university_institution'],
                        $educationPath,
                        $form['has_experience'],
                        $experiencePath,
                        $form['has_pgdt'],
                        $pgdtPath
                    );

                    if (!$stmt->execute()) {

                        throw new RuntimeException(
                            'Could not create teacher information.'
                        );
                    }

                    $stmt->close();
                }

                $conn->commit();

                /*
                |--------------------------------------------------------------------------
                | Delete Replaced Education File
                |--------------------------------------------------------------------------
                */

                if (
                    $newEducationFile &&
                    $newEducationFile['success'] &&
                    $oldEducationFile &&
                    $oldEducationFile !== $educationPath
                ) {

                    deleteStoredFile(
                        $oldEducationFile
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Delete Replaced Experience File
                |--------------------------------------------------------------------------
                */

                if (
                    $newExperienceFile &&
                    $newExperienceFile['success'] &&
                    $oldExperienceFile &&
                    $oldExperienceFile !== $experiencePath
                ) {

                    deleteStoredFile(
                        $oldExperienceFile
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Delete Replaced PGDT File
                |--------------------------------------------------------------------------
                */

                if (
                    $newPgdtFile &&
                    $newPgdtFile['success'] &&
                    $oldPgdtFile &&
                    $oldPgdtFile !== $pgdtPath
                ) {

                    deleteStoredFile(
                        $oldPgdtFile
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Delete Experience File If Changed To No
                |--------------------------------------------------------------------------
                */

                if (
                    $form['has_experience'] === 'No' &&
                    $oldExperienceFile
                ) {

                    deleteStoredFile(
                        $oldExperienceFile
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Delete PGDT File If Changed To No
                |--------------------------------------------------------------------------
                */

                if (
                    $form['has_pgdt'] === 'No' &&
                    $oldPgdtFile
                ) {

                    deleteStoredFile(
                        $oldPgdtFile
                    );
                }

                $_SESSION['success'] =
                    'Teacher information saved successfully.';

                header(
                    'Location: update-teacher.php?user_id='
                    . $userId
                );

                exit;

            } catch (Throwable $exception) {

                $conn->rollback();

                if (
                    $newEducationFile &&
                    $newEducationFile['success']
                ) {

                    deleteStoredFile(
                        $newEducationFile['path']
                    );
                }

                if (
                    $newExperienceFile &&
                    $newExperienceFile['success']
                ) {

                    deleteStoredFile(
                        $newExperienceFile['path']
                    );
                }

                if (
                    $newPgdtFile &&
                    $newPgdtFile['success']
                ) {

                    deleteStoredFile(
                        $newPgdtFile['path']
                    );
                }

                $error =
                    'Unable to save teacher information. Please try again.';
            }

        } else {

            $error =
                implode(' ', $errors);
        }

        /*
        |--------------------------------------------------------------------------
        | Reload Teacher After POST
        |--------------------------------------------------------------------------
        */

        if ($userId > 0) {

            $selectedUserId =
                $userId;

            $stmt = $conn->prepare("
                SELECT
                    u.id AS user_id,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.role,

                    t.id AS teacher_id,
                    t.fayda_number,
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
                    t.education_credential_path,
                    t.has_experience,
                    t.experience_file_path,
                    t.has_pgdt,
                    t.pgdt_file_path

                FROM users u

                LEFT JOIN teachers t
                    ON t.user_id = u.id

                WHERE u.id = ?

                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'i',
                    $userId
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $teacher = $row;
                }

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Maximum Ethiopian Birth Days
|--------------------------------------------------------------------------
*/

$maxDays =
    (
        (int) (
            $form['birth_eth_month'] ?? 0
        ) === 13
    )
        ? 6
        : 30;

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
        Update Teacher | Registrar
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
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
            color: #111827;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2563eb;
            font-size: 20px;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }

        .brand-subtitle {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 18px 10px 8px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            width: calc(100% - 20px);
            margin: 2px 10px;
            padding: 11px 12px;
            border-radius: 8px;
            color: #9ca3af;
            text-decoration: none;
            font-size: 13px;
            transition: .2s ease;
        }

        .nav-link-custom:hover {
            color: #fff;
            background: rgba(255,255,255,.07);
        }

        .nav-link-custom.active {
            color: #fff;
            background: #2563eb;
        }

        .nav-link-custom i {
            width: 20px;
            font-size: 16px;
            text-align: center;
        }

        .nav-group {
            margin: 0;
        }

        .nav-parent {
            width: calc(100% - 20px);
            border: 0;
            background: transparent;
            font-family: inherit;
            cursor: pointer;
            color: #9ca3af;
        }

        .nav-parent:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-parent[aria-expanded="true"] {
            color: white;
        }

        .submenu-arrow {
            width: auto !important;
            font-size: 11px !important;
            transition: transform .2s ease;
            margin-left: auto;
        }

        .nav-parent[aria-expanded="true"]
        .submenu-arrow {
            transform: rotate(180deg);
        }

        .nav-sub-link {
            margin-left: 30px;
            margin-right: 10px;
            width: calc(100% - 40px);
            padding: 9px 12px;
            font-size: 13px;
            color: #9ca3af;
        }

        .nav-sub-link i {
            font-size: 15px;
        }

        .nav-sub-link:hover {
            color: white;
            background: rgba(255,255,255,.06);
        }

        .nav-sub-link.active {
            background: #2563eb;
            color: white;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .page-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
        }

        .profile-role {
            font-size: 11px;
            color: #6b7280;
        }

        .content {
            padding: 28px;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(15,23,42,.04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #eef0f3;
            padding: 18px 20px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        .card-description {
            color: #6b7280;
            font-size: 12px;
            margin-top: 4px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-radius: 9px;
            border-color: #d1d5db;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .section-title i {
            color: #2563eb;
        }

        .account-field {
            background: #f8fafc;
        }

        .required {
            color: #dc2626;
        }

        .file-box {
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 14px;
            background: #f8fafc;
        }

        .current-file {
            margin-top: 8px;
            font-size: 11px;
            color: #6b7280;
        }

        .current-file a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .teacher-result {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 13px;
            margin-bottom: 9px;
            background: #fff;
            transition: .2s ease;
        }

        .teacher-result:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }

        .teacher-result-name {
            font-size: 13px;
            font-weight: 700;
        }

        .teacher-result-info {
            color: #6b7280;
            font-size: 11px;
            margin-top: 3px;
        }

        .btn-primary {
            background: #2563eb;
            border-color: #2563eb;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 18px;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .btn-light {
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 18px;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.5);
            z-index: 1040;
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
        }

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

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-right: 10px;
            }

            .topbar {
                padding: 0 16px;
            }

            .content {
                padding: 18px;
            }

            .profile-name,
            .profile-role {
                display: none;
            }
        }

        @media (max-width: 575px) {

            .page-title {
                font-size: 16px;
            }

            .page-subtitle {
                display: none;
            }

            .content {
                padding: 14px;
            }

            .topbar {
                height: 70px;
            }
        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->
<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

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

    <div class="sidebar-section">
        Main
    </div>

    <a
        href="dashboard.php"
        class="nav-link-custom"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>

    <!-- Students -->
    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#studentsMenu"
            aria-expanded="true"
        >

            <i class="bi bi-people-fill"></i>

            <span>Students</span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse show"
            id="studentsMenu"
        >

            <a
                href="register.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="update-student.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Student</span>
            </a>

            <a
                href="delete-student.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete Student</span>
            </a>

            <a
                href="withdraw-student.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw Student</span>
            </a>

            <a
                href="withdrawn-students.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>Withdrawn Students</span>
            </a>

        </div>

    </div>

    <!-- Teachers -->
    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#teachersMenu"
            aria-expanded="true"
        >

            <i class="bi bi-person-video3"></i>

            <span>Teachers</span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse show"
            id="teachersMenu"
        >

            <a
                href="teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-people"></i>
                <span>Teachers</span>
            </a>

            <a
                href="update-teacher.php"
                class="nav-link-custom nav-sub-link active"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Teacher</span>
            </a>

            <a
                href="withdraw-teacher.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash"></i>
                <span>Withdraw Teacher</span>
            </a>

            <a
                href="homeroom-teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Homeroom Teachers</span>
            </a>

            <a
                href="subject-teachers.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-video2"></i>
                <span>Subject Teachers</span>
            </a>

        </div>

    </div>

    <!-- Other Staff -->
    <div class="nav-group">

        <button
            type="button"
            class="nav-link-custom nav-parent"
            data-bs-toggle="collapse"
            data-bs-target="#staffMenu"
            aria-expanded="false"
        >

            <i class="bi bi-person-badge-fill"></i>

            <span>Other Staff</span>

            <i class="bi bi-chevron-down submenu-arrow"></i>

        </button>

        <div
            class="collapse"
            id="staffMenu"
        >

            <a
                href="add-staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Add Staff</span>
            </a>

            <a
                href="staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Staff</span>
            </a>

            <a
                href="withdraw-staff.php"
                class="nav-link-custom nav-sub-link"
            >
                <i class="bi bi-person-dash-fill"></i>
                <span>Withdraw Staff</span>
            </a>

        </div>

    </div>

    <div class="sidebar-section">
        Academic
    </div>

    <a
        href="certificate.php"
        class="nav-link-custom"
    >
        <i class="bi bi-award-fill"></i>
        <span>Certificate</span>
    </a>

    <a
        href="Roster.php"
        class="nav-link-custom"
    >
        <i class="bi bi-clipboard2-check-fill"></i>
        <span>Roster</span>
    </a>

    <a
        href="Transcript.php"
        class="nav-link-custom"
    >
        <i class="bi bi-file-earmark-text-fill"></i>
        <span>Transcript</span>
    </a>

    <div class="sidebar-section">
        Account
    </div>

    <a
        href="profile.php"
        class="nav-link-custom"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../auth/logout.php"
        class="nav-link-custom"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</aside>

<!-- Main -->
<main class="main">

    <!-- Topbar -->
    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Update Teacher
                </h1>

                <div class="page-subtitle">
                    Manage complete teacher information
                </div>

            </div>

        </div>

        <div class="profile">

            <div class="profile-avatar">

                <?php

                $name =
                    (string) (
                        $registrar['full_name']
                        ?? 'Registrar'
                    );

                echo e(
                    strtoupper(
                        substr($name, 0, 1)
                    )
                );

                ?>

            </div>

            <div>

                <div class="profile-name">
                    <?= e(
                        $registrar['full_name']
                        ?? 'Registrar'
                    ) ?>
                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>

    <div class="content">

        <?php if ($success !== ''): ?>

            <div class="alert alert-success d-flex align-items-center gap-2">

                <i class="bi bi-check-circle-fill"></i>

                <span>
                    <?= e($success) ?>
                </span>

            </div>

        <?php endif; ?>

        <?php if ($error !== ''): ?>

            <div class="alert alert-danger d-flex align-items-center gap-2">

                <i class="bi bi-exclamation-triangle-fill"></i>

                <span>
                    <?= e($error) ?>
                </span>

            </div>

        <?php endif; ?>

        <!-- Search -->
        <div class="card mb-4">

            <div class="card-header">

                <div class="card-title">
                    Find Teacher
                </div>

                <div class="card-description">
                    Search using the teacher's name, email, or phone number.
                </div>

            </div>

            <div class="card-body">

                <form
                    method="GET"
                    action="update-teacher.php"
                >

                    <div class="row g-3">

                        <div class="col-md-9">

                            <label class="form-label">
                                Search Teacher
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                value="<?= e($search) ?>"
                                placeholder="Enter teacher name, email, or phone"
                            >

                        </div>

                        <div class="col-md-3 d-flex align-items-end">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >

                                <i class="bi bi-search me-1"></i>

                                Search

                            </button>

                        </div>

                    </div>

                </form>

                <?php if ($search !== ''): ?>

                    <div class="mt-4">

                        <?php if (count($teachers) > 0): ?>

                            <div class="small text-muted mb-2">

                                <?= count($teachers) ?>
                                teacher(s) found.

                            </div>

                            <?php foreach (
                                $teachers
                                as $resultTeacher
                            ): ?>

                                <div class="teacher-result">

                                    <div
                                        class="d-flex justify-content-between align-items-center gap-3"
                                    >

                                        <div>

                                            <div class="teacher-result-name">

                                                <?= e(
                                                    $resultTeacher['full_name']
                                                ) ?>

                                            </div>

                                            <div class="teacher-result-info">

                                                <?= e(
                                                    $resultTeacher['email'] ?? ''
                                                ) ?>

                                                <?php if (
                                                    !empty(
                                                        $resultTeacher['phone']
                                                    )
                                                ): ?>

                                                    ·
                                                    <?= e(
                                                        $resultTeacher['phone']
                                                    ) ?>

                                                <?php endif; ?>

                                            </div>

                                            <?php if (
                                                empty(
                                                    $resultTeacher['teacher_id']
                                                )
                                            ): ?>

                                                <div
                                                    class="text-warning small mt-1"
                                                >
                                                    Teacher profile not yet completed
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                        <a
                                            href="update-teacher.php?user_id=<?= (int) $resultTeacher['user_id'] ?>"
                                            class="btn btn-primary btn-sm"
                                        >

                                            <i class="bi bi-pencil-square me-1"></i>

                                            Manage

                                        </a>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <div
                                class="text-center py-4 text-muted"
                            >

                                <i class="bi bi-person-x fs-3"></i>

                                <div class="mt-2">
                                    No teacher found.
                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <?php if ($teacher): ?>

            <!-- Teacher Form -->
            <form
                method="POST"
                action="update-teacher.php?user_id=<?= (int) $teacher['user_id'] ?>"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= (int) $teacher['user_id'] ?>"
                >

                <!-- Account Information -->
                <div class="card mb-4">

                    <div class="card-header">

                        <div class="card-title">
                            Account Information
                        </div>

                        <div class="card-description">
                            These details are managed by the administrator.
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-field"
                                    value="<?= e(
                                        $teacher['full_name']
                                    ) ?>"
                                    readonly
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Email
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-field"
                                    value="<?= e(
                                        $teacher['email'] ?? ''
                                    ) ?>"
                                    readonly
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Phone
                                </label>

                                <input
                                    type="text"
                                    class="form-control account-field"
                                    value="<?= e(
                                        $teacher['phone'] ?? ''
                                    ) ?>"
                                    readonly
                                >

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Personal Information -->
                <div class="card mb-4">

                    <div class="card-header">

                        <div class="card-title">
                            Personal Information
                        </div>

                        <div class="card-description">
                            Record the teacher's personal and identification information.
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="section-title">

                            <i class="bi bi-person-vcard-fill"></i>

                            Identification

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">

                                    Fayda Number

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="fayda_number"
                                    class="form-control"
                                    value="<?= e(
                                        $form['fayda_number']
                                    ) ?>"
                                    maxlength="16"
                                    minlength="16"
                                    pattern="[0-9]{16}"
                                    inputmode="numeric"
                                    placeholder="Enter 16-digit Fayda Number"
                                    required
                                >

                                <div class="form-text">
                                    Must contain exactly 16 digits.
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Gender
                                </label>

                                <select
                                    name="gender"
                                    class="form-select"
                                >

                                    <option value="">
                                        Select Gender
                                    </option>

                                    <option
                                        value="Male"
                                        <?= $form['gender'] === 'Male'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Male
                                    </option>

                                    <option
                                        value="Female"
                                        <?= $form['gender'] === 'Female'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Female
                                    </option>

                                </select>

                            </div>

                        </div>

                        <div class="section-title mt-4">

                            <i class="bi bi-calendar3"></i>

                            Ethiopian Birth Date

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">
                                    Year
                                </label>

                                <input
                                    type="number"
                                    name="birth_eth_year"
                                    class="form-control"
                                    value="<?= e(
                                        (string) $form['birth_eth_year']
                                    ) ?>"
                                    min="1900"
                                    max="2200"
                                    placeholder="Year"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Month
                                </label>

                                <select
                                    name="birth_eth_month"
                                    id="birthMonth"
                                    class="form-select"
                                >

                                    <option value="">
                                        Select Month
                                    </option>

                                    <?php foreach (
                                        $ethiopianMonths
                                        as $monthNumber => $monthName
                                    ): ?>

                                        <option
                                            value="<?= $monthNumber ?>"
                                            <?= (string)
                                                $form['birth_eth_month']
                                                ===
                                                (string)
                                                $monthNumber
                                                    ? 'selected'
                                                    : '' ?>
                                        >
                                            <?= e($monthName) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Day
                                </label>

                                <select
                                    name="birth_eth_day"
                                    id="birthDay"
                                    class="form-select"
                                >

                                    <option value="">
                                        Select Day
                                    </option>

                                    <?php for (
                                        $day = 1;
                                        $day <= $maxDays;
                                        $day++
                                    ): ?>

                                        <option
                                            value="<?= $day ?>"
                                            <?= (string)
                                                $form['birth_eth_day']
                                                ===
                                                (string) $day
                                                    ? 'selected'
                                                    : '' ?>
                                        >
                                            <?= $day ?>
                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                        </div>

                        <div class="section-title mt-4">

                            <i class="bi bi-geo-alt-fill"></i>

                            Address

                        </div>

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">
                                    Region
                                </label>

                                <input
                                    type="text"
                                    name="region"
                                    class="form-control"
                                    value="<?= e(
                                        $form['region']
                                    ) ?>"
                                    placeholder="Region"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Zone
                                </label>

                                <input
                                    type="text"
                                    name="zone"
                                    class="form-control"
                                    value="<?= e(
                                        $form['zone']
                                    ) ?>"
                                    placeholder="Zone"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Woreda
                                </label>

                                <input
                                    type="text"
                                    name="woreda"
                                    class="form-control"
                                    value="<?= e(
                                        $form['woreda']
                                    ) ?>"
                                    placeholder="Woreda"
                                >

                            </div>

                        </div>

                        <!-- Marital Status -->
                        <div class="section-title mt-4">

                            <i class="bi bi-heart-fill"></i>

                            Marital Status

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Marital Status
                                </label>

                                <select
                                    name="marital_status"
                                    class="form-select"
                                >

                                    <option value="">
                                        Select Status
                                    </option>

                                    <?php foreach (
                                        $maritalStatuses
                                        as $status
                                    ): ?>

                                        <option
                                            value="<?= e($status) ?>"
                                            <?= $form['marital_status']
                                                === $status
                                                    ? 'selected'
                                                    : '' ?>
                                        >
                                            <?= e($status) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Education -->
                <div class="card mb-4">

                    <div class="card-header">

                        <div class="card-title">
                            Education Information
                        </div>

                        <div class="card-description">
                            Record the teacher's education and supporting credential.
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">

                                    Education Level

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="education_level"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Education Level
                                    </option>

                                    <?php foreach (
                                        $educationLevels
                                        as $level
                                    ): ?>

                                        <option
                                            value="<?= e($level) ?>"
                                            <?= $form['education_level']
                                                === $level
                                                    ? 'selected'
                                                    : '' ?>
                                        >
                                            <?= e($level) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">

                                    Institution

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="college_university_institution"
                                    class="form-control"
                                    value="<?= e(
                                        $form[
                                            'college_university_institution'
                                        ]
                                    ) ?>"
                                    placeholder="College / University / Institution"
                                    required
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">

                                    Department

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="department"
                                    class="form-control"
                                    value="<?= e(
                                        $form['department']
                                    ) ?>"
                                    placeholder="Department"
                                    required
                                >

                            </div>

                            <div class="col-12">

                                <label class="form-label">

                                    Education Credential

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <div class="file-box">

                                    <input
                                        type="file"
                                        name="education_credential"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        PDF, JPG, PNG or WEBP. Maximum 10 MB.
                                    </div>

                                    <?php if (
                                        !empty(
                                            $teacher[
                                                'education_credential_path'
                                            ]
                                        )
                                    ): ?>

                                        <div class="current-file">

                                            Current document:

                                            <a
                                                href="../public/<?= e(
                                                    $teacher[
                                                        'education_credential_path'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                            >

                                                <i class="bi bi-file-earmark-text"></i>

                                                View education credential

                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Experience -->
                <div class="card mb-4">

                    <div class="card-header">

                        <div class="card-title">
                            Experience
                        </div>

                        <div class="card-description">
                            Record whether the teacher has previous experience.
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">

                                    Has Experience?

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="has_experience"
                                    id="hasExperience"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value="No"
                                        <?= $form['has_experience'] === 'No'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        No
                                    </option>

                                    <option
                                        value="Yes"
                                        <?= $form['has_experience'] === 'Yes'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Yes
                                    </option>

                                </select>

                            </div>

                            <div
                                class="col-md-8"
                                id="experienceFileContainer"
                            >

                                <label class="form-label">

                                    Experience Document

                                    <?php if (
                                        $form['has_experience'] === 'Yes'
                                    ): ?>

                                        <span class="required">
                                            *
                                        </span>

                                    <?php endif; ?>

                                </label>

                                <div class="file-box">

                                    <input
                                        type="file"
                                        name="experience_file"
                                        id="experienceFile"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        PDF, JPG, PNG or WEBP. Maximum 10 MB.
                                    </div>

                                    <?php if (
                                        !empty(
                                            $teacher[
                                                'experience_file_path'
                                            ]
                                        )
                                    ): ?>

                                        <div class="current-file">

                                            Current document:

                                            <a
                                                href="../public/<?= e(
                                                    $teacher[
                                                        'experience_file_path'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                            >

                                                <i class="bi bi-file-earmark-text"></i>

                                                View experience document

                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- PGDT -->
                <div class="card mb-4">

                    <div class="card-header">

                        <div class="card-title">
                            PGDT Information
                        </div>

                        <div class="card-description">
                            Record PGDT status and supporting document.
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label">

                                    Has PGDT?

                                    <span class="required">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="has_pgdt"
                                    id="hasPgdt"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value="No"
                                        <?= $form['has_pgdt'] === 'No'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        No
                                    </option>

                                    <option
                                        value="Yes"
                                        <?= $form['has_pgdt'] === 'Yes'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Yes
                                    </option>

                                </select>

                            </div>

                            <div
                                class="col-md-8"
                                id="pgdtFileContainer"
                            >

                                <label class="form-label">

                                    PGDT Document

                                    <?php if (
                                        $form['has_pgdt'] === 'Yes'
                                    ): ?>

                                        <span class="required">
                                            *
                                        </span>

                                    <?php endif; ?>

                                </label>

                                <div class="file-box">

                                    <input
                                        type="file"
                                        name="pgdt_file"
                                        id="pgdtFile"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    >

                                    <div class="form-text">
                                        PDF, JPG, PNG or WEBP. Maximum 10 MB.
                                    </div>

                                    <?php if (
                                        !empty(
                                            $teacher[
                                                'pgdt_file_path'
                                            ]
                                        )
                                    ): ?>

                                        <div class="current-file">

                                            Current document:

                                            <a
                                                href="../public/<?= e(
                                                    $teacher[
                                                        'pgdt_file_path'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                            >

                                                <i class="bi bi-file-earmark-text"></i>

                                                View PGDT document

                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Actions -->
                <div class="d-flex justify-content-end gap-2 mb-4">

                    <a
                        href="update-teacher.php"
                        class="btn btn-light"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-check2-circle me-1"></i>

                        Save Teacher Information

                    </button>

                </div>

            </form>

        <?php elseif ($search === ''): ?>

            <div class="card">

                <div class="card-body text-center py-5">

                    <div
                        class="mb-3"
                        style="font-size:42px;color:#2563eb;"
                    >
                        <i class="bi bi-person-lines-fill"></i>
                    </div>

                    <h5 class="fw-bold">
                        Select a Teacher
                    </h5>

                    <p class="text-muted small mb-0">
                        Search for a teacher above to manage their complete information.
                    </p>

                </div>

            </div>

        <?php endif; ?>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');

    function openSidebar() {

        sidebar.classList.add('show');

        sidebarOverlay.classList.add('show');
    }

    function closeSidebar() {

        sidebar.classList.remove('show');

        sidebarOverlay.classList.remove('show');
    }

    mobileMenuBtn?.addEventListener(
        'click',
        openSidebar
    );

    sidebarOverlay?.addEventListener(
        'click',
        closeSidebar
    );

    /*
    |--------------------------------------------------------------------------
    | Ethiopian Birth Day
    |--------------------------------------------------------------------------
    */

    const birthMonth =
        document.getElementById('birthMonth');

    const birthDay =
        document.getElementById('birthDay');

    function updateBirthDays() {

        if (!birthMonth || !birthDay) {
            return;
        }

        const selectedMonth =
            parseInt(
                birthMonth.value || '0',
                10
            );

        const selectedDay =
            birthDay.value;

        const maxDays =
            selectedMonth === 13
                ? 6
                : 30;

        birthDay.innerHTML =
            '<option value="">Select Day</option>';

        for (
            let day = 1;
            day <= maxDays;
            day++
        ) {

            const option =
                document.createElement('option');

            option.value =
                day;

            option.textContent =
                day;

            if (
                String(day) ===
                String(selectedDay)
            ) {

                option.selected =
                    true;
            }

            birthDay.appendChild(
                option
            );
        }
    }

    birthMonth?.addEventListener(
        'change',
        updateBirthDays
    );

    /*
    |--------------------------------------------------------------------------
    | Experience
    |--------------------------------------------------------------------------
    */

    const hasExperience =
        document.getElementById(
            'hasExperience'
        );

    const experienceFile =
        document.getElementById(
            'experienceFile'
        );

    const experienceFileContainer =
        document.getElementById(
            'experienceFileContainer'
        );

    function updateExperienceField() {

        if (!hasExperience) {
            return;
        }

        const yes =
            hasExperience.value === 'Yes';

        const currentFile =
            experienceFileContainer?.querySelector(
                '.current-file'
            );

        if (experienceFile) {

            experienceFile.required =
                yes && !currentFile;
        }

        if (experienceFileContainer) {

            experienceFileContainer.style.display =
                yes
                    ? ''
                    : 'none';
        }
    }

    hasExperience?.addEventListener(
        'change',
        updateExperienceField
    );

    /*
    |--------------------------------------------------------------------------
    | PGDT
    |--------------------------------------------------------------------------
    */

    const hasPgdt =
        document.getElementById(
            'hasPgdt'
        );

    const pgdtFile =
        document.getElementById(
            'pgdtFile'
        );

    const pgdtFileContainer =
        document.getElementById(
            'pgdtFileContainer'
        );

    function updatePgdtField() {

        if (!hasPgdt) {
            return;
        }

        const yes =
            hasPgdt.value === 'Yes';

        const currentFile =
            pgdtFileContainer?.querySelector(
                '.current-file'
            );

        if (pgdtFile) {

            pgdtFile.required =
                yes && !currentFile;
        }

        if (pgdtFileContainer) {

            pgdtFileContainer.style.display =
                yes
                    ? ''
                    : 'none';
        }
    }

    hasPgdt?.addEventListener(
        'change',
        updatePgdtField
    );

    updateExperienceField();

    updatePgdtField();

</script>

</body>

</html>