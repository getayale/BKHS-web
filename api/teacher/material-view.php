<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

/*
|--------------------------------------------------------------------------
| CORS Configuration
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (preg_match('/^https?:\/\/localhost:\d+$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
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
| Database Connection
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

function respond(int $statusCode, array $data): void
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');

    respond(405, [
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Authenticate Teacher
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
            $authorizationHeader = trim((string) $value);
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
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

$rawToken = trim((string) $matches[1]);

if ($rawToken === '') {
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

/*
|--------------------------------------------------------------------------
| Verify Token
|--------------------------------------------------------------------------
*/

$tokenHash = hash('sha256', $rawToken);

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
    respond(500, [
        'success' => false,
        'message' => 'Database error during authentication.'
    ]);
}

$tokenStmt->bind_param('s', $tokenHash);
$tokenStmt->execute();

$tokenResult = $tokenStmt->get_result();
$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'teacher'
) {
    respond(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Validate Material ID
|--------------------------------------------------------------------------
*/

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$materialId || $materialId < 1) {
    respond(400, [
        'success' => false,
        'message' => 'A valid material ID is required.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Get Material
|--------------------------------------------------------------------------
*/

$materialSql = "
    SELECT
        tm.id,
        tm.title,
        tm.description,
        tm.file_name,
        tm.file_path,
        tm.file_type,
        tm.file_size,
        tm.academic_year,
        tm.created_at,
        gs.id AS grade_subject_id,
        gs.subject_name
    FROM teacher_materials AS tm
    INNER JOIN grade_subjects AS gs
        ON gs.id = tm.grade_subject_id
    WHERE tm.id = ?
      AND tm.teacher_user_id = ?
      AND tm.is_active = 1
    LIMIT 1
";

$stmt = $conn->prepare($materialSql);

if (!$stmt) {
    respond(500, [
        'success' => false,
        'message' => 'Unable to retrieve the material.'
    ]);
}

$stmt->bind_param(
    'ii',
    $materialId,
    $teacherUserId
);

$stmt->execute();

$material = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$material) {
    respond(404, [
        'success' => false,
        'message' => 'Material not found or you do not have permission to view it.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Get Assigned Grades and Sections
|--------------------------------------------------------------------------
*/

$assignments = [];

$assignmentSql = "
    SELECT
        grade,
        section
    FROM subject_teacher_assignments
    WHERE teacher_user_id = ?
      AND grade_subject_id = ?
      AND academic_year = ?
      AND is_active = 1
    ORDER BY grade ASC, section ASC
";

$stmt = $conn->prepare($assignmentSql);

if (!$stmt) {
    respond(500, [
        'success' => false,
        'message' => 'Unable to retrieve the class information.'
    ]);
}

$gradeSubjectId = (int) $material['grade_subject_id'];
$academicYear = (string) $material['academic_year'];

$stmt->bind_param(
    'iis',
    $teacherUserId,
    $gradeSubjectId,
    $academicYear
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $assignments[] = [
        'grade' => (int) $row['grade'],
        'section' => (string) $row['section']
    ];
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Prepare File Information
|--------------------------------------------------------------------------
*/

$filePath = ltrim(
    str_replace('\\', '/', (string) $material['file_path']),
    '/'
);

$projectRoot = dirname(__DIR__, 2);

$physicalFilePath = $projectRoot
    . DIRECTORY_SEPARATOR
    . str_replace('/', DIRECTORY_SEPARATOR, $filePath);

$fileExists = is_file($physicalFilePath);

$fileSize = (int) $material['file_size'];

if ($fileSize < 1024) {
    $formattedFileSize = $fileSize . ' B';
} elseif ($fileSize < 1024 * 1024) {
    $formattedFileSize = number_format($fileSize / 1024, 2) . ' KB';
} else {
    $formattedFileSize = number_format(
        $fileSize / (1024 * 1024),
        2
    ) . ' MB';
}

/*
|--------------------------------------------------------------------------
| Build File URL
|--------------------------------------------------------------------------
*/

$scriptName = str_replace(
    '\\',
    '/',
    $_SERVER['SCRIPT_NAME'] ?? '/BKHS/api/teacher/material-view.php'
);

$projectDirectory = dirname(
    dirname(dirname($scriptName))
);

$projectDirectory = rtrim($projectDirectory, '/');

if ($projectDirectory === '.' || $projectDirectory === '/') {
    $projectDirectory = '';
}

$fileUrl = $projectDirectory . '/' . $filePath;

/*
|--------------------------------------------------------------------------
| Return Material Details
|--------------------------------------------------------------------------
*/

respond(200, [
    'success' => true,
    'message' => 'Material retrieved successfully.',

    'teacher' => [
        'id' => $teacherUserId
    ],

    'material' => [
        'id' => (int) $material['id'],
        'title' => (string) $material['title'],
        'description' => (string) ($material['description'] ?? ''),
        'file_name' => (string) $material['file_name'],
        'file_path' => $filePath,
        'file_url' => $fileUrl,
        'file_type' => (string) ($material['file_type'] ?? ''),
        'file_size' => $fileSize,
        'formatted_file_size' => $formattedFileSize,
        'file_exists' => $fileExists,
        'academic_year' => $academicYear,
        'created_at' => (string) $material['created_at'],

        'subject' => [
            'id' => $gradeSubjectId,
            'name' => (string) $material['subject_name']
        ],

        'assignments' => $assignments
    ]
]);