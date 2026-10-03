<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^http:\/\/localhost:\d+$/',
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
        'Access-Control-Allow-Methods: POST, OPTIONS'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true
    ]);

    exit;
}

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);

    exit;
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

$identifier = trim(
    (string) ($input['identifier'] ?? '')
);

$password = (string) ($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Please enter your login details and password.'
    ]);

    exit;
}

$user = null;

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.password,
        u.role,
        u.is_deleted,
        s.id AS student_id,
        s.student_code
    FROM users AS u
    INNER JOIN students AS s
        ON s.user_id = u.id
    WHERE s.student_code = ?
      AND LOWER(u.role) = 'student'
      AND u.is_deleted = 0
      AND s.is_deleted = 0
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to process your login.'
    ]);

    exit;
}

$stmt->bind_param(
    's',
    $identifier
);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    $sql = "
        SELECT
            id,
            full_name,
            email,
            phone,
            password,
            role,
            is_deleted
        FROM users
        WHERE phone = ?
          AND LOWER(role) = 'parent'
          AND is_deleted = 0
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to process your login.'
        ]);

        exit;
    }

    $stmt->bind_param(
        's',
        $identifier
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();
}

if (!$user) {
    $sql = "
        SELECT
            id,
            full_name,
            email,
            phone,
            password,
            role,
            is_deleted
        FROM users
        WHERE (
            email = ?
            OR phone = ?
        )
        AND is_deleted = 0
        AND LOWER(role) IN (
            'admin',
            'principal',
            'teacher',
            'registrar',
            'librarian'
        )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to process your login.'
        ]);

        exit;
    }

    $stmt->bind_param(
        'ss',
        $identifier,
        $identifier
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();
}

if (!$user) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid login details or password.'
    ]);

    exit;
}

if (
    !password_verify(
        $password,
        (string) $user['password']
    )
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid login details or password.'
    ]);

    exit;
}

$userRole = strtolower(
    trim((string) $user['role'])
);

if ($userRole === 'student') {
    $studentId = (int) $user['student_id'];

    $sql = "
        SELECT
            sr.id
        FROM student_registrations AS sr
        INNER JOIN academic_years AS ay
            ON ay.id = sr.academic_year_id
        WHERE sr.student_id = ?
          AND ay.status = 'Active'
        ORDER BY sr.id DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' =>
                'Unable to verify your student registration.'
        ]);

        exit;
    }

    $stmt->bind_param(
        'i',
        $studentId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $registration = $result->fetch_assoc();

    $stmt->close();

    if (!$registration) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' =>
                'You do not have an active student registration for the current academic year. Please contact the school registrar.'
        ]);

        exit;
    }
}

$rawToken = bin2hex(
    random_bytes(32)
);

$tokenHash = hash(
    'sha256',
    $rawToken
);

$expiresAt = date(
    'Y-m-d H:i:s',
    time() + (30 * 24 * 60 * 60)
);

$userId = (int) $user['id'];

$tokenSql = "
    INSERT INTO api_tokens (
        user_id,
        token_hash,
        expires_at
    )
    VALUES (?, ?, ?)
";

$tokenStmt = $conn->prepare($tokenSql);

if (!$tokenStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create login session.'
    ]);

    exit;
}

$tokenStmt->bind_param(
    'iss',
    $userId,
    $tokenHash,
    $expiresAt
);

if (!$tokenStmt->execute()) {
    $tokenStmt->close();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create login session.'
    ]);

    exit;
}

$tokenStmt->close();

$updateSql = "
    UPDATE users
    SET
        is_logged_in = 1,
        last_login_at = NOW()
    WHERE id = ?
";

$updateStmt = $conn->prepare($updateSql);

if ($updateStmt) {
    $updateStmt->bind_param(
        'i',
        $userId
    );

    $updateStmt->execute();

    $updateStmt->close();
}

$response = [
    'success' => true,
    'message' => 'Login successful.',
    'token' => $rawToken,
    'expires_at' => $expiresAt,
    'user' => [
        'id' => $userId,
        'full_name' => (string) $user['full_name'],
        'email' => (string) ($user['email'] ?? ''),
        'phone' => (string) ($user['phone'] ?? ''),
        'role' => (string) $user['role']
    ]
];

if ($userRole === 'student') {
    $response['user']['student_id'] =
        (int) $user['student_id'];

    $response['user']['student_code'] =
        (string) $user['student_code'];
}

echo json_encode( $response, JSON_UNESCAPED_UNICODE );