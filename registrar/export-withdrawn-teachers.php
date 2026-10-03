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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet
|--------------------------------------------------------------------------
*/

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadLoaded = false;

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    die('PhpSpreadsheet is not installed. Please run: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute();

$result = $stmt->get_result();

$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    die('There is no active academic year.');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Get Withdrawn Teachers
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
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
        t.department,
        t.college_university_institution,
        t.has_experience,
        t.has_pgdt,
        tw.withdrawal_date,
        tw.reason,
        ru.full_name AS withdrawn_by
    FROM teacher_withdrawals tw
    INNER JOIN teachers t
        ON t.id = tw.teacher_id
    INNER JOIN users u
        ON u.id = t.user_id
    LEFT JOIN users ru
        ON ru.id = tw.withdrawn_by
    WHERE tw.academic_year_id = ?
    ORDER BY tw.withdrawal_date DESC, u.full_name ASC
");

$stmt->bind_param(
    'i',
    $academicYearId
);

$stmt->execute();

$result = $stmt->get_result();

$teachers = [];

while ($row = $result->fetch_assoc()) {
    $teachers[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Withdrawn Teachers');

/*
|--------------------------------------------------------------------------
| Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:Q1');

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);

$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(28);

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A2:Q2');

$sheet->setCellValue(
    'A2',
    'WITHDRAWN TEACHERS'
);

$sheet->getStyle('A2')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 14,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(2)->setRowHeight(24);

/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A3:Q3');

$sheet->setCellValue(
    'A3',
    'Academic Year: ' . $academicYearName
);

$sheet->getStyle('A3')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 11,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(3)->setRowHeight(22);

/*
|--------------------------------------------------------------------------
| Empty Row
|--------------------------------------------------------------------------
*/

$sheet->getRowDimension(4)->setRowHeight(8);

/*
|--------------------------------------------------------------------------
| Column Headers
|--------------------------------------------------------------------------
*/

$headers = [
    'No.',
    'Teacher Name',
    'Email',
    'Phone',
    'Fayda Number',
    'Gender',
    'Birth Year',
    'Birth Month',
    'Birth Day',
    'Region',
    'Zone',
    'Woreda',
    'Marital Status',
    'Education Level',
    'Department',
    'Institution',
    'Experience',
    'PGDT',
    'Withdrawal Date',
    'Reason',
    'Withdrawn By',
];

$headerRow = 5;

$columnCount = count($headers);

for ($i = 0; $i < $columnCount; $i++) {

    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
        $i + 1
    );

    $sheet->setCellValue(
        $column . $headerRow,
        $headers[$i]
    );
}

/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
    $columnCount
);

$sheet->getStyle(
    "A{$headerRow}:{$lastColumn}{$headerRow}"
)->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 10,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'E5E7EB',
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
                'rgb' => '9CA3AF',
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

$currentRow = 6;
$number = 1;

foreach ($teachers as $teacher) {

    $sheet->setCellValue(
        "A{$currentRow}",
        $number
    );

    $sheet->setCellValue(
        "B{$currentRow}",
        (string) $teacher['full_name']
    );

    $sheet->setCellValue(
        "C{$currentRow}",
        (string) $teacher['email']
    );

    $sheet->setCellValue(
        "D{$currentRow}",
        (string) $teacher['phone']
    );

    /*
    |--------------------------------------------------------------------------
    | Fayda as Text
    |--------------------------------------------------------------------------
    */

    $sheet->setCellValueExplicit(
        "E{$currentRow}",
        (string) $teacher['fayda_number'],
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    $sheet->setCellValue(
        "F{$currentRow}",
        (string) ($teacher['gender'] ?? '')
    );

    $sheet->setCellValue(
        "G{$currentRow}",
        (string) ($teacher['birth_eth_year'] ?? '')
    );

    $sheet->setCellValue(
        "H{$currentRow}",
        (string) ($teacher['birth_eth_month'] ?? '')
    );

    $sheet->setCellValue(
        "I{$currentRow}",
        (string) ($teacher['birth_eth_day'] ?? '')
    );

    $sheet->setCellValue(
        "J{$currentRow}",
        (string) ($teacher['region'] ?? '')
    );

    $sheet->setCellValue(
        "K{$currentRow}",
        (string) ($teacher['zone'] ?? '')
    );

    $sheet->setCellValue(
        "L{$currentRow}",
        (string) ($teacher['woreda'] ?? '')
    );

    $sheet->setCellValue(
        "M{$currentRow}",
        (string) ($teacher['marital_status'] ?? '')
    );

    $sheet->setCellValue(
        "N{$currentRow}",
        (string) ($teacher['education_level'] ?? '')
    );

    $sheet->setCellValue(
        "O{$currentRow}",
        (string) ($teacher['department'] ?? '')
    );

    $sheet->setCellValue(
        "P{$currentRow}",
        (string) ($teacher['college_university_institution'] ?? '')
    );

    $sheet->setCellValue(
        "Q{$currentRow}",
        (string) ($teacher['has_experience'] ?? '')
    );

    $sheet->setCellValue(
        "R{$currentRow}",
        (string) ($teacher['has_pgdt'] ?? '')
    );

    $sheet->setCellValue(
        "S{$currentRow}",
        (string) $teacher['withdrawal_date']
    );

    $sheet->setCellValue(
        "T{$currentRow}",
        (string) $teacher['reason']
    );

    $sheet->setCellValue(
        "U{$currentRow}",
        (string) ($teacher['withdrawn_by'] ?? '')
    );

    $currentRow++;
    $number++;
}

/*
|--------------------------------------------------------------------------
| Data Styling
|--------------------------------------------------------------------------
*/

if ($currentRow > 6) {

    $sheet->getStyle(
        "A6:U" . ($currentRow - 1)
    )->applyFromArray([
        'alignment' => [
            'vertical' => Alignment::VERTICAL_TOP,
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

    $sheet->getStyle(
        "A6:A" . ($currentRow - 1)
    )->getAlignment()->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );

} else {

    $sheet->mergeCells('A6:U6');

    $sheet->setCellValue(
        'A6',
        'No withdrawn teachers found for this academic year.'
    );

    $sheet->getStyle('A6')->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'font' => [
            'italic' => true,
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
}

/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

$sheet->setAutoFilter(
    "A{$headerRow}:{$lastColumn}" .
    max($headerRow, $currentRow - 1)
);

/*
|--------------------------------------------------------------------------
| Freeze Header
|--------------------------------------------------------------------------
*/

$sheet->freezePane('A6');

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$widths = [
    'A' => 7,
    'B' => 25,
    'C' => 28,
    'D' => 17,
    'E' => 20,
    'F' => 12,
    'G' => 12,
    'H' => 12,
    'I' => 12,
    'J' => 18,
    'K' => 18,
    'L' => 18,
    'M' => 18,
    'N' => 20,
    'O' => 22,
    'P' => 30,
    'Q' => 13,
    'R' => 13,
    'S' => 18,
    'T' => 40,
    'U' => 22,
];

foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()->setOrientation(
    \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
);

$sheet->getPageSetup()->setPaperSize(
    \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
);

$sheet->getPageSetup()->setFitToWidth(1);

$sheet->getPageSetup()->setFitToHeight(0);

$sheet->getPageMargins()->setTop(0.5);
$sheet->getPageMargins()->setRight(0.25);
$sheet->getPageMargins()->setLeft(0.25);
$sheet->getPageMargins()->setBottom(0.5);

/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$safeAcademicYear = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    $academicYearName
);

$filename =
    'BKHS_Withdrawn_Teachers_' .
    $safeAcademicYear .
    '.xlsx';

/*
|--------------------------------------------------------------------------
| Download
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

$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;