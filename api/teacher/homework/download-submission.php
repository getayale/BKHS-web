<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/localhost(?::\d+)?$/',
        $origin
    )
) {
    header(
        'Access-Control-Allow-Origin: ' . $origin
    );

    header(
        'Access-Control-Allow-Headers: Content-Type, Authorization'
    );

    header(
        'Access-Control-Allow-Methods: GET, OPTIONS'
    );

    header(
        'Access-Control-Expose-Headers: Content-Disposition, Content-Length, Content-Type'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => true
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Only GET is allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    http_response_code(405);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Authorization Header
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {

    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );

} elseif (function_exists('getallheaders')) {

    $headers = getallheaders();

    foreach ($headers as $name => $value) {

        if (strtolower($name) === 'authorization') {

            $authorizationHeader = trim(
                (string) $value
            );

            break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Validate Bearer Token
|--------------------------------------------------------------------------
*/

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {

    http_response_code(401);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {

    http_response_code(401);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Hash API Token
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $rawToken
);

/*
|--------------------------------------------------------------------------
| Validate API Token
|--------------------------------------------------------------------------
*/

$tokenSql = "
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

$tokenStmt = $conn->prepare(
    $tokenSql
);

if (!$tokenStmt) {

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

$tokenStmt->execute();

$tokenResult = $tokenStmt->get_result();

$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

/*
|--------------------------------------------------------------------------
| Require Teacher Role
|--------------------------------------------------------------------------
*/

if (
    !$tokenUser ||
    strtolower(
        (string) $tokenUser['role']
    ) !== 'teacher'
) {

    http_response_code(401);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Submission ID
|--------------------------------------------------------------------------
*/

$submissionId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($submissionId <= 0) {

    http_response_code(422);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'A valid submission ID is required.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Submission
|--------------------------------------------------------------------------
|
| Only the teacher who owns the homework can download
| the student's submission.
|
*/

$submissionSql = "
    SELECT
        hs.id,
        hs.homework_id,
        hs.student_id,
        hs.file_path,
        hs.original_file_name,
        hs.file_type,
        hs.file_size,
        hs.submitted_at,
        h.teacher_user_id,
        h.title AS homework_title,
        s.full_name AS student_name,
        s.student_code
    FROM homework_submissions AS hs
    INNER JOIN homeworks AS h
        ON h.id = hs.homework_id
    INNER JOIN students AS s
        ON s.id = hs.student_id
    WHERE hs.id = ?
      AND h.teacher_user_id = ?
      AND s.is_deleted = 0
    LIMIT 1
";

$submissionStmt = $conn->prepare(
    $submissionSql
);

if (!$submissionStmt) {

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$submissionStmt->bind_param(
    'ii',
    $submissionId,
    $teacherUserId
);

$submissionStmt->execute();

$submissionResult =
    $submissionStmt->get_result();

$submission =
    $submissionResult->fetch_assoc();

$submissionStmt->close();

/*
|--------------------------------------------------------------------------
| Submission Not Found
|--------------------------------------------------------------------------
*/

if (!$submission) {

    http_response_code(404);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' =>
            'Submission was not found or you do not have permission to download it.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Stored File Path
|--------------------------------------------------------------------------
*/

$filePath = trim(
    (string) $submission['file_path']
);

if ($filePath === '') {

    http_response_code(404);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'The submitted file was not found.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Project Root
|--------------------------------------------------------------------------
|
| Current file:
|
| BKHS/api/teacher/homework/download-submission.php
|
| dirname(__DIR__, 3):
|
| BKHS
|
*/

$projectRoot = realpath(
    dirname(__DIR__, 3)
);

if (
    $projectRoot === false ||
    !is_dir($projectRoot)
) {

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'The project directory was not found.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Student Submission Directory
|--------------------------------------------------------------------------
*/

$submissionDirectory = realpath(
    $projectRoot
    . DIRECTORY_SEPARATOR
    . 'uploads'
    . DIRECTORY_SEPARATOR
    . 'homework'
    . DIRECTORY_SEPARATOR
    . 'submissions'
);

if (
    $submissionDirectory === false ||
    !is_dir($submissionDirectory)
) {

    http_response_code(404);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'The submission directory was not found.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Normalize Relative File Path
|--------------------------------------------------------------------------
*/

$relativeFilePath = ltrim(
    str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        $filePath
    ),
    DIRECTORY_SEPARATOR
);

/*
|--------------------------------------------------------------------------
| Build Physical File Path
|--------------------------------------------------------------------------
*/

$physicalFilePath = realpath(
    $projectRoot
    . DIRECTORY_SEPARATOR
    . $relativeFilePath
);

if (
    $physicalFilePath === false ||
    !is_file($physicalFilePath)
) {

    http_response_code(404);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'The submitted file could not be found.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Security Check
|--------------------------------------------------------------------------
|
| Make sure the requested file is actually inside:
|
| BKHS/uploads/homework/submissions/
|
*/

$submissionDirectoryWithSeparator =
    rtrim(
        $submissionDirectory,
        DIRECTORY_SEPARATOR
    )
    . DIRECTORY_SEPARATOR;

if (
    strncmp(
        $physicalFilePath,
        $submissionDirectoryWithSeparator,
        strlen($submissionDirectoryWithSeparator)
    ) !== 0
) {

    http_response_code(403);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Access to this file is not allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get File Size
|--------------------------------------------------------------------------
*/

$fileSize = filesize(
    $physicalFilePath
);

if ($fileSize === false) {

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to read the submitted file.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Detect MIME Type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(
    FILEINFO_MIME_TYPE
);

$mimeType = $finfo->file(
    $physicalFilePath
);

if (
    $mimeType === false ||
    $mimeType === ''
) {

    $mimeType =
        'application/octet-stream';
}

/*
|--------------------------------------------------------------------------
| Original Filename
|--------------------------------------------------------------------------
*/

$originalFileName = trim(
    (string) $submission['original_file_name']
);

if ($originalFileName === '') {

    $originalFileName =
        basename($physicalFilePath);
}

/*
|--------------------------------------------------------------------------
| Sanitize Filename
|--------------------------------------------------------------------------
*/

$originalFileName = str_replace(
    [
        "\r",
        "\n",
        '"'
    ],
    '',
    $originalFileName
);

if ($originalFileName === '') {

    $originalFileName =
        'submitted_material';
}

/*
|--------------------------------------------------------------------------
| Clear Output Buffers
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {
    ob_end_clean();
}

/*
|--------------------------------------------------------------------------
| Download Headers
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: ' . $mimeType
);

header(
    'Content-Length: ' . $fileSize
);

header(
    'Content-Disposition: attachment; filename="' .
    $originalFileName .
    '"'
);

header(
    'Content-Transfer-Encoding: binary'
);

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'Cache-Control: private, no-store, max-age=0'
);

/*
|--------------------------------------------------------------------------
| Send File
|--------------------------------------------------------------------------
*/

readfile(
    $physicalFilePath
);

exit;