<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && preg_match($allowedOriginPattern, $origin)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

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
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function getBearerToken(): string
{
    $authorization = getAuthorizationHeader();

    if ($authorization === '') {
        apiResponse(
            false,
            'Authorization token is required.',
            [],
            401
        );
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        apiResponse(
            false,
            'Invalid authorization format.',
            [],
            401
        );
    }

    return trim($matches[1]);
}

function getEthiopianDateFromGregorian(string $date): array
{
    $parts = explode('-', $date);

    if (count($parts) !== 3) {
        throw new Exception('Invalid Gregorian date.');
    }

    return EthiopianCalendar::gregorianToEthiopian(
        (int) $parts[0],
        (int) $parts[1],
        (int) $parts[2]
    );
}

function getTodayDates(): array
{
    $timezone = new DateTimeZone('Africa/Addis_Ababa');

    $now = new DateTimeImmutable(
        'now',
        $timezone
    );

    $todayGregorian = $now->format('Y-m-d');

    $ethiopian = getEthiopianDateFromGregorian(
        $todayGregorian
    );

    $ethYear = (int) ($ethiopian['year'] ?? 0);
    $ethMonth = (int) ($ethiopian['month'] ?? 0);
    $ethDay = (int) ($ethiopian['day'] ?? 0);

    $ethFormatted = EthiopianCalendar::format(
        $ethYear,
        $ethMonth,
        $ethDay
    );

    return [
        'gregorian' => $todayGregorian,
        'ethiopian' => $ethFormatted,
        'year' => $ethYear,
        'month' => $ethMonth,
        'day' => $ethDay
    ];
}

function teacherOwnsClass(
    mysqli $conn,
    int $teacherUserId,
    string $academicYearName,
    int $grade,
    string $section
): bool {
    $stmt = $conn->prepare("
        SELECT id
        FROM homeroom_teacher_assignments
        WHERE teacher_user_id = ?
          AND academic_year = ?
          AND grade = ?
          AND section = ?
          AND is_active = 1
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'isis',
        $teacherUserId,
        $academicYearName,
        $grade,
        $section
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $exists = $result->num_rows > 0;

    $stmt->close();

    return $exists;
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

$tokenHash = hash(
    'sha256',
    $token
);

$authStmt = $conn->prepare("
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
");

if (!$authStmt) {
    apiResponse(
        false,
        'Unable to prepare authentication query.',
        [],
        500
    );
}

$authStmt->bind_param(
    's',
    $tokenHash
);

$authStmt->execute();

$authResult = $authStmt->get_result();

$teacher = $authResult->fetch_assoc();

$authStmt->close();

if (!$teacher) {
    apiResponse(
        false,
        'Invalid or expired authorization token.',
        [],
        401
    );
}

if (
    strtolower(
        (string) ($teacher['role'] ?? '')
    ) !== 'teacher'
) {
    apiResponse(
        false,
        'Only teachers can manage attendance.',
        [],
        403
    );
}

$teacherUserId = (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name,
        start_year,
        start_month,
        start_day,
        end_year,
        end_month,
        end_day,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$academicYearStmt) {
    apiResponse(
        false,
        'Unable to load academic year.',
        [],
        500
    );
}

$academicYearStmt->execute();

$academicYearResult = $academicYearStmt->get_result();

$academicYear = $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    apiResponse(
        false,
        'There is no active academic year.',
        [],
        400
    );
}

$academicYearId = (int) $academicYear['id'];

$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Today's Date
|--------------------------------------------------------------------------
*/

try {
    $today = getTodayDates();
} catch (Throwable $e) {
    apiResponse(
        false,
        "Unable to determine today's Ethiopian date.",
        [],
        500
    );
}

$todayGregorian = $today['gregorian'];
$todayEthFormatted = $today['ethiopian'];
$todayEthYear = $today['year'];
$todayEthMonth = $today['month'];
$todayEthDay = $today['day'];

/*
|--------------------------------------------------------------------------
| Teacher Homeroom Classes
|--------------------------------------------------------------------------
*/

$classes = [];

$classStmt = $conn->prepare("
    SELECT
        hta.id,
        hta.grade,
        hta.section,
        g.id AS grade_id,
        g.name AS grade_name,
        sec.id AS section_id,
        sec.name AS section_name,
        sec.code AS section_code
    FROM homeroom_teacher_assignments hta
    INNER JOIN grades g
        ON g.grade_number = hta.grade
    INNER JOIN sections sec
        ON sec.code = hta.section
    WHERE hta.teacher_user_id = ?
      AND hta.academic_year = ?
      AND hta.is_active = 1
    ORDER BY hta.grade, hta.section
");

if (!$classStmt) {
    apiResponse(
        false,
        'Unable to load teacher classes.',
        [],
        500
    );
}

$classStmt->bind_param(
    'is',
    $teacherUserId,
    $academicYearName
);

$classStmt->execute();

$classResult = $classStmt->get_result();

while ($row = $classResult->fetch_assoc()) {
    $grade = (int) $row['grade'];

    $section = (string) $row['section'];

    $classes[] = [
        'id' => (int) $row['id'],
        'key' => $grade . '-' . $section,
        'grade' => $grade,
        'grade_name' => (string) ($row['grade_name'] ?? ''),
        'section' => $section,
        'section_name' => (string) ($row['section_name'] ?? ''),
        'section_code' => (string) ($row['section_code'] ?? $section),
        'grade_id' => (int) $row['grade_id'],
        'section_id' => (int) $row['section_id']
    ];
}

$classStmt->close();

if (count($classes) === 0) {
    apiResponse(
        true,
        'No homeroom class is assigned to this teacher.',
        [
            'teacher' => [
                'id' => $teacherUserId,
                'full_name' => (string) $teacher['full_name'],
                'email' => (string) ($teacher['email'] ?? ''),
                'phone' => (string) ($teacher['phone'] ?? ''),
                'role' => (string) $teacher['role']
            ],

            'academic_year' => [
                'id' => $academicYearId,
                'name' => $academicYearName
            ],

            'today' => [
                'gregorian' => $todayGregorian,
                'ethiopian' => $todayEthFormatted,
                'year' => $todayEthYear,
                'month' => $todayEthMonth,
                'day' => $todayEthDay,
                'formatted' => $todayEthFormatted
            ],

            'selected_class' => null,

            'classes' => [],

            'attendance_taken' => false,

            'attendance_date' => [
                'gregorian' => $todayGregorian,
                'ethiopian' => $todayEthFormatted
            ],

            'statistics' => [
                'Present' => 0,
                'Absent' => 0,
                'Late' => 0,
                'Excused' => 0
            ],

            'pagination' => [
                'page' => 1,
                'per_page' => 10,
                'total_students' => 0,
                'total_pages' => 1
            ],

            'students' => [],

            'statuses' => [
                'Present',
                'Absent',
                'Late',
                'Excused'
            ]
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Read Request Data
|--------------------------------------------------------------------------
*/

$requestMethod = $_SERVER['REQUEST_METHOD'];

$requestedClass = '';

$body = [];

if ($requestMethod === 'GET') {
    $requestedClass = trim(
        (string) ($_GET['class'] ?? '')
    );
} elseif ($requestMethod === 'POST') {
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        apiResponse(
            false,
            'Request body is required.',
            [],
            400
        );
    }

    $body = json_decode(
        $rawBody,
        true
    );

    if (!is_array($body)) {
        apiResponse(
            false,
            'Invalid JSON request body.',
            [],
            400
        );
    }

    $requestedClass = trim(
        (string) ($body['class'] ?? '')
    );
}

/*
|--------------------------------------------------------------------------
| Selected Class
|--------------------------------------------------------------------------
*/

$selectedClass = $classes[0];

if ($requestedClass !== '') {
    foreach ($classes as $class) {
        if (
            (string) $class['key'] ===
            $requestedClass
        ) {
            $selectedClass = $class;
            break;
        }
    }
}

$selectedGrade = (int) $selectedClass['grade'];

$selectedSection = (string) $selectedClass['section'];

$selectedGradeId = (int) $selectedClass['grade_id'];

$selectedSectionId = (int) $selectedClass['section_id'];

$selectedClassKey = (string) $selectedClass['key'];

/*
|--------------------------------------------------------------------------
| Verify Teacher Assignment
|--------------------------------------------------------------------------
*/

if (
    !teacherOwnsClass(
        $conn,
        $teacherUserId,
        $academicYearName,
        $selectedGrade,
        $selectedSection
    )
) {
    apiResponse(
        false,
        'You are not authorized to manage attendance for this class.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| POST — Save Today's Attendance
|--------------------------------------------------------------------------
*/

if ($requestMethod === 'POST') {
    $submittedStudents = $body['students'] ?? null;

    if (!is_array($submittedStudents)) {
        apiResponse(
            false,
            'Students attendance data is required.',
            [],
            400
        );
    }

    $allowedStatuses = [
        'Present',
        'Absent',
        'Late',
        'Excused'
    ];

    if (count($submittedStudents) === 0) {
        apiResponse(
            false,
            'No students were submitted.',
            [],
            400
        );
    }

    try {
        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Verify Submitted Students
        |--------------------------------------------------------------------------
        */

        $studentCheckStmt = $conn->prepare("
            SELECT
                sr.id AS registration_id,
                sr.student_id
            FROM student_registrations sr
            WHERE sr.id = ?
              AND sr.student_id = ?
              AND sr.academic_year_id = ?
              AND sr.grade_id = ?
              AND sr.section_id = ?
            LIMIT 1
        ");

        if (!$studentCheckStmt) {
            throw new Exception(
                'Unable to verify student registration.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Existing Attendance
        |--------------------------------------------------------------------------
        */

        $existingStmt = $conn->prepare("
            SELECT id
            FROM student_attendance
            WHERE student_id = ?
              AND registration_id = ?
              AND academic_year_id = ?
              AND grade_id = ?
              AND section_id = ?
              AND attendance_date = ?
            LIMIT 1
        ");

        if (!$existingStmt) {
            throw new Exception(
                'Unable to check existing attendance.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Existing Attendance
        |--------------------------------------------------------------------------
        */

        $updateStmt = $conn->prepare("
            UPDATE student_attendance
            SET
                status = ?,
                updated_by = ?
            WHERE id = ?
        ");

        if (!$updateStmt) {
            throw new Exception(
                'Unable to prepare attendance update.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Insert New Attendance
        |--------------------------------------------------------------------------
        */

        $insertStmt = $conn->prepare("
            INSERT INTO student_attendance (
                student_id,
                registration_id,
                academic_year_id,
                grade_id,
                section_id,
                attendance_date,
                ethiopian_year,
                ethiopian_month,
                ethiopian_day,
                ethiopian_date,
                status,
                marked_by,
                updated_by
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        if (!$insertStmt) {
            throw new Exception(
                'Unable to prepare attendance insert.'
            );
        }

        $savedCount = 0;

        foreach ($submittedStudents as $student) {
            if (!is_array($student)) {
                throw new Exception(
                    'Invalid student attendance data.'
                );
            }

            $studentId = (int) (
                $student['student_id'] ?? 0
            );

            $registrationId = (int) (
                $student['registration_id'] ?? 0
            );

            $status = trim(
                (string) (
                    $student['status'] ?? ''
                )
            );

            if ($studentId <= 0) {
                throw new Exception(
                    'Invalid student ID.'
                );
            }

            if ($registrationId <= 0) {
                throw new Exception(
                    'Invalid registration ID.'
                );
            }

            if (
                !in_array(
                    $status,
                    $allowedStatuses,
                    true
                )
            ) {
                throw new Exception(
                    'Invalid attendance status for student ID ' .
                    $studentId .
                    '.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Registration Belongs to Selected Class
            |--------------------------------------------------------------------------
            */

            $studentCheckStmt->bind_param(
                'iiiii',
                $registrationId,
                $studentId,
                $academicYearId,
                $selectedGradeId,
                $selectedSectionId
            );

            if (!$studentCheckStmt->execute()) {
                throw new Exception(
                    'Unable to verify student registration for student ID ' .
                    $studentId .
                    '.'
                );
            }

            $studentCheckResult =
                $studentCheckStmt->get_result();

            $validStudent =
                $studentCheckResult->fetch_assoc();

            if (!$validStudent) {
                throw new Exception(
                    'Student ID ' .
                    $studentId .
                    ' does not belong to the selected class.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Find Existing Record
            |--------------------------------------------------------------------------
            */

            $existingId = 0;

            $existingStmt->bind_param(
                'iiiiis',
                $studentId,
                $registrationId,
                $academicYearId,
                $selectedGradeId,
                $selectedSectionId,
                $todayGregorian
            );

            if (!$existingStmt->execute()) {
                throw new Exception(
                    'Unable to check existing attendance for student ID ' .
                    $studentId .
                    '.'
                );
            }

            $existingResult =
                $existingStmt->get_result();

            $existing =
                $existingResult->fetch_assoc();

            if ($existing) {
                $existingId =
                    (int) $existing['id'];
            }

            /*
            |--------------------------------------------------------------------------
            | Update Existing Attendance
            |--------------------------------------------------------------------------
            */

            if ($existingId > 0) {
                $updateStmt->bind_param(
                    'sii',
                    $status,
                    $teacherUserId,
                    $existingId
                );

                if (!$updateStmt->execute()) {
                    throw new Exception(
                        'Failed to update attendance for student ID ' .
                        $studentId .
                        '.'
                    );
                }
            } else {
                /*
                |--------------------------------------------------------------------------
                | Insert New Attendance
                |--------------------------------------------------------------------------
                */

                $markedBy = $teacherUserId;

                $updatedBy = $teacherUserId;

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT:
                |
                | status is STRING, not INTEGER.
                |
                | Correct types:
                | i i i i i s i i i s s i i
                |--------------------------------------------------------------------------
                */

                $insertStmt->bind_param(
                    'iiiiisiiissii',
                    $studentId,
                    $registrationId,
                    $academicYearId,
                    $selectedGradeId,
                    $selectedSectionId,
                    $todayGregorian,
                    $todayEthYear,
                    $todayEthMonth,
                    $todayEthDay,
                    $todayEthFormatted,
                    $status,
                    $markedBy,
                    $updatedBy
                );

                if (!$insertStmt->execute()) {
                    throw new Exception(
                        'Failed to save attendance for student ID ' .
                        $studentId .
                        ': ' .
                        $insertStmt->error
                    );
                }
            }

            $savedCount++;
        }

        $studentCheckStmt->close();
        $existingStmt->close();
        $updateStmt->close();
        $insertStmt->close();

        $conn->commit();

        apiResponse(
            true,
            "Attendance saved successfully for {$savedCount} students.",
            [
                'attendance_taken' => true,

                'attendance_date' => [
                    'gregorian' => $todayGregorian,
                    'ethiopian' => $todayEthFormatted
                ],

                'selected_class' => [
                    'id' => (int) $selectedClass['id'],
                    'key' => $selectedClassKey,
                    'grade' => $selectedGrade,
                    'grade_name' => (string) $selectedClass['grade_name'],
                    'section' => $selectedSection,
                    'section_name' => (string) $selectedClass['section_name']
                ],

                'saved_count' => $savedCount
            ]
        );
    } catch (Throwable $e) {
        if ($conn->errno === 0 || true) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        }

        apiResponse(
            false,
            'Attendance could not be saved: ' .
            $e->getMessage(),
            [],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| GET — Load Today's Attendance
|--------------------------------------------------------------------------
*/

if ($requestMethod !== 'GET') {
    apiResponse(
        false,
        'Unsupported request method.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Total Students
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM student_registrations sr
    INNER JOIN students s
        ON s.id = sr.student_id
    WHERE sr.academic_year_id = ?
      AND sr.grade_id = ?
      AND sr.section_id = ?
      AND s.is_deleted = 0
");

if (!$countStmt) {
    apiResponse(
        false,
        'Unable to count students.',
        [],
        500
    );
}

$countStmt->bind_param(
    'iii',
    $academicYearId,
    $selectedGradeId,
    $selectedSectionId
);

$countStmt->execute();

$countResult = $countStmt->get_result();

$countRow = $countResult->fetch_assoc();

$totalStudents = (int) (
    $countRow['total'] ?? 0
);

$countStmt->close();

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$perPage = 10;

$totalPages = max(
    1,
    (int) ceil(
        $totalStudents / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Students
|--------------------------------------------------------------------------
*/

$students = [];

if ($totalStudents > 0) {
    $studentsStmt = $conn->prepare("
        SELECT
            sr.id AS registration_id,
            s.id AS student_id,
            s.student_code,
            s.full_name,
            sa.status AS attendance_status
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        LEFT JOIN student_attendance sa
            ON sa.student_id = sr.student_id
            AND sa.registration_id = sr.id
            AND sa.attendance_date = ?
            AND sa.academic_year_id = sr.academic_year_id
            AND sa.grade_id = sr.grade_id
            AND sa.section_id = sr.section_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND s.is_deleted = 0
        ORDER BY s.student_code
        LIMIT ? OFFSET ?
    ");

    if (!$studentsStmt) {
        apiResponse(
            false,
            'Unable to load students.',
            [],
            500
        );
    }

    $studentsStmt->bind_param(
        'siiiii',
        $todayGregorian,
        $academicYearId,
        $selectedGradeId,
        $selectedSectionId,
        $perPage,
        $offset
    );

    $studentsStmt->execute();

    $studentsResult =
        $studentsStmt->get_result();

    while (
        $student =
        $studentsResult->fetch_assoc()
    ) {
        $students[] = [
            'registration_id' =>
                (int) $student['registration_id'],

            'student_id' =>
                (int) $student['student_id'],

            'student_code' =>
                (string) ($student['student_code'] ?? ''),

            'full_name' =>
                (string) $student['full_name'],

            'status' =>
                $student['attendance_status'] !== null
                    ? (string) $student['attendance_status']
                    : null
        ];
    }

    $studentsStmt->close();
}

/*
|--------------------------------------------------------------------------
| Today's Statistics
|--------------------------------------------------------------------------
*/

$statistics = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0
];

$statsStmt = $conn->prepare("
    SELECT
        sa.status,
        COUNT(*) AS total
    FROM student_attendance sa
    WHERE sa.attendance_date = ?
      AND sa.academic_year_id = ?
      AND sa.grade_id = ?
      AND sa.section_id = ?
    GROUP BY sa.status
");

if ($statsStmt) {
    $statsStmt->bind_param(
        'siii',
        $todayGregorian,
        $academicYearId,
        $selectedGradeId,
        $selectedSectionId
    );

    $statsStmt->execute();

    $statsResult =
        $statsStmt->get_result();

    while (
        $row =
        $statsResult->fetch_assoc()
    ) {
        $status =
            (string) ($row['status'] ?? '');

        if (
            array_key_exists(
                $status,
                $statistics
            )
        ) {
            $statistics[$status] =
                (int) $row['total'];
        }
    }

    $statsStmt->close();
}

/*
|--------------------------------------------------------------------------
| Determine Whether Attendance Is Complete
|--------------------------------------------------------------------------
|
| Attendance is considered taken only when
| every student in the selected class has
| an attendance record for today.
|--------------------------------------------------------------------------
*/

$recordedCount = 0;

$recordedStmt = $conn->prepare("
    SELECT COUNT(DISTINCT sa.student_id) AS total
    FROM student_attendance sa
    INNER JOIN student_registrations sr
        ON sr.id = sa.registration_id
        AND sr.student_id = sa.student_id
    INNER JOIN students s
        ON s.id = sr.student_id
    WHERE sa.attendance_date = ?
      AND sa.academic_year_id = ?
      AND sa.grade_id = ?
      AND sa.section_id = ?
      AND sr.academic_year_id = ?
      AND sr.grade_id = ?
      AND sr.section_id = ?
      AND s.is_deleted = 0
");

if ($recordedStmt) {
    $recordedStmt->bind_param(
        'siiiiii',
        $todayGregorian,
        $academicYearId,
        $selectedGradeId,
        $selectedSectionId,
        $academicYearId,
        $selectedGradeId,
        $selectedSectionId
    );

    $recordedStmt->execute();

    $recordedResult =
        $recordedStmt->get_result();

    $recordedRow =
        $recordedResult->fetch_assoc();

    $recordedCount =
        (int) ($recordedRow['total'] ?? 0);

    $recordedStmt->close();
}

$attendanceTaken =
    $totalStudents > 0 &&
    $recordedCount >= $totalStudents;

/*
|--------------------------------------------------------------------------
| Final Response
|--------------------------------------------------------------------------
*/

apiResponse(
    true,
    '',
    [
        'teacher' => [
            'id' => $teacherUserId,
            'full_name' => (string) $teacher['full_name'],
            'email' => (string) ($teacher['email'] ?? ''),
            'phone' => (string) ($teacher['phone'] ?? ''),
            'role' => (string) $teacher['role']
        ],

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
            'status' => (string) $academicYear['status']
        ],

        'today' => [
            'gregorian' => $todayGregorian,
            'ethiopian' => $todayEthFormatted,
            'year' => $todayEthYear,
            'month' => $todayEthMonth,
            'day' => $todayEthDay,
            'formatted' => $todayEthFormatted
        ],

        'selected_class' => [
            'id' => (int) $selectedClass['id'],
            'key' => $selectedClassKey,
            'grade' => $selectedGrade,
            'grade_name' => (string) $selectedClass['grade_name'],
            'section' => $selectedSection,
            'section_name' => (string) $selectedClass['section_name']
        ],

        'classes' => $classes,

        'attendance_taken' => $attendanceTaken,

        'attendance_date' => [
            'gregorian' => $todayGregorian,
            'ethiopian' => $todayEthFormatted
        ],

        'statistics' => $statistics,

        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_students' => $totalStudents,
            'total_pages' => $totalPages
        ],

        'students' => $students,

        'statuses' => [
            'Present',
            'Absent',
            'Late',
            'Excused'
        ]
    ]
);