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
    http_response_code(403);
    exit('Unauthorized.');
}

require_once '../config/database.php';

$autoloadPath = '../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    exit('PhpSpreadsheet autoloader not found.');
}

require_once $autoloadPath;

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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

function statusDisplay(?string $status): string
{
    return match (normalizeStatus($status)) {
        'Present' => 'Present',
        'Absent' => 'Absent',
        'Late' => 'Late',
        'Excused' => 'Excused',
        default => '—',
    };
}

function statusLetter(?string $status): string
{
    return match (normalizeStatus($status)) {
        'Present' => 'P',
        'Absent' => 'A',
        'Late' => 'L',
        'Excused' => 'E',
        default => '—',
    };
}

function statusClass(?string $status): string
{
    return match (normalizeStatus($status)) {
        'Present' => 'present',
        'Absent' => 'absent',
        'Late' => 'late',
        'Excused' => 'excused',
        default => 'none',
    };
}

/* =========================================================
   ETHIOPIAN CALENDAR
========================================================= */

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

function gregorianToEthiopian(string $gregorianDate): array
{
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

    $dayOfYear =
        $jdn - $newYearJdn;

    if ($dayOfYear < 0) {

        $ethYear--;

        $newYearJdn =
            $ethiopianEpoch
            + (365 * ($ethYear - 1))
            + intdiv($ethYear, 4);

        $dayOfYear =
            $jdn - $newYearJdn;
    }

    return [
        'year' => $ethYear,
        'month' => intdiv(
            $dayOfYear,
            30
        ) + 1,
        'day' =>
            ($dayOfYear % 30) + 1,
    ];
}

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

    $maxDay =
        $month === 13
            ? 6
            : 30;

    if (
        $day < 1 ||
        $day > $maxDay
    ) {
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

    $gregorian =
        jdtogregorian($jdn);

    $parts =
        explode('/', $gregorian);

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

/* =========================================================
   REQUEST PARAMETERS
========================================================= */

$searchType = strtolower(
    trim((string) ($_GET['search'] ?? ''))
);

$gradeNumber = (int) (
    $_GET['grade'] ?? 0
);

$sectionCode = strtoupper(
    trim((string) (
        $_GET['section'] ?? ''
    ))
);

if (
    $gradeNumber <= 0 ||
    $sectionCode === ''
) {
    exit(
        'Missing attendance export parameters.'
    );
}

/* =========================================================
   DATE PARAMETERS
========================================================= */

$ethYear = 0;
$ethMonth = 0;
$ethDay = 0;

$fromEthYear = 0;
$fromEthMonth = 0;
$fromEthDay = 0;

$toEthYear = 0;
$toEthMonth = 0;
$toEthDay = 0;

/* =========================================================
   VALIDATE SEARCH TYPE
========================================================= */

if ($searchType === 'day') {

    $ethYear = (int) (
        $_GET['eth_year'] ?? 0
    );

    $ethMonth = (int) (
        $_GET['eth_month'] ?? 0
    );

    $ethDay = (int) (
        $_GET['eth_day'] ?? 0
    );

    if (
        $ethYear <= 0 ||
        $ethMonth <= 0 ||
        $ethDay <= 0
    ) {
        exit(
            'Missing single-day attendance export date.'
        );
    }

} elseif ($searchType === 'range') {

    $fromEthYear = (int) (
        $_GET['from_eth_year'] ?? 0
    );

    $fromEthMonth = (int) (
        $_GET['from_eth_month'] ?? 0
    );

    $fromEthDay = (int) (
        $_GET['from_eth_day'] ?? 0
    );

    $toEthYear = (int) (
        $_GET['to_eth_year'] ?? 0
    );

    $toEthMonth = (int) (
        $_GET['to_eth_month'] ?? 0
    );

    $toEthDay = (int) (
        $_GET['to_eth_day'] ?? 0
    );

    if (
        $fromEthYear <= 0 ||
        $fromEthMonth <= 0 ||
        $fromEthDay <= 0 ||
        $toEthYear <= 0 ||
        $toEthMonth <= 0 ||
        $toEthDay <= 0
    ) {
        exit(
            'Missing attendance date range.'
        );
    }

} else {

    exit(
        'Invalid attendance search type.'
    );
}

/* =========================================================
   RESOLVE GRADE
========================================================= */

$gradeId = 0;
$gradeName =
    'Grade ' . $gradeNumber;

$stmt = $conn->prepare(
    'SELECT
        id,
        grade_number,
        name
     FROM grades
     WHERE grade_number = ?
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Could not prepare grade query.'
    );
}

$stmt->bind_param(
    'i',
    $gradeNumber
);

$stmt->execute();

$gradeRow =
    $stmt->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$gradeRow) {
    exit('Grade not found.');
}

$gradeId =
    (int) $gradeRow['id'];

$gradeName =
    (string) $gradeRow['name'];

/* =========================================================
   RESOLVE SECTION
========================================================= */

$sectionId = 0;
$sectionName = $sectionCode;

$stmt = $conn->prepare(
    'SELECT
        id,
        code,
        name
     FROM sections
     WHERE UPPER(code) = ?
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Could not prepare section query.'
    );
}

$stmt->bind_param(
    's',
    $sectionCode
);

$stmt->execute();

$sectionRow =
    $stmt->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$sectionRow) {
    exit('Section not found.');
}

$sectionId =
    (int) $sectionRow['id'];

$sectionName =
    !empty($sectionRow['name'])
        ? (string) $sectionRow['name']
        : $sectionCode;

/* =========================================================
   ACTIVE ACADEMIC YEAR
========================================================= */

$stmt = $conn->prepare(
    'SELECT
        id,
        name
     FROM academic_years
     WHERE status = "Active"
     ORDER BY id DESC
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Could not prepare academic year query.'
    );
}

$stmt->execute();

$academicYear =
    $stmt->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    exit(
        'No active academic year found.'
    );
}

$academicYearId =
    (int) $academicYear['id'];

/* =========================================================
   STUDENTS
========================================================= */

$students = [];

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
     ORDER BY
        s.full_name ASC,
        sr.id ASC'
);

if (!$stmt) {
    exit(
        'Could not prepare student query.'
    );
}

$stmt->bind_param(
    'iii',
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
    $students[] = $row;
}

$stmt->close();

if (empty($students)) {
    exit(
        'No students found for the selected grade and section.'
    );
}

/* =========================================================
   STATUS COLORS
========================================================= */

$statusColors = [
    'present' => [
        'fill' => 'DCFCE7',
        'font' => '166534',
    ],
    'absent' => [
        'fill' => 'FEE2E2',
        'font' => '991B1B',
    ],
    'late' => [
        'fill' => 'FEF3C7',
        'font' => '92400E',
    ],
    'excused' => [
        'fill' => 'DBEAFE',
        'font' => '1E40AF',
    ],
    'none' => [
        'fill' => 'F1F5F9',
        'font' => '64748B',
    ],
];

/* =========================================================
   COMMON EXCEL STYLES
========================================================= */

$titleStyle = [
    'font' => [
        'bold' => true,
        'size' => 18,
        'color' => [
            'rgb' => 'FFFFFF'
        ],
    ],
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '312E81'
        ],
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'bottom' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'left' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'right' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'insideHorizontal' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'insideVertical' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'diagonal' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
    ],
];

$infoStyle = [
    'font' => [
        'bold' => true,
        'size' => 12,
    ],
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'EEF2FF'
        ],
    ],
    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_LEFT,
        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'bottom' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'left' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'right' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'insideHorizontal' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'insideVertical' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
        'diagonal' => [
            'borderStyle' => Border::BORDER_NONE,
        ],
    ],
];

$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF'
        ],
    ],
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '312E81'
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
            'color' => [
                'rgb' => '777777'
            ],
        ],
    ],
];

/* =========================================================
   SINGLE DAY EXPORT
========================================================= */

if ($searchType === 'day') {

    try {

        $gregorianDate =
            ethiopianToGregorian(
                $ethYear,
                $ethMonth,
                $ethDay
            );

    } catch (Throwable $e) {

        exit(
            'Invalid Ethiopian attendance date.'
        );
    }

    /* =====================================================
       CHECK WHETHER ATTENDANCE WAS RECORDED
    ===================================================== */

    $attendanceRecorded = false;

    $stmt = $conn->prepare(
        'SELECT 1
         FROM student_attendance sa
         INNER JOIN student_registrations sr
             ON sr.id = sa.registration_id
         WHERE sr.academic_year_id = ?
           AND sr.grade_id = ?
           AND sr.section_id = ?
           AND sa.attendance_date = ?
         LIMIT 1'
    );

    if (!$stmt) {
        exit(
            'Could not prepare attendance record check.'
        );
    }

    $stmt->bind_param(
        'iiis',
        $academicYearId,
        $gradeId,
        $sectionId,
        $gregorianDate
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $attendanceRecorded =
        (bool) $result->fetch_row();

    $stmt->close();

    if (!$attendanceRecorded) {

        exit(
            'Attendance for this date is not recorded.'
        );
    }

    /* =====================================================
       ATTENDANCE LOOKUP
       Latest record wins.
    ===================================================== */

    $registrationIds =
        array_map(
            static fn(array $row): int =>
                (int) $row['registration_id'],
            $students
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

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {
        exit(
            'Could not prepare attendance query.'
        );
    }

    $params =
        $registrationIds;

    $params[] =
        $gregorianDate;

    $types =
        str_repeat(
            'i',
            count($registrationIds)
        ) . 's';

    $bindParams = [];
    $bindParams[] = $types;

    foreach (
        $params as $key => $value
    ) {
        $bindParams[] =
            &$params[$key];
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindParams
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $attendanceMap = [];

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $attendanceMap[
            (int) $row['registration_id']
        ] =
            normalizeStatus(
                $row['status'] ?? ''
            );
    }

    $stmt->close();

    /* =====================================================
       ETHIOPIAN DATE DISPLAY
    ===================================================== */

    $monthName =
        $ethiopianMonths[
            $ethMonth
        ] ?? '';

    $ethiopianDateDisplay =
        $monthName .
        ' ' .
        $ethDay .
        ', ' .
        $ethYear;

    /* =====================================================
       FILENAME
    ===================================================== */

    $filename =
        'Attendance_Grade_' .
        $gradeNumber .
        '_Section_' .
        $sectionCode .
        '_' .
        sprintf(
            '%04d%02d%02d',
            $ethYear,
            $ethMonth,
            $ethDay
        ) .
        '.xlsx';

    /* =====================================================
       CREATE SPREADSHEET
    ===================================================== */

    $spreadsheet =
        new Spreadsheet();

    $sheet =
        $spreadsheet->getActiveSheet();

    $sheet->setTitle('Attendance');

    /*
     * Hide Excel gridlines.
     * This keeps the title/info area visually clean.
     */
    $sheet->setShowGridlines(false);

    /* =====================================================
       TITLE
    ===================================================== */

    $sheet->mergeCells('A1:C1');

    $sheet->setCellValue(
        'A1',
        'Bole Kale Hiwot School'
    );

    $sheet
        ->getStyle('A1:C1')
        ->applyFromArray(
            $titleStyle
        );

    /* =====================================================
       INFORMATION
    ===================================================== */

    $sheet->mergeCells('A2:C2');

    $sheet->setCellValue(
        'A2',
        'Grade: ' .
        $gradeName .
        '    Section: ' .
        $sectionCode .
        '    Ethiopian Date: ' .
        $ethiopianDateDisplay .
        '    Academic Year: ' .
        ($academicYear['name'] ?? '')
    );

    $sheet
        ->getStyle('A2:C2')
        ->applyFromArray(
            $infoStyle
        );

    /* =====================================================
       HEADER
    ===================================================== */

    $sheet->setCellValue(
        'A4',
        'No.'
    );

    $sheet->setCellValue(
        'B4',
        'Student'
    );

    $sheet->setCellValue(
        'C4',
        'Status'
    );

    $sheet
        ->getStyle('A4:C4')
        ->applyFromArray(
            $headerStyle
        );

    /* =====================================================
       DATA ROWS
    ===================================================== */

    $rowNumber = 5;

    foreach (
        $students as $index => $student
    ) {

        $registrationId =
            (int) $student[
                'registration_id'
            ];

        $status =
            $attendanceMap[
                $registrationId
            ] ?? '';

        $displayStatus =
            statusDisplay($status);

        /* No. */
        $sheet->setCellValue(
            'A' . $rowNumber,
            $index + 1
        );

        /* Student */
        $sheet->setCellValue(
            'B' . $rowNumber,
            $student['full_name']
        );

        /* Status */
        $sheet->setCellValue(
            'C' . $rowNumber,
            $displayStatus
        );

        /*
         * Table borders begin here.
         */
        $sheet
            ->getStyle(
                'A' .
                $rowNumber .
                ':C' .
                $rowNumber
            )
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(
                Border::BORDER_THIN
            );

        /*
         * Vertical alignment.
         */
        $sheet
            ->getStyle(
                'A' .
                $rowNumber .
                ':C' .
                $rowNumber
            )
            ->getAlignment()
            ->setVertical(
                Alignment::VERTICAL_CENTER
            );

        /*
         * No. alignment.
         */
        $sheet
            ->getStyle(
                'A' . $rowNumber
            )
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );

        /*
         * Status color.
         */
        $statusClass =
            statusClass($status);

        $colors =
            $statusColors[
                $statusClass
            ] ?? $statusColors['none'];

        $statusCell =
            $sheet->getStyle(
                'C' . $rowNumber
            );

        $statusCell
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setRGB(
                $colors['fill']
            );

        $statusCell
            ->getFont()
            ->setBold(true)
            ->getColor()
            ->setRGB(
                $colors['font']
            );

        $statusCell
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            );

        $rowNumber++;
    }

    /* =====================================================
       COLUMN WIDTHS
    ===================================================== */

    $sheet
        ->getColumnDimension('A')
        ->setWidth(10);

    $sheet
        ->getColumnDimension('B')
        ->setWidth(35);

    $sheet
        ->getColumnDimension('C')
        ->setWidth(18);

    /* =====================================================
       ROW HEIGHTS
    ===================================================== */

    $sheet
        ->getRowDimension(1)
        ->setRowHeight(30);

    $sheet
        ->getRowDimension(2)
        ->setRowHeight(25);

    $sheet
        ->getRowDimension(4)
        ->setRowHeight(24);

    /* =====================================================
       FREEZE
    ===================================================== */

    $sheet->freezePane('A5');

    /* =====================================================
       PAGE SETUP
    ===================================================== */

    $sheet
        ->getPageSetup()
        ->setOrientation(
            PageSetup::ORIENTATION_PORTRAIT
        );

    $sheet
        ->getPageSetup()
        ->setPaperSize(
            PageSetup::PAPERSIZE_A4
        );

    $sheet
        ->getPageSetup()
        ->setFitToWidth(1);

    $sheet
        ->getPageSetup()
        ->setFitToHeight(0);

    $sheet
        ->getPageMargins()
        ->setTop(0.4);

    $sheet
        ->getPageMargins()
        ->setBottom(0.4);

    $sheet
        ->getPageMargins()
        ->setLeft(0.3);

    $sheet
        ->getPageMargins()
        ->setRight(0.3);

    /* =====================================================
       PRINT AREA
    ===================================================== */

    $sheet
        ->getPageSetup()
        ->setPrintArea(
            'A1:C' .
            ($rowNumber - 1)
        );

    /* =====================================================
       DOWNLOAD
    ===================================================== */

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header(
        'Cache-Control: max-age=0'
    );

    header(
        'Pragma: public'
    );

    $writer =
        new Xlsx($spreadsheet);

    $writer->save(
        'php://output'
    );

    $spreadsheet
        ->disconnectWorksheets();

    exit;
}

/* =========================================================
   DATE RANGE EXPORT
========================================================= */

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

    exit(
        'Invalid Ethiopian attendance date range.'
    );
}

/* =========================================================
   SWAP IF BACKWARDS
========================================================= */

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

/* =========================================================
   MAXIMUM 370 DAYS
========================================================= */

$startDate =
    new DateTimeImmutable(
        $fromGregorianDate
    );

$endDate =
    new DateTimeImmutable(
        $toGregorianDate
    );

$difference =
    $startDate->diff($endDate)->days;

if ($difference > 370) {

    $endDate =
        $startDate->modify(
            '+370 days'
        );

    $toGregorianDate =
        $endDate->format('Y-m-d');
}

/* =========================================================
   ACTUAL ETHIOPIAN RANGE
========================================================= */

$actualFromEth =
    gregorianToEthiopian(
        $fromGregorianDate
    );

$actualToEth =
    gregorianToEthiopian(
        $toGregorianDate
    );

/* =========================================================
   BUILD ALL DATES
========================================================= */

$historyDates = [];

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

/* =========================================================
   FIND ACTUAL RECORDED DATES
========================================================= */

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

if (!$stmt) {
    exit(
        'Could not prepare recorded dates query.'
    );
}

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

while (
    $row =
    $result->fetch_assoc()
) {

    $recordedDate =
        (string) $row['attendance_date'];

    $recordedDates[
        $recordedDate
    ] = true;
}

$stmt->close();

/*
 * IMPORTANT:
 * Only dates that actually contain attendance
 * records are included as Excel columns.
 */
$historyDates =
    array_values(
        array_filter(
            $historyDates,
            static fn(array $date): bool =>
                isset(
                    $recordedDates[
                        $date['gregorian']
                    ]
                )
        )
    );

/* =========================================================
   RANGE ATTENDANCE
========================================================= */

$attendanceMap = [];

$registrationIds =
    array_map(
        static fn(array $row): int =>
            (int) $row['registration_id'],
        $students
    );

$placeholders = implode(
    ',',
    array_fill(
        0,
        count($registrationIds),
        '?'
    )
);

if (!empty($historyDates)) {

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

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {
        exit(
            'Could not prepare range attendance query.'
        );
    }

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
        $bindParams[] =
            &$params[$key];
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

        $attendanceMap[
            $registrationId
        ][$date] =
            normalizeStatus(
                $row['status'] ?? ''
            );
    }

    $stmt->close();
}

/* =========================================================
   RANGE EXCEL
========================================================= */

$totalColumns =
    2 + count($historyDates);

$lastColumn =
    Coordinate::stringFromColumnIndex(
        $totalColumns
    );

$filename =
    'Attendance_Grade_' .
    $gradeNumber .
    '_Section_' .
    $sectionCode .
    '_' .
    sprintf(
        '%04d%02d%02d',
        $actualFromEth['year'],
        $actualFromEth['month'],
        $actualFromEth['day']
    ) .
    '_to_' .
    sprintf(
        '%04d%02d%02d',
        $actualToEth['year'],
        $actualToEth['month'],
        $actualToEth['day']
    ) .
    '.xlsx';

/* =========================================================
   CREATE SPREADSHEET
========================================================= */

$spreadsheet =
    new Spreadsheet();

$sheet =
    $spreadsheet->getActiveSheet();

$sheet->setTitle('Attendance');

/*
 * Hide gridlines so rows 1–2 remain a clean
 * merged information area.
 */
$sheet->setShowGridlines(false);

/* =========================================================
   TITLE
========================================================= */

$sheet->mergeCells(
    'A1:' . $lastColumn . '1'
);

$sheet->setCellValue(
    'A1',
    'Bole Kale Hiwot School'
);

$sheet
    ->getStyle(
        'A1:' . $lastColumn . '1'
    )
    ->applyFromArray(
        $titleStyle
    );

/* =========================================================
   INFORMATION
========================================================= */

$sheet->mergeCells(
    'A2:' . $lastColumn . '2'
);

$sheet->setCellValue(
    'A2',
    'Grade: ' .
    $gradeName .
    '    Section: ' .
    $sectionCode .
    '    Ethiopian Date: ' .
    ($ethiopianMonths[
        $actualFromEth['month']
    ] ?? '') .
    ' ' .
    $actualFromEth['day'] .
    ', ' .
    $actualFromEth['year'] .
    ' to ' .
    ($ethiopianMonths[
        $actualToEth['month']
    ] ?? '') .
    ' ' .
    $actualToEth['day'] .
    ', ' .
    $actualToEth['year'] .
    '    Academic Year: ' .
    ($academicYear['name'] ?? '')
);

$sheet
    ->getStyle(
        'A2:' . $lastColumn . '2'
    )
    ->applyFromArray(
        $infoStyle
    );

/* =========================================================
   HEADER
========================================================= */

$sheet->setCellValue(
    'A4',
    'No.'
);

$sheet->setCellValue(
    'B4',
    'Student'
);

$columnIndex = 3;

foreach (
    $historyDates as $date
) {

    $column =
        Coordinate::stringFromColumnIndex(
            $columnIndex
        );

    $sheet->setCellValue(
        $column . '4',
        $date['month_name'] .
        ' ' .
        $date['day']
    );

    $columnIndex++;
}

$sheet
    ->getStyle(
        'A4:' . $lastColumn . '4'
    )
    ->applyFromArray(
        $headerStyle
    );

/* =========================================================
   DATA ROWS
========================================================= */

$rowNumber = 5;

foreach (
    $students as $index => $student
) {

    $registrationId =
        (int) $student[
            'registration_id'
        ];

    /* No. */
    $sheet->setCellValue(
        'A' . $rowNumber,
        $index + 1
    );

    /* Student */
    $sheet->setCellValue(
        'B' . $rowNumber,
        $student['full_name']
    );

    /* Attendance dates */
    $columnIndex = 3;

    foreach (
        $historyDates as $date
    ) {

        $column =
            Coordinate::stringFromColumnIndex(
                $columnIndex
            );

        $status =
            $attendanceMap[
                $registrationId
            ][$date['gregorian']]
            ?? '';

        $letter =
            statusLetter($status);

        $sheet->setCellValue(
            $column . $rowNumber,
            $letter
        );

        $statusClass =
            statusClass($status);

        $colors =
            $statusColors[
                $statusClass
            ] ?? $statusColors['none'];

        $cellStyle =
            $sheet->getStyle(
                $column . $rowNumber
            );

        $cellStyle
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setRGB(
                $colors['fill']
            );

        $cellStyle
            ->getFont()
            ->setBold(true)
            ->getColor()
            ->setRGB(
                $colors['font']
            );

        $cellStyle
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_CENTER
            )
            ->setVertical(
                Alignment::VERTICAL_CENTER
            );

        $columnIndex++;
    }

    /*
     * Table borders only start at the data row.
     */
    $sheet
        ->getStyle(
            'A' .
            $rowNumber .
            ':' .
            $lastColumn .
            $rowNumber
        )
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(
            Border::BORDER_THIN
        );

    /*
     * No. alignment
     */
    $sheet
        ->getStyle(
            'A' . $rowNumber
        )
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        )
        ->setVertical(
            Alignment::VERTICAL_CENTER
        );

    /*
     * Student alignment
     */
    $sheet
        ->getStyle(
            'B' . $rowNumber
        )
        ->getAlignment()
        ->setVertical(
            Alignment::VERTICAL_CENTER
        );

    $rowNumber++;
}

/* =========================================================
   COLUMN WIDTHS
========================================================= */

$sheet
    ->getColumnDimension('A')
    ->setWidth(10);

$sheet
    ->getColumnDimension('B')
    ->setWidth(35);

for (
    $i = 3;
    $i <= $totalColumns;
    $i++
) {

    $column =
        Coordinate::stringFromColumnIndex(
            $i
        );

    $sheet
        ->getColumnDimension($column)
        ->setWidth(14);
}

/* =========================================================
   ROW HEIGHTS
========================================================= */

$sheet
    ->getRowDimension(1)
    ->setRowHeight(30);

$sheet
    ->getRowDimension(2)
    ->setRowHeight(25);

$sheet
    ->getRowDimension(4)
    ->setRowHeight(24);

/* =========================================================
   FREEZE
========================================================= */

$sheet->freezePane('A5');

/* =========================================================
   PAGE SETUP
========================================================= */

$sheet
    ->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

$sheet
    ->getPageSetup()
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

$sheet
    ->getPageSetup()
    ->setFitToWidth(1);

$sheet
    ->getPageSetup()
    ->setFitToHeight(0);

$sheet
    ->getPageMargins()
    ->setTop(0.4);

$sheet
    ->getPageMargins()
    ->setBottom(0.4);

$sheet
    ->getPageMargins()
    ->setLeft(0.3);

$sheet
    ->getPageMargins()
    ->setRight(0.3);

/* =========================================================
   PRINT AREA
========================================================= */

$sheet
    ->getPageSetup()
    ->setPrintArea(
        'A1:' .
        $lastColumn .
        ($rowNumber - 1)
    );

/* =========================================================
   DOWNLOAD XLSX
========================================================= */

while (ob_get_level() > 0) {
    ob_end_clean();
}

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

header(
    'Cache-Control: max-age=0'
);

header(
    'Pragma: public'
);

$writer =
    new Xlsx($spreadsheet);

$writer->save(
    'php://output'
);

$spreadsheet
    ->disconnectWorksheets();

exit;

