<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Principal Attendance Data
|--------------------------------------------------------------------------
| Included by principal/attendance.php
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/EthiopianCalendar.php';

date_default_timezone_set('Africa/Addis_Ababa');

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('normalizeDate')) {
    function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $date)
        );

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            $day
        );
    }
}

if (!function_exists('gregorianToEthiopian')) {
    function gregorianToEthiopian(string $date): ?array
    {
        $normalized = normalizeDate($date);

        if ($normalized === null) {
            return null;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $normalized)
        );

        return EthiopianCalendar::gregorianToEthiopian(
            $year,
            $month,
            $day
        );
    }
}

if (!function_exists('ethiopianDateLabel')) {
    function ethiopianDateLabel(
        int $year,
        int $month,
        int $day
    ): string {
        return EthiopianCalendar::format(
            $year,
            $month,
            $day
        );
    }
}

if (!function_exists('ethiopianMonthName')) {
    function ethiopianMonthName(int $month): string
    {
        return EthiopianCalendar::monthName($month);
    }
}

if (!function_exists('attendanceStatusClass')) {
    function attendanceStatusClass(string $status): string
    {
        return match ($status) {
            'Present' => 'status-present',
            'Absent' => 'status-absent',
            'Late' => 'status-late',
            'Excused' => 'status-excused',
            default => ''
        };
    }
}

if (!function_exists('attendanceStatusShort')) {
    function attendanceStatusShort(string $status): string
    {
        return match ($status) {
            'Present' => 'P',
            'Absent' => 'A',
            'Late' => 'L',
            'Excused' => 'E',
            default => '—'
        };
    }
}

if (!function_exists('attendanceUrl')) {
    function attendanceUrl(array $params = []): string
    {
        $params = array_filter(
            $params,
            static function ($value): bool {
                return $value !== null && $value !== '';
            }
        );

        return 'attendance.php' .
            (
                count($params) > 0
                    ? '?' . http_build_query($params)
                    : ''
            );
    }
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$successMessage = '';
$errorMessage = '';
$warningMessage = '';

if (
    isset($_GET['success']) &&
    is_string($_GET['success'])
) {
    $successMessage = trim($_GET['success']);
}

if (
    isset($_GET['error']) &&
    is_string($_GET['error'])
) {
    $errorMessage = trim($_GET['error']);
}

if (
    isset($_GET['warning']) &&
    is_string($_GET['warning'])
) {
    $warningMessage = trim($_GET['warning']);
}

/*
|--------------------------------------------------------------------------
| Attendance Statuses
|--------------------------------------------------------------------------
*/

$statuses = [
    'Present',
    'Absent',
    'Late',
    'Excused'
];

/*
|--------------------------------------------------------------------------
| Search Type
|--------------------------------------------------------------------------
|
| day   = one-day attendance
| range = attendance history
|
*/

$searchType = strtolower(
    trim(
        (string) ($_GET['search'] ?? 'day')
    )
);

if (!in_array($searchType, ['day', 'range'], true)) {
    $searchType = 'day';
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearId = 0;
$academicYearName = '';
$academicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    $academicYear = $result->fetch_assoc();

    $stmt->close();
}

if ($academicYear) {
    $academicYearId = (int) $academicYear['id'];
    $academicYearName = (string) $academicYear['name'];
}

/*
|--------------------------------------------------------------------------
| Today
|--------------------------------------------------------------------------
*/

$todayGregorian = date('Y-m-d');

try {
    $todayEthiopian = EthiopianCalendar::today();

    $todayEthYear =
        (int) $todayEthiopian['year'];

    $todayEthMonth =
        (int) $todayEthiopian['month'];

    $todayEthDay =
        (int) $todayEthiopian['day'];

    $todayEthFormatted =
        EthiopianCalendar::todayFormatted();

} catch (Throwable $e) {

    $todayEthYear = 0;
    $todayEthMonth = 0;
    $todayEthDay = 0;
    $todayEthFormatted = $todayGregorian;
}

$todayEthiopianFormatted =
    $todayEthFormatted;

/*
|--------------------------------------------------------------------------
| Ethiopian Months
|--------------------------------------------------------------------------
*/

$ethiopianMonths = [
    1  => 'Meskerem',
    2  => 'Tikemet',
    3  => 'Hidar',
    4  => 'Tahsas',
    5  => 'Tir',
    6  => 'Yekatit',
    7  => 'Megabit',
    8  => 'Miazia',
    9  => 'Ginbot',
    10 => 'Sene',
    11 => 'Hamle',
    12 => 'Nehase',
    13 => 'Pagume'
];

/*
|--------------------------------------------------------------------------
| Ethiopian Years
|--------------------------------------------------------------------------
*/

$ethiopianYears = [];

if ($todayEthYear > 0) {
    for (
        $year = $todayEthYear - 5;
        $year <= $todayEthYear;
        $year++
    ) {
        $ethiopianYears[] = $year;
    }
}

/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

$grades = [];

$stmt = $conn->prepare("
    SELECT
        id,
        grade_number,
        name
    FROM grades
    WHERE grade_number BETWEEN 1 AND 12
    ORDER BY grade_number
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

$sections = [];

$stmt = $conn->prepare("
    SELECT
        id,
        code,
        name
    FROM sections
    WHERE code IN ('A', 'B', 'C', 'D', 'E')
    ORDER BY code
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Grade
|--------------------------------------------------------------------------
*/

$selectedGrade = (int) (
    $_GET['grade'] ?? 0
);

if (
    $selectedGrade < 1 ||
    $selectedGrade > 12
) {
    $selectedGrade = 0;
}

/*
|--------------------------------------------------------------------------
| Selected Section
|--------------------------------------------------------------------------
*/

$selectedSectionCode = strtoupper(
    trim(
        (string) ($_GET['section'] ?? '')
    )
);

$validSections = [
    'A',
    'B',
    'C',
    'D',
    'E'
];

if (!in_array(
    $selectedSectionCode,
    $validSections,
    true
)) {
    $selectedSectionCode = '';
}

/*
|--------------------------------------------------------------------------
| Selected Date
|--------------------------------------------------------------------------
|
| Backend date is always Gregorian.
|
*/

$selectedDateGregorian =
    $todayGregorian;

/*
|--------------------------------------------------------------------------
| Ethiopian Selected Date
|--------------------------------------------------------------------------
|
| New UI sends:
|
| eth_year
| eth_month
| eth_day
|
| Older links may still send:
|
| date
|
*/

$selectedEthYear =
    $todayEthYear;

$selectedEthMonth =
    $todayEthMonth;

$selectedEthDay =
    $todayEthDay;

if (
    isset($_GET['eth_year']) &&
    isset($_GET['eth_month']) &&
    isset($_GET['eth_day'])
) {
    $requestedEthYear =
        (int) $_GET['eth_year'];

    $requestedEthMonth =
        (int) $_GET['eth_month'];

    $requestedEthDay =
        (int) $_GET['eth_day'];

    try {

        $convertedDate =
            EthiopianCalendar::toGregorian(
                $requestedEthYear,
                $requestedEthMonth,
                $requestedEthDay
            );

        $normalizedDate =
            normalizeDate($convertedDate);

        if ($normalizedDate !== null) {

            $selectedDateGregorian =
                $normalizedDate;

            $selectedEthYear =
                $requestedEthYear;

            $selectedEthMonth =
                $requestedEthMonth;

            $selectedEthDay =
                $requestedEthDay;
        }

    } catch (Throwable $e) {

        $warningMessage =
            'The selected Ethiopian date is invalid.';
    }

} elseif (isset($_GET['date'])) {

    $requestedDate =
        normalizeDate(
            (string) $_GET['date']
        );

    if ($requestedDate !== null) {

        $selectedDateGregorian =
            $requestedDate;

        $selectedParts =
            gregorianToEthiopian(
                $selectedDateGregorian
            );

        if ($selectedParts !== null) {

            $selectedEthYear =
                (int) $selectedParts['year'];

            $selectedEthMonth =
                (int) $selectedParts['month'];

            $selectedEthDay =
                (int) $selectedParts['day'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Prevent Future Selected Date
|--------------------------------------------------------------------------
*/

if ($selectedDateGregorian > $todayGregorian) {

    $selectedDateGregorian =
        $todayGregorian;

    $selectedEthYear =
        $todayEthYear;

    $selectedEthMonth =
        $todayEthMonth;

    $selectedEthDay =
        $todayEthDay;
}

/*
|--------------------------------------------------------------------------
| Selected Date Labels
|--------------------------------------------------------------------------
*/

$selectedDateEthiopian =
    ethiopianDateLabel(
        $selectedEthYear,
        $selectedEthMonth,
        $selectedEthDay
    );

$selectedDateLabel =
    $selectedDateEthiopian;

/*
|--------------------------------------------------------------------------
| Selected Grade Name
|--------------------------------------------------------------------------
*/

$selectedGradeName =
    $selectedGrade > 0
        ? 'Grade ' . $selectedGrade
        : '';

foreach ($grades as $grade) {

    if (
        (int) $grade['grade_number'] ===
        $selectedGrade
    ) {
        $selectedGradeName =
            (string) $grade['name'];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Date Range Defaults
|--------------------------------------------------------------------------
*/

$dateRangeStartGregorian =
    $selectedDateGregorian;

$dateRangeEndGregorian =
    $selectedDateGregorian;

$fromEthYear =
    $selectedEthYear;

$fromEthMonth =
    $selectedEthMonth;

$fromEthDay =
    $selectedEthDay;

$toEthYear =
    $selectedEthYear;

$toEthMonth =
    $selectedEthMonth;

$toEthDay =
    $selectedEthDay;

/*
|--------------------------------------------------------------------------
| Ethiopian From Date
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['from_eth_year']) &&
    isset($_GET['from_eth_month']) &&
    isset($_GET['from_eth_day'])
) {

    $requestedFromYear =
        (int) $_GET['from_eth_year'];

    $requestedFromMonth =
        (int) $_GET['from_eth_month'];

    $requestedFromDay =
        (int) $_GET['from_eth_day'];

    try {

        $convertedFrom =
            EthiopianCalendar::toGregorian(
                $requestedFromYear,
                $requestedFromMonth,
                $requestedFromDay
            );

        $normalizedFrom =
            normalizeDate($convertedFrom);

        if ($normalizedFrom !== null) {

            $dateRangeStartGregorian =
                $normalizedFrom;

            $fromEthYear =
                $requestedFromYear;

            $fromEthMonth =
                $requestedFromMonth;

            $fromEthDay =
                $requestedFromDay;
        }

    } catch (Throwable $e) {

        $warningMessage =
            'The selected From Ethiopian date is invalid.';
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian To Date
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['to_eth_year']) &&
    isset($_GET['to_eth_month']) &&
    isset($_GET['to_eth_day'])
) {

    $requestedToYear =
        (int) $_GET['to_eth_year'];

    $requestedToMonth =
        (int) $_GET['to_eth_month'];

    $requestedToDay =
        (int) $_GET['to_eth_day'];

    try {

        $convertedTo =
            EthiopianCalendar::toGregorian(
                $requestedToYear,
                $requestedToMonth,
                $requestedToDay
            );

        $normalizedTo =
            normalizeDate($convertedTo);

        if ($normalizedTo !== null) {

            $dateRangeEndGregorian =
                $normalizedTo;

            $toEthYear =
                $requestedToYear;

            $toEthMonth =
                $requestedToMonth;

            $toEthDay =
                $requestedToDay;
        }

    } catch (Throwable $e) {

        $warningMessage =
            'The selected To Ethiopian date is invalid.';
    }
}

/*
|--------------------------------------------------------------------------
| Gregorian From/To Compatibility
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['from']) &&
    !isset($_GET['from_eth_year'])
) {

    $from =
        normalizeDate(
            (string) $_GET['from']
        );

    if ($from !== null) {

        $dateRangeStartGregorian =
            $from;

        $parts =
            gregorianToEthiopian($from);

        if ($parts !== null) {

            $fromEthYear =
                (int) $parts['year'];

            $fromEthMonth =
                (int) $parts['month'];

            $fromEthDay =
                (int) $parts['day'];
        }
    }
}

if (
    isset($_GET['to']) &&
    !isset($_GET['to_eth_year'])
) {

    $to =
        normalizeDate(
            (string) $_GET['to']
        );

    if ($to !== null) {

        $dateRangeEndGregorian =
            $to;

        $parts =
            gregorianToEthiopian($to);

        if ($parts !== null) {

            $toEthYear =
                (int) $parts['year'];

            $toEthMonth =
                (int) $parts['month'];

            $toEthDay =
                (int) $parts['day'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Prevent Future Range Dates
|--------------------------------------------------------------------------
*/

if ($dateRangeStartGregorian > $todayGregorian) {

    $dateRangeStartGregorian =
        $todayGregorian;

    $fromEthYear =
        $todayEthYear;

    $fromEthMonth =
        $todayEthMonth;

    $fromEthDay =
        $todayEthDay;
}

if ($dateRangeEndGregorian > $todayGregorian) {

    $dateRangeEndGregorian =
        $todayGregorian;

    $toEthYear =
        $todayEthYear;

    $toEthMonth =
        $todayEthMonth;

    $toEthDay =
        $todayEthDay;
}

/*
|--------------------------------------------------------------------------
| Correct Reversed Range
|--------------------------------------------------------------------------
*/

if (
    $dateRangeStartGregorian >
    $dateRangeEndGregorian
) {

    $temporary =
        $dateRangeStartGregorian;

    $dateRangeStartGregorian =
        $dateRangeEndGregorian;

    $dateRangeEndGregorian =
        $temporary;

    $temporary =
        $fromEthYear;

    $fromEthYear =
        $toEthYear;

    $toEthYear =
        $temporary;

    $temporary =
        $fromEthMonth;

    $fromEthMonth =
        $toEthMonth;

    $toEthMonth =
        $temporary;

    $temporary =
        $fromEthDay;

    $fromEthDay =
        $toEthDay;

    $toEthDay =
        $temporary;
}

/*
|--------------------------------------------------------------------------
| Range Labels
|--------------------------------------------------------------------------
*/

$rangeStartLabel =
    ethiopianDateLabel(
        $fromEthYear,
        $fromEthMonth,
        $fromEthDay
    );

$rangeEndLabel =
    ethiopianDateLabel(
        $toEthYear,
        $toEthMonth,
        $toEthDay
    );

$dateRangeStartEthiopian =
    $rangeStartLabel;

$dateRangeEndEthiopian =
    $rangeEndLabel;

/*
|--------------------------------------------------------------------------
| Default Data
|--------------------------------------------------------------------------
*/

$todayStudents = [];

$totalStudents = 0;

$todayStats = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0
];

$attendanceRows = [];

$attendanceSummary = [
    'students' => 0,
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0
];

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$perPage = 20;

$totalPages = 1;

$historyPage = max(
    1,
    (int) ($_GET['history_page'] ?? 1)
);

$historyPerPage = 20;

$historyStudentCount = 0;

$historyTotalPages = 1;

$historyStudents = [];

$historyDates = [];

$editAttendance = [];

$pagination = [
    'page' => 1,
    'per_page' => 20,
    'total' => 0,
    'total_pages' => 1,
    'offset' => 0,
    'queries' => [],
    'previous_query' => '',
    'next_query' => ''
];

$historyPagination = [
    'page' => 1,
    'per_page' => 20,
    'total' => 0,
    'total_pages' => 1,
    'offset' => 0,
    'queries' => [],
    'previous_query' => '',
    'next_query' => ''
];

$exportUrl =
    'attendance-export.php';

/*
|--------------------------------------------------------------------------
| Query Only When Class + Academic Year Exist
|--------------------------------------------------------------------------
*/

if (
    $academicYearId > 0 &&
    $selectedGrade > 0 &&
    $selectedSectionCode !== ''
) {

    /*
    |--------------------------------------------------------------------------
    | Resolve Grade ID + Section ID
    |--------------------------------------------------------------------------
    */

    $gradeId = 0;
    $sectionId = 0;

    $stmt = $conn->prepare("
        SELECT
            g.id AS grade_id,
            sec.id AS section_id
        FROM grades g
        INNER JOIN sections sec
            ON sec.code = ?
        WHERE g.grade_number = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            'si',
            $selectedSectionCode,
            $selectedGrade
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $class =
            $result->fetch_assoc();

        $stmt->close();

        if ($class) {

            $gradeId =
                (int) $class['grade_id'];

            $sectionId =
                (int) $class['section_id'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Continue Only If Class Exists
    |--------------------------------------------------------------------------
    */

    if (
        $gradeId > 0 &&
        $sectionId > 0
    ) {

        /*
        |--------------------------------------------------------------------------
        | Total Students
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total
            FROM student_registrations sr
            INNER JOIN students s
                ON s.id = sr.student_id
            WHERE sr.academic_year_id = ?
              AND sr.grade_id = ?
              AND sr.section_id = ?
        ");

        if ($stmt) {

            $stmt->bind_param(
                'iii',
                $academicYearId,
                $gradeId,
                $sectionId
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $countRow =
                $result->fetch_assoc();

            $stmt->close();

            $totalStudents =
                (int) (
                    $countRow['total'] ?? 0
                );
        }

        $attendanceSummary['students'] =
            $totalStudents;

        /*
        |--------------------------------------------------------------------------
        | Daily Attendance
        |--------------------------------------------------------------------------
        |
        | Only load this data for day mode.
        |
        */

        if ($searchType === 'day') {

            $totalPages = max(
                1,
                (int) ceil(
                    $totalStudents /
                    $perPage
                )
            );

            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $offset =
                ($page - 1) *
                $perPage;

            $pagination['page'] =
                $page;

            $pagination['per_page'] =
                $perPage;

            $pagination['total'] =
                $totalStudents;

            $pagination['total_pages'] =
                $totalPages;

            $pagination['offset'] =
                $offset;

            /*
            |--------------------------------------------------------------------------
            | Selected-Day Students
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Get the latest attendance record for the exact
            | registration and attendance date.
            |
            */

            $sql = "
                SELECT
                    sr.id AS registration_id,
                    sr.student_id,
                    s.student_code,
                    s.full_name,

                    sa.status AS attendance_status,
                    sa.marked_by,

                    marker.full_name AS marked_by_name

                FROM student_registrations sr

                INNER JOIN students s
                    ON s.id = sr.student_id

                LEFT JOIN student_attendance sa
                    ON sa.id = (
                        SELECT MAX(sa2.id)
                        FROM student_attendance sa2
                        WHERE sa2.registration_id =
                            sr.id
                          AND sa2.student_id =
                            sr.student_id
                          AND sa2.academic_year_id =
                            sr.academic_year_id
                          AND sa2.grade_id =
                            sr.grade_id
                          AND sa2.section_id =
                            sr.section_id
                          AND sa2.attendance_date = ?
                    )

                LEFT JOIN users marker
                    ON marker.id = sa.marked_by

                WHERE sr.academic_year_id = ?
                  AND sr.grade_id = ?
                  AND sr.section_id = ?

                ORDER BY
                    s.full_name ASC

                LIMIT ? OFFSET ?
            ";

            $stmt =
                $conn->prepare($sql);

            if ($stmt) {

                /*
                |--------------------------------------------------------------------------
                | 6 variables
                |
                | date      = s
                | academic  = i
                | grade     = i
                | section   = i
                | limit     = i
                | offset    = i
                |
                | Correct:
                | siiiii
                |--------------------------------------------------------------------------
                */

                $stmt->bind_param(
                    'siiiii',
                    $selectedDateGregorian,
                    $academicYearId,
                    $gradeId,
                    $sectionId,
                    $perPage,
                    $offset
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                while (
                    $row =
                        $result->fetch_assoc()
                ) {

                    /*
                    |--------------------------------------------------------------------------
                    | Empty status means no attendance row.
                    |--------------------------------------------------------------------------
                    */

                    $row['attendance_status'] =
                        !empty(
                            $row['attendance_status']
                        )
                            ? (string)
                                $row['attendance_status']
                            : '';

                    /*
                    |--------------------------------------------------------------------------
                    | Only show Recorded By when an
                    | actual attendance record exists.
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $row['attendance_status'] === ''
                    ) {
                        $row['marked_by_name'] = '';
                        $row['marked_by'] = null;
                    }

                    $todayStudents[] =
                        $row;

                    $attendanceRows[] =
                        $row;
                }

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Selected-Day Statistics
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Use the same latest-record logic as the table.
            | This prevents duplicate attendance records from
            | making the summary different from the table.
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    latest.status,
                    COUNT(*) AS total
                FROM student_registrations sr

                INNER JOIN (
                    SELECT
                        sa.registration_id,
                        sa.status
                    FROM student_attendance sa
                    INNER JOIN (
                        SELECT
                            registration_id,
                            MAX(id) AS latest_id
                        FROM student_attendance
                        WHERE attendance_date = ?
                          AND academic_year_id = ?
                          AND grade_id = ?
                          AND section_id = ?
                        GROUP BY registration_id
                    ) latest_record
                        ON latest_record.latest_id = sa.id
                ) latest
                    ON latest.registration_id = sr.id

                WHERE sr.academic_year_id = ?
                  AND sr.grade_id = ?
                  AND sr.section_id = ?

                GROUP BY latest.status
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'siiiiii',
                    $selectedDateGregorian,
                    $academicYearId,
                    $gradeId,
                    $sectionId,
                    $academicYearId,
                    $gradeId,
                    $sectionId
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                while (
                    $row =
                        $result->fetch_assoc()
                ) {

                    $status =
                        (string) $row['status'];

                    $total =
                        (int) $row['total'];

                    if (
                        array_key_exists(
                            $status,
                            $todayStats
                        )
                    ) {

                        $todayStats[$status] =
                            $total;

                        $attendanceSummary[$status] =
                            $total;
                    }
                }

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Daily Pagination URLs
            |--------------------------------------------------------------------------
            */

            for (
                $p = 1;
                $p <= $totalPages;
                $p++
            ) {

                $params = [
                    'search' => 'day',
                    'grade' => $selectedGrade,
                    'section' => $selectedSectionCode,

                    'eth_year' =>
                        $selectedEthYear,

                    'eth_month' =>
                        $selectedEthMonth,

                    'eth_day' =>
                        $selectedEthDay,

                    'page' =>
                        $p
                ];

                $pagination['queries'][$p] =
                    http_build_query($params);
            }

            if ($page > 1) {

                $params = [
                    'search' => 'day',
                    'grade' => $selectedGrade,
                    'section' => $selectedSectionCode,

                    'eth_year' =>
                        $selectedEthYear,

                    'eth_month' =>
                        $selectedEthMonth,

                    'eth_day' =>
                        $selectedEthDay,

                    'page' =>
                        $page - 1
                ];

                $pagination['previous_query'] =
                    http_build_query($params);
            }

            if ($page < $totalPages) {

                $params = [
                    'search' => 'day',
                    'grade' => $selectedGrade,
                    'section' => $selectedSectionCode,

                    'eth_year' =>
                        $selectedEthYear,

                    'eth_month' =>
                        $selectedEthMonth,

                    'eth_day' =>
                        $selectedEthDay,

                    'page' =>
                        $page + 1
                ];

                $pagination['next_query'] =
                    http_build_query($params);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Attendance History
        |--------------------------------------------------------------------------
        |
        | Only load this data for range mode.
        |
        */

        if ($searchType === 'range') {

            /*
            |--------------------------------------------------------------------------
            | Build Date List
            |--------------------------------------------------------------------------
            */

            try {

                $startDate =
                    new DateTimeImmutable(
                        $dateRangeStartGregorian
                    );

                $endDate =
                    new DateTimeImmutable(
                        $dateRangeEndGregorian
                    );

                $currentDate =
                    $startDate;

                $safetyCounter = 0;

                while (
                    $currentDate <= $endDate &&
                    $safetyCounter < 370
                ) {

                    $gregorian =
                        $currentDate->format('Y-m-d');

                    $eth =
                        gregorianToEthiopian(
                            $gregorian
                        );

                    if ($eth !== null) {

                        $historyDates[] = [
                            'gregorian' =>
                                $gregorian,

                            'year' =>
                                (int) $eth['year'],

                            'month' =>
                                (int) $eth['month'],

                            'day' =>
                                (int) $eth['day'],

                            'label' =>
                                ethiopianDateLabel(
                                    (int) $eth['year'],
                                    (int) $eth['month'],
                                    (int) $eth['day']
                                )
                        ];
                    }

                    $currentDate =
                        $currentDate->modify(
                            '+1 day'
                        );

                    $safetyCounter++;
                }

            } catch (Throwable $e) {

                $historyDates = [];
            }

            /*
            |--------------------------------------------------------------------------
            | History Student Count
            |--------------------------------------------------------------------------
            */

            $historyStudentCount =
                $totalStudents;

            $historyPerPage =
                20;

            $historyTotalPages =
                max(
                    1,
                    (int) ceil(
                        $historyStudentCount /
                        $historyPerPage
                    )
                );

            if (
                $historyPage >
                $historyTotalPages
            ) {

                $historyPage =
                    $historyTotalPages;
            }

            $historyOffset =
                ($historyPage - 1) *
                $historyPerPage;

            $historyPagination['page'] =
                $historyPage;

            $historyPagination['per_page'] =
                $historyPerPage;

            $historyPagination['total'] =
                $historyStudentCount;

            $historyPagination['total_pages'] =
                $historyTotalPages;

            $historyPagination['offset'] =
                $historyOffset;

            /*
            |--------------------------------------------------------------------------
            | Load History Students
            |--------------------------------------------------------------------------
            */

            $historyStudentIds = [];

            $historyRegistrationIds = [];

            $stmt = $conn->prepare("
                SELECT
                    sr.id AS registration_id,
                    sr.student_id,
                    s.student_code,
                    s.full_name
                FROM student_registrations sr

                INNER JOIN students s
                    ON s.id = sr.student_id

                WHERE sr.academic_year_id = ?
                  AND sr.grade_id = ?
                  AND sr.section_id = ?

                ORDER BY
                    s.full_name ASC

                LIMIT ? OFFSET ?
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'iiiii',
                    $academicYearId,
                    $gradeId,
                    $sectionId,
                    $historyPerPage,
                    $historyOffset
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                while (
                    $row =
                        $result->fetch_assoc()
                ) {

                    $row['attendance'] =
                        [];

                    $historyStudents[] =
                        $row;

                    $historyStudentIds[] =
                        (int) $row['student_id'];

                    $historyRegistrationIds[] =
                        (int) $row['registration_id'];
                }

                $stmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Load Historical Attendance
            |--------------------------------------------------------------------------
            */

            if (
                count($historyRegistrationIds) > 0 &&
                count($historyDates) > 0
            ) {

                /*
                |--------------------------------------------------------------------------
                | Use registration IDs so attendance belongs
                | to the exact academic-year/class registration.
                |--------------------------------------------------------------------------
                */

                $placeholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($historyRegistrationIds),
                            '?'
                        )
                    );

                $historySql = "
                    SELECT
                        sa.registration_id,
                        sa.student_id,
                        sa.attendance_date,
                        sa.status

                    FROM student_attendance sa

                    INNER JOIN (
                        SELECT
                            registration_id,
                            attendance_date,
                            MAX(id) AS latest_id

                        FROM student_attendance

                        WHERE registration_id IN (
                            $placeholders
                        )

                          AND academic_year_id = ?
                          AND grade_id = ?
                          AND section_id = ?

                          AND attendance_date BETWEEN ?
                              AND ?

                        GROUP BY
                            registration_id,
                            attendance_date

                    ) latest

                        ON latest.latest_id =
                            sa.id

                    ORDER BY
                        sa.attendance_date ASC
                ";

                $historyStmt =
                    $conn->prepare(
                        $historySql
                    );

                if ($historyStmt) {

                    /*
                    |--------------------------------------------------------------------------
                    | Dynamic bind parameters
                    |--------------------------------------------------------------------------
                    */

                    $types =
                        str_repeat(
                            'i',
                            count(
                                $historyRegistrationIds
                            )
                        ) .
                        'iiiss';

                    $bindValues =
                        $historyRegistrationIds;

                    $bindValues[] =
                        $academicYearId;

                    $bindValues[] =
                        $gradeId;

                    $bindValues[] =
                        $sectionId;

                    $bindValues[] =
                        $dateRangeStartGregorian;

                    $bindValues[] =
                        $dateRangeEndGregorian;

                    $bindReferences = [];

                    $bindReferences[] =
                        $types;

                    foreach (
                        $bindValues as $key => $value
                    ) {

                        $bindReferences[] =
                            &$bindValues[$key];
                    }

                    call_user_func_array(
                        [
                            $historyStmt,
                            'bind_param'
                        ],
                        $bindReferences
                    );

                    $historyStmt->execute();

                    $historyResult =
                        $historyStmt->get_result();

                    $historyLookup = [];

                    while (
                        $attendance =
                            $historyResult->fetch_assoc()
                    ) {

                        $registrationId =
                            (int) $attendance[
                                'registration_id'
                            ];

                        $date =
                            (string) $attendance[
                                'attendance_date'
                            ];

                        $historyLookup[
                            $registrationId
                        ][$date] =
                            (string) $attendance[
                                'status'
                            ];
                    }

                    $historyStmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | Attach Attendance To Students
                    |--------------------------------------------------------------------------
                    */

                    foreach (
                        $historyStudents
                        as &$historyStudent
                    ) {

                        $registrationId =
                            (int) $historyStudent[
                                'registration_id'
                            ];

                        $historyStudent[
                            'attendance'
                        ] =
                            $historyLookup[
                                $registrationId
                            ] ?? [];
                    }

                    unset($historyStudent);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | History Pagination URLs
            |--------------------------------------------------------------------------
            */

            for (
                $hp = 1;
                $hp <= $historyTotalPages;
                $hp++
            ) {

                $params = [
                    'search' => 'range',

                    'grade' =>
                        $selectedGrade,

                    'section' =>
                        $selectedSectionCode,

                    'from_eth_year' =>
                        $fromEthYear,

                    'from_eth_month' =>
                        $fromEthMonth,

                    'from_eth_day' =>
                        $fromEthDay,

                    'to_eth_year' =>
                        $toEthYear,

                    'to_eth_month' =>
                        $toEthMonth,

                    'to_eth_day' =>
                        $toEthDay,

                    'history_page' =>
                        $hp
                ];

                $historyPagination[
                    'queries'
                ][$hp] =
                    http_build_query($params);
            }

            if ($historyPage > 1) {

                $params = [
                    'search' => 'range',

                    'grade' =>
                        $selectedGrade,

                    'section' =>
                        $selectedSectionCode,

                    'from_eth_year' =>
                        $fromEthYear,

                    'from_eth_month' =>
                        $fromEthMonth,

                    'from_eth_day' =>
                        $fromEthDay,

                    'to_eth_year' =>
                        $toEthYear,

                    'to_eth_month' =>
                        $toEthMonth,

                    'to_eth_day' =>
                        $toEthDay,

                    'history_page' =>
                        $historyPage - 1
                ];

                $historyPagination[
                    'previous_query'
                ] =
                    http_build_query($params);
            }

            if (
                $historyPage <
                $historyTotalPages
            ) {

                $params = [
                    'search' => 'range',

                    'grade' =>
                        $selectedGrade,

                    'section' =>
                        $selectedSectionCode,

                    'from_eth_year' =>
                        $fromEthYear,

                    'from_eth_month' =>
                        $fromEthMonth,

                    'from_eth_day' =>
                        $fromEthDay,

                    'to_eth_year' =>
                        $toEthYear,

                    'to_eth_month' =>
                        $toEthMonth,

                    'to_eth_day' =>
                        $toEthDay,

                    'history_page' =>
                        $historyPage + 1
                ];

                $historyPagination[
                    'next_query'
                ] =
                    http_build_query($params);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Compatibility
        |--------------------------------------------------------------------------
        */

        $pagination['page'] =
            $page;

        $pagination['per_page'] =
            $perPage;

        $pagination['total'] =
            $totalStudents;

        $pagination['total_pages'] =
            $totalPages;
    }
}

/*
|--------------------------------------------------------------------------
| Export URL
|--------------------------------------------------------------------------
*/

$exportParams = [

    'grade' =>
        $selectedGrade,

    'section' =>
        $selectedSectionCode,

    'search' =>
        $searchType,

    'date' =>
        $selectedDateGregorian,

    'from' =>
        $dateRangeStartGregorian,

    'to' =>
        $dateRangeEndGregorian,

    'from_eth_year' =>
        $fromEthYear,

    'from_eth_month' =>
        $fromEthMonth,

    'from_eth_day' =>
        $fromEthDay,

    'to_eth_year' =>
        $toEthYear,

    'to_eth_month' =>
        $toEthMonth,

    'to_eth_day' =>
        $toEthDay
];

$exportUrl =
    'attendance-export.php?' .
    http_build_query($exportParams);

/*
|--------------------------------------------------------------------------
| Compatibility Variables Used By attendance.php
|--------------------------------------------------------------------------
*/

$selectedSection =
    $selectedSectionCode;

$attendanceRows =
    $attendanceRows ?? [];

$attendanceSummary['students'] =
    $totalStudents;

/*
|--------------------------------------------------------------------------
| History Compatibility
|--------------------------------------------------------------------------
*/

if (!isset($historyPagination)) {

    $historyPagination = [
        'page' => 1,
        'per_page' => 20,
        'total' => 0,
        'total_pages' => 1,
        'offset' => 0,
        'queries' => [],
        'previous_query' => '',
        'next_query' => ''
    ];
}