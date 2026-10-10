<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/localhost:\d+$/',
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

if (!isset($conn) || !($conn instanceof mysqli)) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection is not available.'
    ]);

    exit;
}

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
    strtolower((string) $tokenUser['role']) !== 'student'
) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$userId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Student Current Registration
|--------------------------------------------------------------------------
*/

$studentSql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.id AS grade_id,
        g.grade_number,
        sec.id AS section_id,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year
    FROM students s
    INNER JOIN student_registrations sr
        ON sr.student_id = s.id
    INNER JOIN grades g
        ON g.id = sr.grade_id
    INNER JOIN sections sec
        ON sec.id = sr.section_id
    INNER JOIN academic_years ay
        ON ay.id = sr.academic_year_id
    INNER JOIN users u
        ON u.id = s.user_id
    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'
      AND ay.status = 'Active'
    ORDER BY sr.id DESC
    LIMIT 1
";

$stmt = $conn->prepare($studentSql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load student information.'
    ]);

    exit;
}

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$result = $stmt->get_result();

$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {

    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'No active student registration was found.'
    ]);

    exit;
}

$studentId = (int) $student['student_id'];

$registrationId = (int) $student['registration_id'];

$gradeId = (int) $student['grade_id'];

$gradeNumber = (int) $student['grade_number'];

$section = (string) $student['section'];

$academicYearId = (int) $student['academic_year_id'];

$academicYear = (string) $student['academic_year'];

/*
|--------------------------------------------------------------------------
| Load Subjects
|--------------------------------------------------------------------------
|
| grade_subjects does NOT have subject_code.
|
*/

$subjects = [];

$subjectSql = "
    SELECT
        gs.id,
        gs.subject_name,
        gs.book_pdf,
        gs.is_active,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT u.full_name
                ORDER BY u.full_name
                SEPARATOR ', '
            ),
            ''
        ) AS teacher_names
    FROM grade_subjects gs
    LEFT JOIN subject_teacher_assignments sta
        ON sta.grade_subject_id = gs.id
        AND sta.academic_year = ?
        AND sta.grade = ?
        AND sta.section = ?
        AND sta.is_active = 1
    LEFT JOIN users u
        ON u.id = sta.teacher_user_id
        AND LOWER(u.role) = 'teacher'
        AND u.is_deleted = 0
    WHERE gs.grade = ?
      AND gs.is_active = 1
    GROUP BY
        gs.id,
        gs.subject_name,
        gs.book_pdf,
        gs.is_active
    ORDER BY gs.subject_name ASC
";

$stmt = $conn->prepare($subjectSql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load subjects.'
    ]);

    exit;
}

$stmt->bind_param(
    'sisi',
    $academicYear,
    $gradeNumber,
    $section,
    $gradeNumber
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $subjects[] = [
        'id' => (int) $row['id'],

        'subject_name' =>
            (string) $row['subject_name'],

        'book_pdf' =>
            $row['book_pdf'] !== null
                ? (string) $row['book_pdf']
                : null,

        'is_active' =>
            (int) $row['is_active'],

        'teacher_names' =>
            (string) $row['teacher_names']
    ];
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,

    'message' =>
        'Student subjects loaded successfully.',

    'student' => [

        'student_id' =>
            $studentId,

        'student_code' =>
            (string) $student['student_code'],

        'full_name' =>
            (string) $student['full_name'],

        'registration_id' =>
            $registrationId,

        'grade_id' =>
            $gradeId,

        'grade_number' =>
            $gradeNumber,

        'section' =>
            $section,

        'academic_year_id' =>
            $academicYearId,

        'academic_year' =>
            $academicYear
    ],

    'subjects' =>
        $subjects,

    'subject_count' =>
        count($subjects)
], JSON_UNESCAPED_UNICODE);
