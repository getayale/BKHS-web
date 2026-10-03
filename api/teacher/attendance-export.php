<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

require_once '../../vendor/autoload.php';
require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

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
| JSON Error Response
|--------------------------------------------------------------------------
*/

function apiError(
    string $message,
    int $statusCode = 400
): never {
    http_response_code($statusCode);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'success' => false,
            'message' => $message,
        ],
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
        apiError(
            'Authorization token is required.',
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
        apiError(
            'Invalid authorization format.',
            401
        );
    }

    $token = trim(
        (string) $matches[1]
    );

    if ($token === '') {
        apiError(
            'Invalid authorization token.',
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
| Ethiopian Week
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

function getEthiopianWeekRange(
    int $weekNumber
): array {
    return [
        'start_day' =>
            (($weekNumber - 1) * 7) + 1,

        'end_day' =>
            $weekNumber * 7,
    ];
}

/*
|--------------------------------------------------------------------------
| Format Ethiopian Date
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
            (int) $eth['year'],
            (int) $eth['month'],
            (int) $eth['day'],
            'en'
        );
    } catch (Throwable $e) {
        return $gregorianDate;
    }
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
        'BKHS attendance export authentication error: ' .
        $e->getMessage()
    );

    apiError(
        'Unable to authenticate the request.',
        500
    );
}

if (!$teacher) {
    apiError(
        'Invalid or expired authorization token.',
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
    apiError(
        'Only teachers can export attendance history.',
        403
    );
}

$teacherUserId =
    (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(
        'Only GET requests are allowed.',
        405
    );
}

/*
|--------------------------------------------------------------------------
| Mode
|--------------------------------------------------------------------------
*/

$mode = strtolower(
    trim(
        (string) (
            $_GET['mode'] ?? 'monthly'
        )
    )
);

if (
    !in_array(
        $mode,
        ['monthly', 'weekly'],
        true
    )
) {
    apiError(
        'Invalid mode. Use monthly or weekly.',
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
} catch (Throwable $e) {
    apiError(
        'Unable to determine Ethiopian date.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Selected Month
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
    apiError(
        'A valid Ethiopian month is required. Month must be between 1 and 13.',
        400
    );
}

$selectedMonth =
    (int) $selectedMonth;

$selectedMonthName =
    $ethiopianMonthNames[$selectedMonth];

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
        apiError(
            'A valid week is required. Week must be between 1 and 5.',
            400
        );
    }

    $selectedWeek =
        (int) $selectedWeek;
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
        'BKHS attendance export academic year error: ' .
        $e->getMessage()
    );

    apiError(
        'Unable to load the active academic year.',
        500
    );
}

if (!$academicYear) {
    apiError(
        'There is no active academic year.',
        404
    );
}

$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Teacher Homeroom
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
        'BKHS attendance export homeroom error: ' .
        $e->getMessage()
    );

    apiError(
        'Unable to load your assigned class.',
        500
    );
}

if (!$selectedClass) {
    apiError(
        'No homeroom class is assigned to this teacher.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Class Information
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

$gradeName =
    (string) $selectedClass['grade_name'];

$sectionName =
    (string) $selectedClass['section_name'];

$selectedClassName =
    $gradeName . ' - ' . $sectionName;

/*
|--------------------------------------------------------------------------
| Attendance Dates
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

                'ethiopian_year' =>
                    $ethiopianYear,

                'ethiopian_month' =>
                    $ethiopianMonth,

                'ethiopian_day' =>
                    $ethiopianDay,

                'ethiopian_formatted' =>
                    $ethiopianFormatted,

                'week_number' =>
                    $weekNumber,

                'day_name' =>
                    $dateObject->format('l'),

                'day_of_week' =>
                    $dayOfWeek,
            ];
        } catch (Throwable $e) {
            continue;
        }
    }

    $dateStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS attendance export dates error: ' .
        $e->getMessage()
    );

    apiError(
        'Unable to load attendance dates.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Recorded Weeks
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
                'Week ' . $weekNumber,

            'start_day' =>
                $range['start_day'],

            'end_day' =>
                $range['end_day'],
        ];
    }
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
| Validate Weekly Selection
|--------------------------------------------------------------------------
*/

if ($mode === 'weekly') {
    $recordedWeekNumbers = [];

    foreach ($weeks as $week) {
        $recordedWeekNumbers[] =
            (int) $week['week_number'];
    }

    if (
        !in_array(
            $selectedWeek,
            $recordedWeekNumbers,
            true
        )
    ) {
        apiError(
            'No attendance was recorded for Week ' .
            $selectedWeek .
            ' in ' .
            $selectedMonthName .
            ' ' .
            $selectedEthYear .
            '.',
            404
        );
    }
}

/*
|--------------------------------------------------------------------------
| Selected Attendance Dates
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

if (empty($attendanceDates)) {
    apiError(
        'No attendance was recorded for ' .
        $selectedMonthName .
        ' ' .
        $selectedEthYear .
        '.',
        404
    );
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
            s.full_name
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND s.is_deleted = 0
        ORDER BY s.full_name ASC
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

            'full_name' =>
                (string) $row['full_name'],
        ];
    }

    $studentStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS attendance export students error: ' .
        $e->getMessage()
    );

    apiError(
        'Unable to load students.',
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
            'BKHS attendance export records error: ' .
            $e->getMessage()
        );

        apiError(
            'Unable to load attendance records.',
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Student Totals
|--------------------------------------------------------------------------
*/

$studentTotals = [];

foreach ($students as $student) {
    $registrationId =
        (int) $student['registration_id'];

    $studentTotals[$registrationId] = [
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

        $status =
            $attendanceMap[
                $registrationId
            ][
                $date
            ] ?? null;

        if (
            $status !== null &&
            isset(
                $studentTotals[
                    $registrationId
                ][$status]
            )
        ) {
            $studentTotals[
                $registrationId
            ][$status]++;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Overall Statistics
|--------------------------------------------------------------------------
*/

$overallStatistics = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0,
];

foreach ($studentTotals as $totals) {
    foreach (
        $overallStatistics
        as $status => $value
    ) {
        $overallStatistics[$status] +=
            (int) ($totals[$status] ?? 0);
    }
}

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator('Bole Kale Hiwot School')
    ->setLastModifiedBy('Bole Kale Hiwot School')
    ->setTitle('Teacher Attendance History Report')
    ->setSubject('Teacher Attendance History')
    ->setDescription(
        'Teacher attendance history report.'
    )
    ->setKeywords(
        'attendance, school, teacher, students'
    );

/*
|--------------------------------------------------------------------------
| Single Worksheet
|--------------------------------------------------------------------------
*/

$attendanceSheet =
    $spreadsheet->getActiveSheet();

$attendanceSheet->setTitle(
    'Attendance'
);

/*
|--------------------------------------------------------------------------
| Table Columns
|--------------------------------------------------------------------------
*/

$lastDateColumn =
    2 + count($attendanceDates);

$lastTableColumn =
    max(
        2,
        $lastDateColumn
    );

$lastTableColumnLetter =
    Coordinate::stringFromColumnIndex(
        $lastTableColumn
    );

/*
|--------------------------------------------------------------------------
| Header Width
|--------------------------------------------------------------------------
|
| Keep the information section wide enough even when there are
| only a few attendance dates.
|
*/

$headerLastColumn =
    max(
        $lastTableColumn,
        6
    );

$headerLastColumnLetter =
    Coordinate::stringFromColumnIndex(
        $headerLastColumn
    );

/*
|--------------------------------------------------------------------------
| Styles
|--------------------------------------------------------------------------
*/

$titleStyle = [
    'font' => [
        'bold' => true,
        'size' => 18,
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];

$subtitleStyle = [
    'font' => [
        'bold' => true,
        'size' => 13,
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];

$headerStyle = [
    'font' => [
        'bold' => true,
    ],
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'D9E2F3',
        ],
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' =>
                Border::BORDER_THIN,
        ],
    ],
];

$cellBorderStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' =>
                Border::BORDER_THIN,
        ],
    ],
];

$centerStyle = [
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];

$labelStyle = [
    'font' => [
        'bold' => true,
    ],
    'alignment' => [
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];

$summaryHeaderStyle = [
    'font' => [
        'bold' => true,
    ],
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'E2F0D9',
        ],
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' =>
                Border::BORDER_THIN,
        ],
    ],
];

/*
|--------------------------------------------------------------------------
| School Header
|--------------------------------------------------------------------------
*/

$attendanceSheet->mergeCells(
    'A1:' .
    $headerLastColumnLetter .
    '1'
);

$attendanceSheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$attendanceSheet->getStyle('A1')
    ->applyFromArray($titleStyle);

$attendanceSheet->getRowDimension(1)
    ->setRowHeight(30);

$attendanceSheet->mergeCells(
    'A2:' .
    $headerLastColumnLetter .
    '2'
);

$attendanceSheet->setCellValue(
    'A2',
    'TEACHER ATTENDANCE REPORT'
);

$attendanceSheet->getStyle('A2')
    ->applyFromArray($subtitleStyle);

$attendanceSheet->getRowDimension(2)
    ->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Report Information
|--------------------------------------------------------------------------
|
| The information area uses merged cells so long labels and values
| are not cut off.
|
*/

$attendanceSheet->mergeCells('A4:C4');

$attendanceSheet->setCellValue(
    'A4',
    'Academic Year: ' .
    $academicYearName
);

$attendanceSheet->mergeCells('D4:F4');

$attendanceSheet->setCellValue(
    'D4',
    'Class: ' .
    $selectedClassName
);

$attendanceSheet->mergeCells('A5:C5');

$attendanceSheet->setCellValue(
    'A5',
    'Teacher: ' .
    (string) $teacher['full_name']
);

$attendanceSheet->mergeCells('D5:F5');

$attendanceSheet->setCellValue(
    'D5',
    'View: ' .
    ucfirst($mode)
);

$attendanceSheet->mergeCells('A6:C6');

$attendanceSheet->setCellValue(
    'A6',
    'Month: ' .
    $selectedMonthName .
    ' ' .
    $selectedEthYear
);

if ($mode === 'weekly') {
    $attendanceSheet->mergeCells('D6:F6');

    $attendanceSheet->setCellValue(
        'D6',
        'Week: Week ' .
        $selectedWeek
    );
}

$attendanceSheet->getStyle(
    'A4:C6'
)->applyFromArray($labelStyle);

$attendanceSheet->getStyle(
    'D4:F6'
)->applyFromArray($labelStyle);

$attendanceSheet->getRowDimension(4)
    ->setRowHeight(22);

$attendanceSheet->getRowDimension(5)
    ->setRowHeight(22);

$attendanceSheet->getRowDimension(6)
    ->setRowHeight(22);

/*
|--------------------------------------------------------------------------
| Small Attendance Summary
|--------------------------------------------------------------------------
*/

$summaryStartRow = 8;

$attendanceSheet->mergeCells(
    'A' .
    $summaryStartRow .
    ':C' .
    $summaryStartRow
);

$attendanceSheet->setCellValue(
    'A' . $summaryStartRow,
    'ATTENDANCE SUMMARY'
);

$attendanceSheet->getStyle(
    'A' .
    $summaryStartRow .
    ':C' .
    $summaryStartRow
)->applyFromArray(
    $summaryHeaderStyle
);

$summaryData = [
    [
        'Attendance Taken Dates',
        count($attendanceDates),
    ],
    [
        'Present',
        $overallStatistics['Present'],
    ],
    [
        'Absent',
        $overallStatistics['Absent'],
    ],
    [
        'Late',
        $overallStatistics['Late'],
    ],
    [
        'Excused',
        $overallStatistics['Excused'],
    ],
];

$summaryRow =
    $summaryStartRow + 1;

foreach ($summaryData as $summaryItem) {

    /*
    |----------------------------------------------------------------------
    | Wide Summary Label
    |----------------------------------------------------------------------
    */

    $attendanceSheet->mergeCells(
        'A' .
        $summaryRow .
        ':B' .
        $summaryRow
    );

    $attendanceSheet->setCellValue(
        'A' . $summaryRow,
        $summaryItem[0]
    );

    $attendanceSheet->setCellValue(
        'C' . $summaryRow,
        $summaryItem[1]
    );

    $attendanceSheet->getStyle(
        'A' . $summaryRow
    )->applyFromArray(
        $labelStyle
    );

    $attendanceSheet->getStyle(
        'C' . $summaryRow
    )->applyFromArray(
        $centerStyle
    );

    $attendanceSheet->getStyle(
        'A' .
        $summaryRow .
        ':C' .
        $summaryRow
    )->applyFromArray(
        $cellBorderStyle
    );

    $summaryRow++;
}

/*
|--------------------------------------------------------------------------
| Attendance Table Header
|--------------------------------------------------------------------------
*/

$headerRow = 15;

$attendanceSheet->setCellValue(
    'A' . $headerRow,
    'No.'
);

$attendanceSheet->setCellValue(
    'B' . $headerRow,
    'Student Name'
);

$column = 3;

foreach (
    $attendanceDates
    as $dateInfo
) {
    $ethiopianDay =
        (string) $dateInfo['ethiopian_day'];

    $dateLabel =
        $selectedMonthName .
        ' ' .
        $ethiopianDay;

    $columnLetter =
        Coordinate::stringFromColumnIndex(
            $column
        );

    $attendanceSheet->setCellValue(
        $columnLetter . $headerRow,
        $dateLabel
    );

    $column++;
}

$attendanceSheet->getStyle(
    'A' .
    $headerRow .
    ':' .
    $lastTableColumnLetter .
    $headerRow
)->applyFromArray(
    $headerStyle
);

$attendanceSheet->getRowDimension(
    $headerRow
)->setRowHeight(32);

/*
|--------------------------------------------------------------------------
| Attendance Student Rows
|--------------------------------------------------------------------------
*/

$currentRow =
    $headerRow + 1;

foreach (
    $students
    as $index => $student
) {
    $registrationId =
        (int) $student['registration_id'];

    $attendanceSheet->setCellValue(
        'A' . $currentRow,
        $index + 1
    );

    $attendanceSheet->setCellValue(
        'B' . $currentRow,
        (string) $student['full_name']
    );

    $attendanceSheet->getStyle(
        'A' . $currentRow
    )->applyFromArray(
        $centerStyle
    );

    $dateColumn = 3;

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

        $statusCode = '-';

        switch ($status) {
            case 'Present':
                $statusCode = 'P';
                break;

            case 'Absent':
                $statusCode = 'A';
                break;

            case 'Late':
                $statusCode = 'L';
                break;

            case 'Excused':
                $statusCode = 'E';
                break;
        }

        $columnLetter =
            Coordinate::stringFromColumnIndex(
                $dateColumn
            );

        $attendanceSheet->setCellValue(
            $columnLetter . $currentRow,
            $statusCode
        );

        $attendanceSheet->getStyle(
            $columnLetter . $currentRow
        )->applyFromArray(
            $centerStyle
        );

        $dateColumn++;
    }

    $currentRow++;
}

/*
|--------------------------------------------------------------------------
| Attendance Table Borders
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Borders start ONLY at row 15.
| Therefore the vertical line between Student Name and the first
| attendance date does NOT extend into the header information.
|
*/

$lastStudentRow =
    max(
        $currentRow - 1,
        $headerRow
    );

$attendanceSheet->getStyle(
    'A' .
    $headerRow .
    ':' .
    $lastTableColumnLetter .
    $lastStudentRow
)->applyFromArray(
    $cellBorderStyle
);

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$attendanceSheet->getColumnDimension('A')
    ->setWidth(18);

$attendanceSheet->getColumnDimension('B')
    ->setWidth(30);

$attendanceSheet->getColumnDimension('C')
    ->setWidth(13);

$attendanceSheet->getColumnDimension('D')
    ->setWidth(16);

$attendanceSheet->getColumnDimension('E')
    ->setWidth(18);

$attendanceSheet->getColumnDimension('F')
    ->setWidth(13);

for (
    $i = 7;
    $i <= $lastDateColumn;
    $i++
) {
    $columnLetter =
        Coordinate::stringFromColumnIndex(
            $i
        );

    $attendanceSheet
        ->getColumnDimension($columnLetter)
        ->setWidth(13);
}

/*
|--------------------------------------------------------------------------
| Row Heights
|--------------------------------------------------------------------------
*/

for (
    $row = $headerRow + 1;
    $row <= $lastStudentRow;
    $row++
) {
    $attendanceSheet
        ->getRowDimension($row)
        ->setRowHeight(21);
}

/*
|--------------------------------------------------------------------------
| No Freeze Pane
|--------------------------------------------------------------------------
*/

$attendanceSheet->freezePane(null);

/*
|--------------------------------------------------------------------------
| Print Settings
|--------------------------------------------------------------------------
*/

$attendanceSheet->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

$attendanceSheet->getPageSetup()
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

$attendanceSheet->getPageSetup()
    ->setFitToWidth(1);

$attendanceSheet->getPageSetup()
    ->setFitToHeight(0);

$attendanceSheet->setShowGridlines(false);

$attendanceSheet->getPageMargins()
    ->setTop(0.4)
    ->setBottom(0.4)
    ->setLeft(0.3)
    ->setRight(0.3);

/*
|--------------------------------------------------------------------------
| Repeat Attendance Header When Printing
|--------------------------------------------------------------------------
*/

$attendanceSheet->getPageSetup()
    ->setRowsToRepeatAtTopByStartAndEnd(
        $headerRow,
        $headerRow
    );

/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$viewName =
    $mode === 'monthly'
        ? 'Monthly'
        : 'Weekly';

$weekPart =
    $mode === 'weekly'
        ? '_Week' . $selectedWeek
        : '';

$fileName =
    'Attendance_' .
    $viewName .
    '_' .
    $selectedMonthName .
    '_' .
    $selectedEthYear .
    $weekPart .
    '.xlsx';

/*
|--------------------------------------------------------------------------
| Clean Output Buffer
|--------------------------------------------------------------------------
*/

while (
    ob_get_level() > 0
) {
    ob_end_clean();
}

/*
|--------------------------------------------------------------------------
| Excel Download Headers
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' .
    $fileName .
    '"'
);

header(
    'Cache-Control: max-age=0'
);

header(
    'Pragma: public'
);

header(
    'Expires: 0'
);

/*
|--------------------------------------------------------------------------
| Write Excel
|--------------------------------------------------------------------------
*/

$writer =
    new Xlsx($spreadsheet);

$writer->save(
    'php://output'
);

exit;