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

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

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
        'message' => 'Unauthorized'
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
        'message' => 'Unauthorized'
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
        'message' => 'Parent not found'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYear = null;

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

if ($academicYearResult) {
    $academicYear =
        $academicYearResult->fetch_assoc();
}

/*
|--------------------------------------------------------------------------
| Get Parent's Children
|--------------------------------------------------------------------------
*/

$children = [];

if ($academicYear) {
    $childrenSql = "
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
        WHERE p.id = ?
          AND sp.is_account_access = 1
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'
        ORDER BY
            g.grade_number ASC,
            sec.code ASC,
            s.full_name ASC
    ";

    $childrenStmt = $conn->prepare(
        $childrenSql
    );

    if (!$childrenStmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);

        exit;
    }

    $academicYearId =
        (int) $academicYear['id'];

    $parentId =
        (int) $parent['parent_id'];

    $childrenStmt->bind_param(
        'ii',
        $academicYearId,
        $parentId
    );

    $childrenStmt->execute();

    $childrenResult =
        $childrenStmt->get_result();

    while (
        $row =
        $childrenResult->fetch_assoc()
    ) {
        $gradeNumber =
            (int) $row['grade_number'];

        $children[] = [
            'student_id' =>
                (int) $row['student_id'],

            'student_code' =>
                (string) $row['student_code'],

            'full_name' =>
                (string) $row['full_name'],

            'relationship' =>
                (string) ($row['relationship'] ?? ''),

            'registration_id' =>
                (int) $row['registration_id'],

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                (string) $row['section']
        ];
    }

    $childrenStmt->close();
}

/*
|--------------------------------------------------------------------------
| Parent Photo
|--------------------------------------------------------------------------
*/

$photo = (string) ($parent['photo'] ?? '');

$photoUrl = null;

if ($photo !== '') {
    $photoUrl =
        '../public/uploads/' . ltrim($photo, '/');
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' =>
            'Parent dashboard loaded successfully.',

        'parent' => [
            'id' =>
                (int) $parent['user_id'],

            'parent_id' =>
                (int) $parent['parent_id'],

            'full_name' =>
                (string) $parent['full_name'],

            'phone' =>
                (string) ($parent['phone'] ?? ''),

            'email' =>
                (string) ($parent['email'] ?? ''),

            'photo' =>
                $photo,

            'photo_url' =>
                $photoUrl
        ],

        'academic_year' => $academicYear
            ? [
                'id' =>
                    (int) $academicYear['id'],

                'name' =>
                    (string) $academicYear['name'],

                'status' =>
                    (string) $academicYear['status']
            ]
            : null,

        'children' =>
            $children,

        'children_count' =>
            count($children)
    ],
    JSON_UNESCAPED_UNICODE
);