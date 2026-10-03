<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Get request parameters
|--------------------------------------------------------------------------
*/

$selectedClass = trim((string) ($_GET['class'] ?? ''));

$latest = (int) ($_GET['latest'] ?? 0);

if ($latest < 1 || $latest > 30) {
    $latest = 1;
}

/*
|--------------------------------------------------------------------------
| Validate class
|--------------------------------------------------------------------------
*/

if (
    $selectedClass === '' ||
    !preg_match('/^(\d{1,2})-([A-Z])$/', $selectedClass, $matches)
) {
    exit('Missing or invalid class.');
}

$gradeNumber = (int) $matches[1];
$sectionCode = strtoupper($matches[2]);

if ($gradeNumber < 1 || $gradeNumber > 12) {
    exit('Invalid grade.');
}

/*
|--------------------------------------------------------------------------
| Get active academic year
|--------------------------------------------------------------------------
*/

$academicYear = null;

$stmt = $conn->prepare("
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

if (!$stmt) {
    exit('Unable to load academic year.');
}

$stmt->execute();

$result = $stmt->get_result();

$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    exit('No active academic year found.');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify teacher owns selected homeroom class
|--------------------------------------------------------------------------
*/

$homeroom = null;

$stmt = $conn->prepare("
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
      AND hta.grade = ?
      AND hta.section = ?
      AND hta.is_active = 1
    LIMIT 1
");

if (!$stmt) {
    exit('Unable to verify homeroom assignment.');
}

$stmt->bind_param(
    'isis',
    $teacherUserId,
    $academicYearName,
    $gradeNumber,
    $sectionCode
);

$stmt->execute();

$result = $stmt->get_result();

$homeroom = $result->fetch_assoc();

$stmt->close();

if (!$homeroom) {
    exit('You are not assigned to this class.');
}

$gradeName = (string) (
    $homeroom['grade_name']
    ?: 'Grade ' . $gradeNumber
);

$sectionName = (string) (
    $homeroom['section_name']
    ?: 'Section ' . $sectionCode
);

/*
|--------------------------------------------------------------------------
| Resolve Grade and Section IDs
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

if (!$stmt) {
    exit('Unable to resolve class.');
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

if (!$classIds) {
    exit('Grade or section not found.');
}

$gradeId = (int) $classIds['grade_id'];
$sectionId = (int) $classIds['section_id'];

/*
|--------------------------------------------------------------------------
| Get latest attendance dates
|--------------------------------------------------------------------------
*/

$todayGregorian = date('Y-m-d');

$attendanceDates = [];

$stmt = $conn->prepare("
    SELECT DISTINCT attendance_date
    FROM student_attendance
    WHERE academic_year_id = ?
      AND grade_id = ?
      AND section_id = ?
      AND attendance_date <= ?
    ORDER BY attendance_date DESC
    LIMIT ?
");

if (!$stmt) {
    exit('Unable to load attendance dates.');
}

$stmt->bind_param(
    'iiisi',
    $academicYearId,
    $gradeId,
    $sectionId,
    $todayGregorian,
    $latest
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $attendanceDates[] = (string) $row['attendance_date'];
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| No attendance found
|--------------------------------------------------------------------------
*/

if (!$attendanceDates) {
    exit('No attendance records found for the selected class.');
}

/*
|--------------------------------------------------------------------------
| Sort oldest → newest
|--------------------------------------------------------------------------
*/

sort($attendanceDates);

/*
|--------------------------------------------------------------------------
| Ethiopian date information
|--------------------------------------------------------------------------
*/

$ethiopianDates = [];

foreach ($attendanceDates as $date) {
    $ethiopianDates[$date] =
        EthiopianCalendar::fromGregorian($date);
}

/*
|--------------------------------------------------------------------------
| Build readable Ethiopian date range
|--------------------------------------------------------------------------
*/

$firstDate =
    $ethiopianDates[$attendanceDates[0]];

$lastDate =
    $ethiopianDates[
        $attendanceDates[count($attendanceDates) - 1]
    ];

$firstFormatted = EthiopianCalendar::format(
    $firstDate['year'],
    $firstDate['month'],
    $firstDate['day'],
    'en'
);

$lastFormatted = EthiopianCalendar::format(
    $lastDate['year'],
    $lastDate['month'],
    $lastDate['day'],
    'en'
);

$dateRange =
    $firstFormatted .
    ' - ' .
    $lastFormatted;

/*
|--------------------------------------------------------------------------
| Get students
|--------------------------------------------------------------------------
*/

$students = [];

$stmt = $conn->prepare("
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
");

if (!$stmt) {
    exit('Unable to load students.');
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
    $students[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Attendance map
|--------------------------------------------------------------------------
|
| attendanceMap[student_id][attendance_date] = status
|
*/

$attendanceMap = [];

if ($students && $attendanceDates) {

    $studentIds = array_map(
        static fn(array $student): int =>
            (int) $student['student_id'],
        $students
    );

    $studentPlaceholders = implode(
        ',',
        array_fill(
            0,
            count($studentIds),
            '?'
        )
    );

    $datePlaceholders = implode(
        ',',
        array_fill(
            0,
            count($attendanceDates),
            '?'
        )
    );

    $sql = "
        SELECT
            student_id,
            attendance_date,
            status
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND student_id IN ($studentPlaceholders)
          AND attendance_date IN ($datePlaceholders)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        exit('Unable to load attendance records.');
    }

    $types = 'iii';

    $params = [
        $academicYearId,
        $gradeId,
        $sectionId
    ];

    foreach ($studentIds as $studentId) {
        $types .= 'i';
        $params[] = $studentId;
    }

    foreach ($attendanceDates as $date) {
        $types .= 's';
        $params[] = $date;
    }

    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindParams
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $studentId =
            (int) $row['student_id'];

        $attendanceDate =
            (string) $row['attendance_date'];

        $attendanceMap[$studentId][$attendanceDate] =
            (string) $row['status'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Attendance');

$sheet->setShowGridlines(false);

/*
|--------------------------------------------------------------------------
| Calculate final column
|--------------------------------------------------------------------------
*/

$lastColumnNumber = 2 + count($attendanceDates);

$lastColumn =
    Coordinate::stringFromColumnIndex(
        $lastColumnNumber
    );

/*
|--------------------------------------------------------------------------
| Report title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    "A1:{$lastColumn}1"
);

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->mergeCells(
    "A2:{$lastColumn}2"
);

$sheet->setCellValue(
    'A2',
    'Daily Attendance Report'
);

/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$sheet->setCellValue(
    'A4',
    'Academic Year'
);

$sheet->setCellValue(
    'B4',
    $academicYearName
);

$sheet->setCellValue(
    'A5',
    'Grade'
);

$sheet->setCellValue(
    'B5',
    $gradeName
);

$sheet->setCellValue(
    'A6',
    'Section'
);

$sheet->setCellValue(
    'B6',
    $sectionName
);

$sheet->setCellValue(
    'A7',
    'Attendance Date'
);

$sheet->setCellValue(
    'B7',
    $dateRange
);

$sheet->setCellValue(
    'A8',
    'Attendance Days'
);

$sheet->setCellValue(
    'B8',
    count($attendanceDates)
);

/*
|--------------------------------------------------------------------------
| Title styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A1:{$lastColumn}1"
)->applyFromArray([
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
]);

$sheet->getRowDimension(1)
    ->setRowHeight(32);

$sheet->getStyle(
    "A2:{$lastColumn}2"
)->applyFromArray([
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
]);

$sheet->getRowDimension(2)
    ->setRowHeight(26);

/*
|--------------------------------------------------------------------------
| Metadata styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle('A4:A8')
    ->getFont()
    ->setBold(true);

$sheet->getStyle('A4:B8')
    ->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' =>
                    Border::BORDER_THIN,
            ],
        ],
        'alignment' => [
            'vertical' =>
                Alignment::VERTICAL_CENTER,
        ],
    ]);

$sheet->getStyle('B7')
    ->getAlignment()
    ->setWrapText(true);

/*
|--------------------------------------------------------------------------
| Spacer row
|--------------------------------------------------------------------------
*/

$sheet->getRowDimension(9)
    ->setRowHeight(8);

/*
|--------------------------------------------------------------------------
| Table header
|--------------------------------------------------------------------------
*/

$headerRow = 10;

$sheet->setCellValue(
    "A{$headerRow}",
    'No.'
);

$sheet->setCellValue(
    "B{$headerRow}",
    'Student Name'
);

$columnNumber = 3;

foreach ($attendanceDates as $date) {

    $eth =
        $ethiopianDates[$date];

    $formattedDate =
        EthiopianCalendar::format(
            $eth['year'],
            $eth['month'],
            $eth['day'],
            'en'
        );

    $column =
        Coordinate::stringFromColumnIndex(
            $columnNumber
        );

    $sheet->setCellValue(
        "{$column}{$headerRow}",
        $formattedDate
    );

    $columnNumber++;
}

/*
|--------------------------------------------------------------------------
| Table header styling
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    "A{$headerRow}:{$lastColumn}{$headerRow}"
)->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 10,
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
    'fill' => [
        'fillType' =>
            Fill::FILL_SOLID,
        'color' => [
            'rgb' => 'E2E8F0',
        ],
    ],
]);

$sheet->getRowDimension($headerRow)
    ->setRowHeight(48);

/*
|--------------------------------------------------------------------------
| Student rows
|--------------------------------------------------------------------------
*/

$currentRow = 11;

$studentNumber = 1;

foreach ($students as $student) {

    $studentId =
        (int) $student['student_id'];

    $sheet->setCellValue(
        "A{$currentRow}",
        $studentNumber
    );

    $sheet->setCellValue(
        "B{$currentRow}",
        (string) $student['full_name']
    );

    $columnNumber = 3;

    foreach ($attendanceDates as $date) {

        $status =
            $attendanceMap[$studentId][$date]
            ?? '-';

        $column =
            Coordinate::stringFromColumnIndex(
                $columnNumber
            );

        $sheet->setCellValue(
            "{$column}{$currentRow}",
            $status
        );

        $columnNumber++;
    }

    $currentRow++;
    $studentNumber++;
}

/*
|--------------------------------------------------------------------------
| Student table styling
|--------------------------------------------------------------------------
*/

if ($currentRow > 11) {

    $lastStudentRow =
        $currentRow - 1;

    $sheet->getStyle(
        "A11:{$lastColumn}{$lastStudentRow}"
    )->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' =>
                    Border::BORDER_THIN,
            ],
        ],
        'alignment' => [
            'vertical' =>
                Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle(
        "A11:A{$lastStudentRow}"
    )->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

    $sheet->getStyle(
        "B11:B{$lastStudentRow}"
    )->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_LEFT
        );

    $sheet->getStyle(
        "C11:{$lastColumn}{$lastStudentRow}"
    )->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );
}

/*
|--------------------------------------------------------------------------
| Column widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')
    ->setWidth(7);

$sheet->getColumnDimension('B')
    ->setWidth(30);

for (
    $columnNumber = 3;
    $columnNumber <= $lastColumnNumber;
    $columnNumber++
) {

    $column =
        Coordinate::stringFromColumnIndex(
            $columnNumber
        );

    $sheet->getColumnDimension($column)
        ->setWidth(20);
}

/*
|--------------------------------------------------------------------------
| Row heights
|--------------------------------------------------------------------------
*/

if ($currentRow > 11) {

    $lastStudentRow =
        $currentRow - 1;

    for (
        $row = 11;
        $row <= $lastStudentRow;
        $row++
    ) {
        $sheet->getRowDimension($row)
            ->setRowHeight(24);
    }
}

/*
|--------------------------------------------------------------------------
| Freeze panes
|--------------------------------------------------------------------------
|
| Keeps No. and Student Name visible while scrolling.
|
*/

$sheet->freezePane('C11');

/*
|--------------------------------------------------------------------------
| Auto filter
|--------------------------------------------------------------------------
*/

if ($currentRow > 11) {

    $sheet->setAutoFilter(
        "A10:{$lastColumn}" .
        ($currentRow - 1)
    );
}

/*
|--------------------------------------------------------------------------
| Print setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    );

$sheet->getPageSetup()
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    );

$sheet->getPageSetup()
    ->setFitToWidth(1);

$sheet->getPageSetup()
    ->setFitToHeight(0);

$sheet->getPageSetup()
    ->setHorizontalCentered(true);

/*
|--------------------------------------------------------------------------
| Print margins
|--------------------------------------------------------------------------
*/

$sheet->getPageMargins()
    ->setTop(0.35)
    ->setRight(0.25)
    ->setLeft(0.25)
    ->setBottom(0.35);

/*
|--------------------------------------------------------------------------
| Repeat table header when printing
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setRowsToRepeatAtTopByStartAndEnd(
        10,
        10
    );

/*
|--------------------------------------------------------------------------
| Workbook properties
|--------------------------------------------------------------------------
*/

$spreadsheet->getProperties()
    ->setCreator(
        'Bole Kale Hiwot School'
    )
    ->setTitle(
        'Daily Attendance Report'
    )
    ->setSubject(
        'Student Daily Attendance'
    )
    ->setDescription(
        'Daily attendance report generated by the BKHS School Management System.'
    );

/*
|--------------------------------------------------------------------------
| Download XLSX
|--------------------------------------------------------------------------
*/

$filename =
    'BKHS_Daily_Attendance_' .
    'Grade_' . $gradeNumber .
    '_Section_' . $sectionCode .
    '_' . date('Ymd_His') .
    '.xlsx';

/*
|--------------------------------------------------------------------------
| Clean output buffer
|--------------------------------------------------------------------------
|
| Prevents accidental PHP/HTML output from corrupting
| the XLSX file.
|
*/

while (ob_get_level() > 0) {
    ob_end_clean();
}

/*
|--------------------------------------------------------------------------
| XLSX download headers
|--------------------------------------------------------------------------
*/

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
    'Cache-Control: max-age=1'
);

header(
    'Expires: Mon, 26 Jul 1997 05:00:00 GMT'
);

header(
    'Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'
);

header(
    'Pragma: public'
);

/*
|--------------------------------------------------------------------------
| Write XLSX
|--------------------------------------------------------------------------
*/

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

/*
|--------------------------------------------------------------------------
| Cleanup
|--------------------------------------------------------------------------
*/

$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;

