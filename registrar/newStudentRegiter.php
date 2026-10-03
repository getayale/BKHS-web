<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
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
| Helper functions
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Generate student password
|--------------------------------------------------------------------------
|
| Example:
| Student Code: BKHS-STU-000123
| Password:     BKHS@000123
|
*/

function generateStudentPassword(string $studentCode): string
{
    $prefix = 'BKHS-STU-';

    if (str_starts_with($studentCode, $prefix)) {
        $number = substr($studentCode, strlen($prefix));

        return 'BKHS@' . $number;
    }

    throw new Exception(
        'Unable to generate the student password from the student code.'
    );
}

/*
|--------------------------------------------------------------------------
| Generate parent password
|--------------------------------------------------------------------------
|
| Example:
| Phone:    0912345678
| Password: BKHS@5678
|
*/

function generateParentPassword(string $phone): string
{
    $phone = trim($phone);

    if (strlen($phone) < 4) {
        throw new Exception(
            'Unable to generate the parent password from the phone number.'
        );
    }

    $lastFourDigits = substr($phone, -4);

    return 'BKHS@' . $lastFourDigits;
}

function uploadFile(
    string $fieldName,
    string $directory,
    array $allowedExtensions,
    int $maxSize = 5242880
): ?string {

    if (
        !isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Failed to upload {$fieldName}.");
    }

    if ($_FILES[$fieldName]['size'] > $maxSize) {
        throw new Exception("The uploaded {$fieldName} is too large.");
    }

    $originalName = $_FILES[$fieldName]['name'];

    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception(
            "Invalid file type for {$fieldName}. Allowed: " .
            implode(', ', $allowedExtensions)
        );
    }

    if (!is_dir($directory)) {
        if (
            !mkdir($directory, 0775, true) &&
            !is_dir($directory)
        ) {
            throw new Exception(
                "Unable to create upload directory."
            );
        }
    }

    $filename =
        bin2hex(random_bytes(16)) .
        '.' .
        $extension;

    $targetPath =
        rtrim($directory, DIRECTORY_SEPARATOR) .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $_FILES[$fieldName]['tmp_name'],
            $targetPath
        )
    ) {
        throw new Exception(
            "Failed to save uploaded file."
        );
    }

    return $targetPath;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| IMPORTANT:
| Handle "Register Another Student" BEFORE reading success data.
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['new']) &&
    $_GET['new'] === '1'
) {
    unset($_SESSION['new_student_registration']);

    header('Location: newStudentRegiter.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Registrar information
|--------------------------------------------------------------------------
*/

$registrar = null;

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
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$result = $stmt->get_result();

$registrar = $result->fetch_assoc();

$stmt->close();

if (!$registrar) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Current active academic year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        start_year,
        start_month,
        start_day
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY
        start_year DESC,
        start_month DESC,
        start_day DESC,
        id DESC
    LIMIT 1
");

$stmt->execute();

$activeAcademicYear =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$activeAcademicYear) {
    die(
        'No active academic year is configured. ' .
        'Please ask the administrator to activate an academic year.'
    );
}

$activeAcademicYearId =
    (int) $activeAcademicYear['id'];

$activeAcademicYearName =
    (string) $activeAcademicYear['name'];

/*
|--------------------------------------------------------------------------
| Addis Ababa address data
|--------------------------------------------------------------------------
*/

$addisAbabaSubcities = [

    'Addis Ketema' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
    ],

    'Akaki Kality' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
    ],

    'Arada' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
    ],

    'Bole' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
        'Woreda 13',
        'Woreda 14',
    ],

    'Gullele' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
    ],

    'Kirkos' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
    ],

    'Kolfe Keranio' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
        'Woreda 13',
        'Woreda 14',
    ],

    'Lideta' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
    ],

    'Nifas Silk-Lafto' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
        'Woreda 13',
        'Woreda 14',
    ],

    'Yeka' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
    ],

    'Lemi Kura' => [
        'Woreda 01',
        'Woreda 02',
        'Woreda 03',
        'Woreda 04',
        'Woreda 05',
        'Woreda 06',
        'Woreda 07',
        'Woreda 08',
        'Woreda 09',
        'Woreda 10',
        'Woreda 11',
        'Woreda 12',
    ],
];

/*
|--------------------------------------------------------------------------
| Success data
|--------------------------------------------------------------------------
*/

$successData =
    $_SESSION['new_student_registration'] ?? null;

/*
|--------------------------------------------------------------------------
| Handle print/export
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['print']) &&
    $_GET['print'] === '1' &&
    $successData
) {
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
        Student Registration -
        <?= e($successData['student_code']) ?>
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <style>

        @page {
            size: A4;
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, sans-serif;
            line-height: 1.4;
        }

        .document {
            width: 100%;
            max-width: 850px;
            margin: 30px auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #111827;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .header h1 {
            margin: 0 0 5px;
            font-size: 26px;
        }

        .header p {
            margin: 4px 0;
            color: #6b7280;
        }

        .success {
            text-align: center;
            margin-bottom: 30px;
        }

        .success-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 12px;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
        }

        .success h2 {
            margin: 0 0 5px;
            color: #15803d;
        }

        .section {
            margin-top: 28px;
        }

        .section h3 {
            margin: 0 0 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 17px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        td:first-child {
            width: 40%;
            font-weight: 600;
            color: #4b5563;
        }

        .credential {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 12px;
        }

        .warning {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            padding: 14px;
            border-radius: 8px;
            margin-top: 12px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .no-print button {
            padding: 10px 18px;
            border: 0;
            border-radius: 7px;
            background: #111827;
            color: white;
            cursor: pointer;
        }

        @media print {

            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
            }

            .document {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border-radius: 0;
            }

            .no-print {
                display: none !important;
            }

            .section,
            table,
            .credential,
            .warning,
            .footer {
                break-inside: avoid;
                page-break-inside: avoid;
            }

        }

    </style>

</head>

<body>

<div class="no-print">

    <button
        type="button"
        onclick="window.print()"
    >
        Print / Save as PDF
    </button>

</div>

<div class="document">

    <div class="header">

        <h1>
            BKHS School Management System
        </h1>

        <p>
            Student Registration Document
        </p>

    </div>

    <div class="success">

        <div class="success-icon">
            ✓
        </div>

        <h2>
            Registration Successful
        </h2>

        <p>
            The student has been successfully registered.
        </p>

    </div>

    <div class="section">

        <h3>
            Student Information
        </h3>

        <table>

            <tr>
                <td>Student ID</td>
                <td>
                    <?= e($successData['student_code']) ?>
                </td>
            </tr>

            <tr>
                <td>Full Name</td>
                <td>
                    <?= e($successData['student_name']) ?>
                </td>
            </tr>

            <tr>
                <td>Academic Year</td>
                <td>
                    <?= e($successData['academic_year']) ?>
                </td>
            </tr>

            <tr>
                <td>Grade</td>
                <td>
                    <?= e($successData['grade']) ?>
                </td>
            </tr>

            <tr>
                <td>Section</td>
                <td>
                    <?= e($successData['section']) ?>
                </td>
            </tr>

            <tr>
                <td>Registration Type</td>
                <td>
                    New
                </td>
            </tr>

            <tr>
                <td>Registration Date</td>
                <td>
                    <?= e($successData['registration_date']) ?>
                </td>
            </tr>

        </table>

    </div>

    <div class="section">

        <h3>
            Student Account
        </h3>

        <div class="credential">

            <table>

                <tr>
                    <td>Username</td>
                    <td>
                        <?= e($successData['student_username']) ?>
                    </td>
                </tr>

                <tr>
                    <td>Temporary Password</td>
                    <td>
                        <?= e($successData['student_password']) ?>
                    </td>
                </tr>

            </table>

        </div>

        <div class="warning">

            This is a temporary password.
            The student should change it after
            successfully signing in.

        </div>

    </div>

    <div class="section">

        <h3>
            Parent Account
        </h3>

        <table>

            <tr>
                <td>Parent Name</td>
                <td>
                    <?= e($successData['parent_name']) ?>
                </td>
            </tr>

            <tr>
                <td>Relationship</td>
                <td>
                    <?= e($successData['parent_relationship']) ?>
                </td>
            </tr>

            <tr>
                <td>Phone / Username</td>
                <td>
                    <?= e($successData['parent_username']) ?>
                </td>
            </tr>

        </table>

        <?php if ($successData['parent_is_new']): ?>

            <div class="credential">

                <table>

                    <tr>
                        <td>Temporary Password</td>
                        <td>
                            <?= e($successData['parent_password']) ?>
                        </td>
                    </tr>

                </table>

            </div>

            <div class="warning">

                This is a temporary parent password.
                The parent should change it after signing in.

            </div>

        <?php else: ?>

            <div class="warning">

                This parent already has an account
                for another child.

                No new parent account was created.

                <strong>
                    You can use the previous password.
                </strong>

            </div>

        <?php endif; ?>

    </div>

    <div class="footer">

        BKHS School Management System<br>

        Generated by
        <?= e($registrar['full_name']) ?>

    </div>

</div>

<script>

    window.addEventListener('load', function () {

        setTimeout(function () {

            window.print();

        }, 500);

    });

</script>

</body>
</html>

<?php

    exit;
}

/*
|--------------------------------------------------------------------------
| Handle registration
|--------------------------------------------------------------------------
*/

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        $postedToken =
            $_POST['csrf_token'] ?? '';

        if (
            empty($postedToken) ||
            !hash_equals(
                $_SESSION['csrf_token'],
                $postedToken
            )
        ) {

            throw new Exception(
                'Invalid security token. Please refresh the page and try again.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Basic fields
        |--------------------------------------------------------------------------
        */

        $fullName =
            trim($_POST['full_name'] ?? '');

        $dateOfBirth =
            trim($_POST['date_of_birth'] ?? '');

        $gender =
            trim($_POST['gender'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Address
        |--------------------------------------------------------------------------
        */

        $region = 'Addis Ababa';

        $zone =
            trim($_POST['zone'] ?? '');

        $woreda =
            trim($_POST['woreda'] ?? '');

        $fydaNumber =
            trim($_POST['fyda_number'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Academic
        |--------------------------------------------------------------------------
        */

        $academicYearId =
            $activeAcademicYearId;

        $gradeId =
            (int) ($_POST['grade_id'] ?? 0);

        $sectionId =
            (int) ($_POST['section_id'] ?? 0);

        $previousSchoolName =
            trim(
                $_POST['previous_school_name'] ?? ''
            );

        /*
        |--------------------------------------------------------------------------
        | Father
        |--------------------------------------------------------------------------
        */

        $fatherName =
            trim($_POST['father_name'] ?? '');

        $fatherPhone =
            trim($_POST['father_phone'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Mother
        |--------------------------------------------------------------------------
        */

        $motherName =
            trim($_POST['mother_name'] ?? '');

        $motherPhone =
            trim($_POST['mother_phone'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Guardian
        |--------------------------------------------------------------------------
        */

        $guardianName =
            trim($_POST['guardian_name'] ?? '');

        $guardianPhone =
            trim($_POST['guardian_phone'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Account parent
        |--------------------------------------------------------------------------
        */

        $accountParent =
            trim($_POST['account_parent'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($fullName === '') {
            $errors[] =
                'Student full name is required.';
        }

        if ($dateOfBirth === '') {
            $errors[] =
                'Date of birth is required.';
        }

        if (!in_array(
            $gender,
            ['Male', 'Female'],
            true
        )) {
            $errors[] =
                'Please select a valid gender.';
        }

        /*
        |--------------------------------------------------------------------------
        | Address validation
        |--------------------------------------------------------------------------
        */

        if ($zone === '') {

            $errors[] =
                'Please select an Addis Ababa sub-city.';

        } elseif (
            !array_key_exists(
                $zone,
                $addisAbabaSubcities
            )
        ) {

            $errors[] =
                'Invalid Addis Ababa sub-city selected.';

        }

        if ($woreda === '') {

            $errors[] =
                'Please select a woreda.';

        } elseif (
            $zone !== '' &&
            isset($addisAbabaSubcities[$zone]) &&
            !in_array(
                $woreda,
                $addisAbabaSubcities[$zone],
                true
            )
        ) {

            $errors[] =
                'The selected woreda does not belong to the selected sub-city.';

        }

        if ($gradeId <= 0) {

            $errors[] =
                'Please select a grade.';

        }

        if ($sectionId <= 0) {

            $errors[] =
                'Please select a section.';

        }

        if ($fatherName === '') {

            $errors[] =
                'Father full name is required.';

        }

        if ($fatherPhone === '') {

            $errors[] =
                'Father phone number is required.';

        }

        if ($accountParent === '') {

            $errors[] =
                'Please select which parent will receive account access.';

        }

        if (!in_array(
            $accountParent,
            ['Father', 'Mother', 'Guardian'],
            true
        )) {

            $errors[] =
                'Invalid account parent selection.';

        }

        if (
            $accountParent === 'Mother' &&
            (
                $motherName === '' ||
                $motherPhone === ''
            )
        ) {

            $errors[] =
                'Mother name and phone are required when Mother is selected for account access.';

        }

        if (
            $accountParent === 'Guardian' &&
            (
                $guardianName === '' ||
                $guardianPhone === ''
            )
        ) {

            $errors[] =
                'Guardian name and phone are required when Guardian is selected for account access.';

        }

        if (!empty($errors)) {

            throw new Exception(
                implode(' ', $errors)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate active academic year again
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name
            FROM academic_years
            WHERE id = ?
              AND status = 'Active'
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $academicYearId
        );

        $stmt->execute();

        $academicYear =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$academicYear) {

            throw new Exception(
                'There is currently no valid active academic year.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate grade
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name
            FROM grades
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $gradeId
        );

        $stmt->execute();

        $grade =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$grade) {

            throw new Exception(
                'Selected grade was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate section
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                code
            FROM sections
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $sectionId
        );

        $stmt->execute();

        $section =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$section) {

            throw new Exception(
                'Selected section was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Determine selected parent
        |--------------------------------------------------------------------------
        */

        $selectedParentName = '';

        $selectedParentPhone = '';

        $selectedRelationship = '';

        if ($accountParent === 'Father') {

            $selectedParentName =
                $fatherName;

            $selectedParentPhone =
                $fatherPhone;

            $selectedRelationship =
                'Father';

        } elseif ($accountParent === 'Mother') {

            $selectedParentName =
                $motherName;

            $selectedParentPhone =
                $motherPhone;

            $selectedRelationship =
                'Mother';

        } else {

            $selectedParentName =
                $guardianName;

            $selectedParentPhone =
                $guardianPhone;

            $selectedRelationship =
                'Guardian';
        }

        /*
        |--------------------------------------------------------------------------
        | Upload directories
        |--------------------------------------------------------------------------
        */

        $studentPhotoDirectory =
            __DIR__ .
            '/../uploads/students/photos';

        $previousSchoolDirectory =
            __DIR__ .
            '/../uploads/students/previous-school';

        /*
        |--------------------------------------------------------------------------
        | Upload student photo
        |--------------------------------------------------------------------------
        */

        $studentPhotoPath =
            uploadFile(
                'photo',
                $studentPhotoDirectory,
                [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ],
                5 * 1024 * 1024
            );

        /*
        |--------------------------------------------------------------------------
        | Upload previous school file
        |--------------------------------------------------------------------------
        */

        $previousSchoolFilePath =
            uploadFile(
                'previous_school_file',
                $previousSchoolDirectory,
                [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png'
                ],
                10 * 1024 * 1024
            );

        /*
        |--------------------------------------------------------------------------
        | Start transaction
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Create student user
        |--------------------------------------------------------------------------
        |
        | The password is generated after the permanent student code
        | is created below.
        |
        */

        $studentUserFullName =
            $fullName;

        $studentUserEmail =
            null;

        $studentUserPhone =
            null;

        $studentRole =
            'Student';

        /*
        |--------------------------------------------------------------------------
        | Temporary placeholder password
        |--------------------------------------------------------------------------
        |
        | The actual password will be generated after the student code
        | is known. This temporary value is immediately replaced below
        | before the transaction is committed.
        |
        */

        $initialStudentPassword =
            bin2hex(random_bytes(16));

        $initialStudentPasswordHash =
            password_hash(
                $initialStudentPassword,
                PASSWORD_DEFAULT
            );

        $stmt = $conn->prepare("
            INSERT INTO users (
                full_name,
                email,
                phone,
                password,
                role
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'sssss',
            $studentUserFullName,
            $studentUserEmail,
            $studentUserPhone,
            $initialStudentPasswordHash,
            $studentRole
        );

        $stmt->execute();

        $studentUserId =
            $conn->insert_id;

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Create student
        |--------------------------------------------------------------------------
        */

        $temporaryStudentCode =
            'TEMP-' .
            bin2hex(random_bytes(8));

        $stmt = $conn->prepare("
            INSERT INTO students (
                user_id,
                student_code,
                full_name,
                date_of_birth,
                gender,
                region,
                zone,
                woreda,
                fyda_number,
                photo_path
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'isssssssss',
            $studentUserId,
            $temporaryStudentCode,
            $fullName,
            $dateOfBirth,
            $gender,
            $region,
            $zone,
            $woreda,
            $fydaNumber,
            $studentPhotoPath
        );

        $stmt->execute();

        $studentId =
            $conn->insert_id;

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Generate permanent Student Code
        |--------------------------------------------------------------------------
        */

        $studentCode =
            'BKHS-STU-' .
            str_pad(
                (string) $studentId,
                6,
                '0',
                STR_PAD_LEFT
            );

        $stmt = $conn->prepare("
            UPDATE students
            SET student_code = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'si',
            $studentCode,
            $studentId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Generate Student Password
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | BKHS-STU-000123
        |        ↓
        | BKHS@000123
        |
        */

        $studentPassword =
            generateStudentPassword(
                $studentCode
            );

        $studentPasswordHash =
            password_hash(
                $studentPassword,
                PASSWORD_DEFAULT
            );

        /*
        |--------------------------------------------------------------------------
        | Update student user with final password
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'si',
            $studentPasswordHash,
            $studentUserId
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Create admission
        |--------------------------------------------------------------------------
        */

        $admissionStatus =
            'Approved';

        $stmt = $conn->prepare("
            INSERT INTO admissions (
                student_id,
                academic_year_id,
                previous_school_name,
                previous_school_file,
                status
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iisss',
            $studentId,
            $academicYearId,
            $previousSchoolName,
            $previousSchoolFilePath,
            $admissionStatus
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Create first student registration
        |--------------------------------------------------------------------------
        */

        $registrationType =
            'New';

        $resultStatus =
            'Pending';

        $stmt = $conn->prepare("
            INSERT INTO student_registrations (
                student_id,
                academic_year_id,
                grade_id,
                section_id,
                registration_type,
                result
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iiiiss',
            $studentId,
            $academicYearId,
            $gradeId,
            $sectionId,
            $registrationType,
            $resultStatus
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Find existing parent by phone
        |--------------------------------------------------------------------------
        */

        $parentId =
            null;

        $parentUserId =
            null;

        $parentIsNew =
            false;

        $parentPassword =
            null;

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.user_id,
                p.full_name
            FROM parents p
            WHERE p.phone = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $selectedParentPhone
        );

        $stmt->execute();

        $existingParent =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Existing parent
        |--------------------------------------------------------------------------
        */

        if ($existingParent) {

            $parentId =
                (int) $existingParent['id'];

            $parentUserId =
                !empty($existingParent['user_id'])
                    ? (int) $existingParent['user_id']
                    : null;

            /*
            |--------------------------------------------------------------------------
            | Existing parent without user account
            |--------------------------------------------------------------------------
            */

            if (!$parentUserId) {

                /*
                |--------------------------------------------------------------------------
                | Generate parent password
                |--------------------------------------------------------------------------
                |
                | Example:
                |
                | 0912345678
                |      ↓
                | BKHS@5678
                |
                */

                $parentPassword =
                    generateParentPassword(
                        $selectedParentPhone
                    );

                $parentPasswordHash =
                    password_hash(
                        $parentPassword,
                        PASSWORD_DEFAULT
                    );

                $parentRole =
                    'Parent';

                $parentEmail =
                    null;

                $stmt = $conn->prepare("
                    INSERT INTO users (
                        full_name,
                        email,
                        phone,
                        password,
                        role
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    'sssss',
                    $selectedParentName,
                    $parentEmail,
                    $selectedParentPhone,
                    $parentPasswordHash,
                    $parentRole
                );

                $stmt->execute();

                $parentUserId =
                    $conn->insert_id;

                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE parents
                    SET
                        user_id = ?,
                        full_name = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'isi',
                    $parentUserId,
                    $selectedParentName,
                    $parentId
                );

                $stmt->execute();

                $stmt->close();

                $parentIsNew =
                    true;
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | Create new parent account
            |--------------------------------------------------------------------------
            */

            $parentPassword =
                generateParentPassword(
                    $selectedParentPhone
                );

            $parentPasswordHash =
                password_hash(
                    $parentPassword,
                    PASSWORD_DEFAULT
                );

            $parentRole =
                'Parent';

            $parentEmail =
                null;

            $stmt = $conn->prepare("
                INSERT INTO users (
                    full_name,
                    email,
                    phone,
                    password,
                    role
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                'sssss',
                $selectedParentName,
                $parentEmail,
                $selectedParentPhone,
                $parentPasswordHash,
                $parentRole
            );

            $stmt->execute();

            $parentUserId =
                $conn->insert_id;

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Create parent
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO parents (
                    user_id,
                    full_name,
                    phone
                )
                VALUES (?, ?, ?)
            ");

            $stmt->bind_param(
                'iss',
                $parentUserId,
                $selectedParentName,
                $selectedParentPhone
            );

            $stmt->execute();

            $parentId =
                $conn->insert_id;

            $stmt->close();

            $parentIsNew =
                true;
        }

        /*
        |--------------------------------------------------------------------------
        | Create parent ↔ student relationship
        |--------------------------------------------------------------------------
        */

        $isAccountAccess =
            1;

        $stmt = $conn->prepare("
            INSERT INTO student_parents (
                student_id,
                parent_id,
                relationship,
                is_account_access
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iisi',
            $studentId,
            $parentId,
            $selectedRelationship,
            $isAccountAccess
        );

        $stmt->execute();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | Store success information
        |--------------------------------------------------------------------------
        */

        $_SESSION['new_student_registration'] = [

            'student_id' =>
                $studentId,

            'student_code' =>
                $studentCode,

            'student_name' =>
                $fullName,

            'academic_year' =>
                $academicYear['name'],

            'grade' =>
                $grade['name'],

            'section' =>
                $section['name'],

            'registration_date' =>
                date('Y-m-d H:i:s'),

            'student_username' =>
                $studentCode,

            'student_password' =>
                $studentPassword,

            'parent_name' =>
                $selectedParentName,

            'parent_relationship' =>
                $selectedRelationship,

            'parent_username' =>
                $selectedParentPhone,

            'parent_is_new' =>
                $parentIsNew,

            'parent_password' =>
                $parentPassword
        ];

        /*
        |--------------------------------------------------------------------------
        | Redirect after POST
        |--------------------------------------------------------------------------
        */

        header(
            'Location: newStudentRegiter.php?success=1'
        );

        exit;

    } catch (Throwable $exception) {

        if (
            method_exists(
                $conn,
                'in_transaction'
            )
        ) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

        } else {

            /*
            | Older MySQLi compatibility
            */

            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

        }

        $errors[] =
            $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Load grades
|--------------------------------------------------------------------------
*/

$grades = [];

$result = $conn->query("
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $grades[] =
            $row;
    }
}

/*
|--------------------------------------------------------------------------
| Load sections
|--------------------------------------------------------------------------
*/

$sections = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM sections
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $sections[] =
            $row;
    }
}

/*
|--------------------------------------------------------------------------
| Previous POST values
|--------------------------------------------------------------------------
*/

$selectedZone =
    $_POST['zone'] ?? '';

$selectedWoreda =
    $_POST['woreda'] ?? '';

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
        New Student Registration | BKHS
    </title>

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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            color: #111827;
        }

       

        .brand {
            height: 74px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
        }

        .brand-title {
            font-weight: 800;
            font-size: 16px;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

       

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin: 3px 10px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link-custom:hover {
            background: rgba(255,255,255,.07);
            color: white;
        }

        .nav-link-custom.active {
            background: #2563eb;
            color: white;
        }

        .nav-link-custom i {
            font-size: 17px;
            width: 20px;
        }

        

       

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 74px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mobile-menu {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 20px;
        }

        .page-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin-top: 3px;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .topbar-user i {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .content {
            padding: 30px;
            max-width: 1500px;
            margin: auto;
        }

        .form-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(15,23,42,.04);
            margin-bottom: 22px;
        }

        .card-header-custom {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f3;
        }

        .card-header-custom h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .card-header-custom p {
            margin: 5px 0 0;
            color: #6b7280;
            font-size: 12px;
        }

        .card-body-custom {
            padding: 22px;
        }

        .section-number {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            margin-right: 9px;
        }

        .section-title {
            display: flex;
            align-items: center;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 20px;
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
            border-color: #dfe3e8;
            border-radius: 8px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .readonly-field {
            background: #f8fafc !important;
            color: #475569;
            font-weight: 600;
        }

        .active-year-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 9px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
        }

        .active-year-box i {
            font-size: 18px;
        }

        .active-year-label {
            font-size: 11px;
            color: #64748b;
            display: block;
            margin-bottom: 2px;
        }

        .active-year-value {
            font-size: 14px;
            font-weight: 700;
        }

        .parent-box {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 17px;
            height: 100%;
            background: #fafafa;
        }

        .parent-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .account-choice {
            border: 1px solid #dfe3e8;
            border-radius: 9px;
            padding: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 13px;
            transition: .2s;
            background: white;
        }

        .account-choice:hover {
            border-color: #2563eb;
        }

        .account-choice input {
            accent-color: #2563eb;
        }

        .submit-area {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            box-shadow: 0 4px 18px rgba(15,23,42,.04);
        }

        .btn-primary-custom {
            background: #2563eb;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
            color: white;
        }

        .btn-secondary-custom {
            background: #f3f4f6;
            color: #374151;
            border: 0;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-secondary-custom:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 13px;
        }

        .success-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 30px rgba(15,23,42,.06);
            overflow: hidden;
        }

        .success-top {
            padding: 40px 25px;
            text-align: center;
            background: linear-gradient(
                135deg,
                #ecfdf5,
                #eff6ff
            );
        }

        .success-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #dcfce7;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }

        .success-top h2 {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .success-top p {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }

        .success-body {
            padding: 25px;
        }

        .success-section {
            border: 1px solid #e5e7eb;
            border-radius: 11px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .success-section-title {
            padding: 13px 16px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 700;
        }

        .success-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f1f3;
            font-size: 13px;
        }

        .success-row:last-child {
            border-bottom: 0;
        }

        .success-label {
            color: #6b7280;
        }

        .success-value {
            font-weight: 600;
            text-align: right;
        }

        .credential-box {
            margin: 16px;
            padding: 0;
            background: #f8fafc;
            border-radius: 9px;
            border: 1px solid #e2e8f0;
        }

        .credential-box .success-row {
            border-bottom: 0;
        }

        .credential-warning {
            margin: 16px;
            padding: 13px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            font-size: 12px;
        }

        .success-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            padding: 0 25px 28px;
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .topbar-user span {
                display: none;
            }
        }

        @media (max-width: 575px) {

            .content {
                padding: 14px;
            }

            .page-subtitle {
                display: none;
            }

            .card-body-custom {
                padding: 16px;
            }

            .card-header-custom {
                padding: 17px;
            }

            .submit-area {
                flex-direction: column;
            }

            .submit-area a,
            .submit-area button {
                width: 100%;
                text-align: center;
            }

            .success-row {
                flex-direction: column;
                gap: 4px;
            }

            .success-value {
                text-align: left;
            }

        }

    </style>

</head>

<body>

<div
    class="mobile-overlay"
    id="mobileOverlay"
></div>


<!-- MAIN -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-heading">

            <button
                class="mobile-menu"
                id="mobileMenu"
                type="button"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">

                    New Student Registration

                </h1>

                <div class="page-subtitle">

                    Register a new student and create the required accounts

                </div>

            </div>

        </div>

        <div class="topbar-user">

            <i class="bi bi-person"></i>

            <span>

                <?= e($registrar['full_name']) ?>

            </span>

        </div>

    </header>

    <!-- CONTENT -->

    <div class="content">

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger alert-custom">

                <div class="fw-semibold mb-1">

                    Registration could not be completed.

                </div>

                <?php foreach ($errors as $error): ?>

                    <div>

                        <i
                            class="bi bi-exclamation-circle me-1"
                        ></i>

                        <?= e($error) ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($successData): ?>

            <!-- SUCCESS -->

            <div class="success-card">

                <div class="success-top">

                    <div class="success-icon">

                        <i class="bi bi-check-lg"></i>

                    </div>

                    <h2>

                        Registration Successful

                    </h2>

                    <p>

                        <?= e($successData['student_name']) ?>

                        has been successfully registered at BKHS.

                    </p>

                </div>

                <div class="success-body">

                    <div class="success-section">

                        <div class="success-section-title">

                            <i
                                class="bi bi-person-vcard me-2"
                            ></i>

                            Student Information

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Student ID

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['student_code']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Full Name

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['student_name']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Academic Year

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['academic_year']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Grade

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['grade']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Section

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['section']
                                ) ?>

                            </span>

                        </div>

                    </div>


                    <div class="success-section">

                        <div class="success-section-title">

                            <i
                                class="bi bi-person-lock me-2"
                            ></i>

                            Student Account

                        </div>

                        <div class="credential-box">

                            <div class="success-row">

                                <span class="success-label">

                                    Username

                                </span>

                                <span class="success-value">

                                    <?= e(
                                        $successData['student_username']
                                    ) ?>

                                </span>

                            </div>

                            <div class="success-row">

                                <span class="success-label">

                                    Temporary Password

                                </span>

                                <span class="success-value">

                                    <?= e(
                                        $successData['student_password']
                                    ) ?>

                                </span>

                            </div>

                        </div>

                        <div class="credential-warning">

                            <i
                                class="bi bi-shield-exclamation me-1"
                            ></i>

                            This is a temporary password.
                            The student should change it after signing in.

                        </div>

                    </div>


                    <div class="success-section">

                        <div class="success-section-title">

                            <i
                                class="bi bi-people me-2"
                            ></i>

                            Parent Account

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Parent

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['parent_name']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Relationship

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['parent_relationship']
                                ) ?>

                            </span>

                        </div>

                        <div class="success-row">

                            <span class="success-label">

                                Username / Phone

                            </span>

                            <span class="success-value">

                                <?= e(
                                    $successData['parent_username']
                                ) ?>

                            </span>

                        </div>


                        <?php if (
                            $successData['parent_is_new']
                        ): ?>

                            <div class="credential-box">

                                <div class="success-row">

                                    <span class="success-label">

                                        Temporary Password

                                    </span>

                                    <span class="success-value">

                                        <?= e(
                                            $successData['parent_password']
                                        ) ?>

                                    </span>

                                </div>

                            </div>

                            <div class="credential-warning">

                                <i
                                    class="bi bi-shield-exclamation me-1"
                                ></i>

                                This is a new parent account.
                                The parent should change the temporary
                                password after signing in.

                            </div>

                        <?php else: ?>

                            <div class="credential-warning">

                                <i
                                    class="bi bi-info-circle me-1"
                                ></i>

                                This parent already has an account
                                for another child.

                                No new parent account was created.

                                <strong>
                                    You can use the previous password.
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="success-actions">

                    <a
                        href="newStudentRegiter.php?print=1"
                        target="_blank"
                        class="btn-primary-custom text-decoration-none"
                    >

                        <i
                            class="bi bi-file-earmark-pdf me-1"
                        ></i>

                        Export / Print PDF

                    </a>

                    <a
                        href="student-records.php"
                        class="btn-secondary-custom text-decoration-none"
                    >

                        <i
                            class="bi bi-person-vcard me-1"
                        ></i>

                        Student Record

                    </a>

                    <a
                        href="newStudentRegiter.php?new=1"
                        class="btn-secondary-custom text-decoration-none"
                    >

                        <i
                            class="bi bi-person-plus me-1"
                        ></i>

                        Register Another Student

                    </a>

                </div>

            </div>


        <?php else: ?>

            <!-- REGISTRATION FORM -->

            <form
                method="POST"
                enctype="multipart/form-data"
                id="registrationForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <!-- STUDENT INFORMATION -->

                <div class="form-card">

                    <div class="card-header-custom">

                        <h3>

                            <i
                                class="bi bi-person-vcard me-2 text-primary"
                            ></i>

                            Student Information

                        </h3>

                        <p>

                            Enter the student's permanent personal
                            information.

                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="section-title">

                            <span class="section-number">

                                1

                            </span>

                            Basic Information

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">

                                    Full Name

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control"
                                    required
                                    value="<?= e(
                                        $_POST['full_name'] ?? ''
                                    ) ?>"
                                    placeholder="Enter student's full name"
                                >

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">

                                    Date of Birth

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="date"
                                    name="date_of_birth"
                                    class="form-control"
                                    required
                                    value="<?= e(
                                        $_POST['date_of_birth'] ?? ''
                                    ) ?>"
                                >

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">

                                    Gender

                                    <span class="text-danger">
                                        *
                                    </span>

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
                                        <?= (
                                            $_POST['gender'] ?? ''
                                        ) === 'Male'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        Male

                                    </option>

                                    <option
                                        value="Female"
                                        <?= (
                                            $_POST['gender'] ?? ''
                                        ) === 'Female'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        Female

                                    </option>

                                </select>

                            </div>

                            <!-- REGION -->

                            <div class="col-md-4">

                                <label class="form-label">

                                    Region

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <input
                                    type="text"
                                    name="region"
                                    class="form-control readonly-field"
                                    value="Addis Ababa"
                                    readonly
                                >

                                <div class="form-text">

                                    Automatically set to Addis Ababa.

                                </div>

                            </div>

                            <!-- ZONE / SUB-CITY -->

                            <div class="col-md-4">

                                <label
                                    for="zone"
                                    class="form-label"
                                >

                                    Zone / Sub-city

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="zone"
                                    id="zone"
                                    class="form-select"
                                    required
                                >

                                    <option value="">

                                        Select sub-city

                                    </option>

                                    <?php foreach (
                                        $addisAbabaSubcities
                                        as $subcity => $woredas
                                    ): ?>

                                        <option
                                            value="<?= e($subcity) ?>"
                                            <?= $selectedZone === $subcity
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >

                                            <?= e($subcity) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- WOREDA -->

                            <div class="col-md-4">

                                <label
                                    for="woreda"
                                    class="form-label"
                                >

                                    Woreda

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="woreda"
                                    id="woreda"
                                    class="form-select"
                                    required
                                    <?= $selectedZone === ''
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >

                                    <option value="">

                                        Select woreda

                                    </option>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">

                                    FYDA Number

                                </label>

                                <input
                                    type="text"
                                    name="fyda_number"
                                    class="form-control"
                                    value="<?= e(
                                        $_POST['fyda_number'] ?? ''
                                    ) ?>"
                                    placeholder="Optional"
                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">

                                    Student Photo

                                </label>

                                <input
                                    type="file"
                                    name="photo"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp"
                                >

                                <div class="form-text">

                                    JPG, PNG or WEBP.
                                    Maximum 5 MB.

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ACADEMIC INFORMATION -->

                <div class="form-card">

                    <div class="card-header-custom">

                        <h3>

                            <i
                                class="bi bi-mortarboard me-2 text-primary"
                            ></i>

                            Academic Registration

                        </h3>

                        <p>

                            The active academic year is automatically
                            assigned to this registration.

                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="row g-3">

                            <!-- ACTIVE ACADEMIC YEAR -->

                            <div class="col-md-4">

                                <label class="form-label">

                                    Current Academic Year

                                </label>

                                <div class="active-year-box">

                                    <i
                                        class="bi bi-calendar-check"
                                    ></i>

                                    <div>

                                        <span
                                            class="active-year-label"
                                        >
                                            Active Academic Year
                                        </span>

                                        <span
                                            class="active-year-value"
                                        >
                                            <?= e(
                                                $activeAcademicYearName
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                            </div>

                            <!-- GRADE -->

                            <div class="col-md-4">

                                <label
                                    for="grade_id"
                                    class="form-label"
                                >

                                    Grade

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    name="grade_id"
                                    id="grade_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">

                                        Select grade

                                    </option>

                                    <?php foreach (
                                        $grades
                                        as $gradeItem
                                    ): ?>

                                        <option
                                            value="<?= (int) $gradeItem['id'] ?>"
                                            <?= (
                                                (int) (
                                                    $_POST['grade_id'] ?? 0
                                                ) ===
                                                (int) $gradeItem['id']
                                            )
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >

                                            <?= e(
                                                $gradeItem['name']
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- SECTION -->

                            <div class="col-md-4">

                                <label
                                    for="section_id"
                                    class="form-label"
                                >

                                    Section

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>

                                <select
                                    class="form-select"
                                    id="section_id"
                                    name="section_id"
                                    required
                                >

                                    <option value="">

                                        Select Section

                                    </option>

                                    <?php foreach (
                                        $sections
                                        as $section
                                    ): ?>

                                        <option
                                            value="<?= (int) $section['id'] ?>"
                                            <?= (
                                                (
                                                    $_POST['section_id']
                                                    ?? ''
                                                ) ==
                                                $section['id']
                                            )
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >

                                            <?= e(
                                                $section['code']
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- PREVIOUS SCHOOL -->

                <div class="form-card">

                    <div class="card-header-custom">

                        <h3>

                            <i
                                class="bi bi-building me-2 text-primary"
                            ></i>

                            Previous School

                        </h3>

                        <p>

                            Record the student's previous school
                            information.

                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">

                                    Previous School Name

                                </label>

                                <input
                                    type="text"
                                    name="previous_school_name"
                                    class="form-control"
                                    value="<?= e(
                                        $_POST[
                                            'previous_school_name'
                                        ] ?? ''
                                    ) ?>"
                                    placeholder="Previous school name"
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">

                                    Previous School File

                                </label>

                                <input
                                    type="file"
                                    name="previous_school_file"
                                    class="form-control"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                >

                                <div class="form-text">

                                    PDF, JPG or PNG.
                                    Maximum 10 MB.

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- PARENTS -->

                <div class="form-card">

                    <div class="card-header-custom">

                        <h3>

                            <i
                                class="bi bi-people me-2 text-primary"
                            ></i>

                            Parent / Guardian Information

                        </h3>

                        <p>

                            Enter parent information and choose the
                            account that will have access to this student.

                        </p>

                    </div>

                    <div class="card-body-custom">

                        <div class="row g-3">

                            <!-- FATHER -->

                            <div class="col-lg-4">

                                <div class="parent-box">

                                    <div class="parent-title">

                                        <i
                                            class="bi bi-person me-1"
                                        ></i>

                                        Father

                                    </div>

                                    <div class="mb-3">

                                        <label class="form-label">

                                            Full Name

                                            <span class="text-danger">
                                                *
                                            </span>

                                        </label>

                                        <input
                                            type="text"
                                            name="father_name"
                                            class="form-control"
                                            required
                                            value="<?= e(
                                                $_POST[
                                                    'father_name'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="Father full name"
                                        >

                                    </div>

                                    <div>

                                        <label class="form-label">

                                            Phone

                                            <span class="text-danger">
                                                *
                                            </span>

                                        </label>

                                        <input
                                            type="text"
                                            name="father_phone"
                                            class="form-control"
                                            required
                                            value="<?= e(
                                                $_POST[
                                                    'father_phone'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="09XXXXXXXX"
                                        >

                                    </div>

                                </div>

                            </div>


                            <!-- MOTHER -->

                            <div class="col-lg-4">

                                <div class="parent-box">

                                    <div class="parent-title">

                                        <i
                                            class="bi bi-person me-1"
                                        ></i>

                                        Mother

                                    </div>

                                    <div class="mb-3">

                                        <label class="form-label">

                                            Full Name

                                        </label>

                                        <input
                                            type="text"
                                            name="mother_name"
                                            class="form-control"
                                            value="<?= e(
                                                $_POST[
                                                    'mother_name'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="Mother full name"
                                        >

                                    </div>

                                    <div>

                                        <label class="form-label">

                                            Phone

                                        </label>

                                        <input
                                            type="text"
                                            name="mother_phone"
                                            class="form-control"
                                            value="<?= e(
                                                $_POST[
                                                    'mother_phone'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="09XXXXXXXX"
                                        >

                                    </div>

                                </div>

                            </div>


                            <!-- GUARDIAN -->

                            <div class="col-lg-4">

                                <div class="parent-box">

                                    <div class="parent-title">

                                        <i
                                            class="bi bi-person-badge me-1"
                                        ></i>

                                        Guardian

                                    </div>

                                    <div class="mb-3">

                                        <label class="form-label">

                                            Full Name

                                        </label>

                                        <input
                                            type="text"
                                            name="guardian_name"
                                            class="form-control"
                                            value="<?= e(
                                                $_POST[
                                                    'guardian_name'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="Guardian full name"
                                        >

                                    </div>

                                    <div>

                                        <label class="form-label">

                                            Phone

                                        </label>

                                        <input
                                            type="text"
                                            name="guardian_phone"
                                            class="form-control"
                                            value="<?= e(
                                                $_POST[
                                                    'guardian_phone'
                                                ] ?? ''
                                            ) ?>"
                                            placeholder="09XXXXXXXX"
                                        >

                                    </div>

                                </div>

                            </div>

                        </div>


                        <div class="mt-4">

                            <div class="section-title mb-3">

                                <span class="section-number">

                                    2

                                </span>

                                Parent Account Access

                            </div>

                            <div class="row g-2">

                                <div class="col-md-4">

                                    <label
                                        class="account-choice"
                                    >

                                        <input
                                            type="radio"
                                            name="account_parent"
                                            value="Father"
                                            required
                                            <?= (
                                                $_POST[
                                                    'account_parent'
                                                ] ?? ''
                                            ) === 'Father'
                                                ? 'checked'
                                                : ''
                                            ?>
                                        >

                                        <span>

                                            Father

                                        </span>

                                    </label>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="account-choice"
                                    >

                                        <input
                                            type="radio"
                                            name="account_parent"
                                            value="Mother"
                                            <?= (
                                                $_POST[
                                                    'account_parent'
                                                ] ?? ''
                                            ) === 'Mother'
                                                ? 'checked'
                                                : ''
                                            ?>
                                        >

                                        <span>

                                            Mother

                                        </span>

                                    </label>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="account-choice"
                                    >

                                        <input
                                            type="radio"
                                            name="account_parent"
                                            value="Guardian"
                                            <?= (
                                                $_POST[
                                                    'account_parent'
                                                ] ?? ''
                                            ) === 'Guardian'
                                                ? 'checked'
                                                : ''
                                            ?>
                                        >

                                        <span>

                                            Guardian

                                        </span>

                                    </label>

                                </div>

                            </div>

                            <div class="form-text mt-2">

                                <i
                                    class="bi bi-info-circle me-1"
                                ></i>

                                The selected parent will receive account
                                access using their phone number as the
                                username.

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SUBMIT -->

                <div class="submit-area">

                    <a
                        href="register.php"
                        class="btn-secondary-custom text-decoration-none"
                    >

                        Cancel

                    </a>

                    <button
                        type="submit"
                        class="btn-primary-custom"
                        id="submitButton"
                    >

                        <i
                            class="bi bi-person-plus me-1"
                        ></i>

                        Register Student

                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| Mobile sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const mobileMenu =
    document.getElementById('mobileMenu');

const mobileOverlay =
    document.getElementById('mobileOverlay');

if (mobileMenu) {

    mobileMenu.addEventListener(
        'click',
        function () {

            sidebar.classList.toggle('open');

            mobileOverlay.classList.toggle('show');

        }
    );

}

if (mobileOverlay) {

    mobileOverlay.addEventListener(
        'click',
        function () {

            sidebar.classList.remove('open');

            mobileOverlay.classList.remove('show');

        }
    );

}


/*
|--------------------------------------------------------------------------
| Addis Ababa sub-city → woreda mapping
|--------------------------------------------------------------------------
*/

const addisAbabaWoredas =
    <?= json_encode(
        $addisAbabaSubcities,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


/*
|--------------------------------------------------------------------------
| Woreda dropdown
|--------------------------------------------------------------------------
*/

const zoneSelect =
    document.getElementById('zone');

const woredaSelect =
    document.getElementById('woreda');

const previousZone =
    <?= json_encode(
        $selectedZone,
        JSON_UNESCAPED_UNICODE
    ) ?>;

const previousWoreda =
    <?= json_encode(
        $selectedWoreda,
        JSON_UNESCAPED_UNICODE
    ) ?>;


function loadWoredas(
    selectedZone,
    selectedWoreda = ''
) {

    if (!woredaSelect) {
        return;
    }

    woredaSelect.innerHTML = '';

    const defaultOption =
        document.createElement('option');

    defaultOption.value = '';

    defaultOption.textContent =
        selectedZone
            ? 'Select woreda'
            : 'Select sub-city first';

    woredaSelect.appendChild(
        defaultOption
    );

    if (
        !selectedZone ||
        !addisAbabaWoredas[selectedZone]
    ) {

        woredaSelect.disabled = true;

        return;
    }

    const woredas =
        addisAbabaWoredas[selectedZone];

    woredas.forEach(
        function (woreda) {

            const option =
                document.createElement('option');

            option.value =
                woreda;

            option.textContent =
                woreda;

            if (
                selectedWoreda &&
                selectedWoreda === woreda
            ) {

                option.selected =
                    true;

            }

            woredaSelect.appendChild(
                option
            );

        }
    );

    woredaSelect.disabled =
        false;
}


if (zoneSelect) {

    zoneSelect.addEventListener(
        'change',
        function () {

            loadWoredas(
                this.value
            );

        }
    );

}


/*
|--------------------------------------------------------------------------
| Restore values after validation error
|--------------------------------------------------------------------------
*/

if (previousZone) {

    loadWoredas(
        previousZone,
        previousWoreda
    );

}


/*
|--------------------------------------------------------------------------
| Prevent accidental double submission
|--------------------------------------------------------------------------
*/

const registrationForm =
    document.getElementById(
        'registrationForm'
    );

if (registrationForm) {

    registrationForm.addEventListener(
        'submit',
        function () {

            const submitButton =
                document.getElementById(
                    'submitButton'
                );

            if (submitButton) {

                submitButton.disabled =
                    true;

                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    'Registering Student...';

            }

        }
    );

}

</script>

</body>

</html>