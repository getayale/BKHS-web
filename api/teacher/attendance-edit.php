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
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/localhost(?::[0-9]+)?$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization'
);

header(
    'Access-Control-Allow-Methods: GET, POST, OPTIONS'
);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| API Response Helper
|--------------------------------------------------------------------------
*/

function apiResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    $response = [
        'success' => $success,
    ];

    if ($message !== '') {
        $response['message'] = $message;
    }

    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {

    $authorizationHeader =
        trim((string) $_SERVER['HTTP_AUTHORIZATION']);

} elseif (function_exists('getallheaders')) {

    $headers = getallheaders();

    foreach ($headers as $key => $value) {

        if (strtolower($key) === 'authorization') {

            $authorizationHeader =
                trim((string) $value);

            break;
        }
    }
}

if ($authorizationHeader === '') {

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
        $authorizationHeader,
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

$plainToken = trim((string) $matches[1]);

if ($plainToken === '') {

    apiResponse(
        false,
        'Invalid authorization token.',
        [],
        401
    );
}

$tokenHash = hash(
    'sha256',
    $plainToken
);

/*
|--------------------------------------------------------------------------
| Authenticate Token
|--------------------------------------------------------------------------
*/

try {

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

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare token query.'
        );
    }

    $stmt->bind_param(
        's',
        $tokenHash
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $authUser = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API authentication error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to authenticate the request.',
        [],
        500
    );
}

if (!$authUser) {

    apiResponse(
        false,
        'Invalid or expired token.',
        [],
        401
    );
}

/*
|--------------------------------------------------------------------------
| Teacher Authorization
|--------------------------------------------------------------------------
*/

if (
    strtolower(
        trim((string) $authUser['role'])
    ) !== 'teacher'
) {

    apiResponse(
        false,
        'Only teachers can access this attendance API.',
        [],
        403
    );
}

$teacherUserId =
    (int) $authUser['user_id'];

/*
|--------------------------------------------------------------------------
| Timezone / Today
|--------------------------------------------------------------------------
*/

$timezone = new DateTimeZone(
    'Africa/Addis_Ababa'
);

$todayGregorian = (
    new DateTimeImmutable(
        'now',
        $timezone
    )
)->format('Y-m-d');

try {

    $todayEthiopian =
        EthiopianCalendar::fromGregorian(
            $todayGregorian
        );

} catch (Throwable $e) {

    apiResponse(
        false,
        'Unable to calculate the Ethiopian date.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare(
        "
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
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare academic year query.'
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    $academicYear = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API academic year error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to load the active academic year.',
        [],
        500
    );
}

if (!$academicYear) {

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
| Teacher Homeroom Assignments
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare(
        "
        SELECT
            hta.id,
            hta.grade,
            hta.section,
            g.name AS grade_name,
            sec.name AS section_name,
            sec.code AS section_code
        FROM homeroom_teacher_assignments hta
        LEFT JOIN grades g
            ON g.grade_number = hta.grade
        LEFT JOIN sections sec
            ON sec.code = hta.section
        WHERE hta.teacher_user_id = ?
          AND hta.academic_year = ?
          AND hta.is_active = 1
        ORDER BY hta.grade, hta.section
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare assignment query.'
        );
    }

    $stmt->bind_param(
        'is',
        $teacherUserId,
        $academicYearName
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $assignments = [];

    while ($row = $result->fetch_assoc()) {

        $assignments[] = [
            'id' =>
                (int) $row['id'],

            'grade' =>
                (int) $row['grade'],

            'grade_name' =>
                (string) (
                    $row['grade_name'] ??
                    ('Grade ' . $row['grade'])
                ),

            'section' =>
                (string) $row['section'],

            'section_name' =>
                (string) (
                    $row['section_name'] ??
                    ('Section ' . $row['section'])
                ),

            'section_code' =>
                (string) (
                    $row['section_code'] ??
                    $row['section']
                ),
        ];
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API assignment error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to load your assigned class.',
        [],
        500
    );
}

if (empty($assignments)) {

    apiResponse(
        true,
        '',
        [
            'teacher' => [
                'user_id' =>
                    $teacherUserId,

                'full_name' =>
                    (string) $authUser['full_name'],

                'email' =>
                    (string) ($authUser['email'] ?? ''),

                'phone' =>
                    (string) ($authUser['phone'] ?? ''),
            ],

            'academic_year' => [
                'id' =>
                    $academicYearId,

                'name' =>
                    $academicYearName,
            ],

            'today' => [
                'gregorian' =>
                    $todayGregorian,

                'ethiopian' =>
                    $todayEthiopian,
            ],

            'selected_class' => null,

            'assignments' => [],

            'attendance_dates' => [],

            'attendance_date' => null,

            'students' => [],

            'statuses' => [
                'Present',
                'Absent',
                'Late',
                'Excused',
            ],
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Read Request
|--------------------------------------------------------------------------
*/

$body = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $body = $_GET;

} else {

    $rawBody =
        file_get_contents('php://input');

    $body = json_decode(
        $rawBody,
        true
    );

    if (!is_array($body)) {

        apiResponse(
            false,
            'Invalid JSON request.',
            [],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Selected Class
|--------------------------------------------------------------------------
|
| Flutter can send:
|
| {
|     "class": 12,
|     "date": "2018-01-15"
| }
|
| The date remains Ethiopian YYYY-MM-DD.
|
*/

$selectedAssignmentId =
    (int) ($body['class'] ?? 0);

if ($selectedAssignmentId <= 0) {

    $selectedAssignmentId =
        (int) $assignments[0]['id'];
}

$selectedAssignment = null;

foreach ($assignments as $assignment) {

    if (
        (int) $assignment['id'] ===
        $selectedAssignmentId
    ) {

        $selectedAssignment =
            $assignment;

        break;
    }
}

if (!$selectedAssignment) {

    apiResponse(
        false,
        'You are not authorized to edit this class.',
        [],
        403
    );
}

$gradeNumber =
    (int) $selectedAssignment['grade'];

$sectionCode =
    (string) $selectedAssignment['section'];

/*
|--------------------------------------------------------------------------
| Resolve Grade / Section IDs
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare(
        "
        SELECT
            g.id AS grade_id,
            sec.id AS section_id
        FROM grades g
        INNER JOIN sections sec
            ON sec.code = ?
        WHERE g.grade_number = ?
        LIMIT 1
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare class resolution query.'
        );
    }

    $stmt->bind_param(
        'si',
        $sectionCode,
        $gradeNumber
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $classIds = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API class resolution error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to resolve your assigned class.',
        [],
        500
    );
}

if (!$classIds) {

    apiResponse(
        false,
        'Your assigned class could not be resolved.',
        [],
        404
    );
}

$gradeId =
    (int) $classIds['grade_id'];

$sectionId =
    (int) $classIds['section_id'];

/*
|--------------------------------------------------------------------------
| Selected Ethiopian Date
|--------------------------------------------------------------------------
*/

$selectedEthiopianDate =
    trim((string) ($body['date'] ?? ''));

$selectedGregorianDate = null;

$ethiopianYear = null;
$ethiopianMonth = null;
$ethiopianDay = null;

/*
|--------------------------------------------------------------------------
| Convert Ethiopian Date To Gregorian
|--------------------------------------------------------------------------
*/

if ($selectedEthiopianDate !== '') {

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedEthiopianDate
        )
    ) {

        apiResponse(
            false,
            'Invalid Ethiopian date. Use YYYY-MM-DD.',
            [],
            400
        );
    }

    $dateParts =
        explode(
            '-',
            $selectedEthiopianDate
        );

    $ethiopianYear =
        (int) $dateParts[0];

    $ethiopianMonth =
        (int) $dateParts[1];

    $ethiopianDay =
        (int) $dateParts[2];

    if (
        $ethiopianMonth < 1 ||
        $ethiopianMonth > 13 ||
        $ethiopianDay < 1 ||
        $ethiopianDay > 30
    ) {

        apiResponse(
            false,
            'Invalid Ethiopian date.',
            [],
            400
        );
    }

    try {

        $selectedGregorianDate =
            EthiopianCalendar::toGregorian(
                $ethiopianYear,
                $ethiopianMonth,
                $ethiopianDay
            );

    } catch (Throwable $e) {

        apiResponse(
            false,
            'Invalid Ethiopian date.',
            [],
            400
        );
    }

    if (
        !is_string($selectedGregorianDate) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedGregorianDate
        )
    ) {

        apiResponse(
            false,
            'Unable to convert the Ethiopian date.',
            [],
            400
        );
    }

    if (
        $selectedGregorianDate >
        $todayGregorian
    ) {

        apiResponse(
            false,
            'Future dates cannot be edited.',
            [],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Attendance Dates
|--------------------------------------------------------------------------
*/

$attendanceDates = [];

try {

    $stmt = $conn->prepare(
        "
        SELECT DISTINCT
            attendance_date
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND attendance_date <= ?
        ORDER BY attendance_date DESC
        LIMIT 500
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare attendance date query.'
        );
    }

    $stmt->bind_param(
        'iiis',
        $academicYearId,
        $gradeId,
        $sectionId,
        $todayGregorian
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $date =
            (string) $row['attendance_date'];

        try {

            $ethiopian =
                EthiopianCalendar::fromGregorian(
                    $date
                );

            $attendanceDates[] = [
                'gregorian' =>
                    $date,

                'ethiopian' =>
                    $ethiopian,
            ];

        } catch (Throwable $e) {
            continue;
        }
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API dates error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to load attendance dates.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Verify Selected Date Exists
|--------------------------------------------------------------------------
*/

if ($selectedGregorianDate !== null) {

    $dateExists = false;

    foreach (
        $attendanceDates
        as $attendanceDate
    ) {

        if (
            $attendanceDate['gregorian'] ===
            $selectedGregorianDate
        ) {

            $dateExists = true;

            break;
        }
    }

    if (!$dateExists) {

        apiResponse(
            false,
            'No attendance record exists for the selected Ethiopian date.',
            [],
            404
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load Students
|--------------------------------------------------------------------------
*/

$students = [];

try {

    $stmt = $conn->prepare(
        "
        SELECT
            sr.id AS registration_id,
            s.id AS student_id,
            s.student_code,
            s.full_name
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
        ORDER BY s.full_name
        LIMIT 5000
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare student query.'
        );
    }

    $stmt->bind_param(
        'iii',
        $academicYearId,
        $gradeId,
        $sectionId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $students[] = [
            'registration_id' =>
                (int) $row['registration_id'],

            'student_id' =>
                (int) $row['student_id'],

            'student_code' =>
                (string) $row['student_code'],

            'full_name' =>
                (string) $row['full_name'],

            'status' =>
                null,
        ];
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance API students error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to load students.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Load Existing Attendance
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Attendance is matched by registration_id,
| not student_id.
|
*/

if ($selectedGregorianDate !== null) {

    $attendanceMap = [];

    try {

        $stmt = $conn->prepare(
            "
            SELECT
                registration_id,
                status
            FROM student_attendance
            WHERE academic_year_id = ?
              AND grade_id = ?
              AND section_id = ?
              AND attendance_date = ?
            "
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare attendance query.'
            );
        }

        $stmt->bind_param(
            'iiis',
            $academicYearId,
            $gradeId,
            $sectionId,
            $selectedGregorianDate
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $attendanceMap[
                (int) $row['registration_id']
            ] = (string) $row['status'];
        }

        $stmt->close();

    } catch (Throwable $e) {

        error_log(
            'BKHS attendance API attendance load error: ' .
            $e->getMessage()
        );

        apiResponse(
            false,
            'Unable to load the selected attendance.',
            [],
            500
        );
    }

    foreach ($students as &$student) {

        $registrationId =
            (int) $student['registration_id'];

        $student['status'] =
            $attendanceMap[$registrationId] ?? null;
    }

    unset($student);
}

/*
|--------------------------------------------------------------------------
| POST - Update Attendance
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($selectedGregorianDate === null) {

        apiResponse(
            false,
            'Ethiopian attendance date is required.',
            [],
            400
        );
    }

    $submittedStudents =
        $body['students'] ?? null;

    if (
        !is_array($submittedStudents) ||
        empty($submittedStudents)
    ) {

        apiResponse(
            false,
            'Student attendance data is required.',
            [],
            400
        );
    }

    $allowedStatuses = [
        'Present',
        'Absent',
        'Late',
        'Excused',
    ];

    /*
    |--------------------------------------------------------------------------
    | Ethiopian Date Values
    |--------------------------------------------------------------------------
    */

    if (
        $ethiopianYear === null ||
        $ethiopianMonth === null ||
        $ethiopianDay === null
    ) {

        try {

            $selectedEthiopian =
                EthiopianCalendar::fromGregorian(
                    $selectedGregorianDate
                );

            $ethiopianYear =
                (int) $selectedEthiopian['year'];

            $ethiopianMonth =
                (int) $selectedEthiopian['month'];

            $ethiopianDay =
                (int) $selectedEthiopian['day'];

        } catch (Throwable $e) {

            apiResponse(
                false,
                'Unable to calculate the Ethiopian date.',
                [],
                500
            );
        }
    }

    try {

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Update Existing Attendance
        |--------------------------------------------------------------------------
        */

        $update = $conn->prepare(
            "
            UPDATE student_attendance
            SET
                status = ?,
                updated_by = ?
            WHERE registration_id = ?
              AND attendance_date = ?
              AND academic_year_id = ?
              AND grade_id = ?
              AND section_id = ?
            "
        );

        if (!$update) {
            throw new RuntimeException(
                'Unable to prepare attendance update.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Insert Missing Attendance
        |--------------------------------------------------------------------------
        */

        $insert = $conn->prepare(
            "
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
            "
        );

        if (!$insert) {
            throw new RuntimeException(
                'Unable to prepare attendance insert.'
            );
        }

        foreach ($submittedStudents as $submittedStudent) {

            if (!is_array($submittedStudent)) {

                throw new RuntimeException(
                    'Invalid student attendance data.'
                );
            }

            $studentId =
                (int) (
                    $submittedStudent['student_id'] ?? 0
                );

            $registrationId =
                (int) (
                    $submittedStudent['registration_id'] ?? 0
                );

            $status =
                trim(
                    (string) (
                        $submittedStudent['status'] ?? ''
                    )
                );

            if (
                $studentId <= 0 ||
                $registrationId <= 0 ||
                !in_array(
                    $status,
                    $allowedStatuses,
                    true
                )
            ) {

                throw new RuntimeException(
                    'Invalid student attendance data.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Registration
            |--------------------------------------------------------------------------
            |
            | This prevents a teacher from submitting a registration
            | belonging to another class or academic year.
            |
            */

            $check = $conn->prepare(
                "
                SELECT
                    sr.id
                FROM student_registrations sr
                INNER JOIN students s
                    ON s.id = sr.student_id
                WHERE sr.id = ?
                  AND sr.student_id = ?
                  AND sr.academic_year_id = ?
                  AND sr.grade_id = ?
                  AND sr.section_id = ?
                LIMIT 1
                "
            );

            if (!$check) {
                throw new RuntimeException(
                    'Unable to validate student registration.'
                );
            }

            $check->bind_param(
                'iiiii',
                $registrationId,
                $studentId,
                $academicYearId,
                $gradeId,
                $sectionId
            );

            $check->execute();

            $checkResult =
                $check->get_result();

            $validStudent =
                $checkResult->fetch_assoc();

            $check->close();

            if (!$validStudent) {

                throw new RuntimeException(
                    'A student does not belong to the selected class.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Check Existing Attendance By Registration
            |--------------------------------------------------------------------------
            */

            $checkAttendance =
                $conn->prepare(
                    "
                    SELECT
                        id
                    FROM student_attendance
                    WHERE registration_id = ?
                      AND attendance_date = ?
                      AND academic_year_id = ?
                      AND grade_id = ?
                      AND section_id = ?
                    LIMIT 1
                    "
                );

            if (!$checkAttendance) {
                throw new RuntimeException(
                    'Unable to check attendance record.'
                );
            }

            $checkAttendance->bind_param(
                'isiii',
                $registrationId,
                $selectedGregorianDate,
                $academicYearId,
                $gradeId,
                $sectionId
            );

            $checkAttendance->execute();

            $attendanceResult =
                $checkAttendance->get_result();

            $existingAttendance =
                $attendanceResult->fetch_assoc();

            $checkAttendance->close();

            /*
            |--------------------------------------------------------------------------
            | Existing Record -> UPDATE
            |--------------------------------------------------------------------------
            */

            if ($existingAttendance) {

                $update->bind_param(
                    'siisiii',
                    $status,
                    $teacherUserId,
                    $registrationId,
                    $selectedGregorianDate,
                    $academicYearId,
                    $gradeId,
                    $sectionId
                );

                $update->execute();

            } else {

                /*
                |--------------------------------------------------------------------------
                | Missing Record -> INSERT
                |--------------------------------------------------------------------------
                */

                $ethiopianDateText =
                    $selectedEthiopianDate;

                $insert->bind_param(
                    'iiiiisiiissii',
                    $studentId,
                    $registrationId,
                    $academicYearId,
                    $gradeId,
                    $sectionId,
                    $selectedGregorianDate,
                    $ethiopianYear,
                    $ethiopianMonth,
                    $ethiopianDay,
                    $ethiopianDateText,
                    $status,
                    $teacherUserId,
                    $teacherUserId
                );

                $insert->execute();
            }
        }

        $update->close();
        $insert->close();

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();

        error_log(
            'BKHS attendance API update error: ' .
            $e->getMessage()
        );

        apiResponse(
            false,
            'Unable to update attendance. Please try again.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return Updated Attendance
    |--------------------------------------------------------------------------
    */

    $updatedStudents = [];

    try {

        $stmt = $conn->prepare(
            "
            SELECT
                sr.id AS registration_id,
                s.id AS student_id,
                s.student_code,
                s.full_name,
                sa.status
            FROM student_registrations sr
            INNER JOIN students s
                ON s.id = sr.student_id
            LEFT JOIN student_attendance sa
                ON sa.registration_id = sr.id
                AND sa.attendance_date = ?
                AND sa.academic_year_id = ?
                AND sa.grade_id = ?
                AND sa.section_id = ?
            WHERE sr.academic_year_id = ?
              AND sr.grade_id = ?
              AND sr.section_id = ?
            ORDER BY s.full_name
            LIMIT 5000
            "
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare updated attendance query.'
            );
        }

        $stmt->bind_param(
            'siiiiii',
            $selectedGregorianDate,
            $academicYearId,
            $gradeId,
            $sectionId,
            $academicYearId,
            $gradeId,
            $sectionId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $updatedStudents[] = [
                'registration_id' =>
                    (int) $row['registration_id'],

                'student_id' =>
                    (int) $row['student_id'],

                'student_code' =>
                    (string) $row['student_code'],

                'full_name' =>
                    (string) $row['full_name'],

                'status' =>
                    $row['status'] !== null
                        ? (string) $row['status']
                        : null,
            ];
        }

        $stmt->close();

    } catch (Throwable $e) {

        error_log(
            'BKHS attendance API updated response error: ' .
            $e->getMessage()
        );

        apiResponse(
            true,
            'Attendance updated successfully.',
            [
                'attendance_date' => [
                    'gregorian' =>
                        $selectedGregorianDate,

                    'ethiopian' =>
                        $selectedEthiopianDate,
                ],

                'selected_class' => [
                    'id' =>
                        (int) $selectedAssignment['id'],

                    'grade' =>
                        $gradeNumber,

                    'grade_name' =>
                        (string) $selectedAssignment['grade_name'],

                    'section' =>
                        $sectionCode,

                    'section_name' =>
                        (string) $selectedAssignment['section_name'],
                ],
            ]
        );
    }

    apiResponse(
        true,
        'Attendance updated successfully.',
        [
            'attendance_date' => [
                'gregorian' =>
                    $selectedGregorianDate,

                'ethiopian' =>
                    $selectedEthiopianDate,
            ],

            'selected_class' => [
                'id' =>
                    (int) $selectedAssignment['id'],

                'grade' =>
                    $gradeNumber,

                'grade_name' =>
                    (string) $selectedAssignment['grade_name'],

                'section' =>
                    $sectionCode,

                'section_name' =>
                    (string) $selectedAssignment['section_name'],
            ],

            'students' =>
                $updatedStudents,
        ]
    );
}

/*
|--------------------------------------------------------------------------
| GET Response
|--------------------------------------------------------------------------
*/

$selectedDateInfo = null;

if ($selectedGregorianDate !== null) {

    $selectedDateInfo = [
        'gregorian' =>
            $selectedGregorianDate,

        'ethiopian' =>
            $selectedEthiopianDate,
    ];
}

apiResponse(
    true,
    '',
    [
        'teacher' => [
            'user_id' =>
                $teacherUserId,

            'full_name' =>
                (string) $authUser['full_name'],

            'email' =>
                (string) ($authUser['email'] ?? ''),

            'phone' =>
                (string) ($authUser['phone'] ?? ''),
        ],

        'academic_year' => [
            'id' =>
                $academicYearId,

            'name' =>
                $academicYearName,
        ],

        'today' => [
            'gregorian' =>
                $todayGregorian,

            'ethiopian' =>
                $todayEthiopian,
        ],

        'selected_class' => [
            'id' =>
                (int) $selectedAssignment['id'],

            'grade' =>
                $gradeNumber,

            'grade_name' =>
                (string) $selectedAssignment['grade_name'],

            'section' =>
                $sectionCode,

            'section_name' =>
                (string) $selectedAssignment['section_name'],
        ],

        'assignments' =>
            $assignments,

        'attendance_dates' =>
            $attendanceDates,

        'attendance_date' =>
            $selectedDateInfo,

        'students' =>
            $students,

        'statuses' => [
            'Present',
            'Absent',
            'Late',
            'Excused',
        ],
    ]
);

