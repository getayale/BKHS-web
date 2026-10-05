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
| Pagination
|--------------------------------------------------------------------------
*/

$recordsPerPage = 10;

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

if (
    $currentPage === false ||
    $currentPage === null ||
    $currentPage < 1
) {
    $currentPage = 1;
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

$parentId = (int) $parent['parent_id'];

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
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

$studentId = (int) $child['student_id'];

$studentName = (string) $child['full_name'];

$studentCode = (string) $child['student_code'];

$gradeNumber = (int) $child['grade_number'];

$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Count Attendance Records
|--------------------------------------------------------------------------
|
| Only Absent and Late are displayed.
|
*/

$countStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_records
    FROM student_attendance
    WHERE student_id = ?
      AND academic_year_id = ?
      AND status IN ('Absent', 'Late')
");

if (!$countStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare attendance count query.',
    ]);

    exit;
}

$countStmt->bind_param(
    'ii',
    $studentId,
    $academicYearId
);

$countStmt->execute();

$countResult = $countStmt->get_result();

$countRow = $countResult->fetch_assoc();

$countStmt->close();

$totalRecords = (int) (
    $countRow['total_records'] ?? 0
);

/*
|--------------------------------------------------------------------------
| Calculate Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalRecords / $recordsPerPage
    )
);

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = (
    $currentPage - 1
) * $recordsPerPage;

/*
|--------------------------------------------------------------------------
| Get Attendance Records
|--------------------------------------------------------------------------
|
| Latest attendance appears first.
| Only Absent and Late are returned.
|
*/

$attendanceStmt = $conn->prepare("
    SELECT
        id,
        ethiopian_date,
        status
    FROM student_attendance
    WHERE student_id = ?
      AND academic_year_id = ?
      AND status IN ('Absent', 'Late')
    ORDER BY attendance_date DESC, id DESC
    LIMIT ? OFFSET ?
");

if (!$attendanceStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare attendance query.',
    ]);

    exit;
}

$attendanceStmt->bind_param(
    'iiii',
    $studentId,
    $academicYearId,
    $recordsPerPage,
    $offset
);

$attendanceStmt->execute();

$attendanceResult = $attendanceStmt->get_result();

$attendanceRecords = [];

while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRecords[] = [
        'id' => (int) $row['id'],
        'ethiopian_date' => (string) $row['ethiopian_date'],
        'status' => (string) $row['status'],
    ];
}

$attendanceStmt->close();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' => 'Attendance loaded successfully.',

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
        ],

        'student' => [
            'student_id' => $studentId,
            'student_code' => $studentCode,
            'full_name' => $studentName,
            'relationship' => (string) (
                $child['relationship'] ?? ''
            ),
            'grade_number' => $gradeNumber,
            'grade_label' => 'Grade ' . $gradeNumber,
            'section' => $section,
        ],

        'attendance' => $attendanceRecords,

        'pagination' => [
            'current_page' => $currentPage,
            'records_per_page' => $recordsPerPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
        ],
    ],
    JSON_UNESCAPED_UNICODE
);

