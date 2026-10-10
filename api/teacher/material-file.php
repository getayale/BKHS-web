<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Addis_Ababa');

/*
|--------------------------------------------------------------------------
| CORS Configuration
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/localhost:\d+$/',
        $origin
    ) ||
    preg_match(
        '/^https?:\/\/127\.0\.0\.1:\d+$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Error Response
|--------------------------------------------------------------------------
*/

function failRequest(int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    if ($status === 400) {
        $message = 'A valid material ID is required.';
    } elseif ($status === 401) {
        $message = 'Unauthorized.';
    } elseif ($status === 404) {
        $message = 'Material or file not found.';
    } else {
        $message = 'Unable to serve the file.';
    }

    echo json_encode(
        [
            'success' => false,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

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

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {
    failRequest(401);
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    failRequest(401);
}

/*
|--------------------------------------------------------------------------
| Verify Token
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $rawToken
);

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

$tokenStmt = $conn->prepare($tokenSql);

if (!$tokenStmt) {
    failRequest(500);
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

$tokenStmt->execute();

$tokenResult = $tokenStmt->get_result();

$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'teacher'
) {
    failRequest(401);
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Validate Material ID
|--------------------------------------------------------------------------
*/

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$materialId || $materialId < 1) {
    failRequest(400);
}

/*
|--------------------------------------------------------------------------
| Get Material
|--------------------------------------------------------------------------
*/

$materialSql = "
    SELECT
        id,
        file_name,
        file_path
    FROM teacher_materials
    WHERE id = ?
      AND teacher_user_id = ?
      AND is_active = 1
    LIMIT 1
";

$stmt = $conn->prepare($materialSql);

if (!$stmt) {
    failRequest(500);
}

$stmt->bind_param(
    'ii',
    $materialId,
    $teacherUserId
);

$stmt->execute();

$material = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$material) {
    failRequest(404);
}

/*
|--------------------------------------------------------------------------
| Resolve Physical File Path
|--------------------------------------------------------------------------
*/

$filePath = ltrim(
    str_replace(
        '\\',
        '/',
        (string) $material['file_path']
    ),
    '/'
);

$projectRoot = realpath(
    dirname(__DIR__, 2)
);

$uploadRoot = realpath(
    dirname(__DIR__, 2) . '/uploads/materials'
);

if (
    $projectRoot === false ||
    $uploadRoot === false
) {
    failRequest(404);
}

$physicalFilePath = realpath(
    $projectRoot .
    DIRECTORY_SEPARATOR .
    str_replace(
        '/',
        DIRECTORY_SEPARATOR,
        $filePath
    )
);

if (
    $physicalFilePath === false ||
    !is_file($physicalFilePath) ||
    !str_starts_with(
        $physicalFilePath,
        $uploadRoot . DIRECTORY_SEPARATOR
    )
) {
    failRequest(404);
}

/*
|--------------------------------------------------------------------------
| Determine File Content Type
|--------------------------------------------------------------------------
*/

$extension = strtolower(
    pathinfo(
        (string) $material['file_name'],
        PATHINFO_EXTENSION
    )
);

$mimeTypes = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

/*
|--------------------------------------------------------------------------
| Prepare File Response
|--------------------------------------------------------------------------
*/

$downloadName = basename(
    str_replace(
        ["\r", "\n"],
        '',
        (string) $material['file_name']
    )
);

header('Content-Type: ' . $contentType);

header(
    'Content-Length: ' .
    (string) filesize($physicalFilePath)
);

header(
    'Content-Disposition: inline; filename="' .
    addcslashes($downloadName, '"\\') .
    '"'
);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

/*
|--------------------------------------------------------------------------
| Stream File
|--------------------------------------------------------------------------
*/

readfile($physicalFilePath);

exit;
