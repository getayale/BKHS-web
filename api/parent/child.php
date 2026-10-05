<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?$/',
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
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

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

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

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
| Validate Authorization Header
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

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is required.'
    ]);

    exit;
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is required.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Hash Token
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $rawToken
);

/*
|--------------------------------------------------------------------------
| Validate Token
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

$tokenStmt = $conn->prepare($tokenSql);

if (!$tokenStmt) {
    http_response_code(500);

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

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'parent'
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$parentUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Parent
|--------------------------------------------------------------------------
*/

$parentSql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.phone,
        u.email,
        p.id AS parent_id,
        p.photo
    FROM users AS u
    INNER JOIN parents AS p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'parent'
      AND u.is_deleted = 0
    LIMIT 1
";

$parentStmt = $conn->prepare($parentSql);

if (!$parentStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$parentStmt->bind_param(
    'i',
    $parentUserId
);

$parentStmt->execute();

$parentResult = $parentStmt->get_result();

$parent = $parentResult->fetch_assoc();

$parentStmt->close();

if (!$parent) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Parent not found.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Student ID
|--------------------------------------------------------------------------
*/

$studentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if ($studentId === false || $studentId === null || $studentId <= 0) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid student ID is required.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearSql = "
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
";

$academicYearResult = $conn->query(
    $academicYearSql
);

$academicYear = null;

if ($academicYearResult) {
    $academicYear = $academicYearResult->fetch_assoc();
}

if (!$academicYear) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'No active academic year found.'
    ]);

    exit;
}

$academicYearId = (int) $academicYear['id'];

/*
|--------------------------------------------------------------------------
| Get Selected Child
|--------------------------------------------------------------------------
|
| This query verifies that:
|
| 1. The child belongs to the logged-in parent.
| 2. Account access is enabled.
| 3. The child has an active academic-year registration.
| 4. The student is not deleted.
| 5. The student user is not deleted.
| 6. The student user has the student role.
|
*/

$childSql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sp.relationship,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section
    FROM parents AS p
    INNER JOIN student_parents AS sp
        ON sp.parent_id = p.id
        AND sp.student_id = ?
        AND sp.is_account_access = 1
    INNER JOIN students AS s
        ON s.id = sp.student_id
    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id
        AND sr.academic_year_id = ?
    INNER JOIN grades AS g
        ON g.id = sr.grade_id
    INNER JOIN sections AS sec
        ON sec.id = sr.section_id
    INNER JOIN users AS u
        ON u.id = s.user_id
    WHERE p.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'
    ORDER BY sr.id DESC
    LIMIT 1
";

$childStmt = $conn->prepare(
    $childSql
);

if (!$childStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$childStmt->bind_param(
    'iii',
    $studentId,
    $academicYearId,
    $parentUserId
);

$childStmt->execute();

$childResult = $childStmt->get_result();

$child = $childResult->fetch_assoc();

$childStmt->close();

/*
|--------------------------------------------------------------------------
| Child Not Found / Not Accessible
|--------------------------------------------------------------------------
*/

if (!$child) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Child not found or access is not allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Prepare Child Data
|--------------------------------------------------------------------------
*/

$gradeNumber = (int) $child['grade_number'];

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' =>
            'Child information loaded successfully.',

        'child' => [
            'student_id' =>
                (int) $child['student_id'],

            'student_code' =>
                (string) $child['student_code'],

            'full_name' =>
                (string) $child['full_name'],

            'relationship' =>
                (string) ($child['relationship'] ?? ''),

            'registration_id' =>
                (int) $child['registration_id'],

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                (string) $child['section'],

            'academic_year_id' =>
                $academicYearId,

            'academic_year_name' =>
                (string) $academicYear['name'],

            'academic_year_status' =>
                (string) $academicYear['status'],

            'registration_status' =>
                'Active'
        ]
    ],
    JSON_UNESCAPED_UNICODE
);
