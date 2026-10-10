<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Teacher Material Edit API
|--------------------------------------------------------------------------
|
| Method: POST
| Content-Type: multipart/form-data
|
| Required:
|   material_id
|   assignment_id
|   title
|
| Optional:
|   description
|   material_file
|
*/

date_default_timezone_set('Africa/Addis_Ababa');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

function respond(
    int $statusCode,
    bool $success,
    string $message,
    ?array $data = null
): void {
    http_response_code($statusCode);

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');

    respond(
        405,
        false,
        'Method not allowed. Use POST.'
    );
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/

$authorization = trim(
    (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    )
);

if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();

    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
            $authorization = trim((string) $value);
            break;
        }
    }
}

if (
    !preg_match(
        '/^Bearer\s+(\S+)$/i',
        $authorization,
        $matches
    )
) {
    respond(
        401,
        false,
        'Authorization token is required.'
    );
}

$token = $matches[1];
$tokenHash = hash('sha256', $token);

/*
|--------------------------------------------------------------------------
| Authenticate Teacher
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name
    FROM api_tokens at
    INNER JOIN users u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'teacher'
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Authentication service is unavailable.'
    );
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    respond(
        401,
        false,
        'Invalid or expired token, or teacher access is not permitted.'
    );
}

$teacherUserId = (int) $teacher['id'];

/*
|--------------------------------------------------------------------------
| Read Request Fields
|--------------------------------------------------------------------------
*/

$materialId = filter_var(
    $_POST['material_id'] ?? null,
    FILTER_VALIDATE_INT
);

$assignmentId = filter_var(
    $_POST['assignment_id'] ?? null,
    FILTER_VALIDATE_INT
);

$title = trim(
    (string) ($_POST['title'] ?? '')
);

$description = trim(
    (string) ($_POST['description'] ?? '')
);

if (!$materialId || $materialId < 1) {
    respond(
        422,
        false,
        'A valid material_id is required.'
    );
}

if (!$assignmentId || $assignmentId < 1) {
    respond(
        422,
        false,
        'Please select a valid class and subject assignment.'
    );
}

if ($title === '') {
    respond(
        422,
        false,
        'Please enter a material title.'
    );
}

if (!function_exists('mb_strlen')) {
    respond(
        500,
        false,
        'The server requires the PHP mbstring extension.'
    );
}

if (mb_strlen($title, 'UTF-8') > 255) {
    respond(
        422,
        false,
        'Material title cannot exceed 255 characters.'
    );
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Could not check the active academic year.'
    );
}

$stmt->execute();

$result = $stmt->get_result();
$academicYearRow = $result->fetch_assoc();

$stmt->close();

if (!$academicYearRow) {
    respond(
        409,
        false,
        'There is no active academic year.'
    );
}

$activeAcademicYear = (string) $academicYearRow['name'];

/*
|--------------------------------------------------------------------------
| Get Existing Material
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        tm.id,
        tm.teacher_user_id,
        tm.grade_subject_id,
        tm.academic_year,
        tm.title,
        tm.description,
        tm.file_name,
        tm.file_path,
        tm.file_type,
        tm.file_size
    FROM teacher_materials tm
    WHERE tm.id = ?
      AND tm.teacher_user_id = ?
      AND tm.is_active = 1
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Could not retrieve the material.'
    );
}

$stmt->bind_param(
    'ii',
    $materialId,
    $teacherUserId
);

$stmt->execute();

$result = $stmt->get_result();
$material = $result->fetch_assoc();

$stmt->close();

if (!$material) {
    respond(
        404,
        false,
        'Material not found or you do not have permission to edit it.'
    );
}

/*
|--------------------------------------------------------------------------
| Validate Teacher Assignment
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        sta.id,
        sta.grade,
        sta.section,
        sta.grade_subject_id,
        gs.subject_name
    FROM subject_teacher_assignments sta
    INNER JOIN grade_subjects gs
        ON gs.id = sta.grade_subject_id
    WHERE sta.id = ?
      AND sta.teacher_user_id = ?
      AND sta.academic_year = ?
      AND sta.is_active = 1
      AND gs.is_active = 1
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Could not validate the subject assignment.'
    );
}

$stmt->bind_param(
    'iis',
    $assignmentId,
    $teacherUserId,
    $activeAcademicYear
);

$stmt->execute();

$result = $stmt->get_result();
$assignment = $result->fetch_assoc();

$stmt->close();

if (!$assignment) {
    respond(
        422,
        false,
        'The selected class and subject assignment is not valid for this teacher.'
    );
}

/*
|--------------------------------------------------------------------------
| File Upload Configuration
|--------------------------------------------------------------------------
*/

$allowedExtensions = [
    'pdf',
    'doc',
    'docx',
    'ppt',
    'pptx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'zip'
];

$maxFileSize = 20 * 1024 * 1024;

$newFileUploaded = false;
$newFileName = '';
$newOriginalFileName = '';
$newDatabaseFilePath = '';
$newPhysicalFilePath = '';
$newFileType = '';
$newFileSize = null;

/*
|--------------------------------------------------------------------------
| Validate Optional Replacement File
|--------------------------------------------------------------------------
*/

$file = $_FILES['material_file'] ?? null;

if ($file !== null && is_array($file)) {
    $uploadError = (int) (
        $file['error'] ?? UPLOAD_ERR_NO_FILE
    );

    if ($uploadError !== UPLOAD_ERR_NO_FILE) {
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE =>
                    'The uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE =>
                    'The uploaded file exceeds the allowed form size.',
                UPLOAD_ERR_PARTIAL =>
                    'The file upload was incomplete.',
                UPLOAD_ERR_NO_TMP_DIR =>
                    'The server temporary upload directory is missing.',
                UPLOAD_ERR_CANT_WRITE =>
                    'The server could not write the uploaded file.',
                UPLOAD_ERR_EXTENSION =>
                    'The file upload was stopped by a server extension.'
            ];

            respond(
                422,
                false,
                $uploadMessages[$uploadError]
                    ?? 'The file could not be uploaded.'
            );
        }

        $uploadedTmpName = (string) (
            $file['tmp_name'] ?? ''
        );

        $uploadedOriginalName = trim(
            (string) ($file['name'] ?? '')
        );

        $uploadedSize = (int) (
            $file['size'] ?? 0
        );

        if (
            $uploadedTmpName === '' ||
            !is_uploaded_file($uploadedTmpName)
        ) {
            respond(
                422,
                false,
                'The uploaded file is invalid.'
            );
        }

        if ($uploadedOriginalName === '') {
            respond(
                422,
                false,
                'The uploaded file has an invalid filename.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve Original Filename
        |--------------------------------------------------------------------------
        */

        $newOriginalFileName = basename(
            str_replace(
                '\\',
                '/',
                $uploadedOriginalName
            )
        );

        if (
            $newOriginalFileName === '' ||
            mb_strlen($newOriginalFileName, 'UTF-8') > 255
        ) {
            respond(
                422,
                false,
                'The uploaded filename is invalid or too long.'
            );
        }

        if ($uploadedSize <= 0) {
            respond(
                422,
                false,
                'The uploaded file is empty.'
            );
        }

        if ($uploadedSize > $maxFileSize) {
            respond(
                422,
                false,
                'The maximum file size is 20 MB.'
            );
        }

        $extension = strtolower(
            pathinfo(
                $newOriginalFileName,
                PATHINFO_EXTENSION
            )
        );

        if (
            !in_array(
                $extension,
                $allowedExtensions,
                true
            )
        ) {
            respond(
                422,
                false,
                'This file type is not allowed.'
            );
        }

        if (!class_exists('finfo')) {
            respond(
                500,
                false,
                'The PHP fileinfo extension is required.'
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $detectedMimeType = (string) (
            $finfo->file($uploadedTmpName) ?: ''
        );

        if ($detectedMimeType === '') {
            respond(
                422,
                false,
                'Could not determine the uploaded file type.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Safe Physical Filename
        |--------------------------------------------------------------------------
        */

        try {
            $newFileName =
                date('YmdHis') .
                '_' .
                $teacherUserId .
                '_' .
                bin2hex(random_bytes(16)) .
                '.' .
                $extension;
        } catch (Throwable $e) {
            respond(
                500,
                false,
                'Could not generate a safe file name.'
            );
        }

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
            'materials';

        if (!is_dir($uploadDirectory)) {
            if (
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                respond(
                    500,
                    false,
                    'The upload directory could not be created.'
                );
            }
        }

        if (!is_writable($uploadDirectory)) {
            respond(
                500,
                false,
                'The materials upload directory is not writable.'
            );
        }

        $newPhysicalFilePath =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $newFileName;

        $newDatabaseFilePath =
            'uploads/materials/' . $newFileName;

        if (
            !move_uploaded_file(
                $uploadedTmpName,
                $newPhysicalFilePath
            )
        ) {
            respond(
                500,
                false,
                'The uploaded file could not be saved on the server.'
            );
        }

        $newFileUploaded = true;
        $newFileType = $detectedMimeType;
        $newFileSize = $uploadedSize;
    }
}

/*
|--------------------------------------------------------------------------
| Prepare New Material Values
|--------------------------------------------------------------------------
*/

$newGradeSubjectId = (int) (
    $assignment['grade_subject_id']
);

$databaseFileName = (string) (
    $material['file_name'] ?? ''
);

$databaseFilePath = (string) (
    $material['file_path'] ?? ''
);

$databaseFileType = (string) (
    $material['file_type'] ?? ''
);

$databaseFileSize = isset($material['file_size'])
    ? (int) $material['file_size']
    : null;

if ($newFileUploaded) {
    /*
     * Store the original filename for display.
     * Store the generated filename only in file_path.
     */
    $databaseFileName = $newOriginalFileName;
    $databaseFilePath = $newDatabaseFilePath;
    $databaseFileType = $newFileType;
    $databaseFileSize = $newFileSize;
}

/*
|--------------------------------------------------------------------------
| Update Material
|--------------------------------------------------------------------------
*/

if ($newFileUploaded) {
    $stmt = $conn->prepare("
        UPDATE teacher_materials
        SET
            grade_subject_id = ?,
            title = ?,
            description = ?,
            file_name = ?,
            file_path = ?,
            file_type = ?,
            file_size = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND teacher_user_id = ?
          AND is_active = 1
        LIMIT 1
    ");

    if (!$stmt) {
        @unlink($newPhysicalFilePath);

        respond(
            500,
            false,
            'Could not prepare the material update.'
        );
    }

    $stmt->bind_param(
        'isssssiii',
        $newGradeSubjectId,
        $title,
        $description,
        $databaseFileName,
        $databaseFilePath,
        $databaseFileType,
        $databaseFileSize,
        $materialId,
        $teacherUserId
    );
} else {
    $stmt = $conn->prepare("
        UPDATE teacher_materials
        SET
            grade_subject_id = ?,
            title = ?,
            description = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND teacher_user_id = ?
          AND is_active = 1
        LIMIT 1
    ");

    if (!$stmt) {
        respond(
            500,
            false,
            'Could not prepare the material update.'
        );
    }

    $stmt->bind_param(
        'issii',
        $newGradeSubjectId,
        $title,
        $description,
        $materialId,
        $teacherUserId
    );
}

$updated = $stmt->execute();

$stmt->close();

if (!$updated) {
    if (
        $newFileUploaded &&
        $newPhysicalFilePath !== '' &&
        is_file($newPhysicalFilePath)
    ) {
        @unlink($newPhysicalFilePath);
    }

    respond(
        500,
        false,
        'The material could not be updated. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| Safely Delete Old File After Successful Replacement
|--------------------------------------------------------------------------
*/

if ($newFileUploaded) {
    $oldPath = str_replace(
        '\\',
        '/',
        trim((string) ($material['file_path'] ?? ''))
    );

    $oldPath = ltrim($oldPath, '/');

    $oldPathIsValid =
        str_starts_with(
            strtolower($oldPath),
            'uploads/materials/'
        ) &&
        !str_contains($oldPath, '../') &&
        !str_contains($oldPath, '..\\');

    if ($oldPathIsValid) {
        $materialsDirectory = realpath(
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            'uploads' .
            DIRECTORY_SEPARATOR .
            'materials'
        );

        $oldPhysicalPath =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $oldPath
            );

        $oldRealPath = realpath($oldPhysicalPath);
        $newRealPath = realpath($newPhysicalFilePath);

        if (
            $materialsDirectory !== false &&
            $oldRealPath !== false &&
            is_file($oldRealPath) &&
            $newRealPath !== false &&
            strtolower($oldRealPath) !== strtolower($newRealPath) &&
            str_starts_with(
                strtolower($oldRealPath),
                strtolower(
                    $materialsDirectory .
                    DIRECTORY_SEPARATOR
                )
            )
        ) {
            @unlink($oldRealPath);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success Response
|--------------------------------------------------------------------------
*/

respond(
    200,
    true,
    'Material updated successfully.',
    [
        'material_id' => (int) $materialId,
        'teacher_user_id' => $teacherUserId,
        'grade_subject_id' => $newGradeSubjectId,
        'assignment_id' => (int) $assignment['id'],
        'grade' => (int) $assignment['grade'],
        'section' => (string) $assignment['section'],
        'subject_name' => (string) $assignment['subject_name'],
        'academic_year' => (string) $material['academic_year'],
        'title' => $title,
        'description' => $description,
        'file_name' => $databaseFileName,
        'file_path' => $databaseFilePath,
        'file_type' => $databaseFileType,
        'file_size' => $databaseFileSize,
        'file_replaced' => $newFileUploaded
    ]
);
