<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/localhost(?::\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header(
    'Access-Control-Allow-Headers: '
    . 'Authorization, Content-Type'
);

header(
    'Access-Control-Allow-Methods: GET, OPTIONS'
);

/*
|--------------------------------------------------------------------------
| Handle OPTIONS request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Only GET is allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Method not allowed.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';

/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    int $statusCode,
    array $data
): never {
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

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
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Unauthorized',
        ]
    );
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Unauthorized',
        ]
    );
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
    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Database error',
        ]
    );
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
    jsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Unauthorized',
        ]
    );
}

$studentUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Student Information And Active Registration
|--------------------------------------------------------------------------
*/

$studentSql = "
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section,
        ay.id AS academic_year_id,
        ay.name AS academic_year,
        ay.status AS academic_year_status
    FROM students AS s
    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id
    INNER JOIN grades AS g
        ON g.id = sr.grade_id
    INNER JOIN sections AS sec
        ON sec.id = sr.section_id
    INNER JOIN academic_years AS ay
        ON ay.id = sr.academic_year_id
    INNER JOIN users AS u
        ON u.id = s.user_id
    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'
      AND ay.status = 'Active'
    ORDER BY sr.id DESC
    LIMIT 1
";

$studentStmt = $conn->prepare($studentSql);

if (!$studentStmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Database error',
        ]
    );
}

$studentStmt->bind_param(
    'i',
    $studentUserId
);

$studentStmt->execute();

$studentResult = $studentStmt->get_result();

$student = $studentResult->fetch_assoc();

$studentStmt->close();

if (!$student) {
    jsonResponse(
        404,
        [
            'success' => false,
            'message' =>
                'Student record or active registration was not found.',
        ]
    );
}

$studentId = (int) $student['student_id'];

$registrationId = (int) $student['registration_id'];

$academicYearId = (int) $student['academic_year_id'];

$gradeNumber = (int) $student['grade_number'];

$section = (string) $student['section'];

$studentName = (string) $student['full_name'];

$studentCode = (string) $student['student_code'];

$academicYear = (string) $student['academic_year'];

$academicYearStatus = (string) $student['academic_year_status'];

/*
|--------------------------------------------------------------------------
| Get Undone Homework History
|--------------------------------------------------------------------------
|
| Same logic as student/homework/data.php:
|
| due_date < CURRENT_DATE()
|
| due_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)
|
| Student has not submitted the homework.
|
*/

$historySql = "
    SELECT
        h.id,
        h.academic_year,
        h.teacher_user_id,
        h.grade,
        h.section,
        h.grade_subject_id,
        h.title,
        h.description,
        h.teacher_material_path,
        h.teacher_material_original_name,
        h.assigned_date,
        h.due_date,
        h.status,
        gs.subject_name,
        u.full_name AS teacher_name
    FROM homeworks AS h
    INNER JOIN grade_subjects AS gs
        ON gs.id = h.grade_subject_id
    INNER JOIN users AS u
        ON u.id = h.teacher_user_id
    LEFT JOIN homework_submissions AS hs
        ON hs.homework_id = h.id
        AND hs.student_id = ?
    WHERE TRIM(h.academic_year) = TRIM(?)
      AND h.grade = ?
      AND h.section = ?
      AND h.due_date < CURRENT_DATE()
      AND h.due_date >= DATE_SUB(
          CURRENT_DATE(),
          INTERVAL 1 MONTH
      )
      AND hs.id IS NULL
    ORDER BY
        h.due_date DESC,
        h.id DESC
";

$historyStmt = $conn->prepare(
    $historySql
);

if (!$historyStmt) {
    jsonResponse(
        500,
        [
            'success' => false,
            'message' => 'Database error',
        ]
    );
}

$historyStmt->bind_param(
    'isis',
    $studentId,
    $academicYear,
    $gradeNumber,
    $section
);

$historyStmt->execute();

$historyResult = $historyStmt->get_result();

$homeworks = [];

/*
|--------------------------------------------------------------------------
| Build Homework History
|--------------------------------------------------------------------------
*/

while ($row = $historyResult->fetch_assoc()) {

    $homeworkId = (int) $row['id'];

    $homeworks[] = [
        'id' =>
            $homeworkId,

        'academic_year' =>
            (string) $row['academic_year'],

        'teacher_user_id' =>
            (int) $row['teacher_user_id'],

        'grade' =>
            (int) $row['grade'],

        'section' =>
            (string) $row['section'],

        'grade_subject_id' =>
            (int) $row['grade_subject_id'],

        'subject_name' =>
            (string) $row['subject_name'],

        'title' =>
            (string) $row['title'],

        'description' =>
            (string) ($row['description'] ?? ''),

        'teacher_name' =>
            (string) $row['teacher_name'],

        'teacher_material_path' =>
            $row['teacher_material_path'] !== null
                ? (string) $row['teacher_material_path']
                : null,

        'teacher_material_original_name' =>
            $row['teacher_material_original_name'] !== null
                ? (string) $row['teacher_material_original_name']
                : null,

        'assigned_date' =>
            (string) $row['assigned_date'],

        'due_date' =>
            (string) $row['due_date'],

        'status' =>
            (string) $row['status'],

        'submission' =>
            null,

        'is_submitted' =>
            false,
    ];
}

$historyStmt->close();

/*
|--------------------------------------------------------------------------
| Student Avatar Initial
|--------------------------------------------------------------------------
*/

$avatarInitial = '';

if ($studentName !== '') {
    $avatarInitial = strtoupper(
        substr($studentName, 0, 1)
    );
}

/*
|--------------------------------------------------------------------------
| Homework Statistics
|--------------------------------------------------------------------------
*/

$total = count($homeworks);

$submitted = 0;

$notSubmitted = $total;

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' =>
            'Student homework history loaded successfully.',

        'student' => [
            'id' =>
                $studentUserId,

            'student_id' =>
                $studentId,

            'student_code' =>
                $studentCode,

            'full_name' =>
                $studentName,

            'avatar_initial' =>
                $avatarInitial,

            'registration_id' =>
                $registrationId,

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                $section,

            'academic_year_id' =>
                $academicYearId,

            'academic_year' =>
                $academicYear,

            'academic_year_status' =>
                $academicYearStatus,
        ],

        'homeworks' =>
            $homeworks,

        'statistics' => [
            'total' =>
                $total,

            'submitted' =>
                $submitted,

            'not_submitted' =>
                $notSubmitted,
        ],

        'today' =>
            date('Y-m-d'),
    ],
    JSON_UNESCAPED_UNICODE
);
