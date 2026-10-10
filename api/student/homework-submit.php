<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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
        '/^https?:\/\/localhost(?::\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Only POST is allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

function jsonResponse(
    int $statusCode,
    array $data
): never {
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization header
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

        foreach ($headers as $name => $value) {

            if (
                strtolower((string) $name)
                === 'authorization'
            ) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Get Bearer token
|--------------------------------------------------------------------------
*/

$authorization = getAuthorizationHeader();

if (
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorization,
        $matches
    )
) {
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Authentication token is required.',
        ]
    );
}

$token = trim((string) $matches[1]);

if ($token === '') {
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Authentication token is required.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Validate API token
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $token
);

$sql = "
    SELECT
        at.user_id,
        u.role,
        u.is_deleted

    FROM api_tokens AS at

    INNER JOIN users AS u
        ON u.id = at.user_id

    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Authentication service is unavailable.',
        ]
    );
}

$stmt->bind_param(
    's',
    $tokenHash
);

if (!$stmt->execute()) {
    $stmt->close();

    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Authentication service is unavailable.',
        ]
    );
}

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Invalid or expired authentication token.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Student role
|--------------------------------------------------------------------------
*/

if (
    strtolower((string) $user['role'])
    !== 'student'
) {
    jsonResponse(
        403,
        [
            'success' => false,
            'message' => 'Only students can submit homework.',
        ]
    );
}

$userId = (int) $user['user_id'];

/*
|--------------------------------------------------------------------------
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = filter_var(
    $_POST['homework_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$homeworkId || $homeworkId <= 0) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'Invalid homework.',
        ]
    );
}

$homeworkId = (int) $homeworkId;

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['file']) ||
    !is_array($_FILES['file'])
) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'Please select a file to submit.',
        ]
    );
}

$file = $_FILES['file'];

/*
|--------------------------------------------------------------------------
| Validate upload error
|--------------------------------------------------------------------------
*/

if (
    !isset($file['error']) ||
    is_array($file['error'])
) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'Invalid upload.',
        ]
    );
}

if ((int) $file['error'] !== UPLOAD_ERR_OK) {

    $message = match ((int) $file['error']) {

        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded file is too large.',

        UPLOAD_ERR_PARTIAL =>
            'The file upload was incomplete.',

        UPLOAD_ERR_NO_FILE =>
            'Please select a file.',

        default =>
            'The file could not be uploaded.',
    };

    jsonResponse(
        400,
        [
            'success' => false,
            'message' => $message,
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Validate file size
|--------------------------------------------------------------------------
*/

$maxSize = 10 * 1024 * 1024;

$fileSize = (int) ($file['size'] ?? 0);

if ($fileSize > $maxSize) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'File size must not exceed 10 MB.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Validate original filename
|--------------------------------------------------------------------------
*/

$originalName = basename(
    (string) ($file['name'] ?? '')
);

$extension = strtolower(
    pathinfo(
        $originalName,
        PATHINFO_EXTENSION
    )
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
    'zip',
];

if (
    !in_array(
        $extension,
        $allowedExtensions,
        true
    )
) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'This file type is not allowed.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Validate temporary uploaded file
|--------------------------------------------------------------------------
*/

$tmpPath = (string) (
    $file['tmp_name'] ?? ''
);

if (
    $tmpPath === '' ||
    !is_uploaded_file($tmpPath)
) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' => 'Invalid uploaded file.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Validate MIME type
|--------------------------------------------------------------------------
*/

$allowedMimes = [
    'pdf' => [
        'application/pdf',
    ],

    'doc' => [
        'application/msword',
    ],

    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ],

    'ppt' => [
        'application/vnd.ms-powerpoint',
    ],

    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
    ],

    'xls' => [
        'application/vnd.ms-excel',
    ],

    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ],

    'jpg' => [
        'image/jpeg',
    ],

    'jpeg' => [
        'image/jpeg',
    ],

    'png' => [
        'image/png',
    ],

    'zip' => [
        'application/zip',
        'application/x-zip-compressed',
    ],
];

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mime = $finfo->file($tmpPath);

if (
    !isset($allowedMimes[$extension]) ||
    !in_array(
        $mime,
        $allowedMimes[$extension],
        true
    )
) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' =>
                'The uploaded file content does not match its file type.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Get active student registration
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year,
        ay.status AS academic_year_status

    FROM students AS s

    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id

    INNER JOIN grades AS g
        ON g.id = sr.grade_id

    INNER JOIN sections AS sec
        ON sec.id = sr.section_id

    INNER JOIN academic_years AS ay
        ON ay.id = sr.academic_year_id

    INNER JOIN users AS u
        ON u.id = s.user_id

    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'
      AND ay.status = 'Active'

    ORDER BY sr.id DESC

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Unable to verify your student registration.',
        ]
    );
}

$stmt->bind_param(
    'i',
    $userId
);

if (!$stmt->execute()) {
    $stmt->close();

    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Unable to verify your student registration.',
        ]
    );
}

$result = $stmt->get_result();

$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    jsonResponse(
        404,
        [
            'success' => false,
            'message' =>
                'Your active student registration could not be found.',
        ]
    );
}

$studentId = (int) $student['student_id'];

$academicYear = (string) $student['academic_year'];

$grade = (int) $student['grade_number'];

$section = (string) $student['section'];

/*
|--------------------------------------------------------------------------
| Get homework and verify student's class
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        h.id,
        h.academic_year,
        h.teacher_user_id,
        h.grade,
        h.section,
        h.grade_subject_id,
        h.title,
        h.description,
        h.teacher_material_path,
        h.teacher_material_original_name,
        h.assigned_date,
        h.due_date,
        h.status,
        gs.subject_name,
        u.full_name AS teacher_name

    FROM homeworks AS h

    INNER JOIN grade_subjects AS gs
        ON gs.id = h.grade_subject_id

    INNER JOIN users AS u
        ON u.id = h.teacher_user_id

    WHERE h.id = ?
      AND TRIM(h.academic_year) = TRIM(?)
      AND h.grade = ?
      AND h.section = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Unable to load homework.',
        ]
    );
}

$stmt->bind_param(
    'isis',
    $homeworkId,
    $academicYear,
    $grade,
    $section
);

if (!$stmt->execute()) {
    $stmt->close();

    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Unable to load homework.',
        ]
    );
}

$result = $stmt->get_result();

$homework = $result->fetch_assoc();

$stmt->close();

if (!$homework) {
    jsonResponse(
        404,
        [
            'success' => false,
            'message' =>
                'Homework not found or it does not belong to your class.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Check homework status
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$dueDate = (string) $homework['due_date'];

$isClosed = (
    (string) $homework['status'] === 'Closed'
);

$isPastDue = (
    $dueDate < $today
);

if ($isClosed) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' =>
                'This homework has been closed by the teacher.',
        ]
    );
}

if ($isPastDue) {
    jsonResponse(
        400,
        [
            'success' => false,
            'message' =>
                'The submission deadline has passed.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Get existing submission
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        homework_id,
        student_id,
        file_path,
        original_file_name,
        file_type,
        file_size,
        submitted_at,
        updated_at

    FROM homework_submissions

    WHERE homework_id = ?
      AND student_id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Unable to check your existing submission.',
        ]
    );
}

$stmt->bind_param(
    'ii',
    $homeworkId,
    $studentId
);

if (!$stmt->execute()) {
    $stmt->close();

    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Unable to check your existing submission.',
        ]
    );
}

$result = $stmt->get_result();

$existingSubmission = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Prepare upload directory
|--------------------------------------------------------------------------
*/

$uploadDirectory =
    dirname(__DIR__, 2)
    . '/uploads/homework/submissions';

if (!is_dir($uploadDirectory)) {

    if (
        !mkdir(
            $uploadDirectory,
            0755,
            true
        ) &&
        !is_dir($uploadDirectory)
    ) {
        jsonResponse(
            500,
            [
                'success' => false,
                'message' =>
                    'Could not create the homework submission directory.',
            ]
        );
    }
}

/*
|--------------------------------------------------------------------------
| Generate safe stored filename
|--------------------------------------------------------------------------
*/

$filename = sprintf(
    'student_%d_homework_%d_%s.%s',
    $studentId,
    $homeworkId,
    bin2hex(random_bytes(8)),
    $extension
);

$destination =
    $uploadDirectory
    . DIRECTORY_SEPARATOR
    . $filename;

$newFilePath =
    'uploads/homework/submissions/'
    . $filename;

$oldFilePath = null;

/*
|--------------------------------------------------------------------------
| Move uploaded file
|--------------------------------------------------------------------------
*/

if (
    !move_uploaded_file(
        $tmpPath,
        $destination
    )
) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'The uploaded file could not be saved.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Save database record
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    if ($existingSubmission) {

        $oldFilePath = (string) (
            $existingSubmission['file_path'] ?? ''
        );

        $sql = "
            UPDATE homework_submissions

            SET
                file_path = ?,
                original_file_name = ?,
                file_type = ?,
                file_size = ?,
                submitted_at = NOW()

            WHERE homework_id = ?
              AND student_id = ?
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Failed to prepare submission update.'
            );
        }

        $stmt->bind_param(
            'sssiii',
            $newFilePath,
            $originalName,
            $mime,
            $fileSize,
            $homeworkId,
            $studentId
        );

        if (!$stmt->execute()) {
            $stmt->close();

            throw new RuntimeException(
                'Failed to update homework submission.'
            );
        }

        $stmt->close();

    } else {

        $sql = "
            INSERT INTO homework_submissions (
                homework_id,
                student_id,
                file_path,
                original_file_name,
                file_type,
                file_size,
                submitted_at
            )

            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Failed to prepare submission insert.'
            );
        }

        $stmt->bind_param(
            'iisssi',
            $homeworkId,
            $studentId,
            $newFilePath,
            $originalName,
            $mime,
            $fileSize
        );

        if (!$stmt->execute()) {
            $stmt->close();

            throw new RuntimeException(
                'Failed to save homework submission.'
            );
        }

        $stmt->close();
    }

    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    if (is_file($destination)) {
        @unlink($destination);
    }

    jsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'The homework submission could not be saved.',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Delete old physical file after successful DB update
|--------------------------------------------------------------------------
*/

if (
    $oldFilePath !== null &&
    $oldFilePath !== '' &&
    $oldFilePath !== $newFilePath
) {

    $oldFilePath = ltrim(
        $oldFilePath,
        '/\\'
    );

    $prefix =
        'uploads/homework/submissions/';

    if (
        str_starts_with(
            $oldFilePath,
            $prefix
        )
    ) {

        $oldPhysicalPath =
            dirname(__DIR__, 2)
            . '/'
            . $oldFilePath;

        if (is_file($oldPhysicalPath)) {
            @unlink($oldPhysicalPath);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Get saved submission
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        homework_id,
        student_id,
        file_path,
        original_file_name,
        file_type,
        file_size,
        submitted_at,
        updated_at

    FROM homework_submissions

    WHERE homework_id = ?
      AND student_id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(
        500,
        [
            'success' => true,
            'message' =>
                $existingSubmission
                    ? 'Your homework submission was replaced successfully.'
                    : 'Your homework was submitted successfully.',
            'homework_id' => $homeworkId,
            'is_submitted' => true,
        ]
    );
}

$stmt->bind_param(
    'ii',
    $homeworkId,
    $studentId
);

if (!$stmt->execute()) {
    $stmt->close();

    jsonResponse(
        200,
        [
            'success' => true,
            'message' =>
                $existingSubmission
                    ? 'Your homework submission was replaced successfully.'
                    : 'Your homework was submitted successfully.',
            'homework_id' => $homeworkId,
            'is_submitted' => true,
        ]
    );
}

$result = $stmt->get_result();

$submission = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

jsonResponse(
    200,
    [
        'success' => true,

        'message' =>
            $existingSubmission
                ? 'Your homework submission was replaced successfully.'
                : 'Your homework was submitted successfully.',

        'homework_id' => $homeworkId,

        'is_submitted' => true,

        'submission' => $submission
            ? [
                'id' => (int) $submission['id'],
                'homework_id' => (int) $submission['homework_id'],
                'student_id' => (int) $submission['student_id'],
                'file_path' => (string) $submission['file_path'],
                'original_file_name' =>
                    (string) $submission['original_file_name'],
                'file_type' =>
                    (string) $submission['file_type'],
                'file_size' =>
                    (int) $submission['file_size'],
                'submitted_at' =>
                    (string) $submission['submitted_at'],
                'updated_at' =>
                    $submission['updated_at'] !== null
                        ? (string) $submission['updated_at']
                        : null,
            ]
            : null,
    ]
);
