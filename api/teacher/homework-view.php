<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && preg_match($allowedOriginPattern, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
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

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
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

if (strtolower((string) $teacher['role']) !== 'teacher') {
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(
        false,
        'Only GET requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

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

$academicYearId = (int) $academicYear['id'];

$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Get homework
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
        'Homework was not found or you do not have permission to view it.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Get students and homework statuses
|--------------------------------------------------------------------------
*/

try {
    $students = getHomeworkStudentStatuses(
        $conn,
        $homeworkId,
        $academicYearId,
        (int) $homework['grade'],
        (string) $homework['section']
    );
} catch (Throwable) {
    apiResponse(
        false,
        'Unable to load student homework statuses.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalStudents = count($students);

$doneCount = 0;
$submittedCount = 0;
$notDoneCount = 0;
$notSubmittedCount = 0;

$formattedStudents = [];

foreach ($students as $student) {
    $homeworkStatus = (string) (
        $student['homework_status'] ?? 'Not Done'
    );

    $submissionId = isset($student['submission_id'])
        ? (int) $student['submission_id']
        : 0;

    $submitted = $submissionId > 0;

    if (strtolower($homeworkStatus) === 'done') {
        $doneCount++;
    } else {
        $notDoneCount++;
    }

    if ($submitted) {
        $submittedCount++;
    } else {
        $notSubmittedCount++;
    }

    /*
    |--------------------------------------------------------------------------
    | Submitted date
    |--------------------------------------------------------------------------
    */

    $submittedAt = $student['submitted_at'] ?? null;

    $submittedAtEthiopian = null;

    if ($submittedAt !== null && $submittedAt !== '') {
        try {
            $timestamp = strtotime((string) $submittedAt);

            if ($timestamp !== false) {
                $ethiopianDate =
                    EthiopianCalendar::gregorianToEthiopian(
                        (int) date('Y', $timestamp),
                        (int) date('m', $timestamp),
                        (int) date('d', $timestamp)
                    );

                $submittedAtEthiopian = [
                    'year' => (int) $ethiopianDate['year'],
                    'month' => (int) $ethiopianDate['month'],
                    'day' => (int) $ethiopianDate['day'],
                    'formatted' => EthiopianCalendar::format(
                        (int) $ethiopianDate['year'],
                        (int) $ethiopianDate['month'],
                        (int) $ethiopianDate['day'],
                        'en'
                    ),
                    'formatted_am' => EthiopianCalendar::format(
                        (int) $ethiopianDate['year'],
                        (int) $ethiopianDate['month'],
                        (int) $ethiopianDate['day'],
                        'am'
                    ),
                    'time' => date(
                        'H:i:s',
                        $timestamp
                    ),
                ];
            }
        } catch (Throwable) {
            $submittedAtEthiopian = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Submission path
    |--------------------------------------------------------------------------
    */

    $submissionPath = !empty($student['submission_path'])
        ? (string) $student['submission_path']
        : null;

    $submissionOriginalName = !empty(
        $student['submission_original_name']
    )
        ? (string) $student['submission_original_name']
        : null;

    $formattedStudents[] = [
        'student_id' => (int) $student['student_id'],

        'full_name' => (string) $student['full_name'],

        'student_code' => (string) $student['student_code'],

        'homework_status' => $homeworkStatus,

        'submitted' => $submitted,

        'submission_id' => $submitted
            ? $submissionId
            : null,

        'submission_path' => $submissionPath,

        'submission_original_name' => $submissionOriginalName,

        'submitted_at' => $submittedAt,

        'submitted_at_ethiopian' => $submittedAtEthiopian,
    ];
}

/*
|--------------------------------------------------------------------------
| Homework dates
|--------------------------------------------------------------------------
*/

$assignedDate = (string) $homework['assigned_date'];

$dueDate = (string) $homework['due_date'];

$assignedEthiopian = null;

$dueEthiopian = null;

try {
    $assignedParts = explode('-', $assignedDate);

    if (count($assignedParts) === 3) {
        $assignedEthiopian =
            EthiopianCalendar::gregorianToEthiopian(
                (int) $assignedParts[0],
                (int) $assignedParts[1],
                (int) $assignedParts[2]
            );
    }

    $dueParts = explode('-', $dueDate);

    if (count($dueParts) === 3) {
        $dueEthiopian =
            EthiopianCalendar::gregorianToEthiopian(
                (int) $dueParts[0],
                (int) $dueParts[1],
                (int) $dueParts[2]
            );
    }
} catch (Throwable) {
    $assignedEthiopian = null;
    $dueEthiopian = null;
}

/*
|--------------------------------------------------------------------------
| Ethiopian date response
|--------------------------------------------------------------------------
*/

$assignedDateResponse = null;

$dueDateResponse = null;

if ($assignedEthiopian !== null) {
    $assignedDateResponse = [
        'year' => (int) $assignedEthiopian['year'],
        'month' => (int) $assignedEthiopian['month'],
        'day' => (int) $assignedEthiopian['day'],
        'month_name' => (string) $assignedEthiopian['month_name'],
        'month_name_am' => (string) $assignedEthiopian['month_name_am'],
        'formatted' => EthiopianCalendar::format(
            (int) $assignedEthiopian['year'],
            (int) $assignedEthiopian['month'],
            (int) $assignedEthiopian['day'],
            'en'
        ),
        'formatted_am' => EthiopianCalendar::format(
            (int) $assignedEthiopian['year'],
            (int) $assignedEthiopian['month'],
            (int) $assignedEthiopian['day'],
            'am'
        ),
    ];
}

if ($dueEthiopian !== null) {
    $dueDateResponse = [
        'year' => (int) $dueEthiopian['year'],
        'month' => (int) $dueEthiopian['month'],
        'day' => (int) $dueEthiopian['day'],
        'month_name' => (string) $dueEthiopian['month_name'],
        'month_name_am' => (string) $dueEthiopian['month_name_am'],
        'formatted' => EthiopianCalendar::format(
            (int) $dueEthiopian['year'],
            (int) $dueEthiopian['month'],
            (int) $dueEthiopian['day'],
            'en'
        ),
        'formatted_am' => EthiopianCalendar::format(
            (int) $dueEthiopian['year'],
            (int) $dueEthiopian['month'],
            (int) $dueEthiopian['day'],
            'am'
        ),
    ];
}

/*
|--------------------------------------------------------------------------
| Teacher material
|--------------------------------------------------------------------------
*/

$teacherMaterialPath = !empty(
    $homework['teacher_material_path']
)
    ? (string) $homework['teacher_material_path']
    : null;

$teacherMaterialExists = false;

if ($teacherMaterialPath !== null) {
    $physicalMaterialPath =
        dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            ltrim(
                $teacherMaterialPath,
                '/\\'
            )
        );

    $teacherMaterialExists =
        is_file($physicalMaterialPath);
}

/*
|--------------------------------------------------------------------------
| Success response
|--------------------------------------------------------------------------
*/

apiResponse(
    true,
    'Homework loaded successfully.',
    [
        'teacher' => [
            'id' => $teacherUserId,
            'full_name' => (string) $teacher['full_name'],
            'email' => (string) $teacher['email'],
            'phone' => (string) $teacher['phone'],
            'role' => (string) $teacher['role'],
        ],

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
            'status' => (string) $academicYear['status'],
        ],

        'homework' => [
            'id' => $homeworkId,

            'grade' => (int) $homework['grade'],

            'section' => (string) $homework['section'],

            'grade_subject_id' =>
                (int) $homework['grade_subject_id'],

            'subject_name' =>
                (string) $homework['subject_name'],

            'title' =>
                (string) $homework['title'],

            'description' =>
                $homework['description'] !== null
                    ? (string) $homework['description']
                    : null,

            'assigned_date' => $assignedDate,

            'assigned_date_ethiopian' =>
                $assignedDateResponse,

            'due_date' => $dueDate,

            'due_date_ethiopian' =>
                $dueDateResponse,

            'status' =>
                (string) $homework['status'],

            'teacher_material' => [
                'exists' =>
                    $teacherMaterialPath !== null,

                'file_exists' =>
                    $teacherMaterialExists,

                'path' =>
                    $teacherMaterialPath,

                'original_name' =>
                    !empty(
                        $homework[
                            'teacher_material_original_name'
                        ]
                    )
                        ? (string) $homework[
                            'teacher_material_original_name'
                        ]
                        : null,

                'type' =>
                    !empty(
                        $homework[
                            'teacher_material_type'
                        ]
                    )
                        ? (string) $homework[
                            'teacher_material_type'
                        ]
                        : null,

                'size' =>
                    !empty(
                        $homework[
                            'teacher_material_size'
                        ]
                    )
                        ? (int) $homework[
                            'teacher_material_size'
                        ]
                        : null,
            ],

            'created_at' =>
                $homework['created_at'] ?? null,

            'updated_at' =>
                $homework['updated_at'] ?? null,
        ],

        'statistics' => [
            'total_students' =>
                $totalStudents,

            'done' =>
                $doneCount,

            'not_done' =>
                $notDoneCount,

            'submitted' =>
                $submittedCount,

            'not_submitted' =>
                $notSubmittedCount,
        ],

        'students' =>
            $formattedStudents,
    ]
);

