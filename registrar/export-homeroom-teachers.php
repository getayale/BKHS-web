<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Registrar Authentication
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoloadPath)) {
    exit(
        'PhpSpreadsheet is not installed. ' .
        'Please install it with Composer using: composer require phpoffice/phpspreadsheet'
    );
}

require_once $autoloadPath;

/*
|--------------------------------------------------------------------------
| PhpSpreadsheet Classes
|--------------------------------------------------------------------------
*/

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
|--------------------------------------------------------------------------
| Database Charset
|--------------------------------------------------------------------------
*/

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helper Functions
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

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearName = 'All';

$stmt = $conn->prepare("
    SELECT name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    exit('Failed to prepare academic year query.');
}

$stmt->execute();

$result = $stmt->get_result();

$academicYear = $result->fetch_assoc();

if ($academicYear) {
    $academicYearName = (string) $academicYear['name'];
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Get Filters
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));

$grade = trim((string) ($_GET['grade'] ?? ''));

$section = trim((string) ($_GET['section'] ?? ''));

/*
|--------------------------------------------------------------------------
| Build Homeroom Teacher Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        hta.id,
        u.full_name AS teacher_name,
        u.phone,
        hta.grade,
        hta.section
    FROM homeroom_teacher_assignments hta
    INNER JOIN users u
        ON u.id = hta.teacher_user_id
    WHERE hta.academic_year = ?
      AND hta.is_active = 1
      AND LOWER(u.role) = 'teacher'
      AND COALESCE(u.is_deleted, 0) = 0
";

$params = [$academicYearName];
$types = 's';

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.phone LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'ss';
}

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($grade !== '') {

    $sql .= "
        AND hta.grade = ?
    ";

    $params[] = (int) $grade;

    $types .= 'i';
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($section !== '') {

    $sql .= "
        AND hta.section = ?
    ";

    $params[] = $section;

    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        hta.grade ASC,
        hta.section ASC,
        u.full_name ASC
";

/*
|--------------------------------------------------------------------------
| Execute Query
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit('Failed to prepare homeroom teacher export query.');
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator('Bole Kale Hiwot School')
    ->setLastModifiedBy('Bole Kale Hiwot School')
    ->setTitle('Homeroom Teachers')
    ->setSubject('BKHS Homeroom Teachers Report')
    ->setDescription(
        'Homeroom teachers report generated by Bole Kale Hiwot School.'
    );

/*
|--------------------------------------------------------------------------
| Active Worksheet
|--------------------------------------------------------------------------
*/

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Homeroom Teachers');

/*
|--------------------------------------------------------------------------
| Report Header
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A1:E1');

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

/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A2:E2');

$sheet->setCellValue(
    'A2',
    'HOMEROOM TEACHERS REPORT'
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

/*
|--------------------------------------------------------------------------
| Report Filters
|--------------------------------------------------------------------------
*/

$gradeDisplay = $grade !== '' ? 'Grade ' . $grade : 'All';

$sectionDisplay = $section !== '' ? $section : 'All';

$sheet->mergeCells('A3:E3');

$sheet->setCellValue(
    'A3',
    'Academic Year: ' . $academicYearName .
    '   |   Grade: ' . $gradeDisplay .
    '   |   Section: ' . $sectionDisplay
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

/*
|--------------------------------------------------------------------------
| Export Information
|--------------------------------------------------------------------------
*/

$sheet->mergeCells('A4:E4');

$sheet->setCellValue(
    'A4',
    'Exported: ' . date('Y-m-d H:i:s')
);

$sheet->getStyle('A4')->applyFromArray([
    'font' => [
        'italic' => true,
        'size' => 10,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

/*
|--------------------------------------------------------------------------
| Table Header
|--------------------------------------------------------------------------
*/

$headerRow = 6;

$headers = [
    'No.',
    'Teacher',
    'Phone',
    'Grade',
    'Section',
];

foreach ($headers as $columnIndex => $header) {

    $column = Coordinate::stringFromColumnIndex(
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

$sheet->getStyle(
    "A{$headerRow}:E{$headerRow}"
)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => [
            'rgb' => 'FFFFFF',
        ],
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => '1E3A8A',
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

/*
|--------------------------------------------------------------------------
| Write Data
|--------------------------------------------------------------------------
*/

$rowNumber = $headerRow + 1;

$counter = 1;

while ($row = $result->fetch_assoc()) {

    /*
    | No.
    */

    $sheet->setCellValue(
        'A' . $rowNumber,
        $counter
    );

    /*
    | Teacher
    */

    $sheet->setCellValue(
        'B' . $rowNumber,
        $row['teacher_name']
    );

    /*
    | Phone
    |
    | Keep phone as text so leading zero is preserved.
    */

    $sheet->setCellValueExplicit(
        'C' . $rowNumber,
        (string) ($row['phone'] ?? ''),
        DataType::TYPE_STRING
    );

    /*
    | Grade
    */

    $sheet->setCellValue(
        'D' . $rowNumber,
        'Grade ' . $row['grade']
    );

    /*
    | Section
    */

    $sheet->setCellValue(
        'E' . $rowNumber,
        $row['section']
    );

    /*
    | Row Styling
    */

    $sheet->getStyle(
        "A{$rowNumber}:E{$rowNumber}"
    )->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => [
                    'rgb' => 'E5E7EB',
                ],
            ],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    /*
    | Center specific columns
    */

    $sheet->getStyle(
        "A{$rowNumber}:A{$rowNumber}"
    )
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

    $sheet->getStyle(
        "C{$rowNumber}:E{$rowNumber}"
    )
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );

    /*
    | Alternating Rows
    */

    if ($counter % 2 === 0) {

        $sheet->getStyle(
            "A{$rowNumber}:E{$rowNumber}"
        )
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            );

        $sheet->getStyle(
            "A{$rowNumber}:E{$rowNumber}"
        )
            ->getFill()
            ->getStartColor()
            ->setRGB('F8FAFC');
    }

    $rowNumber++;

    $counter++;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Empty Result
|--------------------------------------------------------------------------
*/

if ($counter === 1) {

    $sheet->mergeCells(
        "A{$rowNumber}:E{$rowNumber}"
    );

    $sheet->setCellValue(
        "A{$rowNumber}",
        'No homeroom teachers found for the selected filters.'
    );

    $sheet->getStyle(
        "A{$rowNumber}:E{$rowNumber}"
    )->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 11,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$columnWidths = [
    'A' => 8,
    'B' => 30,
    'C' => 20,
    'D' => 15,
    'E' => 15,
];

foreach ($columnWidths as $column => $width) {

    $sheet->getColumnDimension($column)
        ->setWidth($width);
}

/*
|--------------------------------------------------------------------------
| Row Heights
|--------------------------------------------------------------------------
*/

$sheet->getRowDimension(1)->setRowHeight(30);

$sheet->getRowDimension(2)->setRowHeight(25);

$sheet->getRowDimension(3)->setRowHeight(22);

$sheet->getRowDimension(4)->setRowHeight(20);

$sheet->getRowDimension(6)->setRowHeight(30);

/*
|--------------------------------------------------------------------------
| Freeze Header
|--------------------------------------------------------------------------
*/

$sheet->freezePane('A7');

/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

$sheet->setAutoFilter(
    "A{$headerRow}:E" .
    max($headerRow, $rowNumber - 1)
);

/*
|--------------------------------------------------------------------------
| Page Setup
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

$sheet->getPageMargins()
    ->setTop(0.5)
    ->setRight(0.3)
    ->setLeft(0.3)
    ->setBottom(0.5);

$sheet->getPageSetup()
    ->setHorizontalCentered(true);

/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$footerRow = $rowNumber + 2;

$sheet->mergeCells(
    "A{$footerRow}:E{$footerRow}"
);

$sheet->setCellValue(
    "A{$footerRow}",
    'Bole Kale Hiwot School - Homeroom Teachers Report'
);

$sheet->getStyle(
    "A{$footerRow}:E{$footerRow}"
)->applyFromArray([
    'font' => [
        'italic' => true,
        'size' => 9,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$academicYearFile = cleanExcelFilename(
    $academicYearName
);

$gradeFile = cleanExcelFilename(
    $gradeDisplay
);

$sectionFile = cleanExcelFilename(
    $sectionDisplay
);

$filename =
    'BKHS_' .
    $academicYearFile .
    '_' .
    $gradeFile .
    '_' .
    $sectionFile .
    '_Homeroom_Teachers.xlsx';

/*
|--------------------------------------------------------------------------
| Clean Output Buffer
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {
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
    $filename .
    '"'
);

header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

/*
|--------------------------------------------------------------------------
| Generate XLSX
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