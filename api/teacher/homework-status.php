<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && preg_match($allowedOriginPattern, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once '../../teacher/homework/helpers.php';
require_once '../../teacher/homework/data.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

function apiResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function getBearerToken(): string
{
    $header = getAuthorizationHeader();

    if ($header === '') {
        apiResponse(
            false,
            'Authorization token is required.',
            [],
            401
        );
    }

    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            $header,
            $matches
        )
    ) {
        apiResponse(
            false,
            'Invalid authorization format.',
            [],
            401
        );
    }

    return trim((string) $matches[1]);
}

/*
|--------------------------------------------------------------------------
| Authenticate teacher
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

$tokenHash = hash(
    'sha256',
    $token
);

$stmt = $conn->prepare(
    "
    SELECT
        at.user_id,
        at.expires_at,
        u.full_name,
        u.email,
        u.phone,
        u.role
    FROM api_tokens at
    INNER JOIN users u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
    "
);

$stmt->bind_param(
    's',
    $tokenHash
);

$stmt->execute();

$result = $stmt->get_result();

$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    apiResponse(
        false,
        'Invalid or expired authorization token.',
        [],
        401
    );
}

if (
    strtolower((string) $teacher['role']) !== 'teacher'
) {
    apiResponse(
        false,
        'Teacher access is required.',
        [],
        403
    );
}

$teacherUserId = (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON body
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    apiResponse(
        false,
        'Request body is required.',
        [],
        422
    );
}

$requestData = json_decode(
    $rawBody,
    true
);

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($requestData)
) {
    apiResponse(
        false,
        'Invalid JSON request body.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Request values
|--------------------------------------------------------------------------
*/

$homeworkId = isset($requestData['homework_id'])
    ? (int) $requestData['homework_id']
    : 0;

$studentId = isset($requestData['student_id'])
    ? (int) $requestData['student_id']
    : 0;

$status = isset($requestData['status'])
    ? trim((string) $requestData['status'])
    : '';

$action = isset($requestData['action'])
    ? trim((string) $requestData['action'])
    : '';

if ($homeworkId <= 0) {
    apiResponse(
        false,
        'A valid homework ID is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Active academic year
|--------------------------------------------------------------------------
*/

try {
    $academicYear = getActiveAcademicYear($conn);
} catch (Throwable) {
    apiResponse(
        false,
        'Unable to load the active academic year.',
        [],
        500
    );
}

if ($academicYear === null) {
    apiResponse(
        false,
        'There is no active academic year.',
        [],
        404
    );
}

$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify homework ownership
|--------------------------------------------------------------------------
*/

try {
    $homework = getTeacherHomework(
        $conn,
        $homeworkId,
        $teacherUserId,
        $academicYearName
    );
} catch (Throwable) {
    apiResponse(
        false,
        'Unable to load the homework.',
        [],
        500
    );
}

if ($homework === null) {
    apiResponse(
        false,
        'Homework was not found or you do not have permission to modify it.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Mark all students as Done
|--------------------------------------------------------------------------
*/

if ($action === 'all_done') {

    try {
        $students = getStudentsForHomeworkClass(
            $conn,
            $academicYearId,
            (int) $homework['grade'],
            (string) $homework['section']
        );
    } catch (Throwable) {
        apiResponse(
            false,
            'Unable to load students for this homework class.',
            [],
            500
        );
    }

    if (empty($students)) {
        apiResponse(
            true,
            'There are no students in this homework class.',
            [
                'homework_id' =>
                    $homeworkId,

                'updated_count' =>
                    0,

                'action' =>
                    'all_done',
            ]
        );
    }

    $updatedCount = 0;

    try {

        $conn->begin_transaction();

        foreach ($students as $student) {

            $currentStudentId =
                (int) (
                    $student['student_id']
                    ?? $student['id']
                    ?? 0
                );

            if ($currentStudentId <= 0) {
                throw new RuntimeException(
                    'Invalid student record.'
                );
            }

            $belongsToClass =
                studentBelongsToHomeworkClass(
                    $conn,
                    $currentStudentId,
                    $academicYearId,
                    (int) $homework['grade'],
                    (string) $homework['section']
                );

            if (!$belongsToClass) {
                throw new RuntimeException(
                    'A student does not belong to the homework class.'
                );
            }

            $updated =
                updateHomeworkStudentStatus(
                    $conn,
                    $homeworkId,
                    $currentStudentId,
                    'Done'
                );

            if (!$updated) {
                throw new RuntimeException(
                    'A student homework status could not be updated.'
                );
            }

            $updatedCount++;
        }

        $conn->commit();

    } catch (Throwable) {

        if ($conn->in_transaction) {
            $conn->rollback();
        }

        apiResponse(
            false,
            'Student homework statuses could not be updated.',
            [],
            500
        );
    }

    apiResponse(
        true,
        'All students were marked as Done.',
        [
            'homework_id' =>
                $homeworkId,

            'updated_count' =>
                $updatedCount,

            'action' =>
                'all_done',
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Individual student status
|--------------------------------------------------------------------------
*/

if ($studentId <= 0) {
    apiResponse(
        false,
        'A valid student ID is required.',
        [],
        422
    );
}

if (!isValidHomeworkStatus($status)) {
    apiResponse(
        false,
        'Invalid homework status.',
        [
            'allowed_statuses' => [
                'Done',
                'Not Done',
            ],
        ],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Verify student belongs to homework class
|--------------------------------------------------------------------------
*/

try {

    $belongsToClass =
        studentBelongsToHomeworkClass(
            $conn,
            $studentId,
            $academicYearId,
            (int) $homework['grade'],
            (string) $homework['section']
        );

} catch (Throwable) {

    apiResponse(
        false,
        'Unable to verify the student class.',
        [],
        500
    );
}

if (!$belongsToClass) {
    apiResponse(
        false,
        'The selected student does not belong to this homework class.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Update individual status
|--------------------------------------------------------------------------
*/

try {

    $updated =
        updateHomeworkStudentStatus(
            $conn,
            $homeworkId,
            $studentId,
            $status
        );

} catch (Throwable) {

    apiResponse(
        false,
        'Student homework status could not be updated.',
        [],
        500
    );
}

if (!$updated) {
    apiResponse(
        false,
        'Student homework status could not be updated.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Success response
|--------------------------------------------------------------------------
*/

apiResponse(
    true,
    'Student homework status updated successfully.',
    [
        'homework_id' =>
            $homeworkId,

        'student_id' =>
            $studentId,

        'status' =>
            $status,

        'action' =>
            'student',
    ]
);

