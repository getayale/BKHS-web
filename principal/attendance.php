<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/* =========================================================
   HELPERS
========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused',
        default => '',
    };
}

function statusClass(?string $status): string
{
    return match (normalizeStatus($status)) {
        'Present' => 'status-present',
        'Absent' => 'status-absent',
        'Late' => 'status-late',
        'Excused' => 'status-excused',
        default => 'status-none',
    };
}

function statusIcon(?string $status): string
{
    return match (normalizeStatus($status)) {
        'Present' =>
            '<i class="bi bi-check-circle-fill"></i>',
        'Absent' =>
            '<i class="bi bi-x-circle-fill"></i>',
        'Late' =>
            '<i class="bi bi-clock-fill"></i>',
        'Excused' =>
            '<i class="bi bi-info-circle-fill"></i>',
        default =>
            '<i class="bi bi-dash-circle"></i>',
    };
}

/*
 * Ethiopian month names
 */
$ethiopianMonths = [
    1  => 'Meskerem',
    2  => 'Tikimt',
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
    13 => 'Pagume',
];

/*
 * Gregorian -> Ethiopian
 *
 * Ethiopian New Year 2019 = September 11, 2026.
 */
function gregorianToEthiopian(
    string $gregorianDate
): array {

    $parts = explode('-', $gregorianDate);

    if (count($parts) !== 3) {
        throw new RuntimeException(
            'Invalid Gregorian date.'
        );
    }

    $year = (int) $parts[0];
    $month = (int) $parts[1];
    $day = (int) $parts[2];

    $jdn = gregoriantojd(
        $month,
        $day,
        $year
    );

    $ethiopianEpoch = 1724221;

    $ethYear = intdiv(
        (4 * ($jdn - $ethiopianEpoch)) + 1463,
        1461
    );

    $newYearJdn =
        $ethiopianEpoch
        + (365 * ($ethYear - 1))
        + intdiv($ethYear, 4);

    $dayOfYear = $jdn - $newYearJdn;

    if ($dayOfYear < 0) {
        $ethYear--;

        $newYearJdn =
            $ethiopianEpoch
            + (365 * ($ethYear - 1))
            + intdiv($ethYear, 4);

        $dayOfYear = $jdn - $newYearJdn;
    }

    $ethMonth = intdiv($dayOfYear, 30) + 1;
    $ethDay = ($dayOfYear % 30) + 1;

    return [
        'year' => $ethYear,
        'month' => $ethMonth,
        'day' => $ethDay,
    ];
}

/*
 * Ethiopian -> Gregorian
 */
function ethiopianToGregorian(
    int $year,
    int $month,
    int $day
): string {

    if ($year < 1) {
        throw new RuntimeException(
            'Invalid Ethiopian year.'
        );
    }

    if ($month < 1 || $month > 13) {
        throw new RuntimeException(
            'Invalid Ethiopian month.'
        );
    }

    $maxDay = ($month === 13) ? 6 : 30;

    if ($day < 1 || $day > $maxDay) {
        throw new RuntimeException(
            'Invalid Ethiopian day.'
        );
    }

    $ethiopianEpoch = 1724221;

    $jdn =
        $ethiopianEpoch
        + (365 * ($year - 1))
        + intdiv($year, 4)
        + (30 * ($month - 1))
        + $day
        - 1;

    $gregorian = jdtogregorian($jdn);

    $parts = explode('/', $gregorian);

    if (count($parts) !== 3) {
        throw new RuntimeException(
            'Could not convert Ethiopian date.'
        );
    }

    return sprintf(
        '%04d-%02d-%02d',
        (int) $parts[2],
        (int) $parts[0],
        (int) $parts[1]
    );
}

/*
 * Today's Ethiopian date
 */
$todayGregorian = date('Y-m-d');

$todayEth = gregorianToEthiopian(
    $todayGregorian
);

$todayEthYear = $todayEth['year'];
$todayEthMonth = $todayEth['month'];
$todayEthDay = $todayEth['day'];

/* =========================================================
   SEARCH PARAMETERS
========================================================= */

$searchType = strtolower(
    trim((string) ($_GET['search'] ?? 'day'))
);

if (!in_array($searchType, ['day', 'range'], true)) {
    $searchType = 'day';
}

$selectedGrade = (int) (
    $_GET['grade'] ?? 1
);

$selectedSection = strtoupper(
    trim((string) ($_GET['section'] ?? 'A'))
);

/*
 * Single day
 */
$selectedEthYear = (int) (
    $_GET['eth_year'] ?? $todayEthYear
);

$selectedEthMonth = (int) (
    $_GET['eth_month'] ?? $todayEthMonth
);

$selectedEthDay = (int) (
    $_GET['eth_day'] ?? $todayEthDay
);

/*
 * Range
 */
$fromEthYear = (int) (
    $_GET['from_eth_year'] ?? $todayEthYear
);

$fromEthMonth = (int) (
    $_GET['from_eth_month'] ?? $todayEthMonth
);

$fromEthDay = (int) (
    $_GET['from_eth_day'] ?? $todayEthDay
);

$toEthYear = (int) (
    $_GET['to_eth_year'] ?? $todayEthYear
);

$toEthMonth = (int) (
    $_GET['to_eth_month'] ?? $todayEthMonth
);

$toEthDay = (int) (
    $_GET['to_eth_day'] ?? $todayEthDay
);

/*
 * Month names.
 */
$selectedEthMonthName =
    $ethiopianMonths[$selectedEthMonth]
    ?? '';

$fromEthMonthName =
    $ethiopianMonths[$fromEthMonth]
    ?? '';

$toEthMonthName =
    $ethiopianMonths[$toEthMonth]
    ?? '';

/* =========================================================
   ACTIVE ACADEMIC YEAR
========================================================= */

$activeAcademicYear = null;

$stmt = $conn->prepare(
    'SELECT id, name, status
     FROM academic_years
     WHERE status = "Active"
     ORDER BY id DESC
     LIMIT 1'
);

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    $activeAcademicYear =
        $result->fetch_assoc();

    $stmt->close();
}

if (!$activeAcademicYear) {
    die('No active academic year found.');
}

$academicYearId =
    (int) $activeAcademicYear['id'];

$academicYearName =
    (string) $activeAcademicYear['name'];

/* =========================================================
   GRADES
========================================================= */

$grades = [];

$result = $conn->query(
    'SELECT id, grade_number, name
     FROM grades
     ORDER BY grade_number ASC'
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $result->free();
}

/* =========================================================
   SECTIONS
========================================================= */

$sections = [];

$result = $conn->query(
    'SELECT id, code, name
     FROM sections
     ORDER BY code ASC'
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $result->free();
}

/* =========================================================
   RESOLVE GRADE
========================================================= */

$gradeId = 0;
$gradeName = 'Grade ' . $selectedGrade;

$stmt = $conn->prepare(
    'SELECT id, grade_number, name
     FROM grades
     WHERE grade_number = ?
     LIMIT 1'
);

if ($stmt) {

    $stmt->bind_param(
        'i',
        $selectedGrade
    );

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $gradeId = (int) $row['id'];
        $gradeName = (string) $row['name'];
    }

    $stmt->close();
}

/* =========================================================
   RESOLVE SECTION
========================================================= */

$sectionId = 0;
$sectionName = $selectedSection;

$stmt = $conn->prepare(
    'SELECT id, code, name
     FROM sections
     WHERE UPPER(code) = ?
     LIMIT 1'
);

if ($stmt) {

    $stmt->bind_param(
        's',
        $selectedSection
    );

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $sectionId = (int) $row['id'];

        $sectionName =
            !empty($row['name'])
                ? (string) $row['name']
                : (string) $row['code'];
    }

    $stmt->close();
}

/* =========================================================
   SEARCH DATA
========================================================= */

$attendanceRows = [];
$historyStudents = [];
$historyDates = [];

$dailyCounts = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0,
];

/*
 * Used to determine whether ANY attendance
 * was recorded for the selected day.
 */
$dayAttendanceRecorded = false;

/*
 * Default date strings
 */
$selectedGregorianDate = '';
$fromGregorianDate = '';
$toGregorianDate = '';

/* =========================================================
   SINGLE DAY
========================================================= */

if (
    $searchType === 'day' &&
    $gradeId > 0 &&
    $sectionId > 0
) {

    try {

        $selectedGregorianDate =
            ethiopianToGregorian(
                $selectedEthYear,
                $selectedEthMonth,
                $selectedEthDay
            );

    } catch (Throwable $e) {

        $selectedGregorianDate =
            $todayGregorian;

        $selectedEth =
            gregorianToEthiopian(
                $todayGregorian
            );

        $selectedEthYear =
            $selectedEth['year'];

        $selectedEthMonth =
            $selectedEth['month'];

        $selectedEthDay =
            $selectedEth['day'];

        $selectedEthMonthName =
            $ethiopianMonths[$selectedEthMonth]
            ?? '';
    }

    /*
     * Pagination
     */
    $page = max(
        1,
        (int) ($_GET['page'] ?? 1)
    );

    $perPage = 20;

    /*
     * Total students
     */
    $totalStudents = 0;

    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM student_registrations sr
         INNER JOIN students s
             ON s.id = sr.student_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iii',
            $academicYearId,
            $gradeId,
            $sectionId
        );

        $stmt->execute();

        $stmt->bind_result(
            $totalStudents
        );

        $stmt->fetch();

        $stmt->close();
    }

    $totalPages = max(
        1,
        (int) ceil(
            $totalStudents / $perPage
        )
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset =
        ($page - 1) * $perPage;

    /*
     * Students
     *
     * Student Code intentionally removed.
     */
    $stmt = $conn->prepare(
        'SELECT
            sr.id AS registration_id,
            sr.student_id,
            s.full_name
         FROM student_registrations sr
         INNER JOIN students s
             ON s.id = sr.student_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?
         ORDER BY s.full_name ASC, sr.id ASC
         LIMIT ? OFFSET ?'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iiiii',
            $academicYearId,
            $gradeId,
            $sectionId,
            $perPage,
            $offset
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $attendanceRows[] = $row;
        }

        $stmt->close();
    }

    /*
     * IMPORTANT:
     *
     * Check attendance for the ENTIRE selected
     * Grade + Section, not only the current page.
     *
     * This prevents pagination from incorrectly
     * saying attendance was not recorded.
     */
    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM student_attendance sa
         INNER JOIN student_registrations sr
             ON sr.id = sa.registration_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?
           AND sa.attendance_date = ?'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iiis',
            $academicYearId,
            $gradeId,
            $sectionId,
            $selectedGregorianDate
        );

        $stmt->execute();

        $attendanceCount = 0;

        $stmt->bind_result(
            $attendanceCount
        );

        $stmt->fetch();

        $stmt->close();

        $dayAttendanceRecorded =
            $attendanceCount > 0;
    }

    /*
     * Get latest attendance for the selected day
     * for the students on the current page.
     */
    if (
        $dayAttendanceRecorded &&
        !empty($attendanceRows)
    ) {

        $registrationIds = array_map(
            static fn(array $row): int =>
                (int) $row['registration_id'],
            $attendanceRows
        );

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($registrationIds),
                '?'
            )
        );

        $types =
            str_repeat('i', count($registrationIds))
            . 's';

        $sql =
            'SELECT
                sa.registration_id,
                sa.status
             FROM student_attendance sa
             INNER JOIN (
                SELECT
                    registration_id,
                    MAX(id) AS latest_id
                FROM student_attendance
                WHERE registration_id IN (' .
                $placeholders .
                ')
                  AND attendance_date = ?
                GROUP BY registration_id
             ) latest
                ON latest.latest_id = sa.id';

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $params = $registrationIds;
            $params[] = $selectedGregorianDate;

            $bindParams = [];
            $bindParams[] = $types;

            foreach ($params as $key => $value) {
                $bindParams[] = &$params[$key];
            }

            call_user_func_array(
                [$stmt, 'bind_param'],
                $bindParams
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $registrationId =
                    (int) $row['registration_id'];

                $attendanceStatus =
                    normalizeStatus(
                        $row['status'] ?? ''
                    );

                foreach (
                    $attendanceRows
                    as &$student
                ) {
                    if (
                        (int) $student['registration_id']
                        === $registrationId
                    ) {
                        $student['attendance_status'] =
                            $attendanceStatus;

                        break;
                    }
                }

                unset($student);
            }

            $stmt->close();
        }
    }

    /*
     * Counts
     */
    foreach ($attendanceRows as $row) {

        $status =
            normalizeStatus(
                $row['attendance_status'] ?? ''
            );

        if (
            isset($dailyCounts[$status])
        ) {
            $dailyCounts[$status]++;
        }
    }
}

/* =========================================================
   DATE RANGE
========================================================= */

if (
    $searchType === 'range' &&
    $gradeId > 0 &&
    $sectionId > 0
) {

    try {

        $fromGregorianDate =
            ethiopianToGregorian(
                $fromEthYear,
                $fromEthMonth,
                $fromEthDay
            );

        $toGregorianDate =
            ethiopianToGregorian(
                $toEthYear,
                $toEthMonth,
                $toEthDay
            );

    } catch (Throwable $e) {

        $fromGregorianDate =
            $todayGregorian;

        $toGregorianDate =
            $todayGregorian;
    }

    /*
     * Make sure start <= end.
     */
    if (
        $fromGregorianDate >
        $toGregorianDate
    ) {

        [
            $fromGregorianDate,
            $toGregorianDate
        ] = [
            $toGregorianDate,
            $fromGregorianDate
        ];
    }

    /*
     * Pagination
     */
    $historyPage = max(
        1,
        (int) (
            $_GET['history_page'] ?? 1
        )
    );

    $perPage = 20;

    /*
     * Total students
     */
    $totalHistoryStudents = 0;

    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM student_registrations sr
         INNER JOIN students s
             ON s.id = sr.student_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iii',
            $academicYearId,
            $gradeId,
            $sectionId
        );

        $stmt->execute();

        $stmt->bind_result(
            $totalHistoryStudents
        );

        $stmt->fetch();

        $stmt->close();
    }

    $historyTotalPages = max(
        1,
        (int) ceil(
            $totalHistoryStudents / $perPage
        )
    );

    if (
        $historyPage >
        $historyTotalPages
    ) {
        $historyPage =
            $historyTotalPages;
    }

    $offset =
        ($historyPage - 1) * $perPage;

    /*
     * Students
     *
     * Student Code intentionally removed.
     */
    $stmt = $conn->prepare(
        'SELECT
            sr.id AS registration_id,
            sr.student_id,
            s.full_name
         FROM student_registrations sr
         INNER JOIN students s
             ON s.id = sr.student_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?
         ORDER BY s.full_name ASC, sr.id ASC
         LIMIT ? OFFSET ?'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iiiii',
            $academicYearId,
            $gradeId,
            $sectionId,
            $perPage,
            $offset
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $row['attendance'] = [];

            $historyStudents[] =
                $row;
        }

        $stmt->close();
    }

    /*
     * Build all dates in the selected range.
     */
    try {

        $startDate =
            new DateTimeImmutable(
                $fromGregorianDate
            );

        $endDate =
            new DateTimeImmutable(
                $toGregorianDate
            );

        /*
         * Prevent excessively large ranges.
         */
        $difference =
            $startDate->diff($endDate)->days;

        if ($difference > 370) {
            $endDate =
                $startDate->modify('+370 days');

            $toGregorianDate =
                $endDate->format('Y-m-d');
        }

        $period =
            new DatePeriod(
                $startDate,
                new DateInterval('P1D'),
                $endDate->modify('+1 day')
            );

        foreach ($period as $date) {

            $gregorian =
                $date->format('Y-m-d');

            $eth =
                gregorianToEthiopian(
                    $gregorian
                );

            $historyDates[] = [
                'gregorian' => $gregorian,
                'year' => $eth['year'],
                'month' => $eth['month'],
                'day' => $eth['day'],
                'month_name' =>
                    $ethiopianMonths[
                        $eth['month']
                    ] ?? '',
            ];
        }

    } catch (Throwable $e) {
        $historyDates = [];
    }

    /*
     * =====================================================
     * FIND DATES WHERE ATTENDANCE WAS ACTUALLY RECORDED
     * =====================================================
     *
     * IMPORTANT:
     *
     * This checks ALL students in the selected
     * Grade + Section, not only the current page.
     *
     * Dates with zero attendance records are removed
     * completely from the history columns.
     */
    $recordedDates = [];

    $stmt = $conn->prepare(
        'SELECT DISTINCT
            sa.attendance_date
         FROM student_attendance sa
         INNER JOIN student_registrations sr
             ON sr.id = sa.registration_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?
           AND sa.attendance_date BETWEEN ? AND ?
         ORDER BY sa.attendance_date ASC'
    );

    if ($stmt) {

        $stmt->bind_param(
            'iiiss',
            $academicYearId,
            $gradeId,
            $sectionId,
            $fromGregorianDate,
            $toGregorianDate
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $recordedDate =
                (string) $row['attendance_date'];

            $recordedDates[$recordedDate] =
                true;
        }

        $stmt->close();
    }

    /*
     * Keep ONLY dates where at least one
     * attendance record exists.
     */
    $historyDates = array_values(
        array_filter(
            $historyDates,
            static function (
                array $date
            ) use (
                $recordedDates
            ): bool {

                return isset(
                    $recordedDates[
                        $date['gregorian']
                    ]
                );
            }
        )
    );

    /*
     * Attendance records
     *
     * This query is still limited to the students
     * shown on the current page because the table
     * itself is paginated.
     */
    if (
        !empty($historyStudents) &&
        !empty($historyDates)
    ) {

        $registrationIds = array_map(
            static fn(array $row): int =>
                (int) $row['registration_id'],
            $historyStudents
        );

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($registrationIds),
                '?'
            )
        );

        $sql =
            'SELECT
                sa.registration_id,
                sa.attendance_date,
                sa.status
             FROM student_attendance sa
             INNER JOIN (
                SELECT
                    registration_id,
                    attendance_date,
                    MAX(id) AS latest_id
                FROM student_attendance
                WHERE registration_id IN (' .
                $placeholders .
                ')
                  AND attendance_date
                      BETWEEN ? AND ?
                GROUP BY
                    registration_id,
                    attendance_date
             ) latest
                ON latest.latest_id = sa.id
             ORDER BY
                sa.registration_id ASC,
                sa.attendance_date ASC';

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $params =
                $registrationIds;

            $params[] =
                $fromGregorianDate;

            $params[] =
                $toGregorianDate;

            $types =
                str_repeat(
                    'i',
                    count($registrationIds)
                ) . 'ss';

            $bindParams = [];
            $bindParams[] = $types;

            foreach (
                $params as $key => $value
            ) {
                $bindParams[] = &$params[$key];
            }

            call_user_func_array(
                [$stmt, 'bind_param'],
                $bindParams
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            while (
                $row =
                $result->fetch_assoc()
            ) {

                $registrationId =
                    (int) $row[
                        'registration_id'
                    ];

                $date =
                    (string) $row[
                        'attendance_date'
                    ];

                $status =
                    normalizeStatus(
                        $row['status'] ?? ''
                    );

                foreach (
                    $historyStudents
                    as &$student
                ) {

                    if (
                        (int) $student[
                            'registration_id'
                        ] === $registrationId
                    ) {

                        $student['attendance'][$date] =
                            $status;

                        break;
                    }
                }

                unset($student);
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   EXPORT URL
========================================================= */

/*
 * IMPORTANT:
 *
 * This uses the EXACT same parameter names
 * that attendance-export.php expects.
 */
$exportParams = [
    'search' => $searchType,
    'grade' => $selectedGrade,
    'section' => $selectedSection,
];

if ($searchType === 'range') {

    $exportParams['from_eth_year'] =
        $fromEthYear;

    $exportParams['from_eth_month'] =
        $fromEthMonth;

    $exportParams['from_eth_day'] =
        $fromEthDay;

    $exportParams['to_eth_year'] =
        $toEthYear;

    $exportParams['to_eth_month'] =
        $toEthMonth;

    $exportParams['to_eth_day'] =
        $toEthDay;

} else {

    /*
     * SINGLE DAY
     */
    $exportParams['eth_year'] =
        $selectedEthYear;

    $exportParams['eth_month'] =
        $selectedEthMonth;

    $exportParams['eth_day'] =
        $selectedEthDay;
}

$exportUrl =
    'attendance-export.php?' .
    http_build_query($exportParams);

/* =========================================================
   DISPLAY DATE
========================================================= */

$singleDateDisplay =
    $selectedEthMonthName .
    ' ' .
    $selectedEthDay .
    ', ' .
    $selectedEthYear;

$rangeFromDisplay =
    $fromEthMonthName .
    ' ' .
    $fromEthDay .
    ', ' .
    $fromEthYear;

$rangeToDisplay =
    $toEthMonthName .
    ' ' .
    $toEthDay .
    ', ' .
    $toEthYear;

/* =========================================================
   PAGINATION URLS
========================================================= */

function buildPageUrl(
    array $params
): string {

    return 'attendance.php?' .
        http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Attendance | Principal</title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: .3s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand .logo-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #312e81;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .sidebar-brand img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-brand strong {
            font-size: 15px;
        }

        .nav-section {
            padding: 20px 14px;
        }

        .nav-title {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 0 10px 9px;
        }

        .sidebar a {
            color: #cbd5e1;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            border-radius: 9px;
            margin-bottom: 3px;
            font-size: 13px;
            transition: .2s ease;
        }

        .sidebar a i {
            font-size: 17px;
            width: 20px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #312e81;
            color: #fff;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-title h5 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .topbar-title small {
            color: #64748b;
            font-size: 12px;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #312e81;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .content {
            padding: 28px;
        }

        .page-heading {
            margin-bottom: 22px;
        }

        .page-heading h4 {
            margin-bottom: 4px;
            font-weight: 700;
        }

        .page-heading p {
            color: #64748b;
            margin: 0;
            font-size: 13px;
        }

        .card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(15,23,42,.04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 18px 20px;
        }

        .card-body {
            padding: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }

        .form-select,
        .form-control {
            border-color: #cbd5e1;
            border-radius: 9px;
            min-height: 42px;
            font-size: 13px;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 .2rem rgba(99,102,241,.12);
        }

        .search-type {
            display: flex;
            gap: 8px;
        }

        .search-type label {
            flex: 1;
            cursor: pointer;
        }

        .search-type input {
            display: none;
        }

        .search-type span {
            display: block;
            text-align: center;
            border: 1px solid #cbd5e1;
            padding: 10px;
            border-radius: 9px;
            font-size: 13px;
            color: #475569;
            transition: .2s;
        }

        .search-type input:checked + span {
            background: #312e81;
            border-color: #312e81;
            color: #fff;
        }

        .btn-search {
            background: #312e81;
            border-color: #312e81;
            color: #fff;
            border-radius: 9px;
            min-height: 42px;
            font-weight: 600;
        }

        .btn-search:hover {
            background: #272467;
            border-color: #272467;
            color: #fff;
        }

        .btn-export {
            background: #15803d;
            border-color: #15803d;
            color: #fff;
            text-decoration: none;
            border-radius: 9px;
            padding: 10px 15px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-export:hover {
            background: #166534;
            color: #fff;
        }

        .result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .result-title {
            font-weight: 700;
            margin: 0;
            font-size: 16px;
        }

        .result-subtitle {
            color: #64748b;
            font-size: 12px;
            margin-top: 4px;
        }

        .stat-card {
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 12px;
            padding: 15px;
        }

        .stat-label {
            color: #64748b;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 700;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            margin-bottom: 0 !important;
        }

        .table thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
            padding: 13px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table tbody td {
            font-size: 13px;
            vertical-align: middle;
            padding: 13px 12px;
        }

        .student-name {
            font-weight: 600;
            color: #1e293b;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-present {
            background: #dcfce7;
            color: #166534;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .status-excused {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-none {
            background: #f1f5f9;
            color: #64748b;
        }

        .pagination .page-link {
            color: #312e81;
            border-radius: 7px;
            margin: 0 2px;
            font-size: 12px;
        }

        .pagination .active .page-link {
            background: #312e81;
            border-color: #312e81;
            color: #fff;
        }

        .mobile-toggle {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.5);
            z-index: 1040;
        }

        .range-box {
            display: none;
        }

        .range-box.show {
            display: block;
        }

        .day-box.hide {
            display: none;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-toggle {
                display: inline-block;
            }

            .overlay.show {
                display: block;
            }

            .content {
                padding: 20px;
            }
        }

        @media (max-width: 575.98px) {

            .topbar {
                padding: 0 15px;
            }

            .content {
                padding: 15px;
            }

            .profile-name {
                display: none;
            }
        }

    </style>

</head>

<body>

<div
    class="overlay"
    id="sidebarOverlay"
></div>

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="logo-box">

            <img
                src="../public/logo.webp"
                alt="BKHS"
            >

        </div>

        <strong>Bole Kale Hiwot School</strong>

    </div>

    <div class="nav-section">

        <div class="nav-title">
            Principal
        </div>

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="announcements.php">
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcements</span>
        </a>

        <a href="subject-assignment.php">
            <i class="bi bi-book-fill"></i>
            <span>Subject Assignment</span>
        </a>

        <a href="homeroom-assignment.php">
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a href="student-assignment.php">
            <i class="bi bi-people-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a
            href="attendance.php"
            class="active"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a href="roster.php">
            <i class="bi bi-list-ul"></i>
            <span>Roster</span>
        </a>

        <a href="certificate.php">
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a href="result.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="nav-title mt-4">
            Account
        </div>

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<main class="main">

    <header class="topbar">

        <div class="d-flex align-items-center gap-2">

            <button
                type="button"
                class="mobile-toggle"
                id="sidebarToggle"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">

                <h5>Attendance</h5>

                <small>
                    Principal Attendance Monitoring
                </small>

            </div>

        </div>

        <div class="profile-mini">

            <div class="profile-name text-end">

                <div
                    style="
                        font-size:13px;
                        font-weight:600;
                    "
                >
                    <?= h($_SESSION['full_name'] ?? 'Principal') ?>
                </div>

                <div
                    style="
                        font-size:11px;
                        color:#64748b;
                    "
                >
                    Principal
                </div>

            </div>

            <div class="profile-avatar">
                <i class="bi bi-person-fill"></i>
            </div>

        </div>

    </header>

    <div class="content">

        <div class="page-heading">

            <h4>Attendance</h4>

            <p>
                View student attendance for the active
                academic year.
            </p>

        </div>

        <!-- SEARCH CARD -->

        <div class="card mb-4">

            <div class="card-header">

                <strong>
                    <i class="bi bi-search me-2"></i>
                    Search Attendance
                </strong>

            </div>

            <div class="card-body">

                <form
                    method="get"
                    action="attendance.php"
                    id="attendanceSearchForm"
                >

                    <div class="row g-3">

                        <div class="col-lg-3 col-md-6">

                            <label
                                class="form-label"
                                for="grade"
                            >
                                Grade
                            </label>

                            <select
                                name="grade"
                                id="grade"
                                class="form-select"
                                required
                            >

                                <?php foreach (
                                    $grades
                                    as $grade
                                ): ?>

                                    <?php
                                    $gradeNumber =
                                        (int)
                                        $grade[
                                            'grade_number'
                                        ];
                                    ?>

                                    <option
                                        value="<?= $gradeNumber ?>"
                                        <?= (
                                            $selectedGrade
                                            ===
                                            $gradeNumber
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= h(
                                            $grade['name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-3 col-md-6">

                            <label
                                class="form-label"
                                for="section"
                            >
                                Section
                            </label>

                            <select
                                name="section"
                                id="section"
                                class="form-select"
                                required
                            >

                                <?php foreach (
                                    $sections
                                    as $section
                                ): ?>

                                    <?php
                                    $code =
                                        strtoupper(
                                            (string)
                                            $section['code']
                                        );
                                    ?>

                                    <option
                                        value="<?= h($code) ?>"
                                        <?= (
                                            $selectedSection
                                            === $code
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= h(
                                            !empty(
                                                $section['name']
                                            )
                                                ? $section['name']
                                                : $code
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-6">

                            <label class="form-label">
                                Search Type
                            </label>

                            <div class="search-type">

                                <label>

                                    <input
                                        type="radio"
                                        name="search"
                                        value="day"
                                        <?= $searchType === 'day'
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        <i class="bi bi-calendar-day me-1"></i>
                                        Single Day
                                    </span>

                                </label>

                                <label>

                                    <input
                                        type="radio"
                                        name="search"
                                        value="range"
                                        <?= $searchType === 'range'
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        <i class="bi bi-calendar-range me-1"></i>
                                        Date Range
                                    </span>

                                </label>

                            </div>

                        </div>

                        <!-- SINGLE DAY -->

                        <div
                            class="col-12 day-box"
                            id="dayBox"
                        >

                            <div class="row g-3">

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Ethiopian Year
                                    </label>

                                    <input
                                        type="number"
                                        name="eth_year"
                                        class="form-control"
                                        value="<?= h(
                                            $selectedEthYear
                                        ) ?>"
                                        min="1"
                                        required
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Ethiopian Month
                                    </label>

                                    <select
                                        name="eth_month"
                                        class="form-select"
                                        required
                                    >

                                        <?php foreach (
                                            $ethiopianMonths
                                            as $monthNumber =>
                                            $monthName
                                        ): ?>

                                            <option
                                                value="<?= $monthNumber ?>"
                                                <?= (
                                                    $selectedEthMonth
                                                    ===
                                                    $monthNumber
                                                )
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >
                                                <?= h(
                                                    $monthName
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Ethiopian Day
                                    </label>

                                    <input
                                        type="number"
                                        name="eth_day"
                                        class="form-control"
                                        value="<?= h(
                                            $selectedEthDay
                                        ) ?>"
                                        min="1"
                                        max="30"
                                        required
                                    >

                                </div>

                            </div>

                        </div>

                        <!-- RANGE -->

                        <div
                            class="col-12 range-box"
                            id="rangeBox"
                        >

                            <div class="row g-4">

                                <div class="col-lg-6">

                                    <div
                                        class="border rounded-3 p-3"
                                    >

                                        <div
                                            class="fw-semibold mb-3"
                                            style="font-size:13px;"
                                        >
                                            <i class="bi bi-calendar-event me-1"></i>
                                            From
                                        </div>

                                        <div class="row g-2">

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Year
                                                </label>

                                                <input
                                                    type="number"
                                                    name="from_eth_year"
                                                    class="form-control"
                                                    value="<?= h(
                                                        $fromEthYear
                                                    ) ?>"
                                                    min="1"
                                                >

                                            </div>

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Month
                                                </label>

                                                <select
                                                    name="from_eth_month"
                                                    class="form-select"
                                                >

                                                    <?php foreach (
                                                        $ethiopianMonths
                                                        as $monthNumber =>
                                                        $monthName
                                                    ): ?>

                                                        <option
                                                            value="<?= $monthNumber ?>"
                                                            <?= (
                                                                $fromEthMonth
                                                                ===
                                                                $monthNumber
                                                            )
                                                                ? 'selected'
                                                                : ''
                                                            ?>
                                                        >
                                                            <?= h(
                                                                $monthName
                                                            ) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Day
                                                </label>

                                                <input
                                                    type="number"
                                                    name="from_eth_day"
                                                    class="form-control"
                                                    value="<?= h(
                                                        $fromEthDay
                                                    ) ?>"
                                                    min="1"
                                                    max="30"
                                                >

                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-lg-6">

                                    <div
                                        class="border rounded-3 p-3"
                                    >

                                        <div
                                            class="fw-semibold mb-3"
                                            style="font-size:13px;"
                                        >
                                            <i class="bi bi-calendar-event me-1"></i>
                                            To
                                        </div>

                                        <div class="row g-2">

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Year
                                                </label>

                                                <input
                                                    type="number"
                                                    name="to_eth_year"
                                                    class="form-control"
                                                    value="<?= h(
                                                        $toEthYear
                                                    ) ?>"
                                                    min="1"
                                                >

                                            </div>

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Month
                                                </label>

                                                <select
                                                    name="to_eth_month"
                                                    class="form-select"
                                                >

                                                    <?php foreach (
                                                        $ethiopianMonths
                                                        as $monthNumber =>
                                                        $monthName
                                                    ): ?>

                                                        <option
                                                            value="<?= $monthNumber ?>"
                                                            <?= (
                                                                $toEthMonth
                                                                ===
                                                                $monthNumber
                                                            )
                                                                ? 'selected'
                                                                : ''
                                                            ?>
                                                        >
                                                            <?= h(
                                                                $monthName
                                                            ) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                            <div class="col-4">

                                                <label class="form-label">
                                                    Day
                                                </label>

                                                <input
                                                    type="number"
                                                    name="to_eth_day"
                                                    class="form-control"
                                                    value="<?= h(
                                                        $toEthDay
                                                    ) ?>"
                                                    min="1"
                                                    max="30"
                                                >

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                        <div class="col-12">

                            <button
                                type="submit"
                                class="btn btn-search"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search Attendance
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- SINGLE DAY RESULT -->

        <?php if ($searchType === 'day'): ?>

            <div class="card">

                <div class="card-header">

                    <div class="result-header">

                        <div>

                            <div class="result-title">
                                Daily Attendance
                            </div>

                            <div class="result-subtitle">

                                <?= h($gradeName) ?>
                                —
                                Section
                                <?= h($selectedSection) ?>
                                —
                                <?= h($singleDateDisplay) ?>

                            </div>

                        </div>

                        <?php if ($dayAttendanceRecorded): ?>

                            <a
                                href="<?= h($exportUrl) ?>"
                                class="btn-export"
                            >
                                <i class="bi bi-file-earmark-excel"></i>
                                Export Excel
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="card-body">

                    <?php if (!$dayAttendanceRecorded): ?>

                        <div
                            class="text-center py-5"
                        >

                            <div
                                class="mb-3"
                                style="
                                    width:64px;
                                    height:64px;
                                    margin:0 auto;
                                    border-radius:50%;
                                    background:#f1f5f9;
                                    color:#64748b;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    font-size:28px;
                                "
                            >
                                <i class="bi bi-calendar-x"></i>
                            </div>

                            <h6
                                class="fw-semibold mb-2"
                            >
                                Attendance for this date is not recorded.
                            </h6>

                            <p
                                class="text-muted mb-0"
                                style="font-size:13px;"
                            >
                                No attendance record was found for
                                <?= h($gradeName) ?>,
                                Section <?= h($selectedSection) ?>
                                on <?= h($singleDateDisplay) ?>.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="row g-3 mb-4">

                            <div class="col-6 col-md-3">

                                <div class="stat-card">

                                    <div class="stat-label">
                                        Present
                                    </div>

                                    <div
                                        class="stat-value"
                                        style="color:#15803d;"
                                    >
                                        <?= $dailyCounts['Present'] ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-6 col-md-3">

                                <div class="stat-card">

                                    <div class="stat-label">
                                        Absent
                                    </div>

                                    <div
                                        class="stat-value"
                                        style="color:#b91c1c;"
                                    >
                                        <?= $dailyCounts['Absent'] ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-6 col-md-3">

                                <div class="stat-card">

                                    <div class="stat-label">
                                        Late
                                    </div>

                                    <div
                                        class="stat-value"
                                        style="color:#b45309;"
                                    >
                                        <?= $dailyCounts['Late'] ?>
                                    </div>

                                </div>

                            </div>

                            <div class="col-6 col-md-3">

                                <div class="stat-card">

                                    <div class="stat-label">
                                        Excused
                                    </div>

                                    <div
                                        class="stat-value"
                                        style="color:#1d4ed8;"
                                    >
                                        <?= $dailyCounts['Excused'] ?>
                                    </div>

                                </div>

                            </div>

                        </div>

                        <div class="table-wrap">

                            <table class="table table-hover align-middle">

                                <thead>

                                    <tr>

                                        <th>No.</th>

                                        <th>Student</th>

                                        <th>Status</th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php if (
                                    empty($attendanceRows)
                                ): ?>

                                    <tr>

                                        <td
                                            colspan="3"
                                            class="text-center text-muted py-4"
                                        >
                                            No students found.
                                        </td>

                                    </tr>

                                <?php else: ?>

                                    <?php foreach (
                                        $attendanceRows
                                        as $index => $row
                                    ): ?>

                                        <?php
                                        $status =
                                            normalizeStatus(
                                                $row[
                                                    'attendance_status'
                                                ] ?? ''
                                            );

                                        $rowNo =
                                            (
                                                ($page - 1)
                                                * $perPage
                                            )
                                            + $index
                                            + 1;
                                        ?>

                                        <tr>

                                            <td>
                                                <?= $rowNo ?>
                                            </td>

                                            <td>
                                                <div class="student-name">
                                                    <?= h(
                                                        $row['full_name']
                                                    ) ?>
                                                </div>
                                            </td>

                                            <td>

                                                <?php if ($status !== ''): ?>

                                                    <span
                                                        class="status-badge <?= h(
                                                            statusClass(
                                                                $status
                                                            )
                                                        ) ?>"
                                                    >
                                                        <?= statusIcon(
                                                            $status
                                                        ) ?>

                                                        <?= h(
                                                            $status
                                                        ) ?>
                                                    </span>

                                                <?php else: ?>

                                                    <span
                                                        class="status-badge status-none"
                                                    >
                                                        <i class="bi bi-dash-circle"></i>
                                                        Not Recorded
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                        <?php if (
                            isset($totalPages) &&
                            $totalPages > 1
                        ): ?>

                            <div
                                class="d-flex justify-content-end mt-4"
                            >

                                <nav>

                                    <ul class="pagination mb-0">

                                        <?php for (
                                            $p = 1;
                                            $p <= $totalPages;
                                            $p++
                                        ): ?>

                                            <?php
                                            $pageParams = [
                                                'search' => 'day',
                                                'grade' =>
                                                    $selectedGrade,
                                                'section' =>
                                                    $selectedSection,
                                                'eth_year' =>
                                                    $selectedEthYear,
                                                'eth_month' =>
                                                    $selectedEthMonth,
                                                'eth_day' =>
                                                    $selectedEthDay,
                                                'page' => $p,
                                            ];
                                            ?>

                                            <li
                                                class="page-item <?= $p === $page
                                                    ? 'active'
                                                    : ''
                                                ?>"
                                            >

                                                <a
                                                    class="page-link"
                                                    href="<?= h(
                                                        buildPageUrl(
                                                            $pageParams
                                                        )
                                                    ) ?>"
                                                >
                                                    <?= $p ?>
                                                </a>

                                            </li>

                                        <?php endfor; ?>

                                    </ul>

                                </nav>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

        <!-- RANGE RESULT -->

        <?php if ($searchType === 'range'): ?>

            <div class="card">

                <div class="card-header">

                    <div class="result-header">

                        <div>

                            <div class="result-title">
                                Attendance History
                            </div>

                            <div class="result-subtitle">

                                <?= h($gradeName) ?>
                                —
                                Section
                                <?= h($selectedSection) ?>
                                —
                                <?= h($rangeFromDisplay) ?>
                                to
                                <?= h($rangeToDisplay) ?>

                            </div>

                        </div>

                        <a
                            href="<?= h($exportUrl) ?>"
                            class="btn-export"
                        >
                            <i class="bi bi-file-earmark-excel"></i>
                            Export Excel
                        </a>

                    </div>

                </div>

                <div class="card-body">

                    <div class="table-wrap">

                        <table class="table table-hover align-middle">

                            <thead>

                                <tr>

                                    <th>No.</th>

                                    <th>Student</th>

                                    <?php foreach (
                                        $historyDates
                                        as $date
                                    ): ?>

                                        <th class="text-center">

                                            <?= h(
                                                $date[
                                                    'month_name'
                                                ]
                                            ) ?>

                                            <?= h(
                                                $date['day']
                                            ) ?>

                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                empty(
                                    $historyStudents
                                )
                            ): ?>

                                <tr>

                                    <td
                                        colspan="<?= 2 + count(
                                            $historyDates
                                        ) ?>"
                                        class="text-center text-muted py-4"
                                    >
                                        No students found.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $historyStudents
                                    as $index => $student
                                ): ?>

                                    <?php
                                    $rowNo =
                                        (
                                            ($historyPage - 1)
                                            * $perPage
                                        )
                                        + $index
                                        + 1;
                                    ?>

                                    <tr>

                                        <td>
                                            <?= $rowNo ?>
                                        </td>

                                        <td>

                                            <div class="student-name">
                                                <?= h(
                                                    $student[
                                                        'full_name'
                                                    ]
                                                ) ?>
                                            </div>

                                        </td>

                                        <?php foreach (
                                            $historyDates
                                            as $date
                                        ): ?>

                                            <?php

                                            $dateKey =
                                                $date[
                                                    'gregorian'
                                                ];

                                            $status =
                                                normalizeStatus(
                                                    $student[
                                                        'attendance'
                                                    ][$dateKey]
                                                        ?? ''
                                                );

                                            ?>

                                            <td class="text-center">

                                                <?php if (
                                                    $status !== ''
                                                ): ?>

                                                    <span
                                                        class="status-badge <?= h(
                                                            statusClass(
                                                                $status
                                                            )
                                                        ) ?>"
                                                    >

                                                        <?= statusIcon(
                                                            $status
                                                        ) ?>

                                                        <?= match (
                                                            $status
                                                        ) {
                                                            'Present' => 'P',
                                                            'Absent' => 'A',
                                                            'Late' => 'L',
                                                            'Excused' => 'E',
                                                            default => '—',
                                                        } ?>

                                                    </span>

                                                <?php else: ?>

                                                    <span
                                                        class="status-badge status-none"
                                                    >
                                                        —
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if (
                        isset($historyTotalPages) &&
                        $historyTotalPages > 1
                    ): ?>

                        <div
                            class="d-flex justify-content-end mt-4"
                        >

                            <nav>

                                <ul class="pagination mb-0">

                                    <?php for (
                                        $p = 1;
                                        $p <= $historyTotalPages;
                                        $p++
                                    ): ?>

                                        <?php
                                        $pageParams = [
                                            'search' => 'range',
                                            'grade' =>
                                                $selectedGrade,
                                            'section' =>
                                                $selectedSection,

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

                                            'history_page' => $p,
                                        ];
                                        ?>

                                        <li
                                            class="page-item <?= $p === $historyPage
                                                ? 'active'
                                                : ''
                                            ?>"
                                        >

                                            <a
                                                class="page-link"
                                                href="<?= h(
                                                    buildPageUrl(
                                                        $pageParams
                                                    )
                                                ) ?>"
                                            >
                                                <?= $p ?>
                                            </a>

                                        </li>

                                    <?php endfor; ?>

                                </ul>

                            </nav>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</main>

<script>

    const sidebar =
        document.getElementById('sidebar');

    const sidebarToggle =
        document.getElementById('sidebarToggle');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    sidebarToggle?.addEventListener(
        'click',
        function () {

            sidebar.classList.add('show');

            sidebarOverlay.classList.add('show');

        }
    );

    sidebarOverlay?.addEventListener(
        'click',
        function () {

            sidebar.classList.remove('show');

            sidebarOverlay.classList.remove('show');

        }
    );

    function toggleDateMode() {

        const selected =
            document.querySelector(
                'input[name="search"]:checked'
            )?.value || 'day';

        const dayBox =
            document.getElementById('dayBox');

        const rangeBox =
            document.getElementById('rangeBox');

        if (selected === 'range') {

            dayBox.classList.add('hide');

            rangeBox.classList.add('show');

        } else {

            dayBox.classList.remove('hide');

            rangeBox.classList.remove('show');

        }
    }

    document
        .querySelectorAll(
            'input[name="search"]'
        )
        .forEach(
            function (radio) {

                radio.addEventListener(
                    'change',
                    toggleDateMode
                );

            }
        );

    toggleDateMode();

</script>

</body>

</html>