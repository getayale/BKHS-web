<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match($allowedOriginPattern, $origin)
) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once '../../teacher/homework/helpers.php';
require_once '../../teacher/homework/data.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| API Response
|--------------------------------------------------------------------------
*/

function apiResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim(
            (string) $_SERVER['HTTP_AUTHORIZATION']
        );
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $key => $value) {
            if (
                strtolower((string) $key) ===
                'authorization'
            ) {
                return trim(
                    (string) $value
                );
            }
        }
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Bearer Token
|--------------------------------------------------------------------------
*/

function getBearerToken(): string
{
    $authorization = getAuthorizationHeader();

    if ($authorization === '') {
        apiResponse(
            false,
            'Authorization token is required.',
            [],
            401
        );
    }

    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            $authorization,
            $matches
        )
    ) {
        apiResponse(
            false,
            'Invalid authorization format.',
            [],
            401
        );
    }

    return trim($matches[1]);
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

function getEthiopianDateFromGregorian(
    string $date
): array {
    $parts = explode('-', $date);

    if (count($parts) !== 3) {
        throw new RuntimeException(
            'Invalid Gregorian date.'
        );
    }

    return EthiopianCalendar::gregorianToEthiopian(
        (int) $parts[0],
        (int) $parts[1],
        (int) $parts[2]
    );
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

$tokenHash = hash(
    'sha256',
    $token
);

$authStmt = $conn->prepare("
    SELECT
        at.user_id,
        at.expires_at,
        u.full_name,
        u.email,
        u.phone,
        u.role
    FROM api_tokens at
    INNER JOIN users u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
");

if ($authStmt === false) {
    apiResponse(
        false,
        'Unable to prepare authentication query: ' .
        $conn->error,
        [],
        500
    );
}

$authStmt->bind_param(
    's',
    $tokenHash
);

if (!$authStmt->execute()) {
    $error = $authStmt->error;

    $authStmt->close();

    apiResponse(
        false,
        'Unable to authenticate request: ' .
        $error,
        [],
        500
    );
}

$authResult = $authStmt->get_result();

$teacher = $authResult->fetch_assoc();

$authStmt->close();

if (!$teacher) {
    apiResponse(
        false,
        'Invalid or expired authorization token.',
        [],
        401
    );
}

if (
    strtolower(
        (string) ($teacher['role'] ?? '')
    ) !== 'teacher'
) {
    apiResponse(
        false,
        'Only teachers can manage homework.',
        [],
        403
    );
}

$teacherUserId =
    (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name,
        start_year,
        start_month,
        start_day,
        end_year,
        end_month,
        end_day,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($academicYearStmt === false) {
    apiResponse(
        false,
        'Unable to load academic year: ' .
        $conn->error,
        [],
        500
    );
}

if (!$academicYearStmt->execute()) {
    $error = $academicYearStmt->error;

    $academicYearStmt->close();

    apiResponse(
        false,
        'Unable to load academic year: ' .
        $error,
        [],
        500
    );
}

$academicYearResult =
    $academicYearStmt->get_result();

$academicYear =
    $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    apiResponse(
        false,
        'There is no active academic year.',
        [],
        400
    );
}

$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| GET — Load Homework For Editing
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $homeworkId = (int) (
        $_GET['id'] ?? 0
    );

    if ($homeworkId <= 0) {
        apiResponse(
            false,
            'Homework ID is required.',
            [],
            400
        );
    }

    try {

        $homework = getTeacherHomework(
            $conn,
            $homeworkId,
            $teacherUserId,
            $academicYearName
        );

        if (!$homework) {
            apiResponse(
                false,
                'Homework not found or you are not authorized to edit it.',
                [],
                404
            );
        }

        $assignedDateGregorian =
            (string) (
                $homework['assigned_date'] ?? ''
            );

        $dueDateGregorian =
            (string) (
                $homework['due_date'] ?? ''
            );

        if (
            $assignedDateGregorian === '' ||
            $dueDateGregorian === ''
        ) {
            apiResponse(
                false,
                'Homework dates are missing.',
                [],
                500
            );
        }

        $assignedEthiopian =
            getEthiopianDateFromGregorian(
                $assignedDateGregorian
            );

        $dueEthiopian =
            getEthiopianDateFromGregorian(
                $dueDateGregorian
            );

        $assignedYear =
            (int) ($assignedEthiopian['year'] ?? 0);

        $assignedMonth =
            (int) ($assignedEthiopian['month'] ?? 0);

        $assignedDay =
            (int) ($assignedEthiopian['day'] ?? 0);

        $dueYear =
            (int) ($dueEthiopian['year'] ?? 0);

        $dueMonth =
            (int) ($dueEthiopian['month'] ?? 0);

        $dueDay =
            (int) ($dueEthiopian['day'] ?? 0);

        $assignedFormatted =
            EthiopianCalendar::format(
                $assignedYear,
                $assignedMonth,
                $assignedDay
            );

        $dueFormatted =
            EthiopianCalendar::format(
                $dueYear,
                $dueMonth,
                $dueDay
            );

        /*
        |--------------------------------------------------------------------------
        | Teacher Material
        |--------------------------------------------------------------------------
        */

        $materialPath =
            (string) (
                $homework[
                    'teacher_material_path'
                ] ?? ''
            );

        $materialOriginalName =
            (string) (
                $homework[
                    'teacher_material_original_name'
                ] ?? ''
            );

        $materialSize =
            (int) (
                $homework[
                    'teacher_material_size'
                ] ?? 0
            );

        $materialExists = false;

        if ($materialPath !== '') {

            $projectRoot =
                dirname(__DIR__, 2);

            $physicalPath =
                $projectRoot .
                DIRECTORY_SEPARATOR .
                str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $materialPath
                );

            $materialExists =
                is_file($physicalPath);
        }

        /*
        |--------------------------------------------------------------------------
        | GET Response
        |--------------------------------------------------------------------------
        */

        apiResponse(
            true,
            '',
            [
                'teacher' => [
                    'id' =>
                        $teacherUserId,

                    'full_name' =>
                        (string) $teacher['full_name'],

                    'email' =>
                        (string) (
                            $teacher['email'] ?? ''
                        ),

                    'phone' =>
                        (string) (
                            $teacher['phone'] ?? ''
                        ),

                    'role' =>
                        (string) $teacher['role'],
                ],

                'academic_year' => [
                    'id' =>
                        $academicYearId,

                    'name' =>
                        $academicYearName,

                    'status' =>
                        (string) (
                            $academicYear['status'] ?? ''
                        ),
                ],

                'homework' => [
                    'id' =>
                        (int) $homework['id'],

                    'title' =>
                        (string) (
                            $homework['title'] ?? ''
                        ),

                    'description' =>
                        (string) (
                            $homework['description'] ?? ''
                        ),

                    'subject_name' =>
                        (string) (
                            $homework['subject_name'] ?? ''
                        ),

                    'grade' =>
                        (int) (
                            $homework['grade'] ?? 0
                        ),

                    'section' =>
                        (string) (
                            $homework['section'] ?? ''
                        ),

                    'assigned_date' =>
                        $assignedDateGregorian,

                    'due_date' =>
                        $dueDateGregorian,

                    'grade_subject_id' =>
                        (int) (
                            $homework[
                                'grade_subject_id'
                            ] ?? 0
                        ),

                    'status' =>
                        (string) (
                            $homework['status'] ?? ''
                        ),

                    'teacher_material_path' =>
                        $materialPath,

                    'teacher_material_original_name' =>
                        $materialOriginalName,

                    'teacher_material_size' =>
                        $materialSize,
                ],

                'assigned_date_ethiopian' => [
                    'year' =>
                        $assignedYear,

                    'month' =>
                        $assignedMonth,

                    'day' =>
                        $assignedDay,

                    'month_name' =>
                        EthiopianCalendar::monthName(
                            $assignedMonth
                        ),

                    'formatted' =>
                        $assignedFormatted,
                ],

                'due_date_ethiopian' => [
                    'year' =>
                        $dueYear,

                    'month' =>
                        $dueMonth,

                    'day' =>
                        $dueDay,

                    'month_name' =>
                        EthiopianCalendar::monthName(
                            $dueMonth
                        ),

                    'formatted' =>
                        $dueFormatted,
                ],

                'teacher_material' => [
                    'exists' =>
                        $materialExists,

                    'original_name' =>
                        $materialOriginalName,

                    'path' =>
                        $materialPath,

                    'size' =>
                        $materialSize,

                    'extension' =>
                        $materialPath !== ''
                            ? strtolower(
                                pathinfo(
                                    $materialPath,
                                    PATHINFO_EXTENSION
                                )
                            )
                            : '',
                ],
            ]
        );

    } catch (Throwable $e) {

        apiResponse(
            false,
            'Unable to load homework: ' .
            $e->getMessage(),
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST — Update Homework
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Upload Diagnostics
    |--------------------------------------------------------------------------
    |
    | This confirms whether Flutter actually sent the file.
    |
    */

    $fileWasReceived = (
        isset($_FILES['teacher_material']) &&
        is_array($_FILES['teacher_material'])
    );

    $fileUploadError = $fileWasReceived
        ? (int) (
            $_FILES['teacher_material']['error']
            ?? UPLOAD_ERR_NO_FILE
        )
        : UPLOAD_ERR_NO_FILE;

    /*
    |--------------------------------------------------------------------------
    | Homework Fields
    |--------------------------------------------------------------------------
    */

    $homeworkId = (int) (
        $_POST['homework_id'] ?? 0
    );

    if ($homeworkId <= 0) {
        apiResponse(
            false,
            'Homework ID is required.',
            [],
            400
        );
    }

    $title = trim(
        (string) (
            $_POST['title'] ?? ''
        )
    );

    $description = trim(
        (string) (
            $_POST['description'] ?? ''
        )
    );

    $dueYear = (int) (
        $_POST['due_year'] ?? 0
    );

    $dueMonth = (int) (
        $_POST['due_month'] ?? 0
    );

    $dueDay = (int) (
        $_POST['due_day'] ?? 0
    );

    /*
    |--------------------------------------------------------------------------
    | Validate Title
    |--------------------------------------------------------------------------
    */

    if ($title === '') {
        apiResponse(
            false,
            'Homework title is required.',
            [],
            400
        );
    }

    if (mb_strlen($title) > 255) {
        apiResponse(
            false,
            'Homework title cannot exceed 255 characters.',
            [],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Description
    |--------------------------------------------------------------------------
    */

    if (mb_strlen($description) > 10000) {
        apiResponse(
            false,
            'Homework description cannot exceed 10000 characters.',
            [],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Ethiopian Due Date
    |--------------------------------------------------------------------------
    */

    if (
        !isValidEthiopianDate(
            $dueYear,
            $dueMonth,
            $dueDay
        )
    ) {
        apiResponse(
            false,
            'Invalid Ethiopian due date.',
            [],
            400
        );
    }

    $newMaterialPhysicalPath = null;

    $oldMaterialPath = null;

    $transactionStarted = false;

    try {

        /*
        |--------------------------------------------------------------------------
        | Verify Homework Ownership
        |--------------------------------------------------------------------------
        */

        $homework = getTeacherHomework(
            $conn,
            $homeworkId,
            $teacherUserId,
            $academicYearName
        );

        if (!$homework) {
            apiResponse(
                false,
                'Homework not found or you are not authorized to edit it.',
                [],
                404
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assigned Date
        |--------------------------------------------------------------------------
        */

        $assignedDate =
            (string) (
                $homework['assigned_date'] ?? ''
            );

        if ($assignedDate === '') {
            apiResponse(
                false,
                'Homework assigned date is missing.',
                [],
                500
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Convert Ethiopian Due Date
        |--------------------------------------------------------------------------
        */

        $dueDate =
            ethiopianDateToGregorian(
                $dueYear,
                $dueMonth,
                $dueDay
            );

        if ($dueDate === null) {
            apiResponse(
                false,
                'The selected due date could not be converted.',
                [],
                400
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Due Date Cannot Be Before Assigned Date
        |--------------------------------------------------------------------------
        */

        if ($dueDate < $assignedDate) {
            apiResponse(
                false,
                'Due date cannot be earlier than the assigned date.',
                [],
                400
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Material Information
        |--------------------------------------------------------------------------
        */

        $teacherMaterialPath =
            !empty(
                $homework['teacher_material_path']
            )
                ? (string) $homework['teacher_material_path']
                : null;

        $teacherMaterialOriginalName =
            !empty(
                $homework[
                    'teacher_material_original_name'
                ]
            )
                ? (string) (
                    $homework[
                        'teacher_material_original_name'
                    ]
                )
                : null;

        $teacherMaterialType =
            !empty(
                $homework['teacher_material_type']
            )
                ? (string) (
                    $homework[
                        'teacher_material_type'
                    ]
                )
                : null;

        $teacherMaterialSize =
            !empty(
                $homework['teacher_material_size']
            )
                ? (int) (
                    $homework[
                        'teacher_material_size'
                    ]
                )
                : null;

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT UPLOAD DIAGNOSTIC
        |--------------------------------------------------------------------------
        |
        | If Flutter does not send teacher_material, stop here instead
        | of silently reporting a successful update.
        |
        */

        if (!$fileWasReceived) {

            /*
            | If there is already a material, this is a normal edit
            | without replacing the material.
            |
            | If there was no existing material, we also allow a normal
            | text/date update.
            |
            */

            $uploadDiagnostic = [
                'received' => false,
                'files_keys' => array_keys($_FILES),
                'post_keys' => array_keys($_POST),
            ];

        } else {

            /*
            |--------------------------------------------------------------------------
            | File Was Received
            |--------------------------------------------------------------------------
            */

            $uploadedFileName =
                (string) (
                    $_FILES['teacher_material']['name']
                    ?? ''
                );

            $uploadedFileSize =
                (int) (
                    $_FILES['teacher_material']['size']
                    ?? 0
                );

            $uploadedTmpName =
                (string) (
                    $_FILES['teacher_material']['tmp_name']
                    ?? ''
                );

            /*
            |--------------------------------------------------------------------------
            | PHP Upload Error
            |--------------------------------------------------------------------------
            */

            if (
                $fileUploadError !==
                UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $fileUploadError !==
                    UPLOAD_ERR_OK
                ) {

                    $message = match ($fileUploadError) {

                        UPLOAD_ERR_INI_SIZE =>
                            'The selected file is larger than the server upload limit.',

                        UPLOAD_ERR_FORM_SIZE =>
                            'The selected file is larger than the allowed form size.',

                        UPLOAD_ERR_PARTIAL =>
                            'The file was only partially uploaded. Please try again.',

                        UPLOAD_ERR_NO_TMP_DIR =>
                            'PHP could not find the temporary upload directory.',

                        UPLOAD_ERR_CANT_WRITE =>
                            'PHP could not write the uploaded file to the server.',

                        UPLOAD_ERR_EXTENSION =>
                            'A PHP extension stopped the file upload.',

                        default =>
                            'The teacher material upload failed. Upload error code: ' .
                            $fileUploadError,
                    };

                    throw new RuntimeException(
                        $message
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate File
                |--------------------------------------------------------------------------
                */

                $uploadResult =
                    validateHomeworkUpload(
                        $_FILES['teacher_material']
                    );

                if (
                    !is_array($uploadResult) ||
                    !isset($uploadResult['valid'])
                ) {
                    throw new RuntimeException(
                        'The uploaded teacher material could not be validated.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT FIX
                |--------------------------------------------------------------------------
                |
                | helpers.php returns "error", not "message".
                |
                */

                if (!$uploadResult['valid']) {
                    throw new RuntimeException(
                        (string) (
                            $uploadResult['error']
                            ?? 'The selected teacher material is invalid.'
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate Temporary File
                |--------------------------------------------------------------------------
                */

                $tmpName =
                    (string) (
                        $uploadResult['tmp_name']
                        ?? $uploadedTmpName
                    );

                if ($tmpName === '') {
                    throw new RuntimeException(
                        'The uploaded file temporary path is missing.'
                    );
                }

                if (!is_uploaded_file($tmpName)) {
                    throw new RuntimeException(
                        'PHP does not recognize the file as a valid HTTP upload.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Extension
                |--------------------------------------------------------------------------
                */

                $extension = strtolower(
                    (string) (
                        $uploadResult['extension']
                        ?? pathinfo(
                            $uploadedFileName,
                            PATHINFO_EXTENSION
                        )
                    )
                );

                if ($extension === '') {
                    throw new RuntimeException(
                        'The uploaded file does not have a valid extension.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Generate Filename
                |--------------------------------------------------------------------------
                */

                $filename =
                    generateHomeworkFilename(
                        $extension
                    );

                /*
                |--------------------------------------------------------------------------
                | Upload Directory
                |--------------------------------------------------------------------------
                */

                $uploadDirectory =
                    dirname(__DIR__, 2) .
                    DIRECTORY_SEPARATOR .
                    'uploads' .
                    DIRECTORY_SEPARATOR .
                    'homeworks' .
                    DIRECTORY_SEPARATOR .
                    'teacher';

                /*
                |--------------------------------------------------------------------------
                | Ensure Directory
                |--------------------------------------------------------------------------
                */

                if (
                    !ensureDirectoryExists(
                        $uploadDirectory
                    )
                ) {
                    throw new RuntimeException(
                        'The teacher material upload directory does not exist and could not be created.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check Writable
                |--------------------------------------------------------------------------
                */

                if (!is_writable($uploadDirectory)) {
                    throw new RuntimeException(
                        'The teacher material upload directory is not writable.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Physical Destination
                |--------------------------------------------------------------------------
                */

                $newMaterialPhysicalPath =
                    $uploadDirectory .
                    DIRECTORY_SEPARATOR .
                    $filename;

                /*
                |--------------------------------------------------------------------------
                | Move Uploaded File
                |--------------------------------------------------------------------------
                */

                if (
                    !move_uploaded_file(
                        $tmpName,
                        $newMaterialPhysicalPath
                    )
                ) {
                    throw new RuntimeException(
                        'The new teacher material could not be saved.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Confirm Physical File
                |--------------------------------------------------------------------------
                */

                if (
                    !is_file(
                        $newMaterialPhysicalPath
                    )
                ) {
                    throw new RuntimeException(
                        'The uploaded teacher material was moved but could not be found at the destination.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Old Material
                |--------------------------------------------------------------------------
                */

                $oldMaterialPath =
                    !empty(
                        $homework['teacher_material_path']
                    )
                        ? (string) (
                            $homework[
                                'teacher_material_path'
                            ]
                        )
                        : null;

                /*
                |--------------------------------------------------------------------------
                | New Database Values
                |--------------------------------------------------------------------------
                */

                $teacherMaterialPath =
                    'uploads/homeworks/teacher/' .
                    $filename;

                $teacherMaterialOriginalName =
                    (string) (
                        $uploadResult['original_name']
                        ?? $uploadedFileName
                    );

                $teacherMaterialType =
                    !empty(
                        $uploadResult['mime_type']
                    )
                        ? (string) (
                            $uploadResult['mime_type']
                        )
                        : (
                            !empty(
                                $_FILES['teacher_material']['type']
                            )
                                ? (string) (
                                    $_FILES['teacher_material']['type']
                                )
                                : null
                        );

                $teacherMaterialSize =
                    isset(
                        $uploadResult['size']
                    )
                        ? (int) (
                            $uploadResult['size']
                        )
                        : $uploadedFileSize;

                /*
                |--------------------------------------------------------------------------
                | Upload Diagnostic
                |--------------------------------------------------------------------------
                */

                $uploadDiagnostic = [
                    'received' => true,
                    'original_name' =>
                        $teacherMaterialOriginalName,
                    'size' =>
                        $teacherMaterialSize,
                    'extension' =>
                        $extension,
                    'mime_type' =>
                        $teacherMaterialType,
                    'destination' =>
                        $teacherMaterialPath,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Database Transaction
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        $transactionStarted = true;

        /*
        |--------------------------------------------------------------------------
        | Update Homework
        |--------------------------------------------------------------------------
        */

        $updated = updateHomework(
            $conn,
            $homeworkId,
            $teacherUserId,
            $academicYearName,
            $title,
            $description !== ''
                ? $description
                : null,
            $teacherMaterialPath,
            $teacherMaterialOriginalName,
            $teacherMaterialType,
            $teacherMaterialSize,
            $dueDate
        );

        /*
        |--------------------------------------------------------------------------
        | Read Record Again
        |--------------------------------------------------------------------------
        */

        $savedHomework = getTeacherHomework(
            $conn,
            $homeworkId,
            $teacherUserId,
            $academicYearName
        );

        if (!$savedHomework) {
            throw new RuntimeException(
                'Homework could not be found after the update.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Text/Date Values
        |--------------------------------------------------------------------------
        */

        $savedTitle =
            (string) (
                $savedHomework['title'] ?? ''
            );

        $savedDescription =
            $savedHomework['description'] !== null
                ? (string) $savedHomework['description']
                : null;

        $expectedDescription =
            $description !== ''
                ? $description
                : null;

        $savedDueDate =
            (string) (
                $savedHomework['due_date'] ?? ''
            );

        if (
            $savedTitle !== $title ||
            $savedDescription !== $expectedDescription ||
            $savedDueDate !== $dueDate
        ) {
            throw new RuntimeException(
                'The homework information was not saved correctly in the database.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Material Database Values
        |--------------------------------------------------------------------------
        */

        $savedMaterialPath =
            !empty(
                $savedHomework['teacher_material_path']
            )
                ? (string) (
                    $savedHomework[
                        'teacher_material_path'
                    ]
                )
                : null;

        $savedMaterialOriginalName =
            !empty(
                $savedHomework[
                    'teacher_material_original_name'
                ]
            )
                ? (string) (
                    $savedHomework[
                        'teacher_material_original_name'
                    ]
                )
                : null;

        $savedMaterialType =
            !empty(
                $savedHomework[
                    'teacher_material_type'
                ]
            )
                ? (string) (
                    $savedHomework[
                        'teacher_material_type'
                    ]
                )
                : null;

        $savedMaterialSize =
            !empty(
                $savedHomework[
                    'teacher_material_size'
                ]
            )
                ? (int) (
                    $savedHomework[
                        'teacher_material_size'
                    ]
                )
                : null;

        if (
            $savedMaterialPath !==
                $teacherMaterialPath ||
            $savedMaterialOriginalName !==
                $teacherMaterialOriginalName ||
            $savedMaterialType !==
                $teacherMaterialType ||
            $savedMaterialSize !==
                $teacherMaterialSize
        ) {
            throw new RuntimeException(
                'The teacher material was uploaded but was not saved correctly in the database.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify New Physical File
        |--------------------------------------------------------------------------
        */

        if ($newMaterialPhysicalPath !== null) {

            if (
                !is_file(
                    $newMaterialPhysicalPath
                )
            ) {
                throw new RuntimeException(
                    'The teacher material database record was saved, but the physical file could not be found.'
                );
            }

            $savedPhysicalSize =
                filesize(
                    $newMaterialPhysicalPath
                );

            if (
                $savedPhysicalSize === false ||
                (int) $savedPhysicalSize !==
                    $teacherMaterialSize
            ) {
                throw new RuntimeException(
                    'The uploaded teacher material file size does not match the database record.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        $transactionStarted = false;

        /*
        |--------------------------------------------------------------------------
        | Delete Old Physical Material
        |--------------------------------------------------------------------------
        */

        if (
            $oldMaterialPath !== null &&
            $newMaterialPhysicalPath !== null
        ) {

            $projectRoot =
                dirname(__DIR__, 2);

            $oldPhysicalPath =
                $projectRoot .
                DIRECTORY_SEPARATOR .
                str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $oldMaterialPath
                );

            if (
                is_file($oldPhysicalPath) &&
                realpath($oldPhysicalPath) !==
                realpath($newMaterialPhysicalPath)
            ) {
                @unlink(
                    $oldPhysicalPath
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Due Date Response
        |--------------------------------------------------------------------------
        */

        $dueEthiopian =
            getEthiopianDateFromGregorian(
                $dueDate
            );

        $responseDueYear =
            (int) (
                $dueEthiopian['year'] ?? 0
            );

        $responseDueMonth =
            (int) (
                $dueEthiopian['month'] ?? 0
            );

        $responseDueDay =
            (int) (
                $dueEthiopian['day'] ?? 0
            );

        $responseDueFormatted =
            EthiopianCalendar::format(
                $responseDueYear,
                $responseDueMonth,
                $responseDueDay
            );

        /*
        |--------------------------------------------------------------------------
        | Success Response
        |--------------------------------------------------------------------------
        */

        apiResponse(
            true,
            'Homework updated successfully.',
            [
                'upload_diagnostic' =>
                    $uploadDiagnostic ?? [
                        'received' => false,
                    ],

                'homework' => [
                    'id' =>
                        $homeworkId,

                    'title' =>
                        $savedTitle,

                    'description' =>
                        $savedDescription ?? '',

                    'due_date' =>
                        $savedDueDate,

                    'teacher_material_path' =>
                        $savedMaterialPath,

                    'teacher_material_original_name' =>
                        $savedMaterialOriginalName,

                    'teacher_material_type' =>
                        $savedMaterialType,

                    'teacher_material_size' =>
                        $savedMaterialSize,
                ],

                'due_date_ethiopian' => [
                    'year' =>
                        $responseDueYear,

                    'month' =>
                        $responseDueMonth,

                    'day' =>
                        $responseDueDay,

                    'month_name' =>
                        EthiopianCalendar::monthName(
                            $responseDueMonth
                        ),

                    'formatted' =>
                        $responseDueFormatted,
                ],
            ]
        );

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | Rollback
        |--------------------------------------------------------------------------
        */

        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable) {
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Newly Uploaded File
        |--------------------------------------------------------------------------
        */

        if (
            $newMaterialPhysicalPath !== null &&
            is_file($newMaterialPhysicalPath)
        ) {
            @unlink(
                $newMaterialPhysicalPath
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Error Response
        |--------------------------------------------------------------------------
        */

        apiResponse(
            false,
            'Homework could not be updated: ' .
            $e->getMessage(),
            [
                'upload_diagnostic' =>
                    $uploadDiagnostic ?? [
                        'received' =>
                            $fileWasReceived,
                        'files_keys' =>
                            array_keys($_FILES),
                        'post_keys' =>
                            array_keys($_POST),
                    ],
            ],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Unsupported Method
|--------------------------------------------------------------------------
*/

apiResponse(
    false,
    'Unsupported request method.',
    [],
    405
);

