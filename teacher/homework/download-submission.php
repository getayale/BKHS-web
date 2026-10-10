<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    http_response_code(403);
    exit('Access denied.');
}

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Get submission ID
|--------------------------------------------------------------------------
*/

$submissionId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $submissionId === false ||
    $submissionId === null ||
    $submissionId <= 0
) {
    http_response_code(400);
    exit('Invalid submission ID.');
}

/*
|--------------------------------------------------------------------------
| Get submission
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        hs.id,
        hs.homework_id,
        hs.student_id,
        hs.file_path,
        hs.original_file_name,
        hs.file_type,
        hs.file_size,
        h.teacher_user_id,
        h.status AS homework_status
    FROM homework_submissions AS hs
    INNER JOIN homeworks AS h
        ON h.id = hs.homework_id
    WHERE hs.id = ?
      AND h.teacher_user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    http_response_code(500);
    exit('Unable to prepare the request.');
}

$stmt->bind_param(
    'ii',
    $submissionId,
    $teacherUserId
);

$stmt->execute();

$result = $stmt->get_result();

$submission = $result->fetch_assoc();

$stmt->close();

if ($submission === null) {
    http_response_code(404);
    exit('Submission not found or access denied.');
}

/*
|--------------------------------------------------------------------------
| Get file path
|--------------------------------------------------------------------------
*/

$relativePath = trim(
    (string) ($submission['file_path'] ?? '')
);

if ($relativePath === '') {
    http_response_code(404);
    exit('Submitted file was not found.');
}

/*
|--------------------------------------------------------------------------
| Build absolute file path
|--------------------------------------------------------------------------
*/

$baseDirectory = realpath(
    dirname(__DIR__, 2)
);

if ($baseDirectory === false) {
    http_response_code(500);
    exit('Unable to locate application directory.');
}

$filePath = realpath(
    $baseDirectory . DIRECTORY_SEPARATOR . $relativePath
);

$uploadDirectory = realpath(
    $baseDirectory .
    DIRECTORY_SEPARATOR .
    'uploads' .
    DIRECTORY_SEPARATOR .
    'homework' .
    DIRECTORY_SEPARATOR .
    'submissions'
);

if (
    $filePath === false ||
    $uploadDirectory === false
) {
    http_response_code(404);
    exit('Submitted file was not found.');
}

/*
|--------------------------------------------------------------------------
| Security check
|--------------------------------------------------------------------------
*/

$uploadDirectoryPrefix =
    rtrim($uploadDirectory, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR;

if (
    strpos(
        $filePath,
        $uploadDirectoryPrefix
    ) !== 0
) {
    http_response_code(403);
    exit('Invalid file location.');
}

if (!is_file($filePath)) {
    http_response_code(404);
    exit('Submitted file was not found.');
}

/*
|--------------------------------------------------------------------------
| Get MIME type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($filePath);

if (
    $mimeType === false ||
    $mimeType === ''
) {
    $mimeType = 'application/octet-stream';
}

/*
|--------------------------------------------------------------------------
| File name
|--------------------------------------------------------------------------
*/

$originalFileName = trim(
    (string) (
        $submission['original_file_name']
        ?? basename($filePath)
    )
);

if ($originalFileName === '') {
    $originalFileName = basename($filePath);
}

$downloadFileName = str_replace(
    [
        "\r",
        "\n",
        '"'
    ],
    '',
    $originalFileName
);

if ($downloadFileName === '') {
    $downloadFileName = basename($filePath);
}

/*
|--------------------------------------------------------------------------
| Openable file types
|--------------------------------------------------------------------------
*/

$openableTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'text/plain',
];

$disposition = in_array(
    strtolower($mimeType),
    $openableTypes,
    true
)
    ? 'inline'
    : 'attachment';

/*
|--------------------------------------------------------------------------
| Send file
|--------------------------------------------------------------------------
*/

$fileSize = filesize($filePath);

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to determine file size.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header(
    'Content-Type: ' . $mimeType
);

header(
    'Content-Length: ' . $fileSize
);

header(
    'Content-Disposition: ' .
    $disposition .
    '; filename="' .
    $downloadFileName .
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

readfile($filePath);

exit;