<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

$academicYearId = (int) $student['academic_year_id'];

$gradeNumber = (int) $student['grade_number'];

$section = (string) $student['section'];

$studentName = (string) $student['full_name'];

$studentCode = (string) $student['student_code'];

$academicYear = (string) $student['academic_year'];

$academicYearStatus = (string) $student['academic_year_status'];

/*
|--------------------------------------------------------------------------
| Subject Count
|--------------------------------------------------------------------------
*/

$subjectCount = 0;

$subjectSql = "

    SELECT COUNT(*) AS total

    FROM grade_subjects

    WHERE grade = ?

      AND is_active = 1
";

$subjectStmt = $conn->prepare($subjectSql);

if ($subjectStmt) {

    $subjectStmt->bind_param(
        'i',
        $gradeNumber
    );

    $subjectStmt->execute();

    $subjectResult = $subjectStmt->get_result();

    $row = $subjectResult->fetch_assoc();

    $subjectCount = (int) (
        $row['total'] ?? 0
    );

    $subjectStmt->close();
}

/*
|--------------------------------------------------------------------------
| Homework Count
|--------------------------------------------------------------------------
*/

$homeworkCount = 0;

$homeworkSql = "

    SELECT COUNT(*) AS total

    FROM homeworks

    WHERE academic_year = ?

      AND grade = ?

      AND section = ?

      AND status = 'Active'
";

$homeworkStmt = $conn->prepare($homeworkSql);

if ($homeworkStmt) {

    $homeworkStmt->bind_param(
        'sis',
        $academicYear,
        $gradeNumber,
        $section
    );

    $homeworkStmt->execute();

    $homeworkResult = $homeworkStmt->get_result();

    $row = $homeworkResult->fetch_assoc();

    $homeworkCount = (int) (
        $row['total'] ?? 0
    );

    $homeworkStmt->close();
}

/*
|--------------------------------------------------------------------------
| Material Count
|--------------------------------------------------------------------------
*/

$materialCount = 0;

$checkTable = $conn->query("

    SELECT COUNT(*) AS total

    FROM information_schema.tables

    WHERE table_schema = DATABASE()

      AND table_name = 'subject_materials'
");

if ($checkTable) {

    $tableExists = (int) (
        $checkTable->fetch_assoc()['total'] ?? 0
    );

    if ($tableExists > 0) {

        $materialSql = "

            SELECT COUNT(*) AS total

            FROM subject_materials AS sm

            INNER JOIN grade_subjects AS gs
                ON gs.id = sm.grade_subject_id

            WHERE sm.is_active = 1

              AND gs.grade = ?

              AND gs.is_active = 1
        ";

        $materialStmt = $conn->prepare(
            $materialSql
        );

        if ($materialStmt) {

            $materialStmt->bind_param(
                'i',
                $gradeNumber
            );

            $materialStmt->execute();

            $materialResult =
                $materialStmt->get_result();

            $row =
                $materialResult->fetch_assoc();

            $materialCount = (int) (
                $row['total'] ?? 0
            );

            $materialStmt->close();
        }
    }

    $checkTable->close();
}

/*
|--------------------------------------------------------------------------
| Result Count
|--------------------------------------------------------------------------
*/

$resultCount = 0;

$checkTable = $conn->query("

    SELECT COUNT(*) AS total

    FROM information_schema.tables

    WHERE table_schema = DATABASE()

      AND table_name = 'results'
");

if ($checkTable) {

    $tableExists = (int) (
        $checkTable->fetch_assoc()['total'] ?? 0
    );

    if ($tableExists > 0) {

        $resultSql = "

            SELECT COUNT(*) AS total

            FROM results

            WHERE student_registration_id = ?
        ";

        $resultStmt = $conn->prepare(
            $resultSql
        );

        if ($resultStmt) {

            $resultStmt->bind_param(
                'i',
                $registrationId
            );

            $resultStmt->execute();

            $resultResult =
                $resultStmt->get_result();

            $row =
                $resultResult->fetch_assoc();

            $resultCount = (int) (
                $row['total'] ?? 0
            );

            $resultStmt->close();
        }
    }

    $checkTable->close();
}

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
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(

    [

        'success' => true,

        'message' =>
            'Student dashboard loaded successfully.',

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
                $academicYearStatus
        ],

        'academic_year' => [

            'id' =>
                $academicYearId,

            'name' =>
                $academicYear,

            'status' =>
                $academicYearStatus
        ],

        'statistics' => [

            'subject_count' =>
                $subjectCount,

            'material_count' =>
                $materialCount,

            'homework_count' =>
                $homeworkCount,

            'result_count' =>
                $resultCount
        ]

    ],

    JSON_UNESCAPED_UNICODE
);