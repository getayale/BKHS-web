<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && preg_match($allowedOriginPattern, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

if ($stmt === false) {
    apiResponse(
        false,
        'Unable to prepare authentication query.',
        [],
        500
    );
}

$stmt->bind_param(
    's',
    $tokenHash
);

if (!$stmt->execute()) {
    $stmt->close();

    apiResponse(
        false,
        'Unable to authenticate the teacher.',
        [],
        500
    );
}

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
| Active academic year
|--------------------------------------------------------------------------
*/

try {
    $academicYear = getActiveAcademicYear($conn);
} catch (Throwable $e) {
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
| Ethiopian date
|--------------------------------------------------------------------------
*/

$todayGregorian = new DateTimeImmutable(
    'now',
    new DateTimeZone('Africa/Addis_Ababa')
);

$todayGregorianDate = $todayGregorian->format('Y-m-d');

$todayEthiopian = EthiopianCalendar::gregorianToEthiopian(
    (int) $todayGregorian->format('Y'),
    (int) $todayGregorian->format('m'),
    (int) $todayGregorian->format('d')
);

$todayEthiopianFormatted =
    (string) $todayEthiopian['formatted'];

/*
|--------------------------------------------------------------------------
| Teacher information
|--------------------------------------------------------------------------
*/

$teacherData = [
    'id' => $teacherUserId,
    'full_name' => (string) $teacher['full_name'],
    'email' => (string) ($teacher['email'] ?? ''),
    'phone' => (string) ($teacher['phone'] ?? ''),
    'role' => (string) $teacher['role'],
];

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    $grade = filter_input(
        INPUT_GET,
        'grade',
        FILTER_VALIDATE_INT
    );

    $grade =
        $grade !== false &&
        $grade !== null &&
        $grade > 0
            ? (int) $grade
            : null;

    $section = trim(
        (string) ($_GET['section'] ?? '')
    );

    $section = $section !== ''
        ? strtoupper($section)
        : null;

    $subjectId = filter_input(
        INPUT_GET,
        'subject_id',
        FILTER_VALIDATE_INT
    );

    $subjectId =
        $subjectId !== false &&
        $subjectId !== null &&
        $subjectId > 0
            ? (int) $subjectId
            : null;

    $fromDate = trim(
        (string) ($_GET['from_date'] ?? '')
    );

    $fromDate = $fromDate !== ''
        ? $fromDate
        : null;

    $toDate = trim(
        (string) ($_GET['to_date'] ?? '')
    );

    $toDate = $toDate !== ''
        ? $toDate
        : null;

    /*
    |--------------------------------------------------------------------------
    | Homework status filter
    |--------------------------------------------------------------------------
    |
    | This is homeworks.status:
    |
    | Active
    | Closed
    |
    */

    $status = trim(
        (string) ($_GET['status'] ?? '')
    );

    if (
        $status !== 'Active' &&
        $status !== 'Closed'
    ) {
        $status = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $page = filter_input(
        INPUT_GET,
        'page',
        FILTER_VALIDATE_INT
    );

    $page =
        $page !== false &&
        $page !== null &&
        $page > 0
            ? (int) $page
            : 1;

    $perPage = 10;

    $offset = ($page - 1) * $perPage;

    /*
    |--------------------------------------------------------------------------
    | Get teacher homework history
    |--------------------------------------------------------------------------
    */

    try {
        $homeworkHistory = getTeacherHomeworkHistory(
            $conn,
            $teacherUserId,
            $academicYearName,
            $grade,
            $section,
            $subjectId,
            $fromDate,
            $toDate,
            $status
        );
    } catch (Throwable $e) {
        apiResponse(
            false,
            'Unable to load homework history.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Total records
    |--------------------------------------------------------------------------
    */

    $total = count($homeworkHistory);

    $totalPages = $total > 0
        ? (int) ceil($total / $perPage)
        : 1;

    if ($page > $totalPages && $total > 0) {
        $page = $totalPages;

        $offset = ($page - 1) * $perPage;
    }

    $pagedHomework = array_slice(
        $homeworkHistory,
        $offset,
        $perPage
    );

    /*
    |--------------------------------------------------------------------------
    | Format homework
    |--------------------------------------------------------------------------
    */

    $formattedHomework = [];

    foreach ($pagedHomework as $homework) {

        $assignedDate =
            (string) $homework['assigned_date'];

        $dueDate =
            (string) $homework['due_date'];

        $assignedEthiopian = null;

        $dueEthiopian = null;

        /*
        |--------------------------------------------------------------------------
        | Assigned date conversion
        |--------------------------------------------------------------------------
        */

        try {
            $assignedParts = explode(
                '-',
                $assignedDate
            );

            if (count($assignedParts) === 3) {
                $assignedEthiopian =
                    EthiopianCalendar::gregorianToEthiopian(
                        (int) $assignedParts[0],
                        (int) $assignedParts[1],
                        (int) $assignedParts[2]
                    );
            }
        } catch (Throwable) {
            $assignedEthiopian = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Due date conversion
        |--------------------------------------------------------------------------
        */

        try {
            $dueParts = explode(
                '-',
                $dueDate
            );

            if (count($dueParts) === 3) {
                $dueEthiopian =
                    EthiopianCalendar::gregorianToEthiopian(
                        (int) $dueParts[0],
                        (int) $dueParts[1],
                        (int) $dueParts[2]
                    );
            }
        } catch (Throwable) {
            $dueEthiopian = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Teacher material
        |--------------------------------------------------------------------------
        */

        $teacherMaterialPath =
            !empty(
                $homework['teacher_material_path']
            )
                ? (string) $homework['teacher_material_path']
                : null;

        $teacherMaterialOriginalName =
            !empty(
                $homework['teacher_material_original_name']
            )
                ? (string) $homework['teacher_material_original_name']
                : null;

        /*
        |--------------------------------------------------------------------------
        | Homework response
        |--------------------------------------------------------------------------
        */

        $formattedHomework[] = [
            'id' =>
                (int) $homework['id'],

            'grade' =>
                (int) $homework['grade'],

            'section' =>
                (string) $homework['section'],

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

            'assigned_date' =>
                $assignedDate,

            'assigned_date_ethiopian' =>
                $assignedEthiopian !== null
                    ? [
                        'year' =>
                            (int) $assignedEthiopian['year'],

                        'month' =>
                            (int) $assignedEthiopian['month'],

                        'day' =>
                            (int) $assignedEthiopian['day'],

                        'formatted' =>
                            (string) $assignedEthiopian['formatted'],
                    ]
                    : null,

            'due_date' =>
                $dueDate,

            'due_date_ethiopian' =>
                $dueEthiopian !== null
                    ? [
                        'year' =>
                            (int) $dueEthiopian['year'],

                        'month' =>
                            (int) $dueEthiopian['month'],

                        'day' =>
                            (int) $dueEthiopian['day'],

                        'formatted' =>
                            (string) $dueEthiopian['formatted'],
                    ]
                    : null,

            'status' =>
                (string) $homework['status'],

            'teacher_material' => [
                'exists' =>
                    $teacherMaterialPath !== null,

                'path' =>
                    $teacherMaterialPath,

                'original_name' =>
                    $teacherMaterialOriginalName,
            ],

            'created_at' =>
                (string) $homework['created_at'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Classes
    |--------------------------------------------------------------------------
    */

    try {
        $classes = getTeacherClasses(
            $conn,
            $teacherUserId,
            $academicYearName
        );
    } catch (Throwable $e) {
        apiResponse(
            false,
            'Unable to load teacher classes.',
            [],
            500
        );
    }

    $formattedClasses = [];

    foreach ($classes as $class) {
        $formattedClasses[] = [
            'grade' =>
                (int) $class['grade'],

            'section' =>
                (string) $class['section'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */

    try {

        if (
            $grade !== null &&
            $section !== null
        ) {
            $subjects = getTeacherSubjectsForClass(
                $conn,
                $teacherUserId,
                $academicYearName,
                $grade,
                $section
            );
        } else {
            $subjects = getTeacherSubjectAssignments(
                $conn,
                $teacherUserId,
                $academicYearName
            );
        }

    } catch (Throwable $e) {
        apiResponse(
            false,
            'Unable to load teacher subjects.',
            [],
            500
        );
    }

   $formattedSubjects = [];

foreach ($subjects as $subject) {
    $formattedSubjects[] = [
        'assignment_id' =>
            (int) ($subject['id'] ?? 0),

        'grade' =>
            (int) ($subject['grade'] ?? 0),

        'section' =>
            (string) ($subject['section'] ?? ''),

        'grade_subject_id' =>
            (int) ($subject['grade_subject_id'] ?? 0),

        'subject_name' =>
            (string) ($subject['subject_name'] ?? ''),
    ];
}

    /*
    |--------------------------------------------------------------------------
    | GET response
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        '',
        [
            'teacher' => $teacherData,

            'academic_year' => [
                'id' =>
                    $academicYearId,

                'name' =>
                    $academicYearName,

                'status' =>
                    (string) $academicYear['status'],
            ],

            'today' => [
                'gregorian' =>
                    $todayGregorianDate,

                'ethiopian' => [
                    'year' =>
                        (int) $todayEthiopian['year'],

                    'month' =>
                        (int) $todayEthiopian['month'],

                    'day' =>
                        (int) $todayEthiopian['day'],

                    'formatted' =>
                        $todayEthiopianFormatted,
                ],
            ],

            'filters' => [
                'grade' =>
                    $grade,

                'section' =>
                    $section,

                'subject_id' =>
                    $subjectId,

                'from_date' =>
                    $fromDate,

                'to_date' =>
                    $toDate,

                'status' =>
                    $status,
            ],

            'classes' =>
                $formattedClasses,

            'subjects' =>
                $formattedSubjects,

            'homework' =>
                $formattedHomework,

            'pagination' => [
                'page' =>
                    $page,

                'per_page' =>
                    $perPage,

                'total' =>
                    $total,

                'total_pages' =>
                    $totalPages,

                'has_previous' =>
                    $page > 1,

                'has_next' =>
                    $page < $totalPages,
            ],
        ]
    );
}

/*
|--------------------------------------------------------------------------
| POST - CREATE HOMEWORK
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(
        false,
        'Only GET and POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON or multipart form data
|--------------------------------------------------------------------------
*/

$contentType = strtolower(
    (string) ($_SERVER['CONTENT_TYPE'] ?? '')
);

$requestData = [];

if (
    str_contains(
        $contentType,
        'application/json'
    )
) {
    $rawBody = file_get_contents(
        'php://input'
    );

    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {
        apiResponse(
            false,
            'Request body is required.',
            [],
            400
        );
    }

    try {
        $decoded = json_decode(
            $rawBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        apiResponse(
            false,
            'Invalid JSON request body.',
            [],
            400
        );
    }

    if (!is_array($decoded)) {
        apiResponse(
            false,
            'Invalid request data.',
            [],
            400
        );
    }

    $requestData = $decoded;

} else {
    $requestData = $_POST;
}

/*
|--------------------------------------------------------------------------
| Read assignment
|--------------------------------------------------------------------------
*/

$assignmentId =
    isset($requestData['assignment_id'])
        ? (int) $requestData['assignment_id']
        : 0;

$title = trim(
    (string) ($requestData['title'] ?? '')
);

$description = trim(
    (string) ($requestData['description'] ?? '')
);

$description =
    $description !== ''
        ? $description
        : null;

/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($assignmentId <= 0) {
    $errors[] =
        'Please select a valid subject assignment.';
}

if ($title === '') {
    $errors[] =
        'Homework title is required.';

} elseif (mb_strlen($title) > 255) {
    $errors[] =
        'Homework title cannot exceed 255 characters.';
}

if (
    $description !== null &&
    mb_strlen($description) > 10000
) {
    $errors[] =
        'Homework description cannot exceed 10,000 characters.';
}

/*
|--------------------------------------------------------------------------
| Ethiopian assigned date
|--------------------------------------------------------------------------
*/

$assignedYear =
    isset($requestData['assigned_year'])
        ? (int) $requestData['assigned_year']
        : 0;

$assignedMonth =
    isset($requestData['assigned_month'])
        ? (int) $requestData['assigned_month']
        : 0;

$assignedDay =
    isset($requestData['assigned_day'])
        ? (int) $requestData['assigned_day']
        : 0;

/*
|--------------------------------------------------------------------------
| Ethiopian due date
|--------------------------------------------------------------------------
*/

$dueYear =
    isset($requestData['due_year'])
        ? (int) $requestData['due_year']
        : 0;

$dueMonth =
    isset($requestData['due_month'])
        ? (int) $requestData['due_month']
        : 0;

$dueDay =
    isset($requestData['due_day'])
        ? (int) $requestData['due_day']
        : 0;

/*
|--------------------------------------------------------------------------
| Validate assigned date
|--------------------------------------------------------------------------
*/

$assignedDate = null;

if (
    !isValidEthiopianDate(
        $assignedYear,
        $assignedMonth,
        $assignedDay
    )
) {
    $errors[] =
        'Please select a valid Ethiopian assigned date.';

} else {

    $assignedDate =
        ethiopianDateToGregorian(
            $assignedYear,
            $assignedMonth,
            $assignedDay
        );

    if ($assignedDate === null) {
        $errors[] =
            'The assigned date could not be converted.';
    }
}

/*
|--------------------------------------------------------------------------
| Validate due date
|--------------------------------------------------------------------------
*/

$dueDate = null;

if (
    !isValidEthiopianDate(
        $dueYear,
        $dueMonth,
        $dueDay
    )
) {
    $errors[] =
        'Please select a valid Ethiopian due date.';

} else {

    $dueDate =
        ethiopianDateToGregorian(
            $dueYear,
            $dueMonth,
            $dueDay
        );

    if ($dueDate === null) {
        $errors[] =
            'The due date could not be converted.';
    }
}

/*
|--------------------------------------------------------------------------
| Assigned date cannot be after due date
|--------------------------------------------------------------------------
*/

if (
    $assignedDate !== null &&
    $dueDate !== null &&
    $assignedDate > $dueDate
) {
    $errors[] =
        'The due date cannot be earlier than the assigned date.';
}

if (!empty($errors)) {
    apiResponse(
        false,
        implode(' ', $errors),
        [],
        422
    );
}

if (
    $assignedDate === null ||
    $dueDate === null
) {
    apiResponse(
        false,
        'The homework dates could not be processed.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Verify teacher assignment
|--------------------------------------------------------------------------
*/

try {
    $assignment = getTeacherAssignment(
        $conn,
        $assignmentId,
        $teacherUserId,
        $academicYearName
    );

} catch (Throwable $e) {

    apiResponse(
        false,
        'Unable to verify your subject assignment.',
        [],
        500
    );
}

if ($assignment === null) {
    apiResponse(
        false,
        'The selected class or subject is not assigned to your teacher account.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Authoritative class/subject information
|--------------------------------------------------------------------------
*/

$grade =
    (int) $assignment['grade'];

$section =
    (string) $assignment['section'];

$gradeSubjectId =
    (int) $assignment['grade_subject_id'];

$subjectName =
    (string) $assignment['subject_name'];

/*
|--------------------------------------------------------------------------
| Teacher material
|--------------------------------------------------------------------------
*/

$teacherMaterialPath = null;

$teacherMaterialOriginalName = null;

$teacherMaterialType = null;

$teacherMaterialSize = null;

$uploadedMaterial =
    $_FILES['teacher_material'] ?? null;

if (
    is_array($uploadedMaterial) &&
    isset($uploadedMaterial['error']) &&
    (int) $uploadedMaterial['error'] !==
        UPLOAD_ERR_NO_FILE
) {

    $uploadValidation =
        validateHomeworkUpload(
            $uploadedMaterial
        );

    if (!$uploadValidation['valid']) {
        apiResponse(
            false,
            (string) (
                $uploadValidation['error']
                ?? 'The teacher material is invalid.'
            ),
            [],
            422
        );
    }

    $extension =
        (string) $uploadValidation['extension'];

    try {
        $filename =
            generateHomeworkFilename(
                $extension
            );

    } catch (Throwable) {

        apiResponse(
            false,
            'A secure filename could not be generated.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Physical upload directory
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'homeworks'
        . DIRECTORY_SEPARATOR
        . 'teacher';

    if (!ensureDirectoryExists($uploadDirectory)) {
        apiResponse(
            false,
            'The homework upload directory could not be created.',
            [],
            500
        );
    }

    $destination =
        $uploadDirectory
        . DIRECTORY_SEPARATOR
        . $filename;

    if (
        !move_uploaded_file(
            (string) $uploadedMaterial['tmp_name'],
            $destination
        )
    ) {
        apiResponse(
            false,
            'The teacher material could not be uploaded.',
            [],
            500
        );
    }

    $teacherMaterialPath =
        'uploads/homeworks/teacher/' . $filename;

    $teacherMaterialOriginalName =
        basename(
            (string) $uploadedMaterial['name']
        );

    $teacherMaterialType =
        (string) $uploadValidation['mime_type'];

    $teacherMaterialSize =
        (int) $uploadedMaterial['size'];
}

/*
|--------------------------------------------------------------------------
| Create homework
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    $homeworkId = insertHomework(
        $conn,
        $academicYearName,
        $teacherUserId,
        $grade,
        $section,
        $gradeSubjectId,
        $title,
        $description,
        $teacherMaterialPath,
        $teacherMaterialOriginalName,
        $teacherMaterialType,
        $teacherMaterialSize,
        $assignedDate,
        $dueDate
    );

    if ($homeworkId <= 0) {
        throw new RuntimeException(
            'Homework could not be created.'
        );
    }

    initializeHomeworkStudentStatuses(
        $conn,
        $homeworkId,
        $academicYearId,
        $grade,
        $section
    );

    $conn->commit();

} catch (Throwable $e) {

    if ($conn->in_transaction) {
        $conn->rollback();
    }

    if ($teacherMaterialPath !== null) {

        $uploadedFilePath =
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $teacherMaterialPath
            );

        if (is_file($uploadedFilePath)) {
            @unlink($uploadedFilePath);
        }
    }

    apiResponse(
        false,
        'Homework could not be created.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Convert dates for response
|--------------------------------------------------------------------------
*/

$assignedParts = explode(
    '-',
    $assignedDate
);

$dueParts = explode(
    '-',
    $dueDate
);

$assignedEthiopian =
    EthiopianCalendar::gregorianToEthiopian(
        (int) $assignedParts[0],
        (int) $assignedParts[1],
        (int) $assignedParts[2]
    );

$dueEthiopian =
    EthiopianCalendar::gregorianToEthiopian(
        (int) $dueParts[0],
        (int) $dueParts[1],
        (int) $dueParts[2]
    );

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

apiResponse(
    true,
    'Homework created successfully.',
    [
        'academic_year' => [
            'id' =>
                $academicYearId,

            'name' =>
                $academicYearName,

            'status' =>
                (string) $academicYear['status'],
        ],

        'homework' => [
            'id' =>
                $homeworkId,

            'grade' =>
                $grade,

            'section' =>
                $section,

            'assignment_id' =>
                $assignmentId,

            'grade_subject_id' =>
                $gradeSubjectId,

            'subject_name' =>
                $subjectName,

            'title' =>
                $title,

            'description' =>
                $description,

            'assigned_date' =>
                $assignedDate,

            'assigned_date_ethiopian' => [
                'year' =>
                    (int) $assignedEthiopian['year'],

                'month' =>
                    (int) $assignedEthiopian['month'],

                'day' =>
                    (int) $assignedEthiopian['day'],

                'formatted' =>
                    (string) $assignedEthiopian['formatted'],
            ],

            'due_date' =>
                $dueDate,

            'due_date_ethiopian' => [
                'year' =>
                    (int) $dueEthiopian['year'],

                'month' =>
                    (int) $dueEthiopian['month'],

                'day' =>
                    (int) $dueEthiopian['day'],

                'formatted' =>
                    (string) $dueEthiopian['formatted'],
            ],

            'status' =>
                'Active',

            'teacher_material' => [
                'exists' =>
                    $teacherMaterialPath !== null,

                'path' =>
                    $teacherMaterialPath,

                'original_name' =>
                    $teacherMaterialOriginalName,

                'type' =>
                    $teacherMaterialType,

                'size' =>
                    $teacherMaterialSize,
            ],
        ],
    ]
);

