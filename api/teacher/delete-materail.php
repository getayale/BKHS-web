<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
}

/*
|--------------------------------------------------------------------------
| Handle Preflight Request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true,
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
| JSON Response
|--------------------------------------------------------------------------
*/

function respond(int $statusCode, array $data): never
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
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

    respond(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Read JSON Request
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

$input = json_decode($rawInput ?: '', true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request.',
    ]);
}

$materialId = filter_var(
    $input['material_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($materialId === false || $materialId === null) {
    respond(400, [
        'success' => false,
        'message' => 'A valid material ID is required.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Read Bearer Token
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
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized',
    ]);
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized',
    ]);
}

/*
|--------------------------------------------------------------------------
| Authenticate Teacher
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
    respond(500, [
        'success' => false,
        'message' => 'Database error',
    ]);
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

if (!$tokenStmt->execute()) {
    $tokenStmt->close();

    respond(500, [
        'success' => false,
        'message' => 'Authentication failed.',
    ]);
}

$tokenResult = $tokenStmt->get_result();

$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'teacher'
) {
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized',
    ]);
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Find Material Belonging to This Teacher
|--------------------------------------------------------------------------
*/

$materialSql = "
    SELECT
        id,
        file_path
    FROM teacher_materials
    WHERE id = ?
      AND teacher_user_id = ?
      AND is_active = 1
    LIMIT 1
";

$materialStmt = $conn->prepare($materialSql);

if (!$materialStmt) {
    respond(500, [
        'success' => false,
        'message' => 'Database error',
    ]);
}

$materialStmt->bind_param(
    'ii',
    $materialId,
    $teacherUserId
);

if (!$materialStmt->execute()) {
    $materialStmt->close();

    respond(500, [
        'success' => false,
        'message' => 'Unable to retrieve the material.',
    ]);
}

$materialResult = $materialStmt->get_result();

$material = $materialResult->fetch_assoc();

$materialStmt->close();

if (!$material) {
    respond(404, [
        'success' => false,
        'message' => 'Material not found or already deleted.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Soft Delete Material
|--------------------------------------------------------------------------
*/

$deleteSql = "
    UPDATE teacher_materials
    SET
        is_active = 0,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
      AND teacher_user_id = ?
      AND is_active = 1
";

$deleteStmt = $conn->prepare($deleteSql);

if (!$deleteStmt) {
    respond(500, [
        'success' => false,
        'message' => 'Unable to delete the material.',
    ]);
}

$deleteStmt->bind_param(
    'ii',
    $materialId,
    $teacherUserId
);

if (!$deleteStmt->execute()) {
    $deleteStmt->close();

    respond(500, [
        'success' => false,
        'message' => 'Failed to delete the material.',
    ]);
}

$deleted = $deleteStmt->affected_rows === 1;

$deleteStmt->close();

if (!$deleted) {
    respond(409, [
        'success' => false,
        'message' => 'The material was already deleted or changed.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Safely Remove Physical File
|--------------------------------------------------------------------------
*/

$fileRemoved = false;
$fileCleanupPending = false;

$storedPath = str_replace(
    '\\',
    '/',
    trim((string) ($material['file_path'] ?? ''))
);

$storedPath = preg_replace(
    '#/+#',
    '/',
    $storedPath
) ?? '';

$projectRoot = realpath(
    dirname(__DIR__, 2)
);

if (
    $projectRoot !== false &&
    $storedPath !== '' &&
    str_starts_with(
        strtolower($storedPath),
        'uploads/materials/'
    ) &&
    !str_contains($storedPath, '../')
) {
    $materialsDirectory = realpath(
        $projectRoot .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'materials'
    );

    $candidatePath = $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $storedPath
        );

    $realFilePath = realpath($candidatePath);

    if (
        $materialsDirectory !== false &&
        $realFilePath !== false &&
        is_file($realFilePath)
    ) {
        $directoryPrefix = rtrim(
            $materialsDirectory,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        $insideMaterialsDirectory =
            DIRECTORY_SEPARATOR === '\\'
                ? strncasecmp(
                    $realFilePath,
                    $directoryPrefix,
                    strlen($directoryPrefix)
                ) === 0
                : str_starts_with(
                    $realFilePath,
                    $directoryPrefix
                );

        if ($insideMaterialsDirectory) {
            $fileRemoved = @unlink($realFilePath);

            $fileCleanupPending = !$fileRemoved;
        } else {
            $fileCleanupPending = true;
        }
    } elseif ($realFilePath === false) {
        // The database record is deleted and the file is already missing.
        $fileRemoved = true;
    } else {
        $fileCleanupPending = true;
    }
} else {
    // Never delete files outside the permitted materials directory.
    $fileCleanupPending = true;
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

respond(200, [
    'success' => true,
    'message' => 'Material deleted successfully.',
    'material_id' => (int) $materialId,
    'file_removed' => $fileRemoved,
    'file_cleanup_pending' => $fileCleanupPending,
]);
