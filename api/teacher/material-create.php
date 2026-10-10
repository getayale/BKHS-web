<?php

declare(strict_types=1);

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
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| Handle Preflight
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/

function respond(
    int $statusCode,
    bool $success,
    string $message,
    array $data = []
): void {
    http_response_code($statusCode);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => (object) $data
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Validate Request Method
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
| Includes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Authenticate Teacher
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authorizationHeader = trim(
        (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    );
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();

    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authorizationHeader = trim((string) $value);
            break;
        }
    }
}

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {
    respond(
        401,
        false,
        'Authorization token is missing or invalid.'
    );
}

$rawToken = trim((string) $matches[1]);

if ($rawToken === '') {
    respond(
        401,
        false,
        'Authorization token is missing or invalid.'
    );
}

$tokenHash = hash('sha256', $rawToken);

$stmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.role,
        u.is_deleted
    FROM api_tokens AS at
    INNER JOIN users AS u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Unable to authenticate the teacher.'
    );
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();

$result = $stmt->get_result();
$tokenUser = $result->fetch_assoc();

$stmt->close();

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'teacher'
) {
    respond(
        401,
        false,
        'Invalid or expired token, or teacher access is not allowed.'
    );
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    respond(
        500,
        false,
        'Unable to retrieve the active academic year.'
    );
}

$stmt->execute();

$result = $stmt->get_result();
$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    respond(
        400,
        false,
        'There is no active academic year.'
    );
}

$activeAcademicYear = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Read Form Fields
|--------------------------------------------------------------------------
*/

$assignmentId = filter_var(
    $_POST['assignment_id'] ?? null,
    FILTER_VALIDATE_INT
);

$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

/*
|--------------------------------------------------------------------------
| Validate Form Fields
|--------------------------------------------------------------------------
*/

if (
    $assignmentId === false ||
    $assignmentId === null ||
    $assignmentId < 1
) {
    respond(
        422,
        false,
        'Please select a valid class and subject.'
    );
}

if ($title === '') {
    respond(
        422,
        false,
        'Please enter the material title.'
    );
}

if (
    !function_exists('mb_strlen') ||
    mb_strlen($title, 'UTF-8') > 255
) {
    if (!function_exists('mb_strlen')) {
        respond(
            500,
            false,
            'The server is missing the required PHP mbstring extension.'
        );
    }

    respond(
        422,
        false,
        'The material title must not exceed 255 characters.'
    );
}

/*
|--------------------------------------------------------------------------
| Verify Teacher Assignment
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        sta.id AS assignment_id,
        sta.grade,
        sta.section,
        sta.academic_year,
        gs.id AS grade_subject_id,
        gs.subject_name
    FROM subject_teacher_assignments AS sta
    INNER JOIN grade_subjects AS gs
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
        'Unable to verify the selected class and subject.'
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
        403,
        false,
        'The selected class and subject assignment is not valid for this teacher.'
    );
}

$gradeSubjectId = (int) $assignment['grade_subject_id'];

/*
|--------------------------------------------------------------------------
| Validate Uploaded File
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['material_file']) ||
    !is_array($_FILES['material_file'])
) {
    respond(
        422,
        false,
        'Please select a file.'
    );
}

$uploadedFile = $_FILES['material_file'];

$uploadErrorCode = (int) (
    $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE
);

if ($uploadErrorCode !== UPLOAD_ERR_OK) {
    switch ($uploadErrorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            respond(
                413,
                false,
                'The selected file is too large.'
            );

        case UPLOAD_ERR_PARTIAL:
            respond(
                400,
                false,
                'The file upload was incomplete.'
            );

        case UPLOAD_ERR_NO_FILE:
            respond(
                422,
                false,
                'Please select a file.'
            );

        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            respond(
                500,
                false,
                'The server could not process the uploaded file.'
            );

        default:
            respond(
                400,
                false,
                'The file could not be uploaded.'
            );
    }
}

$tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
$originalName = (string) ($uploadedFile['name'] ?? '');
$fileSize = (int) ($uploadedFile['size'] ?? 0);

if (
    $tmpName === '' ||
    !is_uploaded_file($tmpName)
) {
    respond(
        400,
        false,
        'Invalid uploaded file.'
    );
}

if ($originalName === '') {
    respond(
        422,
        false,
        'The uploaded file has an invalid filename.'
    );
}

$extension = strtolower(
    pathinfo($originalName, PATHINFO_EXTENSION)
);

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

if (!in_array($extension, $allowedExtensions, true)) {
    respond(
        422,
        false,
        'File type not allowed. Allowed types: PDF, Word, PowerPoint, Excel, images and ZIP.'
    );
}

$maxFileSize = 20 * 1024 * 1024;

if ($fileSize <= 0) {
    respond(
        422,
        false,
        'The uploaded file is empty.'
    );
}

if ($fileSize > $maxFileSize) {
    respond(
        413,
        false,
        'The maximum allowed file size is 20 MB.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect File MIME Type
|--------------------------------------------------------------------------
*/

$fileType = 'application/octet-stream';

if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo) {
        $detectedType = finfo_file($finfo, $tmpName);

        if ($detectedType !== false) {
            $fileType = (string) $detectedType;
        }

        finfo_close($finfo);
    }
}

/*
|--------------------------------------------------------------------------
| Create Materials Directory
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
            'The materials upload directory could not be created.'
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

/*
|--------------------------------------------------------------------------
| Generate Unique Filename
|--------------------------------------------------------------------------
*/

try {
    $safeFileName =
        date('YmdHis') .
        '_' .
        $teacherUserId .
        '_' .
        bin2hex(random_bytes(12)) .
        '.' .
        $extension;
} catch (Throwable $e) {
    respond(
        500,
        false,
        'Unable to generate a secure filename.'
    );
}

$destinationPath =
    $uploadDirectory .
    DIRECTORY_SEPARATOR .
    $safeFileName;

$databaseFilePath = 'uploads/materials/' . $safeFileName;

/*
|--------------------------------------------------------------------------
| Move Uploaded File
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpName, $destinationPath)) {
    respond(
        500,
        false,
        'The file could not be saved to the materials folder.'
    );
}

if (!is_file($destinationPath)) {
    @unlink($destinationPath);

    respond(
        500,
        false,
        'The server could not verify the saved file.'
    );
}

/*
|--------------------------------------------------------------------------
| Insert Material Into Database
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    INSERT INTO teacher_materials (
        teacher_user_id,
        grade_subject_id,
        academic_year,
        title,
        description,
        file_name,
        file_path,
        file_type,
        file_size,
        is_active
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
");

if (!$stmt) {
    @unlink($destinationPath);

    respond(
        500,
        false,
        'The material could not be saved to the database.'
    );
}

$stmt->bind_param(
    'iissssssi',
    $teacherUserId,
    $gradeSubjectId,
    $activeAcademicYear,
    $title,
    $description,
    $originalName,
    $databaseFilePath,
    $fileType,
    $fileSize
);

if (!$stmt->execute()) {
    $stmt->close();
    @unlink($destinationPath);

    respond(
        500,
        false,
        'The material could not be saved to the database.'
    );
}

$materialId = (int) $stmt->insert_id;

$stmt->close();

/*
|--------------------------------------------------------------------------
| Success Response
|--------------------------------------------------------------------------
*/

respond(
    201,
    true,
    'Material uploaded successfully.',
    [
        'material_id' => $materialId,
        'teacher_user_id' => $teacherUserId,
        'assignment_id' => (int) $assignment['assignment_id'],
        'grade_subject_id' => $gradeSubjectId,
        'grade' => (int) $assignment['grade'],
        'section' => (string) $assignment['section'],
        'subject_name' => (string) $assignment['subject_name'],
        'academic_year' => $activeAcademicYear,
        'title' => $title,
        'description' => $description,
        'file_name' => $originalName,
        'file_path' => $databaseFilePath,
        'file_type' => $fileType,
        'file_size' => $fileSize,
        'is_active' => 1
    ]
);
