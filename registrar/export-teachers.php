<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet
|--------------------------------------------------------------------------
*/

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    http_response_code(500);
    exit('PhpSpreadsheet is not installed.');
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function cleanExcelFilename(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '/[\\\\\/:\*?"<>|]+/',
        '',
        $value
    ) ?? '';

    $value = preg_replace(
        '/\s+/',
        '_',
        $value
    ) ?? '';

    return trim($value, '._ ');
}

function excelValue(?string $value): string
{
    return trim((string) $value);
}

function formatBirthDate(
    ?int $year,
    ?int $month,
    ?int $day
): string {
    if (
        !$year ||
        !$month ||
        !$day ||
        $month < 1 ||
        $month > 13 ||
        $day < 1
    ) {
        return '';
    }

    $months = EthiopianCalendar::months('en');

    $monthName = $months[$month] ?? '';

    if ($monthName === '') {
        return '';
    }

    return $monthName . ' ' . $day . ', ' . $year;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
|
| Search is optional.
| If teachers.php sends search, the same filtered active teachers
| will be exported.
|
*/

$search = trim(
    (string) ($_GET['search'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Query Conditions
|--------------------------------------------------------------------------
*/

$where = "
    t.employment_status = 'Active'
    AND u.is_deleted = 0
";

$params = [];
$types = '';

if ($search !== '') {

    $where .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR t.fayda_number LIKE ?
            OR t.gender LIKE ?
            OR t.region LIKE ?
            OR t.zone LIKE ?
            OR t.woreda LIKE ?
            OR t.education_level LIKE ?
            OR t.department LIKE ?
            OR t.college_university_institution LIKE ?
            OR t.has_experience LIKE ?
            OR t.has_pgdt LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    for ($i = 0; $i < 13; $i++) {
        $params[] = $searchValue;
        $types .= 's';
    }
}

/*
|--------------------------------------------------------------------------
| Query
|--------------------------------------------------------------------------
|
| Teacher relationship:
|
| teachers.user_id
|       ↓
| subject_teacher_assignments.teacher_user_id
|
| Subject relationship:
|
| subject_teacher_assignments.grade_subject_id
|       ↓
| grade_subjects.id
|
| We use correlated subqueries so every teacher remains one row.
|
*/

$sql = "
    SELECT
        t.id AS teacher_id,
        u.full_name,
        u.email,
        u.phone,
        t.fayda_number,
        t.gender,
        t.birth_eth_year,
        t.birth_eth_month,
        t.birth_eth_day,
        t.region,
        t.zone,
        t.woreda,
        t.marital_status,
        t.education_level,
        t.college_university_institution,
        t.department,
        t.has_experience,
        t.has_pgdt,

        (
            SELECT GROUP_CONCAT(
                DISTINCT CONCAT('Grade ', sta.grade)
                ORDER BY sta.grade ASC
                SEPARATOR '\n'
            )
            FROM subject_teacher_assignments sta
            INNER JOIN grade_subjects gs
                ON gs.id = sta.grade_subject_id
            WHERE
                sta.teacher_user_id = t.user_id
                AND sta.is_active = 1
                AND gs.is_active = 1
        ) AS teaching_grades,

        (
            SELECT GROUP_CONCAT(
                DISTINCT gs.subject_name
                ORDER BY
                    sta.grade ASC,
                    gs.subject_name ASC
                SEPARATOR '\n'
            )
            FROM subject_teacher_assignments sta
            INNER JOIN grade_subjects gs
                ON gs.id = sta.grade_subject_id
            WHERE
                sta.teacher_user_id = t.user_id
                AND sta.is_active = 1
                AND gs.is_active = 1
        ) AS teaching_subjects

    FROM teachers t

    INNER JOIN users u
        ON u.id = t.user_id

    WHERE {$where}

    ORDER BY
        u.full_name ASC
";

$stmt = $conn->prepare($sql);

if ($types !== '') {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$result = $stmt->get_result();

$teachers = [];

while ($row = $result->fetch_assoc()) {
    $teachers[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Ethiopian Current Date
|--------------------------------------------------------------------------
*/

$today = EthiopianCalendar::todayFormatted('en');

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Teachers');

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:R1');

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 18,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(30);

/*
|--------------------------------------------------------------------------
| Report Subtitle
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A2:R2');

$sheet->setCellValue(
    'A2',
    'Active Teachers Report'
);

$sheet->getStyle('A2')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 13,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(2)->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Report Information
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A3:I3');

$sheet->setCellValue(
    'A3',
    'Generated Date: ' . $today
);

$sheet->mergeCells('J3:R3');

$sheet->setCellValue(
    'J3',
    'Total Active Teachers: ' . count($teachers)
);

$sheet->getStyle('A3:R3')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 10,
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(3)->setRowHeight(22);

/*
|--------------------------------------------------------------------------
| Search Information
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sheet->mergeCells('A4:R4');

    $sheet->setCellValue(
        'A4',
        'Search: ' . $search
    );

    $sheet->getStyle('A4:R4')->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 10,
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $headerRow = 6;

} else {

    $headerRow = 5;
}

/*
|--------------------------------------------------------------------------
| Headers
|--------------------------------------------------------------------------
*/

$headers = [
    'No.',
    'Teacher Name',
    'Email',
    'Phone',
    'Fayda Number',
    'Gender',
    'Birth Date',
    'Region',
    'Zone',
    'Woreda',
    'Marital Status',
    'Education Level',
    'Institution',
    'Teaching Grade',
    'Subject',
    'Department',
    'Experience',
    'PGDT',
];

foreach ($headers as $columnIndex => $header) {

    $column =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex + 1
        );

    $sheet->setCellValue(
        $column . $headerRow,
        $header
    );
}

/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$lastColumn = 'R';

$sheet->getStyle(
    "A{$headerRow}:{$lastColumn}{$headerRow}"
)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
        'size' => 10,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '2563EB',
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => [
                'rgb' => 'D1D5DB',
            ],
        ],
    ],
]);

$sheet->getRowDimension($headerRow)->setRowHeight(30);

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

$dataStartRow = $headerRow + 1;

foreach ($teachers as $index => $teacher) {

    $rowNumber = $dataStartRow + $index;

    $birthDate = formatBirthDate(
        $teacher['birth_eth_year'] !== null
            ? (int) $teacher['birth_eth_year']
            : null,

        $teacher['birth_eth_month'] !== null
            ? (int) $teacher['birth_eth_month']
            : null,

        $teacher['birth_eth_day'] !== null
            ? (int) $teacher['birth_eth_day']
            : null
    );

    /*
    |--------------------------------------------------------------------------
    | Teaching Grade
    |--------------------------------------------------------------------------
    */

    $teachingGradeValue = excelValue(
        $teacher['teaching_grades'] ?? ''
    );

    /*
    |--------------------------------------------------------------------------
    | Teaching Subject
    |--------------------------------------------------------------------------
    */

    $teachingSubjectValue = excelValue(
        $teacher['teaching_subjects'] ?? ''
    );

    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    $data = [

        $index + 1,

        excelValue(
            $teacher['full_name'] ?? ''
        ),

        excelValue(
            $teacher['email'] ?? ''
        ),

        excelValue(
            $teacher['phone'] ?? ''
        ),

        excelValue(
            $teacher['fayda_number'] ?? ''
        ),

        excelValue(
            $teacher['gender'] ?? ''
        ),

        $birthDate,

        excelValue(
            $teacher['region'] ?? ''
        ),

        excelValue(
            $teacher['zone'] ?? ''
        ),

        excelValue(
            $teacher['woreda'] ?? ''
        ),

        excelValue(
            $teacher['marital_status'] ?? ''
        ),

        excelValue(
            $teacher['education_level'] ?? ''
        ),

        excelValue(
            $teacher['college_university_institution'] ?? ''
        ),

        $teachingGradeValue,

        $teachingSubjectValue,

        excelValue(
            $teacher['department'] ?? ''
        ),

        excelValue(
            $teacher['has_experience'] ?? ''
        ),

        excelValue(
            $teacher['has_pgdt'] ?? ''
        ),
    ];

    foreach ($data as $columnIndex => $value) {

        $column =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $columnIndex + 1
            );

        $sheet->setCellValue(
            $column . $rowNumber,
            $value
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Row Height
    |--------------------------------------------------------------------------
    */

    $gradeLines = $teachingGradeValue !== ''
        ? substr_count($teachingGradeValue, "\n") + 1
        : 0;

    $subjectLines = $teachingSubjectValue !== ''
        ? substr_count($teachingSubjectValue, "\n") + 1
        : 0;

    $assignmentLines = max(
        $gradeLines,
        $subjectLines
    );

    if ($assignmentLines > 1) {

        $sheet->getRowDimension($rowNumber)
            ->setRowHeight(
                max(30, $assignmentLines * 18)
            );
    }
}

/*
|--------------------------------------------------------------------------
| Data Styling
|--------------------------------------------------------------------------
*/

if (count($teachers) > 0) {

    $lastDataRow =
        $dataStartRow + count($teachers) - 1;

    $sheet->getStyle(
        "A{$dataStartRow}:{$lastColumn}{$lastDataRow}"
    )->applyFromArray([
        'font' => [
            'size' => 9,
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => [
                    'rgb' => 'E5E7EB',
                ],
            ],
        ],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Center No. and Gender
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        "A{$dataStartRow}:A{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

    $sheet->getStyle(
        "F{$dataStartRow}:F{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

    /*
    |--------------------------------------------------------------------------
    | Teaching Grade and Subject
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        "N{$dataStartRow}:O{$lastDataRow}"
    )->getAlignment()->setVertical(
        Alignment::VERTICAL_CENTER
    );

    /*
    |--------------------------------------------------------------------------
    | Experience and PGDT
    |--------------------------------------------------------------------------
    */

    $sheet->getStyle(
        "Q{$dataStartRow}:R{$lastDataRow}"
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

    /*
    |--------------------------------------------------------------------------
    | Auto Filter
    |--------------------------------------------------------------------------
    */

    $sheet->setAutoFilter(
        "A{$headerRow}:{$lastColumn}{$lastDataRow}"
    );

    /*
    |--------------------------------------------------------------------------
    | Freeze Header
    |--------------------------------------------------------------------------
    */

    $sheet->freezePane(
        "A" . $dataStartRow
    );
}

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$widths = [

    'A' => 8,

    'B' => 28,

    'C' => 30,

    'D' => 17,

    'E' => 18,

    'F' => 12,

    'G' => 20,

    'H' => 20,

    'I' => 20,

    'J' => 20,

    'K' => 17,

    'L' => 20,

    'M' => 30,

    'N' => 20,

    'O' => 28,

    'P' => 24,

    'Q' => 14,

    'R' => 12,
];

foreach ($widths as $column => $width) {

    $sheet->getColumnDimension($column)
        ->setWidth($width);
}

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

$sheet->getPageSetup()
    ->setPaperSize(PageSetup::PAPERSIZE_A4);

$sheet->getPageSetup()
    ->setFitToWidth(1);

$sheet->getPageSetup()
    ->setFitToHeight(0);

$sheet->getPageMargins()->setTop(0.35);

$sheet->getPageMargins()->setRight(0.25);

$sheet->getPageMargins()->setBottom(0.35);

$sheet->getPageMargins()->setLeft(0.25);

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$sheet->getHeaderFooter()
    ->setOddFooter(
        '&LGenerated: ' . $today .
        '&CActive Teachers Report' .
        '&RPage &P of &N'
    );

/*
|--------------------------------------------------------------------------
| Print Area
|--------------------------------------------------------------------------
*/

$lastReportRow = count($teachers) > 0
    ? $dataStartRow + count($teachers) - 1
    : $headerRow;

$sheet->getPageSetup()->setPrintArea(
    "A1:{$lastColumn}{$lastReportRow}"
);

/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$filename = 'BKHS_Active_Teachers';

if ($search !== '') {

    $filename .= '_' .
        cleanExcelFilename($search);
}

$filename .= '.xlsx';

/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

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

header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

exit;
