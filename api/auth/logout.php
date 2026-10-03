<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    '/^http:\/\/localhost:\d+$/',
    '/^http:\/\/127\.0\.0\.1:\d+$/',
    '/^http:\/\/\[::1\]:\d+$/',
];

foreach ($allowedOrigins as $pattern) {
    if (
        $origin !== '' &&
        preg_match($pattern, $origin)
    ) {
        header(
            'Access-Control-Allow-Origin: ' . $origin
        );

        header('Vary: Origin');

        header(
            'Access-Control-Allow-Headers: Content-Type, Authorization'
        );

        header(
            'Access-Control-Allow-Methods: POST, OPTIONS'
        );

        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true,
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($authorization === '') {
    $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (
    $authorization === '' &&
    function_exists('getallheaders')
) {
    $headers = getallheaders();

    $authorization =
        $headers['Authorization']
        ?? $headers['authorization']
        ?? '';
}

if (
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        trim($authorization),
        $matches
    )
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is required.',
    ]);

    exit;
}

$token = trim($matches[1]);

if ($token === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is invalid.',
    ]);

    exit;
}

$tokenHash = hash(
    'sha256',
    $token
);

$sql = "
    SELECT
        api_tokens.id AS token_id,
        api_tokens.user_id,
        api_tokens.expires_at,
        users.id,
        users.role
    FROM api_tokens
    INNER JOIN users
        ON users.id = api_tokens.user_id
    WHERE api_tokens.token_hash = ?
      AND api_tokens.expires_at > NOW()
      AND users.is_deleted = 0
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare logout request.',
    ]);

    exit;
}

$stmt->bind_param(
    's',
    $tokenHash
);

$stmt->execute();

$result = $stmt->get_result();

$tokenData = $result->fetch_assoc();

$stmt->close();

if (!$tokenData) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired authentication token.',
    ]);

    exit;
}

$userId = (int) $tokenData['user_id'];
$tokenId = (int) $tokenData['token_id'];

$deleteSql = "
    DELETE FROM api_tokens
    WHERE id = ?
";

$deleteStmt = $conn->prepare(
    $deleteSql
);

if (!$deleteStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to complete logout.',
    ]);

    exit;
}

$deleteStmt->bind_param(
    'i',
    $tokenId
);

if (!$deleteStmt->execute()) {
    $deleteStmt->close();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to complete logout.',
    ]);

    exit;
}

$deleteStmt->close();

$countSql = "
    SELECT COUNT(*) AS active_tokens
    FROM api_tokens
    WHERE user_id = ?
      AND expires_at > NOW()
";

$countStmt = $conn->prepare(
    $countSql
);

if ($countStmt) {
    $countStmt->bind_param(
        'i',
        $userId
    );

    $countStmt->execute();

    $countResult = $countStmt->get_result();

    $tokenCount = $countResult->fetch_assoc();

    $countStmt->close();

    $activeTokens = (int) (
        $tokenCount['active_tokens'] ?? 0
    );

    if ($activeTokens === 0) {
        $updateSql = "
            UPDATE users
            SET is_logged_in = 0
            WHERE id = ?
        ";

        $updateStmt = $conn->prepare(
            $updateSql
        );

        if ($updateStmt) {
            $updateStmt->bind_param(
                'i',
                $userId
            );

            $updateStmt->execute();

            $updateStmt->close();
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully.',
]);
