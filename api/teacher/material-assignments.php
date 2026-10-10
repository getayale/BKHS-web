<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Addis_Ababa');

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

function respond(
    int $statusCode,
    bool $success,
    string $message,
    array $data = []
): never {
    http_response_code($statusCode);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => (object) $data,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');

    respond(
        405,
        false,
        'Method not allowed.'
    );
}

require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| Get Bearer Token
|--------------------------------------------------------------------------
*/

$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
    $authorizationHeader === '' &&
    function_exists('getallheaders')
) {
    $headers = getallheaders();

    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authorizationHeader = $value;
            break;
        }
    }
}

if (
    !preg_match(
        '/^Bearer\s+(\S+)$/i',
        trim($authorizationHeader),
        $matches
    )
) {
    respond(
        401,
        false,
        'Authentication token is missing or invalid.'
    );
}

$token = $matches[1];
$tokenHash = hash('sha256', $token);

/*
|--------------------------------------------------------------------------
| Authenticate Teacher
|--------------------------------------------------------------------------
*/

try {
    $authSql = "
        SELECT
            u.id,
            u.role
        FROM api_tokens at
        INNER JOIN users u
            ON u.id = at.user_id
        WHERE at.token_hash = ?
          AND at.expires_at > NOW()
          AND u.is_deleted = 0
        LIMIT 1
    ";

    $authStmt = $conn->prepare($authSql);

    if (!$authStmt) {
        throw new RuntimeException(
            'Unable to prepare authentication query.'
        );
    }

    $authStmt->bind_param('s', $tokenHash);
    $authStmt->execute();

    $authResult = $authStmt->get_result();
    $user = $authResult->fetch_assoc();

    $authStmt->close();

    if (!$user) {
        respond(
            401,
            false,
            'Your session is invalid or has expired. Please log in again.'
        );
    }

    if (strtolower((string) $user['role']) !== 'teacher') {
        respond(
            403,
            false,
            'Only teachers can access subject assignments.'
        );
    }

    $teacherId = (int) $user['id'];

    /*
    |--------------------------------------------------------------------------
    | Get Active Academic Year
    |--------------------------------------------------------------------------
    */

    $yearSql = "
        SELECT name
        FROM academic_years
        WHERE status = 'Active'
        LIMIT 1
    ";

    $yearResult = $conn->query($yearSql);

    if (!$yearResult) {
        throw new RuntimeException(
            'Unable to retrieve the active academic year.'
        );
    }

    $activeYear = $yearResult->fetch_assoc();

    if (!$activeYear) {
        respond(
            200,
            true,
            'No active academic year is available.',
            [
                'assignments' => [],
            ]
        );
    }

    $academicYear = (string) $activeYear['name'];

    /*
    |--------------------------------------------------------------------------
    | Get Teacher's Active Subject Assignments
    |--------------------------------------------------------------------------
    */

    $assignmentSql = "
        SELECT
            sta.id AS assignment_id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $assignmentStmt = $conn->prepare($assignmentSql);

    if (!$assignmentStmt) {
        throw new RuntimeException(
            'Unable to prepare the subject assignments query.'
        );
    }

    $assignmentStmt->bind_param(
        'is',
        $teacherId,
        $academicYear
    );

    $assignmentStmt->execute();

    $assignmentResult = $assignmentStmt->get_result();

    $assignments = [];

    while ($row = $assignmentResult->fetch_assoc()) {
        $assignments[] = [
            'assignment_id' => (int) $row['assignment_id'],
            'grade' => (int) $row['grade'],
            'section' => $row['section'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'subject_name' => $row['subject_name'],
        ];
    }

    $assignmentStmt->close();

    respond(
        200,
        true,
        'Subject assignments loaded successfully.',
        [
            'academic_year' => $academicYear,
            'assignments' => $assignments,
        ]
    );
} catch (Throwable $e) {
    error_log(
        'Teacher Material Assignments API Error: ' . $e->getMessage()
    );

    respond(
        500,
        false,
        'Unable to load subject assignments. Please try again later.'
    );
}
