<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match($allowedOriginPattern, $origin)
) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON Response Helper
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

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim(
            (string) $_SERVER['HTTP_AUTHORIZATION']
        );
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $key => $value) {
            if (
                strtolower((string) $key) ===
                'authorization'
            ) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Bearer Token
|--------------------------------------------------------------------------
*/

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

    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            $authorization,
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

    $token = trim((string) $matches[1]);

    if ($token === '') {
        apiResponse(
            false,
            'Invalid authorization token.',
            [],
            401
        );
    }

    return $token;
}

/*
|--------------------------------------------------------------------------
| Ethiopian Month Names
|--------------------------------------------------------------------------
*/

$ethiopianMonthNames = [
    1 => 'Meskerem',
    2 => 'Tikimt',
    3 => 'Hidar',
    4 => 'Tahsas',
    5 => 'Tir',
    6 => 'Yekatit',
    7 => 'Megabit',
    8 => 'Miyazya',
    9 => 'Ginbot',
    10 => 'Sene',
    11 => 'Hamle',
    12 => 'Nehase',
    13 => 'Pagume',
];

/*
|--------------------------------------------------------------------------
| Ethiopian Date Formatter
|--------------------------------------------------------------------------
*/

function formatEthiopianAttendanceDate(
    string $gregorianDate
): string {
    try {
        $eth = EthiopianCalendar::fromGregorian(
            $gregorianDate
        );

        return EthiopianCalendar::format(
            $eth['year'],
            $eth['month'],
            $eth['day'],
            'en'
        );
    } catch (Throwable $e) {
        return $gregorianDate;
    }
}

/*
|--------------------------------------------------------------------------
| Get Ethiopian Week Number
|--------------------------------------------------------------------------
|
| Week 1 = Ethiopian days 1-7
| Week 2 = Ethiopian days 8-14
| Week 3 = Ethiopian days 15-21
| Week 4 = Ethiopian days 22-28
| Week 5 = Ethiopian days 29-30/31
|
|--------------------------------------------------------------------------
*/

function getEthiopianWeekNumber(
    int $ethiopianDay
): int {
    if ($ethiopianDay < 1) {
        return 1;
    }

    return (int) floor(
        ($ethiopianDay - 1) / 7
    ) + 1;
}

/*
|--------------------------------------------------------------------------
| Get Ethiopian Week Range
|--------------------------------------------------------------------------
*/

function getEthiopianWeekRange(
    int $weekNumber
): array {
    $startDay =
        (($weekNumber - 1) * 7) + 1;

    $endDay =
        $weekNumber * 7;

    return [
        'start_day' => $startDay,
        'end_day' => $endDay,
    ];
}

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

$tokenHash = hash(
    'sha256',
    $token
);

try {
    $authStmt = $conn->prepare(
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

    if (!$authStmt) {
        throw new RuntimeException(
            'Unable to prepare authentication query.'
        );
    }

    $authStmt->bind_param(
        's',
        $tokenHash
    );

    $authStmt->execute();

    $authResult =
        $authStmt->get_result();

    $teacher =
        $authResult->fetch_assoc();

    $authStmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance report authentication error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to authenticate the request.',
        [],
        500
    );
}

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
        trim(
            (string) ($teacher['role'] ?? '')
        )
    ) !== 'teacher'
) {
    apiResponse(
        false,
        'Only teachers can view attendance history.',
        [],
        403
    );
}

$teacherUserId =
    (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Request Validation
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

$mode = strtolower(
    trim(
        (string) ($_GET['mode'] ?? 'monthly')
    )
);

if (
    !in_array(
        $mode,
        ['weekly', 'monthly'],
        true
    )
) {
    apiResponse(
        false,
        'Invalid attendance history mode. Use weekly or monthly.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

$timezone = new DateTimeZone(
    'Africa/Addis_Ababa'
);

$now = new DateTimeImmutable(
    'now',
    $timezone
);

$todayGregorian =
    $now->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| Current Ethiopian Date
|--------------------------------------------------------------------------
*/

try {

    $todayEth =
        EthiopianCalendar::today();

    $todayEthYear =
        (int) $todayEth['year'];

    $todayEthMonth =
        (int) $todayEth['month'];

    $todayEthDay =
        (int) $todayEth['day'];

    $todayEthFormatted =
        EthiopianCalendar::format(
            $todayEthYear,
            $todayEthMonth,
            $todayEthDay,
            'en'
        );

} catch (Throwable $e) {

    apiResponse(
        false,
        'Unable to determine Ethiopian date.',
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

    $yearStmt = $conn->prepare(
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

    if (!$yearStmt) {
        throw new RuntimeException(
            'Unable to prepare academic year query.'
        );
    }

    $yearStmt->execute();

    $yearResult =
        $yearStmt->get_result();

    $academicYear =
        $yearResult->fetch_assoc();

    $yearStmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance report academic year error: ' .
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
| Selected Ethiopian Month
|--------------------------------------------------------------------------
*/

$selectedMonth = filter_var(
    $_GET['month'] ?? null,
    FILTER_VALIDATE_INT
);

if (
    $selectedMonth === false ||
    $selectedMonth === null ||
    $selectedMonth < 1 ||
    $selectedMonth > 13
) {
    apiResponse(
        false,
        'A valid Ethiopian month is required. Month must be between 1 and 13.',
        [],
        400
    );
}

$selectedMonth =
    (int) $selectedMonth;

$selectedMonthName =
    $ethiopianMonthNames[$selectedMonth] ??
    'Unknown';

/*
|--------------------------------------------------------------------------
| Selected Ethiopian Year
|--------------------------------------------------------------------------
*/

$selectedEthYear =
    $todayEthYear;

/*
|--------------------------------------------------------------------------
| Selected Week
|--------------------------------------------------------------------------
|
| Required only for weekly mode.
|
| Example:
|
| ?mode=weekly&month=1&week=2
|
|--------------------------------------------------------------------------
*/

$selectedWeek = null;

if ($mode === 'weekly') {

    $selectedWeek = filter_var(
        $_GET['week'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $selectedWeek === false ||
        $selectedWeek === null ||
        $selectedWeek < 1 ||
        $selectedWeek > 5
    ) {
        apiResponse(
            false,
            'A valid week is required for weekly history. Week must be between 1 and 5.',
            [],
            400
        );
    }

    $selectedWeek =
        (int) $selectedWeek;
}

/*
|--------------------------------------------------------------------------
| Teacher Homeroom Assignment
|--------------------------------------------------------------------------
*/

try {

    $classStmt = $conn->prepare(
        "
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
        LEFT JOIN grades g
            ON g.grade_number = hta.grade
        LEFT JOIN sections sec
            ON sec.code = hta.section
        WHERE hta.teacher_user_id = ?
          AND hta.academic_year = ?
          AND hta.is_active = 1
        ORDER BY hta.id ASC
        LIMIT 1
        "
    );

    if (!$classStmt) {
        throw new RuntimeException(
            'Unable to prepare teacher homeroom query.'
        );
    }

    $classStmt->bind_param(
        'is',
        $teacherUserId,
        $academicYearName
    );

    $classStmt->execute();

    $classResult =
        $classStmt->get_result();

    $selectedClass =
        $classResult->fetch_assoc();

    $classStmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance report homeroom error: ' .
        $e->getMessage()
    );

    apiResponse(
        false,
        'Unable to load your assigned class.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| No Homeroom
|--------------------------------------------------------------------------
*/

if (!$selectedClass) {

    apiResponse(
        true,
        'No homeroom class is assigned to this teacher.',
        [
            'teacher' => [
                'id' =>
                    $teacherUserId,

                'full_name' =>
                    (string) $teacher['full_name'],

                'email' =>
                    (string) ($teacher['email'] ?? ''),

                'phone' =>
                    (string) ($teacher['phone'] ?? ''),

                'role' =>
                    (string) $teacher['role'],
            ],

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
                    $todayGregorian,

                'ethiopian' =>
                    $todayEthFormatted,

                'ethiopian_year' =>
                    $todayEthYear,

                'ethiopian_month' =>
                    $todayEthMonth,

                'ethiopian_day' =>
                    $todayEthDay,
            ],

            'selected_month' => [
                'ethiopian_year' =>
                    $selectedEthYear,

                'ethiopian_month' =>
                    $selectedMonth,

                'name' =>
                    $selectedMonthName,

                'label' =>
                    $selectedMonthName .
                    ' ' .
                    $selectedEthYear,
            ],

            'selected_week' =>
                $selectedWeek,

            'selected_class' =>
                null,

            'total_students' =>
                0,

            'total_attendance_days' =>
                0,

            'attendance_dates' =>
                [],

            'students' =>
                [],

            'attendance' =>
                [],

            'statistics' =>
                [],

            'overall_statistics' => [
                'Present' => 0,
                'Absent' => 0,
                'Late' => 0,
                'Excused' => 0,
            ],

            'weeks' =>
                [],

            'report' => [
                'mode' =>
                    $mode,

                'selected_month' => [
                    'ethiopian_year' =>
                        $selectedEthYear,

                    'ethiopian_month' =>
                        $selectedMonth,

                    'name' =>
                        $selectedMonthName,
                ],

                'selected_week' =>
                    $selectedWeek,

                'attendance_days' =>
                    0,

                'dates' =>
                    [],

                'weeks' =>
                    [],

                'students' =>
                    [],

                'attendance' =>
                    [],

                'statistics' =>
                    [],

                'overall_statistics' => [
                    'Present' => 0,
                    'Absent' => 0,
                    'Late' => 0,
                    'Excused' => 0,
                ],
            ],
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Selected Class Information
|--------------------------------------------------------------------------
*/

$selectedGrade =
    (int) $selectedClass['grade'];

$selectedSection =
    strtoupper(
        trim(
            (string) $selectedClass['section']
        )
    );

$gradeId =
    (int) $selectedClass['grade_id'];

$sectionId =
    (int) $selectedClass['section_id'];

$selectedClassKey =
    $selectedGrade .
    '-' .
    $selectedSection;

$selectedClassName =
    (string) $selectedClass['grade_name'] .
    ' - ' .
    (string) $selectedClass['section_name'];

/*
|--------------------------------------------------------------------------
| All Attendance Dates For Selected Ethiopian Month
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| We first load ALL recorded dates for the selected month.
|
| This allows the API to know which weeks are actually recorded.
|
|--------------------------------------------------------------------------
*/

$allAttendanceDates = [];

try {

    $dateStmt = $conn->prepare(
        "
        SELECT DISTINCT
            attendance_date,
            ethiopian_year,
            ethiopian_month,
            ethiopian_day
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND ethiopian_year = ?
          AND ethiopian_month = ?
          AND attendance_date <= ?
        ORDER BY attendance_date ASC
        "
    );

    if (!$dateStmt) {
        throw new RuntimeException(
            'Unable to prepare attendance date query.'
        );
    }

    $dateStmt->bind_param(
        'iiiiis',
        $academicYearId,
        $gradeId,
        $sectionId,
        $selectedEthYear,
        $selectedMonth,
        $todayGregorian
    );

    $dateStmt->execute();

    $dateResult =
        $dateStmt->get_result();

    while (
        $row =
            $dateResult->fetch_assoc()
    ) {

        $gregorianDate =
            (string) $row['attendance_date'];

        $ethiopianYear =
            (int) $row['ethiopian_year'];

        $ethiopianMonth =
            (int) $row['ethiopian_month'];

        $ethiopianDay =
            (int) $row['ethiopian_day'];

        try {

            $dateObject =
                new DateTimeImmutable(
                    $gregorianDate,
                    $timezone
                );

            $dayOfWeek =
                (int) $dateObject->format('N');

            $ethiopianFormatted =
                formatEthiopianAttendanceDate(
                    $gregorianDate
                );

            $weekNumber =
                getEthiopianWeekNumber(
                    $ethiopianDay
                );

            $allAttendanceDates[] = [
                'gregorian' =>
                    $gregorianDate,

                'ethiopian' => [
                    'year' =>
                        $ethiopianYear,

                    'month' =>
                        $ethiopianMonth,

                    'day' =>
                        $ethiopianDay,

                    'formatted' =>
                        $ethiopianFormatted,
                ],

                'day_of_week' =>
                    $dayOfWeek,

                'day_name' =>
                    $dateObject->format('l'),

                'week_number' =>
                    $weekNumber,
            ];

        } catch (Throwable $e) {
            continue;
        }
    }

    $dateStmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance report dates error: ' .
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
| Recorded Weeks
|--------------------------------------------------------------------------
|
| These weeks are created ONLY from actual attendance dates.
|
| Example:
|
| If attendance exists in Week 1, Week 2 and Week 4:
|
| weeks = [
|     Week 1,
|     Week 2,
|     Week 4
| ]
|
|--------------------------------------------------------------------------
*/

$weeks = [];

foreach (
    $allAttendanceDates
    as $dateInfo
) {

    $weekNumber =
        (int) $dateInfo['week_number'];

    if (!isset($weeks[$weekNumber])) {

        $range =
            getEthiopianWeekRange(
                $weekNumber
            );

        $weeks[$weekNumber] = [
            'week_number' =>
                $weekNumber,

            'label' =>
                'Week ' .
                $weekNumber,

            'start_day' =>
                $range['start_day'],

            'end_day' =>
                $range['end_day'],

            'dates' =>
                [],
        ];
    }

    $weeks[$weekNumber]['dates'][] =
        $dateInfo;
}

$weeks =
    array_values($weeks);

usort(
    $weeks,
    function (
        array $a,
        array $b
    ): int {
        return
            (int) $a['week_number']
            <=>
            (int) $b['week_number'];
    }
);

/*
|--------------------------------------------------------------------------
| Weekly Validation
|--------------------------------------------------------------------------
|
| Make sure the requested week actually has attendance records.
|
|--------------------------------------------------------------------------
*/

if ($mode === 'weekly') {

    $recordedWeekNumbers = [];

    foreach ($weeks as $weekInfo) {
        $recordedWeekNumbers[] =
            (int) $weekInfo['week_number'];
    }

    if (
        !in_array(
            $selectedWeek,
            $recordedWeekNumbers,
            true
        )
    ) {
        apiResponse(
            false,
            'No attendance was recorded for Week ' .
            $selectedWeek .
            ' in ' .
            $selectedMonthName .
            ' ' .
            $selectedEthYear .
            '.',
            [
                'weeks' =>
                    $weeks,

                'selected_month' => [
                    'ethiopian_year' =>
                        $selectedEthYear,

                    'ethiopian_month' =>
                        $selectedMonth,

                    'name' =>
                        $selectedMonthName,

                    'label' =>
                        $selectedMonthName .
                        ' ' .
                        $selectedEthYear,
                ],
            ],
            404
        );
    }
}

/*
|--------------------------------------------------------------------------
| Selected Attendance Dates
|--------------------------------------------------------------------------
|
| Monthly:
|   All recorded dates.
|
| Weekly:
|   Only dates belonging to selected week.
|
|--------------------------------------------------------------------------
*/

$attendanceDates = [];

foreach (
    $allAttendanceDates
    as $dateInfo
) {

    if (
        $mode === 'weekly' &&
        (int) $dateInfo['week_number'] !==
        $selectedWeek
    ) {
        continue;
    }

    $attendanceDates[] =
        $dateInfo;
}

/*
|--------------------------------------------------------------------------
| Selected Week Information
|--------------------------------------------------------------------------
*/

$selectedWeekInfo = null;

if ($mode === 'weekly') {

    $range =
        getEthiopianWeekRange(
            $selectedWeek
        );

    $selectedWeekInfo = [
        'week_number' =>
            $selectedWeek,

        'label' =>
            'Week ' .
            $selectedWeek,

        'start_day' =>
            $range['start_day'],

        'end_day' =>
            $range['end_day'],

        'date_count' =>
            count($attendanceDates),
    ];
}

/*
|--------------------------------------------------------------------------
| Students
|--------------------------------------------------------------------------
*/

$students = [];

try {

    $studentStmt = $conn->prepare(
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
          AND s.is_deleted = 0
        ORDER BY s.full_name
        LIMIT 5000
        "
    );

    if (!$studentStmt) {
        throw new RuntimeException(
            'Unable to prepare student query.'
        );
    }

    $studentStmt->bind_param(
        'iii',
        $academicYearId,
        $gradeId,
        $sectionId
    );

    $studentStmt->execute();

    $studentResult =
        $studentStmt->get_result();

    while (
        $row =
            $studentResult->fetch_assoc()
    ) {

        $students[] = [
            'registration_id' =>
                (int) $row['registration_id'],

            'student_id' =>
                (int) $row['student_id'],

            'student_code' =>
                (string) (
                    $row['student_code'] ?? ''
                ),

            'full_name' =>
                (string) $row['full_name'],
        ];
    }

    $studentStmt->close();

} catch (Throwable $e) {

    error_log(
        'BKHS attendance report students error: ' .
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
| Gregorian Date List
|--------------------------------------------------------------------------
*/

$gregorianDates = [];

foreach (
    $attendanceDates
    as $dateInfo
) {
    $gregorianDates[] =
        (string) $dateInfo['gregorian'];
}

/*
|--------------------------------------------------------------------------
| Attendance Map
|--------------------------------------------------------------------------
|
| registration_id
|       ↓
| gregorian_date
|       ↓
| status
|
|--------------------------------------------------------------------------
*/

$attendanceMap = [];

if (
    !empty($students) &&
    !empty($gregorianDates)
) {

    $registrationIds = [];

    foreach ($students as $student) {

        $registrationIds[] =
            (int) $student['registration_id'];
    }

    $registrationIds =
        array_values(
            array_unique(
                $registrationIds
            )
        );

    $registrationPlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count($registrationIds),
                '?'
            )
        );

    $datePlaceholders =
        implode(
            ',',
            array_fill(
                0,
                count($gregorianDates),
                '?'
            )
        );

    $attendanceSql = "
        SELECT
            registration_id,
            student_id,
            attendance_date,
            status
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND registration_id IN (
              {$registrationPlaceholders}
          )
          AND attendance_date IN (
              {$datePlaceholders}
          )
        ORDER BY attendance_date ASC
    ";

    try {

        $attendanceStmt =
            $conn->prepare(
                $attendanceSql
            );

        if (!$attendanceStmt) {
            throw new RuntimeException(
                'Unable to prepare attendance query.'
            );
        }

        $types = 'iii';

        $params = [
            $academicYearId,
            $gradeId,
            $sectionId,
        ];

        foreach (
            $registrationIds
            as $registrationId
        ) {

            $types .= 'i';

            $params[] =
                $registrationId;
        }

        foreach (
            $gregorianDates
            as $date
        ) {

            $types .= 's';

            $params[] =
                $date;
        }

        $bindParams = [];

        $bindParams[] =
            $types;

        foreach (
            $params
            as $key => $value
        ) {

            $bindParams[] =
                &$params[$key];
        }

        call_user_func_array(
            [
                $attendanceStmt,
                'bind_param',
            ],
            $bindParams
        );

        $attendanceStmt->execute();

        $attendanceResult =
            $attendanceStmt->get_result();

        while (
            $attendanceRow =
                $attendanceResult->fetch_assoc()
        ) {

            $registrationId =
                (int) $attendanceRow[
                    'registration_id'
                ];

            $attendanceDate =
                (string) $attendanceRow[
                    'attendance_date'
                ];

            $status =
                trim(
                    (string) $attendanceRow[
                        'status'
                    ]
                );

            if (
                !in_array(
                    $status,
                    [
                        'Present',
                        'Absent',
                        'Late',
                        'Excused',
                    ],
                    true
                )
            ) {
                $status = null;
            }

            $attendanceMap[
                $registrationId
            ][
                $attendanceDate
            ] = $status;
        }

        $attendanceStmt->close();

    } catch (Throwable $e) {

        error_log(
            'BKHS attendance report records error: ' .
            $e->getMessage()
        );

        apiResponse(
            false,
            'Unable to load attendance records.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Build Student History
|--------------------------------------------------------------------------
*/

$studentHistory = [];

foreach ($students as $student) {

    $registrationId =
        (int) $student['registration_id'];

    $records = [];

    foreach (
        $attendanceDates
        as $dateInfo
    ) {

        $date =
            (string) $dateInfo['gregorian'];

        $status =
            $attendanceMap[
                $registrationId
            ][
                $date
            ] ?? null;

        $records[] = [
            'gregorian' =>
                $date,

            'ethiopian' =>
                $dateInfo['ethiopian'],

            'status' =>
                $status,
        ];
    }

    $studentHistory[] = [
        'registration_id' =>
            $registrationId,

        'student_id' =>
            (int) $student['student_id'],

        'student_code' =>
            (string) $student['student_code'],

        'full_name' =>
            (string) $student['full_name'],

        'attendance' =>
            $records,
    ];
}

/*
|--------------------------------------------------------------------------
| Build Date-Based Attendance
|--------------------------------------------------------------------------
*/

$attendance = [];

foreach (
    $attendanceDates
    as $dateInfo
) {

    $date =
        (string) $dateInfo['gregorian'];

    $records = [];

    foreach (
        $students
        as $student
    ) {

        $registrationId =
            (int) $student['registration_id'];

        $status =
            $attendanceMap[
                $registrationId
            ][
                $date
            ] ?? null;

        $records[] = [
            'registration_id' =>
                $registrationId,

            'student_id' =>
                (int) $student['student_id'],

            'student_code' =>
                (string) $student['student_code'],

            'full_name' =>
                (string) $student['full_name'],

            'status' =>
                $status,
        ];
    }

    $attendance[] = [
        'gregorian_date' =>
            $date,

        'ethiopian_date' =>
            (string) $dateInfo[
                'ethiopian'
            ]['formatted'],

        'ethiopian' =>
            $dateInfo['ethiopian'],

        'day_name' =>
            $dateInfo['day_name'],

        'day_of_week' =>
            $dateInfo['day_of_week'],

        'week_number' =>
            $dateInfo['week_number'],

        'students' =>
            $records,
    ];
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$statistics = [];

$overallStatistics = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0,
];

foreach (
    $attendanceDates
    as $dateInfo
) {

    $date =
        (string) $dateInfo['gregorian'];

    $statistics[$date] = [
        'Present' => 0,
        'Absent' => 0,
        'Late' => 0,
        'Excused' => 0,
    ];

    foreach (
        $students
        as $student
    ) {

        $registrationId =
            (int) $student['registration_id'];

        $status =
            $attendanceMap[
                $registrationId
            ][
                $date
            ] ?? null;

        if (
            $status !== null &&
            array_key_exists(
                $status,
                $statistics[$date]
            )
        ) {

            $statistics[$date][$status]++;

            $overallStatistics[$status]++;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Date Range
|--------------------------------------------------------------------------
*/

$dateRange = null;

if (!empty($attendanceDates)) {

    $firstDate =
        $attendanceDates[0];

    $lastDate =
        $attendanceDates[
            count($attendanceDates) - 1
        ];

    $dateRange = [
        'start' => [
            'gregorian' =>
                $firstDate['gregorian'],

            'ethiopian' =>
                $firstDate['ethiopian'],
        ],

        'end' => [
            'gregorian' =>
                $lastDate['gregorian'],

            'ethiopian' =>
                $lastDate['ethiopian'],
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Report Object
|--------------------------------------------------------------------------
*/

$report = [
    'mode' =>
        $mode,

    'selected_month' => [
        'ethiopian_year' =>
            $selectedEthYear,

        'ethiopian_month' =>
            $selectedMonth,

        'name' =>
            $selectedMonthName,

        'label' =>
            $selectedMonthName .
            ' ' .
            $selectedEthYear,
    ],

    'selected_week' =>
        $selectedWeekInfo,

    'date_range' =>
        $dateRange,

    'attendance_days' =>
        count($attendanceDates),

    'dates' =>
        $attendanceDates,

    'weeks' =>
        $weeks,

    'students' =>
        $studentHistory,

    'attendance' =>
        $attendance,

    'statistics' =>
        $statistics,

    'overall_statistics' =>
        $overallStatistics,
];

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
            'id' =>
                $teacherUserId,

            'full_name' =>
                (string) $teacher['full_name'],

            'email' =>
                (string) (
                    $teacher['email'] ?? ''
                ),

            'phone' =>
                (string) (
                    $teacher['phone'] ?? ''
                ),

            'role' =>
                (string) $teacher['role'],
        ],

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
                $todayGregorian,

            'ethiopian' =>
                $todayEthFormatted,

            'ethiopian_year' =>
                $todayEthYear,

            'ethiopian_month' =>
                $todayEthMonth,

            'ethiopian_day' =>
                $todayEthDay,
        ],

        'selected_month' => [
            'ethiopian_year' =>
                $selectedEthYear,

            'ethiopian_month' =>
                $selectedMonth,

            'name' =>
                $selectedMonthName,

            'label' =>
                $selectedMonthName .
                ' ' .
                $selectedEthYear,
        ],

        'selected_week' =>
            $selectedWeekInfo,

        'selected_class' => [
            'id' =>
                (int) $selectedClass['id'],

            'key' =>
                $selectedClassKey,

            'name' =>
                $selectedClassName,

            'grade' =>
                $selectedGrade,

            'grade_name' =>
                (string) $selectedClass['grade_name'],

            'section' =>
                $selectedSection,

            'section_name' =>
                (string) $selectedClass['section_name'],

            'grade_id' =>
                $gradeId,

            'section_id' =>
                $sectionId,
        ],

        'total_students' =>
            count($students),

        'total_attendance_days' =>
            count($attendanceDates),

        'attendance_dates' =>
            $attendanceDates,

        'students' =>
            $studentHistory,

        'attendance' =>
            $attendance,

        'statistics' =>
            $statistics,

        'overall_statistics' =>
            $overallStatistics,

        'weeks' =>
            $weeks,

        'report' =>
            $report,
    ]
);