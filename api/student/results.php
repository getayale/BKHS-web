<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/localhost(?::\d+)?$/',
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

        ay.name AS academic_year

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

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
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

    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Student record or active registration was not found.'
    ]);

    exit;
}

$studentId = (int) $student['student_id'];

$registrationId = (int) $student['registration_id'];

$gradeNumber = (int) $student['grade_number'];

$section = (string) $student['section'];

$academicYearId = (int) $student['academic_year_id'];

$academicYear = (string) $student['academic_year'];

$studentName = (string) $student['full_name'];

$studentCode = (string) $student['student_code'];

/*
|--------------------------------------------------------------------------
| Get Student Results
|--------------------------------------------------------------------------
*/

$results = [];

$resultSql = "

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

    FROM grade_subjects AS gs

    LEFT JOIN results AS r

        ON r.grade_subject_id = gs.id

        AND r.student_registration_id = ?

    LEFT JOIN semesters AS sem

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

$resultStmt = $conn->prepare($resultSql);

if (!$resultStmt) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$resultStmt->bind_param(
    'iii',
    $registrationId,
    $academicYearId,
    $gradeNumber
);

$resultStmt->execute();

$resultQuery = $resultStmt->get_result();

while ($row = $resultQuery->fetch_assoc()) {

    $results[] = [

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
                : null
    ];
}

$resultStmt->close();

/*
|--------------------------------------------------------------------------
| Semester Information
|--------------------------------------------------------------------------
*/

$semesters = [

    [
        'name' => 'Mid Semester',
        'maximum_mark' => 50
    ],

    [
        'name' => 'First Semester',
        'maximum_mark' => 100
    ],

    [
        'name' => 'Quarter Semester',
        'maximum_mark' => 50
    ],

    [
        'name' => 'Second Semester',
        'maximum_mark' => 100
    ]
];

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$subjectCount = count($results);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(

    [

        'success' => true,

        'message' =>
            'Student results loaded successfully.',

        'student' => [

            'id' =>
                $studentUserId,

            'student_id' =>
                $studentId,

            'student_code' =>
                $studentCode,

            'full_name' =>
                $studentName,

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                $section,

            'academic_year_id' =>
                $academicYearId,

            'academic_year' =>
                $academicYear
        ],

        'semesters' =>
            $semesters,

        'subjects' =>
            $results,

        'statistics' => [

            'subject_count' =>
                $subjectCount
        ],

        'academic_year_id' =>
            $academicYearId,

        'registration_id' =>
            $registrationId
    ],

    JSON_UNESCAPED_UNICODE

);
