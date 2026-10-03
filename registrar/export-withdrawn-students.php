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

    $value = preg_replace('/[\\\\\/:*?"<>|]+/', '', $value) ?? '';

    $value = preg_replace('/\s+/', '_', $value) ?? '';

    return trim($value, '._ ');
}

function formatWithdrawnDate(?string $date): string
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('Y-m-d H:i', $timestamp);
}

/*
|--------------------------------------------------------------------------
| Get Filters
|--------------------------------------------------------------------------
*/
$search = trim((string) ($_GET['search'] ?? ''));
$studentName = trim((string) ($_GET['student_name'] ?? ''));
$reason = trim((string) ($_GET['reason'] ?? ''));
$withdrawnBy = trim((string) ($_GET['withdrawn_by'] ?? ''));

$academicYearId = (int) ($_GET['academic_year_id'] ?? 0);
$gradeId = (int) ($_GET['grade_id'] ?? 0);
$sectionId = (int) ($_GET['section_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Get Selected Academic Year Name
|--------------------------------------------------------------------------
*/
$academicYearName = 'All';

if ($academicYearId > 0) {
    $stmt = $conn->prepare("
        SELECT name
        FROM academic_years
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $academicYearId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        $academicYearName = (string) $row['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Selected Grade Name
|--------------------------------------------------------------------------
*/
$gradeName = 'All';

if ($gradeId > 0) {
    $stmt = $conn->prepare("
        SELECT name
        FROM grades
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $gradeId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        $gradeName = (string) $row['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Selected Section Name
|--------------------------------------------------------------------------
*/
$sectionName = 'All';

if ($sectionId > 0) {
    $stmt = $conn->prepare("
        SELECT name
        FROM sections
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $sectionId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        $sectionName = (string) $row['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Build Withdrawal Query
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        sw.id,
        s.student_code,
        s.full_name AS student_name,
        ay.name AS academic_year,
        g.name AS grade,
        sec.name AS section,
        sw.reason,
        sw.withdrawn_at,
        u.full_name AS withdrawn_by
    FROM student_withdrawals sw
    INNER JOIN students s
        ON s.id = sw.student_id
    INNER JOIN academic_years ay
        ON ay.id = sw.academic_year_id
    INNER JOIN grades g
        ON g.id = sw.grade_id
    INNER JOIN sections sec
        ON sec.id = sw.section_id
    LEFT JOIN users u
        ON u.id = sw.withdrawn_by
    WHERE 1=1
";

$params = [];
$types = '';

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/
if ($search !== '') {
    $sql .= "
        AND (
            s.student_code LIKE ?
            OR s.full_name LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'ss';
}

/*
|--------------------------------------------------------------------------
| Student Name Filter
|--------------------------------------------------------------------------
*/
if ($studentName !== '') {
    $sql .= " AND s.full_name LIKE ? ";

    $params[] = '%' . $studentName . '%';

    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Reason Filter
|--------------------------------------------------------------------------
*/
if ($reason !== '') {
    $sql .= " AND sw.reason LIKE ? ";

    $params[] = '%' . $reason . '%';

    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Withdrawn By Filter
|--------------------------------------------------------------------------
*/
if ($withdrawnBy !== '') {
    $sql .= " AND u.full_name LIKE ? ";

    $params[] = '%' . $withdrawnBy . '%';

    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Academic Year Filter
|--------------------------------------------------------------------------
*/
if ($academicYearId > 0) {
    $sql .= " AND sw.academic_year_id = ? ";

    $params[] = $academicYearId;

    $types .= 'i';
}

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/
if ($gradeId > 0) {
    $sql .= " AND sw.grade_id = ? ";

    $params[] = $gradeId;

    $types .= 'i';
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/
if ($sectionId > 0) {
    $sql .= " AND sw.section_id = ? ";

    $params[] = $sectionId;

    $types .= 'i';
}

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/
$sql .= "
    ORDER BY
        sw.withdrawn_at DESC,
        sw.id DESC
";

/*
|--------------------------------------------------------------------------
| Execute Query
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit('Failed to prepare withdrawal export query.');
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
    ->setTitle('Withdrawn Students')
    ->setSubject('BKHS Withdrawn Students Report')
    ->setDescription('Withdrawn students report generated by Bole Kale Hiwot School.');

/*
|--------------------------------------------------------------------------
| Active Worksheet
|--------------------------------------------------------------------------
*/
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Withdrawn Students');

/*
|--------------------------------------------------------------------------
| Report Header
|--------------------------------------------------------------------------
*/
$sheet->mergeCells('A1:I1');

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

$sheet->mergeCells('A2:I2');

$sheet->setCellValue(
    'A2',
    'WITHDRAWN STUDENTS REPORT'
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

$sheet->mergeCells('A3:I3');

$sheet->setCellValue(
    'A3',
    'Academic Year: ' . $academicYearName .
    '   |   Grade: ' . $gradeName .
    '   |   Section: ' . $sectionName
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
$sheet->mergeCells('A4:I4');

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
    'Student ID',
    'Student Name',
    'Academic Year',
    'Grade',
    'Section',
    'Reason',
    'Withdrawn Date',
    'Withdrawn By',
];

foreach ($headers as $columnIndex => $header) {
    $column = Coordinate::stringFromColumnIndex($columnIndex + 1);

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
$sheet->getStyle("A{$headerRow}:I{$headerRow}")->applyFromArray([
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

    $sheet->setCellValue(
        'A' . $rowNumber,
        $counter
    );

    $sheet->setCellValueExplicit(
        'B' . $rowNumber,
        (string) $row['student_code'],
        DataType::TYPE_STRING
    );

    $sheet->setCellValue(
        'C' . $rowNumber,
        $row['student_name']
    );

    $sheet->setCellValue(
        'D' . $rowNumber,
        $row['academic_year']
    );

    $sheet->setCellValue(
        'E' . $rowNumber,
        $row['grade']
    );

    $sheet->setCellValue(
        'F' . $rowNumber,
        $row['section']
    );

    $sheet->setCellValue(
        'G' . $rowNumber,
        $row['reason']
    );

    $sheet->setCellValue(
        'H' . $rowNumber,
        formatWithdrawnDate($row['withdrawn_at'])
    );

    $sheet->setCellValue(
        'I' . $rowNumber,
        $row['withdrawn_by'] ?? ''
    );

    $sheet->getStyle("A{$rowNumber}:I{$rowNumber}")->applyFromArray([
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

    $sheet->getStyle("A{$rowNumber}:B{$rowNumber}")
        ->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("D{$rowNumber}:F{$rowNumber}")
        ->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("H{$rowNumber}:I{$rowNumber}")
        ->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    if ($counter % 2 === 0) {
        $sheet->getStyle("A{$rowNumber}:I{$rowNumber}")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID);

        $sheet->getStyle("A{$rowNumber}:I{$rowNumber}")
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
        "A{$rowNumber}:I{$rowNumber}"
    );

    $sheet->setCellValue(
        "A{$rowNumber}",
        'No withdrawn students found for the selected filters.'
    );

    $sheet->getStyle("A{$rowNumber}:I{$rowNumber}")->applyFromArray([
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
    'B' => 18,
    'C' => 28,
    'D' => 20,
    'E' => 14,
    'F' => 14,
    'G' => 45,
    'H' => 22,
    'I' => 25,
];

foreach ($columnWidths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
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
    "A{$headerRow}:I" . max($headerRow, $rowNumber - 1)
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
    "A{$footerRow}:I{$footerRow}"
);

$sheet->setCellValue(
    "A{$footerRow}",
    'Bole Kale Hiwot School - Withdrawn Students Report'
);

$sheet->getStyle("A{$footerRow}:I{$footerRow}")->applyFromArray([
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
$academicYearFile = cleanExcelFilename($academicYearName);
$gradeFile = cleanExcelFilename($gradeName);
$sectionFile = cleanExcelFilename($sectionName);

$filename =
    'BKHS_' .
    $academicYearFile .
    '_' .
    $gradeFile .
    '_' .
    $sectionFile .
    '_Withdrawn_Students.xlsx';

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
    'Content-Disposition: attachment; filename="' . $filename . '"'
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