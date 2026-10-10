<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

require_once '../../config/database.php';

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
    $authorization === '' ||
    !preg_match(
        '/Bearer\s+(.+)/i',
        $authorization,
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

$tokenHash = hash('sha256', $token);

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

$stmt = $conn->prepare($tokenSql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to authenticate the request.',
    ]);

    exit;
}

$stmt->bind_param(
    's',
    $tokenHash
);

$stmt->execute();

$result = $stmt->get_result();

$authUser = $result->fetch_assoc();

$stmt->close();

if (!$authUser) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is invalid or expired.',
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Student Role
|--------------------------------------------------------------------------
*/

if (
    strtolower(
        (string) $authUser['role']
    ) !== 'student'
) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Only students can access student results.',
    ]);

    exit;
}

$userId = (int) $authUser['user_id'];

/*
|--------------------------------------------------------------------------
| Current Student Registration
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
    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND ay.status = 'Active'
    ORDER BY sr.id DESC
    LIMIT 1
";

$stmt = $conn->prepare($studentSql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load student information.',
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
        'message' => 'No active student registration was found.',
    ]);

    exit;
}

$studentId = (int) $student['student_id'];
$registrationId = (int) $student['registration_id'];
$gradeNumber = (int) $student['grade_number'];
$section = (string) $student['section'];
$academicYearId = (int) $student['academic_year_id'];
$academicYear = (string) $student['academic_year'];

/*
|--------------------------------------------------------------------------
| Subjects + Results
|--------------------------------------------------------------------------
|
| Four semesters:
|
| Mid Semester       / 50
| First Semester     / 100
| Quarter Semester   / 50
| Second Semester    / 100
|
*/

$subjects = [];

$subjectSql = "
    SELECT
        gs.id AS grade_subject_id,
        gs.subject_name,

        MAX(
            CASE
                WHEN sem.name = 'Mid Semester'
                THEN r.mark
            END
        ) AS mid_mark,

        MAX(
            CASE
                WHEN sem.name = 'First Semester'
                THEN r.mark
            END
        ) AS first_mark,

        MAX(
            CASE
                WHEN sem.name = 'Quarter Semester'
                THEN r.mark
            END
        ) AS quarter_mark,

        MAX(
            CASE
                WHEN sem.name = 'Second Semester'
                THEN r.mark
            END
        ) AS second_mark

    FROM grade_subjects gs

    LEFT JOIN results r
        ON r.grade_subject_id = gs.id
        AND r.student_registration_id = ?

    LEFT JOIN semesters sem
        ON sem.id = r.semester_id
        AND sem.academic_year_id = ?

    WHERE gs.grade = ?
      AND gs.is_active = 1

    GROUP BY
        gs.id,
        gs.subject_name

    ORDER BY
        gs.subject_name ASC
";

$stmt = $conn->prepare($subjectSql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load student results.',
    ]);

    exit;
}

$stmt->bind_param(
    'iii',
    $registrationId,
    $academicYearId,
    $gradeNumber
);

$stmt->execute();

$subjectResult = $stmt->get_result();

while ($row = $subjectResult->fetch_assoc()) {

    $subjects[] = [
        'grade_subject_id' =>
            (int) $row['grade_subject_id'],

        'subject_name' =>
            (string) $row['subject_name'],

        'mid_mark' =>
            $row['mid_mark'] !== null
                ? (float) $row['mid_mark']
                : null,

        'first_mark' =>
            $row['first_mark'] !== null
                ? (float) $row['first_mark']
                : null,

        'quarter_mark' =>
            $row['quarter_mark'] !== null
                ? (float) $row['quarter_mark']
                : null,

        'second_mark' =>
            $row['second_mark'] !== null
                ? (float) $row['second_mark']
                : null,
    ];
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Student Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,

    'message' => 'Student results loaded successfully.',

    'student' => [
        'id' =>
            $studentId,

        'student_id' =>
            $studentId,

        'student_code' =>
            (string) $student['student_code'],

        'full_name' =>
            (string) $student['full_name'],

        'grade_number' =>
            $gradeNumber,

        'grade_label' =>
            'Grade ' . $gradeNumber,

        'section' =>
            $section,

        'academic_year' =>
            $academicYear,
    ],

    'semesters' => [
        [
            'name' => 'Mid Semester',
            'maximum_mark' => 50,
        ],
        [
            'name' => 'First Semester',
            'maximum_mark' => 100,
        ],
        [
            'name' => 'Quarter Semester',
            'maximum_mark' => 50,
        ],
        [
            'name' => 'Second Semester',
            'maximum_mark' => 100,
        ],
    ],

    'subjects' =>
        $subjects,

    'statistics' => [
        'subject_count' =>
            count($subjects),
    ],

    'academic_year_id' =>
        $academicYearId,

    'registration_id' =>
        $registrationId,
]);

$conn->close();