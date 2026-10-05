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
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true,
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
        'message' => 'Method not allowed.',
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
| Validate Bearer Token
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
        'message' => 'Authentication token is required.',
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
        'message' => 'Authentication token is required.',
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
| Validate API Token
|--------------------------------------------------------------------------
*/

$tokenStmt = $conn->prepare("
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
");

if (!$tokenStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare authentication query.',
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
        'message' => 'Unauthorized.',
    ]);

    exit;
}

$parentUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Selected Student ID
|--------------------------------------------------------------------------
*/

$selectedStudentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if (
    $selectedStudentId === false ||
    $selectedStudentId === null ||
    $selectedStudentId <= 0
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'A valid student ID is required.',
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Parent Record
|--------------------------------------------------------------------------
*/

$parentStmt = $conn->prepare("
    SELECT
        parents.id AS parent_id,
        parents.user_id,
        parents.full_name,
        parents.phone
    FROM parents
    INNER JOIN users
        ON users.id = parents.user_id
    WHERE parents.user_id = ?
      AND users.is_deleted = 0
      AND LOWER(users.role) = 'parent'
    LIMIT 1
");

if (!$parentStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare parent query.',
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
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Parent account not found.',
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        academic_years.id,
        academic_years.name
    FROM academic_years
    WHERE academic_years.status = 'Active'
    ORDER BY academic_years.id DESC
    LIMIT 1
");

if (!$academicYearStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare academic year query.',
    ]);

    exit;
}

$academicYearStmt->execute();

$academicYearResult = $academicYearStmt->get_result();

$academicYear = $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'No active academic year found.',
    ]);

    exit;
}

$academicYearId = (int) $academicYear['id'];

$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify Selected Child Belongs To Parent
|--------------------------------------------------------------------------
*/

$childStmt = $conn->prepare("
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
");

if (!$childStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare child query.',
    ]);

    exit;
}

$childStmt->bind_param(
    'iii',
    $selectedStudentId,
    $academicYearId,
    $parentUserId
);

$childStmt->execute();

$childResult = $childStmt->get_result();

$child = $childResult->fetch_assoc();

$childStmt->close();

if (!$child) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'You do not have access to this student.',
    ]);

    exit;
}

$registrationId = (int) $child['registration_id'];

$studentId = (int) $child['student_id'];

$studentName = (string) $child['full_name'];

$studentCode = (string) $child['student_code'];

$gradeNumber = (int) $child['grade_number'];

$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Get Results
|--------------------------------------------------------------------------
| This query matches the web result.php exactly.
|--------------------------------------------------------------------------
*/

$resultStmt = $conn->prepare("
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
");

if (!$resultStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare results query.',
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

$resultsQuery = $resultStmt->get_result();

$results = [];

while ($row = $resultsQuery->fetch_assoc()) {
    $midMark = $row['mid_mark'] !== null
        ? (float) $row['mid_mark']
        : null;

    $firstMark = $row['first_mark'] !== null
        ? (float) $row['first_mark']
        : null;

    $quarterMark = $row['quarter_mark'] !== null
        ? (float) $row['quarter_mark']
        : null;

    $secondMark = $row['second_mark'] !== null
        ? (float) $row['second_mark']
        : null;

    /*
    |--------------------------------------------------------------------------
    | Letter Grade
    |--------------------------------------------------------------------------
    */

    $getLetterGrade = static function (?float $mark): string {
        if ($mark === null) {
            return 'Not Entered';
        }

        if ($mark >= 90) {
            return 'A+';
        }

        if ($mark >= 85) {
            return 'A';
        }

        if ($mark >= 80) {
            return 'B+';
        }

        if ($mark >= 75) {
            return 'B';
        }

        if ($mark >= 70) {
            return 'C+';
        }

        if ($mark >= 65) {
            return 'C';
        }

        if ($mark >= 60) {
            return 'D';
        }

        return 'F';
    };

    $firstGrade = $getLetterGrade($firstMark);

    $secondGrade = $getLetterGrade($secondMark);

    $results[] = [
        'grade_subject_id' => (int) $row['grade_subject_id'],

        'subject_name' => (string) $row['subject_name'],

        'mid_mark' => $midMark,

        'first_mark' => $firstMark,

        'first_grade' => $firstGrade,

        'quarter_mark' => $quarterMark,

        'second_mark' => $secondMark,

        'second_grade' => $secondGrade,
    ];
}

$resultStmt->close();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' => 'Results loaded successfully.',

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
        ],

        'student' => [
            'student_id' => $studentId,
            'student_code' => $studentCode,
            'full_name' => $studentName,
            'relationship' => (string) $child['relationship'],
            'registration_id' => $registrationId,
            'grade_number' => $gradeNumber,
            'grade_label' => 'Grade ' . $gradeNumber,
            'section' => $section,
        ],

        'results' => $results,

        'results_count' => count($results),
    ],
    JSON_UNESCAPED_UNICODE
);
