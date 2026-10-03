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

    return trim($matches[1]);
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date Helper
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
| Weekday Helper
|--------------------------------------------------------------------------
*/

function getWeekNumberInMonth(string $gregorianDate): int
{
    $date = new DateTimeImmutable($gregorianDate);

    $dayOfMonth = (int) $date->format('j');

    return (int) ceil($dayOfMonth / 7);
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
        'Only teachers can view attendance history.',
        [],
        403
    );
}

$teacherUserId = (int) $teacher['user_id'];

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

if (!in_array($mode, ['weekly', 'monthly'], true)) {
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

$todayGregorian = $now->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| Current Ethiopian Date
|--------------------------------------------------------------------------
*/

try {
    $todayEth = EthiopianCalendar::today();

    $todayEthYear = (int) $todayEth['year'];
    $todayEthMonth = (int) $todayEth['month'];
    $todayEthDay = (int) $todayEth['day'];

    $todayEthFormatted = EthiopianCalendar::format(
        $todayEth['year'],
        $todayEth['month'],
        $todayEth['day'],
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

$yearStmt = $conn->prepare("
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

if (!$yearStmt) {
    apiResponse(
        false,
        'Unable to prepare academic year query.',
        [],
        500
    );
}

$yearStmt->execute();

$yearResult = $yearStmt->get_result();

$academicYear = $yearResult->fetch_assoc();

$yearStmt->close();

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
| Selected Ethiopian Month
|--------------------------------------------------------------------------
|
| The academic year is automatic.
|
| The teacher only chooses the Ethiopian month.
|
| Example:
|
| ?mode=monthly&month=1
|
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

/*
|--------------------------------------------------------------------------
| Selected Ethiopian Year
|--------------------------------------------------------------------------
|
| Always use the current Ethiopian year.
|
*/

$selectedEthYear = $todayEthYear;

/*
|--------------------------------------------------------------------------
| Teacher Homeroom Assignment
|--------------------------------------------------------------------------
|
| No class is received from Flutter.
|
| The backend automatically finds the teacher's
| active homeroom assignment for the current
| academic year.
|
*/

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
    LEFT JOIN grades g
        ON g.grade_number = hta.grade
    LEFT JOIN sections sec
        ON sec.code = hta.section
    WHERE hta.teacher_user_id = ?
      AND hta.academic_year = ?
      AND hta.is_active = 1
    ORDER BY hta.id ASC
    LIMIT 1
");

if (!$classStmt) {
    apiResponse(
        false,
        'Unable to load teacher homeroom assignment.',
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

$selectedClass = $classResult->fetch_assoc();

$classStmt->close();

if (!$selectedClass) {
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
                'name' => $academicYearName,
                'status' => (string) $academicYear['status']
            ],

            'today' => [
                'gregorian' => $todayGregorian,
                'ethiopian' => $todayEthFormatted
            ],

            'selected_month' => [
                'ethiopian_year' => $selectedEthYear,
                'ethiopian_month' => $selectedMonth
            ],

            'selected_class' => null,
            'attendance_dates' => [],
            'students' => [],
            'attendance' => [],
            'statistics' => []
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Selected Class Information
|--------------------------------------------------------------------------
*/

$selectedGrade = (int) $selectedClass['grade'];

$selectedSection = strtoupper(
    trim(
        (string) $selectedClass['section']
    )
);

$gradeId = (int) $selectedClass['grade_id'];

$sectionId = (int) $selectedClass['section_id'];

$selectedClassKey =
    $selectedGrade . '-' . $selectedSection;

$selectedClassName =
    (string) $selectedClass['grade_name']
    . ' - '
    . (string) $selectedClass['section_name'];

/*
|--------------------------------------------------------------------------
| Get Attendance Dates
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| We use the Ethiopian year/month columns already
| stored in student_attendance.
|
| We return ONLY dates where attendance actually
| exists for this class.
|
| This means if attendance was taken for 21 school
| days, all 21 days are returned.
|
*/

$attendanceDates = [];

$dateStmt = $conn->prepare("
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
");

if (!$dateStmt) {
    apiResponse(
        false,
        'Unable to load attendance dates.',
        [],
        500
    );
}

$dateStmt->bind_param(
    'iiiiss',
    $academicYearId,
    $gradeId,
    $sectionId,
    $selectedEthYear,
    $selectedMonth,
    $todayGregorian
);

$dateStmt->execute();

$dateResult = $dateStmt->get_result();

while ($row = $dateResult->fetch_assoc()) {
    $gregorianDate = (string) $row['attendance_date'];

    $ethiopianYear = (int) $row['ethiopian_year'];
    $ethiopianMonth = (int) $row['ethiopian_month'];
    $ethiopianDay = (int) $row['ethiopian_day'];

    $ethiopianFormatted =
        formatEthiopianAttendanceDate(
            $gregorianDate
        );

    $dateObject = new DateTimeImmutable(
        $gregorianDate,
        $timezone
    );

    $dayOfWeek = (int) $dateObject->format('N');

    /*
    |--------------------------------------------------------------------------
    | Monday = 1
    | Friday = 5
    |
    | Weekends are never included.
    |--------------------------------------------------------------------------
    */

    if ($dayOfWeek > 5) {
        continue;
    }

    $attendanceDates[] = [
        'gregorian' => $gregorianDate,

        'ethiopian' => [
            'year' => $ethiopianYear,
            'month' => $ethiopianMonth,
            'day' => $ethiopianDay,
            'formatted' => $ethiopianFormatted
        ],

        'day_of_week' => $dayOfWeek,

        'day_name' => $dateObject->format('l'),

        'week_number' => getWeekNumberInMonth(
            $gregorianDate
        )
    ];
}

$dateStmt->close();

/*
|--------------------------------------------------------------------------
| Students
|--------------------------------------------------------------------------
*/

$students = [];

$studentStmt = $conn->prepare("
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
");

if (!$studentStmt) {
    apiResponse(
        false,
        'Unable to load students.',
        [],
        500
    );
}

$studentStmt->bind_param(
    'iii',
    $academicYearId,
    $gradeId,
    $sectionId
);

$studentStmt->execute();

$studentResult = $studentStmt->get_result();

while ($row = $studentResult->fetch_assoc()) {
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
            (string) $row['full_name']
    ];
}

$studentStmt->close();

/*
|--------------------------------------------------------------------------
| Gregorian Date List
|--------------------------------------------------------------------------
*/

$gregorianDates = [];

foreach ($attendanceDates as $date) {
    $gregorianDates[] =
        $date['gregorian'];
}

/*
|--------------------------------------------------------------------------
| Attendance Map
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

    $registrationIds = array_values(
        array_unique(
            $registrationIds
        )
    );

    $registrationPlaceholders = implode(
        ',',
        array_fill(
            0,
            count($registrationIds),
            '?'
        )
    );

    $datePlaceholders = implode(
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

    $attendanceStmt =
        $conn->prepare(
            $attendanceSql
        );

    if (!$attendanceStmt) {
        apiResponse(
            false,
            'Unable to load attendance records.',
            [],
            500
        );
    }

    $types = 'iii';

    $params = [
        $academicYearId,
        $gradeId,
        $sectionId
    ];

    foreach ($registrationIds as $registrationId) {
        $types .= 'i';

        $params[] =
            $registrationId;
    }

    foreach ($gregorianDates as $date) {
        $types .= 's';

        $params[] =
            $date;
    }

    $bindParams = [];

    $bindParams[] =
        $types;

    foreach ($params as $key => $value) {
        $bindParams[] =
            &$params[$key];
    }

    call_user_func_array(
        [
            $attendanceStmt,
            'bind_param'
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

        $attendanceMap[
            $registrationId
        ][
            $attendanceDate
        ] =
            (string) $attendanceRow[
                'status'
            ];
    }

    $attendanceStmt->close();
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

    foreach ($attendanceDates as $dateInfo) {
        $date =
            $dateInfo['gregorian'];

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
                $status
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
            $records
    ];
}

/*
|--------------------------------------------------------------------------
| Build Date-Based Attendance
|--------------------------------------------------------------------------
*/

$attendance = [];

foreach ($attendanceDates as $dateInfo) {
    $date =
        $dateInfo['gregorian'];

    $records = [];

    foreach ($students as $student) {
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
                $status
        ];
    }

    $attendance[] = [
        'gregorian_date' =>
            $date,

        'ethiopian_date' =>
            $dateInfo['ethiopian']['formatted'],

        'ethiopian' =>
            $dateInfo['ethiopian'],

        'day_name' =>
            $dateInfo['day_name'],

        'day_of_week' =>
            $dateInfo['day_of_week'],

        'week_number' =>
            $dateInfo['week_number'],

        'students' =>
            $records
    ];
}

/*
|--------------------------------------------------------------------------
| Weekly Groups
|--------------------------------------------------------------------------
|
| Weekly mode groups the selected month's attendance
| dates into Monday-Friday weeks.
|
*/

$weeks = [];

foreach ($attendanceDates as $dateInfo) {
    $weekNumber =
        (int) $dateInfo['week_number'];

    if (!isset($weeks[$weekNumber])) {
        $weeks[$weekNumber] = [
            'week_number' =>
                $weekNumber,

            'dates' =>
                []
        ];
    }

    $weeks[$weekNumber]['dates'][] =
        $dateInfo;
}

$weeks = array_values($weeks);

usort(
    $weeks,
    function (
        array $a,
        array $b
    ): int {
        return
            $a['week_number']
            <=>
            $b['week_number'];
    }
);

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
    'Excused' => 0
];

foreach ($attendanceDates as $dateInfo) {
    $date =
        $dateInfo['gregorian'];

    $statistics[$date] = [
        'Present' => 0,
        'Absent' => 0,
        'Late' => 0,
        'Excused' => 0
    ];

    foreach ($students as $student) {
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
| Month Information
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
    13 => 'Pagume'
];

$selectedMonthName =
    $ethiopianMonthNames[
        $selectedMonth
    ] ?? 'Unknown';

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
                $firstDate['ethiopian']
        ],

        'end' => [
            'gregorian' =>
                $lastDate['gregorian'],

            'ethiopian' =>
                $lastDate['ethiopian']
        ]
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
            $selectedMonthName
            . ' '
            . $selectedEthYear
    ],

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
        $overallStatistics
];

/*
|--------------------------------------------------------------------------
| Response
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
                (string) $teacher['role']
        ],

        'academic_year' => [
            'id' =>
                $academicYearId,

            'name' =>
                $academicYearName,

            'status' =>
                (string) $academicYear['status']
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
                $todayEthDay
        ],

        'selected_month' => [
            'ethiopian_year' =>
                $selectedEthYear,

            'ethiopian_month' =>
                $selectedMonth,

            'name' =>
                $selectedMonthName,

            'label' =>
                $selectedMonthName
                . ' '
                . $selectedEthYear
        ],

        'selected_class' => [
            'id' =>
                (int) $selectedClass['id'],

            'key' =>
                $selectedClassKey,

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
                $sectionId
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
            $report
    ]
);