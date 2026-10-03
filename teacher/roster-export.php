<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
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
require_once '../registrar/roster-data.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoloadPath)) {
    exit(
        'PhpSpreadsheet is not installed. ' .
        'Please install it with Composer using: composer require phpoffice/phpspreadsheet'
    );
}

require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;


/*
|--------------------------------------------------------------------------
| Database Charset
|--------------------------------------------------------------------------
*/

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| Basic Helpers
|--------------------------------------------------------------------------
*/

function cleanExcelText(string $value): string
{
    return trim($value);
}


function nullableFloat(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    return null;
}


function nullableInt(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (int) $value;
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Extract Numeric Mark From Value
|--------------------------------------------------------------------------
|
| Supports:
|   67
|   "67"
|   ['mark' => 67]
|   ['score' => 67]
|   ['value' => 67]
|   ['raw_mark' => 67]
|
*/

function extractMarkValue(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    if (!is_array($value)) {
        return null;
    }

    $possibleKeys = [
        'mark',
        'score',
        'value',
        'raw_mark',
        'student_mark',
        'subject_mark',
        'average',
    ];

    foreach ($possibleKeys as $key) {
        if (array_key_exists($key, $value)) {
            $number = nullableFloat($value[$key]);

            if ($number !== null) {
                return $number;
            }
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Get Mark From Collection
|--------------------------------------------------------------------------
|
| Handles subject collections where:
|
| [
|     'Maths' => 67,
|     'English' => 89
| ]
|
| or:
|
| [
|     'Maths' => ['mark' => 67],
|     'English' => ['score' => 89]
| ]
|
| or a list of subject records.
|
*/

function getMarkFromCollection(
    mixed $collection,
    string $subjectName
): ?float {

    if (!is_array($collection)) {
        return null;
    }


    /*
     * Direct exact key.
     */
    if (array_key_exists($subjectName, $collection)) {

        $mark = extractMarkValue(
            $collection[$subjectName]
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * Case-insensitive subject key.
     */
    foreach ($collection as $key => $value) {

        if (
            is_string($key) &&
            strcasecmp(
                trim($key),
                trim($subjectName)
            ) === 0
        ) {

            $mark = extractMarkValue($value);

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * List of subject records.
     */
    foreach ($collection as $item) {

        if (!is_array($item)) {
            continue;
        }

        $itemSubjectName = trim(
            (string) (
                $item['subject_name']
                ?? $item['name']
                ?? $item['subject']
                ?? ''
            )
        );

        if (
            $itemSubjectName !== '' &&
            strcasecmp(
                $itemSubjectName,
                trim($subjectName)
            ) === 0
        ) {

            $mark = extractMarkValue($item);

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| Semester Student Mark
|--------------------------------------------------------------------------
*/

function getSemesterStudentMark(
    array $student,
    string $subjectName
): ?float {

    /*
     * Normal semester structure.
     */
    $subjects = $student['subjects'] ?? [];

    $mark = getMarkFromCollection(
        $subjects,
        $subjectName
    );

    if ($mark !== null) {
        return $mark;
    }


    /*
     * Alternative marks structure.
     */
    $marks = $student['marks'] ?? [];

    $mark = getMarkFromCollection(
        $marks,
        $subjectName
    );

    if ($mark !== null) {
        return $mark;
    }


    /*
     * If roster-data.php provides normalizeRosterMarks(),
     * use it as an additional fallback.
     */
    if (
        function_exists('normalizeRosterMarks') &&
        is_array($subjects)
    ) {

        try {

            $normalized = normalizeRosterMarks($subjects);

            $mark = getMarkFromCollection(
                $normalized,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }

        } catch (Throwable $e) {
            // Ignore and continue with other fallbacks.
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| Annual Semester Mark
|--------------------------------------------------------------------------
*/

function getAnnualSemesterMark(
    array $student,
    string $semesterKey,
    string $subjectName
): ?float {

    /*
     * Example:
     * first_subjects
     * second_subjects
     */
    $subjectsKey = $semesterKey . '_subjects';

    if (
        isset($student[$subjectsKey]) &&
        is_array($student[$subjectsKey])
    ) {

        $mark = getMarkFromCollection(
            $student[$subjectsKey],
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * Example:
     * first => [
     *     'subjects' => [...]
     * ]
     */
    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey])
    ) {

        $semester = $student[$semesterKey];


        /*
         * subjects
         */
        if (
            isset($semester['subjects']) &&
            is_array($semester['subjects'])
        ) {

            $mark = getMarkFromCollection(
                $semester['subjects'],
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }


        /*
         * marks
         */
        if (
            isset($semester['marks']) &&
            is_array($semester['marks'])
        ) {

            $mark = getMarkFromCollection(
                $semester['marks'],
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * Alternative naming.
     */
    $semesterMarksKey = $semesterKey . '_marks';

    if (
        isset($student[$semesterMarksKey]) &&
        is_array($student[$semesterMarksKey])
    ) {

        $mark = getMarkFromCollection(
            $student[$semesterMarksKey],
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| Annual Subject Mark
|--------------------------------------------------------------------------
*/

function getAnnualSubjectMark(
    array $student,
    string $subjectName
): ?float {

    /*
     * annual_subjects
     */
    if (
        isset($student['annual_subjects']) &&
        is_array($student['annual_subjects'])
    ) {

        $mark = getMarkFromCollection(
            $student['annual_subjects'],
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * annual.subjects / annual.marks
     */
    if (
        isset($student['annual']) &&
        is_array($student['annual'])
    ) {

        $annual = $student['annual'];


        if (
            isset($annual['subjects']) &&
            is_array($annual['subjects'])
        ) {

            $mark = getMarkFromCollection(
                $annual['subjects'],
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }


        if (
            isset($annual['marks']) &&
            is_array($annual['marks'])
        ) {

            $mark = getMarkFromCollection(
                $annual['marks'],
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * annual_marks
     */
    if (
        isset($student['annual_marks']) &&
        is_array($student['annual_marks'])
    ) {

        $mark = getMarkFromCollection(
            $student['annual_marks'],
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| Semester Total
|--------------------------------------------------------------------------
|
| IMPORTANT:
| If roster-data.php already provides a sum, use it.
| Otherwise calculate it from the subject marks.
|
*/

function getSemesterTotal(
    array $student,
    string $semesterKey,
    array $subjectNames
): ?float {

    /*
     * Existing top-level total.
     */
    $key = $semesterKey . '_sum';

    if (array_key_exists($key, $student)) {

        $value = nullableFloat(
            $student[$key]
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Existing nested total.
     */
    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey]) &&
        array_key_exists(
            'sum',
            $student[$semesterKey]
        )
    ) {

        $value = nullableFloat(
            $student[$semesterKey]['sum']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Semester subjects fallback.
     */
    $total = 0.0;
    $count = 0;

    foreach ($subjectNames as $subjectName) {

        $mark = getAnnualSemesterMark(
            $student,
            $semesterKey,
            $subjectName
        );

        if ($mark !== null) {
            $total += $mark;
            $count++;
        }
    }


    /*
     * If annual roster doesn't contain nested semester data,
     * the current semester structure may be used.
     */
    if ($count === 0) {

        foreach ($subjectNames as $subjectName) {

            $mark = getSemesterStudentMark(
                $student,
                $subjectName
            );

            if ($mark !== null) {
                $total += $mark;
                $count++;
            }
        }
    }


    return $count > 0
        ? $total
        : null;
}


/*
|--------------------------------------------------------------------------
| Semester Average
|--------------------------------------------------------------------------
*/

function getSemesterAverage(
    array $student,
    string $semesterKey,
    array $subjectNames
): ?float {

    /*
     * Existing top-level average.
     */
    $key = $semesterKey . '_average';

    if (array_key_exists($key, $student)) {

        $value = nullableFloat(
            $student[$key]
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Existing nested average.
     */
    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey]) &&
        array_key_exists(
            'average',
            $student[$semesterKey]
        )
    ) {

        $value = nullableFloat(
            $student[$semesterKey]['average']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Calculate from subject marks.
     */
    $total = 0.0;
    $count = 0;

    foreach ($subjectNames as $subjectName) {

        $mark = getAnnualSemesterMark(
            $student,
            $semesterKey,
            $subjectName
        );

        if ($mark !== null) {
            $total += $mark;
            $count++;
        }
    }


    /*
     * Fallback to normal semester subjects.
     */
    if ($count === 0) {

        foreach ($subjectNames as $subjectName) {

            $mark = getSemesterStudentMark(
                $student,
                $subjectName
            );

            if ($mark !== null) {
                $total += $mark;
                $count++;
            }
        }
    }


    return $count > 0
        ? $total / $count
        : null;
}


/*
|--------------------------------------------------------------------------
| Semester Rank
|--------------------------------------------------------------------------
*/

function getSemesterRank(
    array $student,
    string $semesterKey
): ?int {

    $key = $semesterKey . '_rank';

    if (array_key_exists($key, $student)) {

        return nullableInt(
            $student[$key]
        );
    }


    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey]) &&
        array_key_exists(
            'rank',
            $student[$semesterKey]
        )
    ) {

        return nullableInt(
            $student[$semesterKey]['rank']
        );
    }


    return nullableInt(
        $student['rank'] ?? null
    );
}


/*
|--------------------------------------------------------------------------
| Annual Total
|--------------------------------------------------------------------------
*/

function getAnnualTotal(
    array $student,
    array $subjectNames
): ?float {

    /*
     * Existing annual_sum.
     */
    if (array_key_exists('annual_sum', $student)) {

        $value = nullableFloat(
            $student['annual_sum']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Existing annual.sum.
     */
    if (
        isset($student['annual']) &&
        is_array($student['annual']) &&
        array_key_exists(
            'sum',
            $student['annual']
        )
    ) {

        $value = nullableFloat(
            $student['annual']['sum']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Calculate from annual subject marks.
     */
    $total = 0.0;
    $count = 0;

    foreach ($subjectNames as $subjectName) {

        $mark = getAnnualSubjectMark(
            $student,
            $subjectName
        );

        if ($mark !== null) {
            $total += $mark;
            $count++;
        }
    }


    return $count > 0
        ? $total
        : null;
}


/*
|--------------------------------------------------------------------------
| Annual Average
|--------------------------------------------------------------------------
*/

function getAnnualAverage(
    array $student,
    array $subjectNames
): ?float {

    /*
     * Existing annual_average.
     */
    if (array_key_exists('annual_average', $student)) {

        $value = nullableFloat(
            $student['annual_average']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Existing annual.average.
     */
    if (
        isset($student['annual']) &&
        is_array($student['annual']) &&
        array_key_exists(
            'average',
            $student['annual']
        )
    ) {

        $value = nullableFloat(
            $student['annual']['average']
        );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Calculate from annual subject marks.
     */
    $total = 0.0;
    $count = 0;

    foreach ($subjectNames as $subjectName) {

        $mark = getAnnualSubjectMark(
            $student,
            $subjectName
        );

        if ($mark !== null) {
            $total += $mark;
            $count++;
        }
    }


    return $count > 0
        ? $total / $count
        : null;
}


/*
|--------------------------------------------------------------------------
| Annual Rank
|--------------------------------------------------------------------------
*/

function getAnnualRank(
    array $student
): ?int {

    if (array_key_exists('annual_rank', $student)) {

        return nullableInt(
            $student['annual_rank']
        );
    }


    if (
        isset($student['annual']) &&
        is_array($student['annual']) &&
        array_key_exists(
            'rank',
            $student['annual']
        )
    ) {

        return nullableInt(
            $student['annual']['rank']
        );
    }


    return nullableInt(
        $student['rank'] ?? null
    );
}


/*
|--------------------------------------------------------------------------
| Excel Formatting Helpers
|--------------------------------------------------------------------------
*/

function formatExcelNumber(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    if (
        abs(
            $value - round($value)
        ) < 0.000001
    ) {
        return number_format(
            $value,
            0
        );
    }

    return number_format(
        $value,
        2
    );
}


function formatExcelAverage(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format(
        $value,
        2
    );
}


function formatExcelRank(?int $value): string
{
    if ($value === null) {
        return '—';
    }

    return (string) $value;
}


/*
|--------------------------------------------------------------------------
| Get Logged-in Teacher
|--------------------------------------------------------------------------
*/

$teacherUserId = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    'SELECT
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
     WHERE status = ?
     ORDER BY id DESC
     LIMIT 1'
);

$activeStatus = 'Active';

$stmt->bind_param(
    's',
    $activeStatus
);

$stmt->execute();

$academicYear = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$academicYear) {
    exit(
        'No active academic year was found.'
    );
}


$academicYearId = (int) $academicYear['id'];

$academicYearName = (string) $academicYear['name'];


/*
|--------------------------------------------------------------------------
| Get Teacher Active Homeroom
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    'SELECT
        hta.id AS homeroom_id,
        hta.grade AS grade_number,
        hta.section AS section_code,
        ay.id AS academic_year_id,
        ay.name AS academic_year_name,
        g.id AS grade_id,
        g.name AS grade_name,
        sec.id AS section_id,
        sec.name AS section_name,
        sec.code AS section_code_db

     FROM homeroom_teacher_assignments hta

     INNER JOIN academic_years ay
        ON ay.name = hta.academic_year
       AND ay.status = ?

     INNER JOIN grades g
        ON g.grade_number = hta.grade

     INNER JOIN sections sec
        ON sec.code = hta.section

     WHERE hta.teacher_user_id = ?
       AND hta.academic_year = ?
       AND hta.is_active = 1

     ORDER BY hta.id ASC

     LIMIT 1'
);

$stmt->bind_param(
    'sis',
    $activeStatus,
    $teacherUserId,
    $academicYearName
);

$stmt->execute();

$homeroom = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$homeroom) {

    exit(
        'No active homeroom assignment was found for your account ' .
        'in the current academic year.'
    );
}


/*
|--------------------------------------------------------------------------
| Assignment Information
|--------------------------------------------------------------------------
*/

$academicYearId = (int) $homeroom['academic_year_id'];

$academicYearName = (string) $homeroom['academic_year_name'];

$gradeId = (int) $homeroom['grade_id'];

$sectionId = (int) $homeroom['section_id'];

$gradeName = (string) $homeroom['grade_name'];

$sectionName = (string) $homeroom['section_name'];

$sectionCode = trim(
    (string) (
        $homeroom['section_code_db']
        ?? $homeroom['section_code']
        ?? $sectionName
    )
);


/*
|--------------------------------------------------------------------------
| Validate Roster Type
|--------------------------------------------------------------------------
*/

$rosterType = strtolower(
    trim(
        (string) (
            $_GET['roster_type']
            ?? 'first'
        )
    )
);

$allowedRosterTypes = [
    'first',
    'second',
    'annual',
];

if (!in_array(
    $rosterType,
    $allowedRosterTypes,
    true
)) {

    exit(
        'Invalid roster type.'
    );
}


/*
|--------------------------------------------------------------------------
| Roster Title
|--------------------------------------------------------------------------
*/

$rosterTitle = match ($rosterType) {

    'first'
        => 'First Semester Academic Result Roster',

    'second'
        => 'Second Semester Academic Result Roster',

    'annual'
        => 'Annual Academic Result Roster',

    default
        => 'Academic Result Roster',
};


/*
|--------------------------------------------------------------------------
| Annual Roster Validation
|--------------------------------------------------------------------------
*/

if ($rosterType === 'annual') {

    if (!function_exists('isAnnualRosterReady')) {

        exit(
            'Annual roster validation function is unavailable.'
        );
    }


    if (
        !isAnnualRosterReady(
            $conn,
            $academicYearId,
            $gradeId,
            $sectionId
        )
    ) {

        exit(
            'Annual roster is not available. ' .
            'First Semester and Second Semester must both be completed.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Get Roster
|--------------------------------------------------------------------------
*/

$rosterResult = getRoster(
    $conn,
    $academicYearId,
    $gradeId,
    $sectionId,
    $rosterType
);

$roster = $rosterResult['students'] ?? [];

$subjects = $rosterResult['subjects'] ?? [];


/*
|--------------------------------------------------------------------------
| Normalize Subject Names
|--------------------------------------------------------------------------
*/

$subjectNames = [];

foreach ($subjects as $subject) {

    if (is_string($subject)) {

        $subjectName = trim($subject);

    } elseif (is_array($subject)) {

        $subjectName = trim(
            (string) (
                $subject['subject_name']
                ?? $subject['name']
                ?? ''
            )
        );

    } else {

        $subjectName = '';
    }


    if ($subjectName !== '') {

        $subjectNames[] = $subjectName;
    }
}


$subjectNames = array_values(
    array_unique($subjectNames)
);


/*
|--------------------------------------------------------------------------
| Excel Filename
|--------------------------------------------------------------------------
*/

$safeAcademicYear = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $academicYearName
);

$safeGrade = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $gradeName
);

$safeSection = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $sectionCode
);

$fileType = match ($rosterType) {

    'first'
        => 'First_Semester',

    'second'
        => 'Second_Semester',

    'annual'
        => 'Annual',

    default
        => 'Roster',
};


$filename =
    'BKHS_' .
    $fileType .
    '_Roster_' .
    $safeAcademicYear .
    '_' .
    $safeGrade .
    '_' .
    $safeSection .
    '.xlsx';


/*
|--------------------------------------------------------------------------
| Create Spreadsheet
|--------------------------------------------------------------------------
*/

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator(
        'Bole Kale Hiwot School'
    )
    ->setLastModifiedBy(
        'Bole Kale Hiwot School'
    )
    ->setTitle(
        $rosterTitle
    )
    ->setSubject(
        'Academic Result Roster'
    )
    ->setDescription(
        'Academic result roster generated by BKHS School Management System.'
    );


$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle(
    match ($rosterType) {

        'first'
            => 'First Semester',

        'second'
            => 'Second Semester',

        'annual'
            => 'Annual',

        default
            => 'Roster',
    }
);


/*
|--------------------------------------------------------------------------
| Column Helpers
|--------------------------------------------------------------------------
|
| Semester:
| A No
| B Student Code
| C Student Name
| D... Subjects
| ... Sum
| ... Average
| ... Rank
|
| 3 fixed columns + subjects + 3 summary columns.
|
| Annual:
| A No
| B Student Code
| C Student Name
| D Semester
| E... Subjects
| ... Sum
| ... Average
| ... Rank
|
| 4 fixed columns + subjects + 3 summary columns.
|
*/

$columnNumber = count($subjectNames) + (
    $rosterType === 'annual'
        ? 7
        : 6
);

$lastColumn = Coordinate::stringFromColumnIndex(
    $columnNumber
);


/*
|--------------------------------------------------------------------------
| Base Sheet Styling
|--------------------------------------------------------------------------
*/

$sheet->getDefaultRowDimension()
    ->setRowHeight(21);

$sheet->getDefaultColumnDimension()
    ->setWidth(12);


/*
|--------------------------------------------------------------------------
| School Header
|--------------------------------------------------------------------------
*/

$sheet->mergeCells(
    'A1:' .
    $lastColumn .
    '1'
);

$sheet->setCellValue(
    'A1',
    'BOLE KALE HIWOT SCHOOL'
);


$sheet->mergeCells(
    'A2:' .
    $lastColumn .
    '2'
);

$sheet->setCellValue(
    'A2',
    $rosterTitle
);


$sheet->mergeCells(
    'A3:' .
    $lastColumn .
    '3'
);

$sheet->setCellValue(
    'A3',
    'Academic Year: ' .
    $academicYearName .
    '    |    Grade: ' .
    $gradeName .
    '    |    Section: ' .
    $sectionCode
);


/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$schoolHeaderStyle = [

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


$reportTitleStyle = [

    'font' => [
        'bold' => true,
        'size' => 14,
    ],

    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,

        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];


$metaStyle = [

    'font' => [
        'size' => 11,
    ],

    'alignment' => [
        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,

        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
];


$sheet->getStyle(
    'A1:' .
    $lastColumn .
    '1'
)->applyFromArray(
    $schoolHeaderStyle
);


$sheet->getStyle(
    'A2:' .
    $lastColumn .
    '2'
)->applyFromArray(
    $reportTitleStyle
);


$sheet->getStyle(
    'A3:' .
    $lastColumn .
    '3'
)->applyFromArray(
    $metaStyle
);


$sheet->getRowDimension(1)
    ->setRowHeight(30);

$sheet->getRowDimension(2)
    ->setRowHeight(26);

$sheet->getRowDimension(3)
    ->setRowHeight(24);


/*
|--------------------------------------------------------------------------
| Table Header Row
|--------------------------------------------------------------------------
*/

$tableHeaderRow = 5;


/*
|--------------------------------------------------------------------------
| Table Headers
|--------------------------------------------------------------------------
*/

$headers = [
    'No',
    'Student Code',
    'Student Name',
];


if ($rosterType === 'annual') {

    $headers[] = 'Semester';
}


foreach ($subjectNames as $subjectName) {

    $headers[] = $subjectName;
}


$headers[] = 'Sum';

$headers[] = 'Average';

$headers[] = 'Rank';


$headerColumn = 1;

foreach ($headers as $header) {

    $cell =
        Coordinate::stringFromColumnIndex(
            $headerColumn
        ) .
        $tableHeaderRow;

    $sheet->setCellValue(
        $cell,
        $header
    );

    $headerColumn++;
}


/*
|--------------------------------------------------------------------------
| Header Styling
|--------------------------------------------------------------------------
*/

$headerRange =
    'A' .
    $tableHeaderRow .
    ':' .
    $lastColumn .
    $tableHeaderRow;


$sheet->getStyle(
    $headerRange
)->applyFromArray([

    'font' => [

        'bold' => true,

        'color' => [
            'rgb' => 'FFFFFF',
        ],

        'size' => 10,
    ],

    'fill' => [

        'fillType' =>
            Fill::FILL_SOLID,

        'startColor' => [
            'rgb' => '1E3A8A',
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

            'color' => [
                'rgb' => '172554',
            ],
        ],
    ],
]);


$sheet->getRowDimension(
    $tableHeaderRow
)->setRowHeight(32);


/*
|--------------------------------------------------------------------------
| Column Widths
|--------------------------------------------------------------------------
*/

$sheet->getColumnDimension('A')
    ->setWidth(7);

$sheet->getColumnDimension('B')
    ->setWidth(18);

$sheet->getColumnDimension('C')
    ->setWidth(28);


$currentColumnIndex = 4;


if ($rosterType === 'annual') {

    $semesterColumn =
        Coordinate::stringFromColumnIndex(
            $currentColumnIndex
        );

    $sheet->getColumnDimension(
        $semesterColumn
    )->setWidth(18);

    $currentColumnIndex++;
}


foreach ($subjectNames as $subjectName) {

    $columnLetter =
        Coordinate::stringFromColumnIndex(
            $currentColumnIndex
        );

    $sheet->getColumnDimension(
        $columnLetter
    )->setWidth(
        max(
            13,
            min(
                24,
                strlen($subjectName) + 4
            )
        )
    );

    $currentColumnIndex++;
}


/*
|--------------------------------------------------------------------------
| Summary Columns
|--------------------------------------------------------------------------
*/

$sumColumn = Coordinate::stringFromColumnIndex(
    $columnNumber - 2
);

$averageColumn = Coordinate::stringFromColumnIndex(
    $columnNumber - 1
);

$rankColumn = Coordinate::stringFromColumnIndex(
    $columnNumber
);


$sheet->getColumnDimension(
    $sumColumn
)->setWidth(12);


$sheet->getColumnDimension(
    $averageColumn
)->setWidth(13);


$sheet->getColumnDimension(
    $rankColumn
)->setWidth(10);


/*
|--------------------------------------------------------------------------
| Empty Roster
|--------------------------------------------------------------------------
*/

if (empty($roster)) {

    $emptyRow =
        $tableHeaderRow + 1;


    $sheet->mergeCells(
        'A' .
        $emptyRow .
        ':' .
        $lastColumn .
        $emptyRow
    );


    $sheet->setCellValue(
        'A' . $emptyRow,
        'No academic results found.'
    );


    $sheet->getStyle(
        'A' .
        $emptyRow .
        ':' .
        $lastColumn .
        $emptyRow
    )->applyFromArray([

        'font' => [

            'bold' => true,

            'color' => [
                'rgb' => '6B7280',
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
                    'rgb' => '9CA3AF',
                ],
            ],
        ],
    ]);


    $sheet->getRowDimension(
        $emptyRow
    )->setRowHeight(30);
}


/*
|--------------------------------------------------------------------------
| Semester Roster
|--------------------------------------------------------------------------
*/

if (
    !empty($roster) &&
    $rosterType !== 'annual'
) {

    $currentRow =
        $tableHeaderRow + 1;


    foreach ($roster as $index => $student) {

        $rowNumber =
            $index + 1;


        $studentCode = trim(
            (string) (
                $student['student_code']
                ?? ''
            )
        );


        $studentName = trim(
            (string) (
                $student['student_name']
                ?? $student['full_name']
                ?? ''
            )
        );


        /*
         * IMPORTANT:
         * Calculate Sum from actual subject marks
         * when the roster does not provide sum.
         */
        $sum = getSemesterTotal(
            $student,
            $rosterType === 'first'
                ? 'first'
                : 'second',
            $subjectNames
        );


        /*
         * Calculate Average from actual subject marks
         * when the roster does not provide average.
         */
        $average = getSemesterAverage(
            $student,
            $rosterType === 'first'
                ? 'first'
                : 'second',
            $subjectNames
        );


        /*
         * Rank.
         */
        $rank = getSemesterRank(
            $student,
            $rosterType === 'first'
                ? 'first'
                : 'second'
        );


        /*
         * No
         */
        $sheet->setCellValue(
            'A' . $currentRow,
            $rowNumber
        );


        /*
         * Student Code
         */
        $sheet->setCellValueExplicit(
            'B' . $currentRow,
            $studentCode,
            DataType::TYPE_STRING
        );


        /*
         * Student Name
         */
        $sheet->setCellValue(
            'C' . $currentRow,
            $studentName
        );


        /*
         * Subject Marks
         */
        $subjectColumnIndex = 4;


        foreach ($subjectNames as $subjectName) {

            $mark = getSemesterStudentMark(
                $student,
                $subjectName
            );


            $columnLetter =
                Coordinate::stringFromColumnIndex(
                    $subjectColumnIndex
                );


            $sheet->setCellValue(
                $columnLetter . $currentRow,
                formatExcelNumber($mark)
            );


            $subjectColumnIndex++;
        }


        /*
         * Sum
         */
        $sheet->setCellValue(
            $sumColumn . $currentRow,
            formatExcelNumber($sum)
        );


        /*
         * Average
         */
        $sheet->setCellValue(
            $averageColumn . $currentRow,
            formatExcelAverage($average)
        );


        /*
         * Rank
         */
        $sheet->setCellValue(
            $rankColumn . $currentRow,
            formatExcelRank($rank)
        );


        $currentRow++;
    }
}


/*
|--------------------------------------------------------------------------
| Annual Roster
|--------------------------------------------------------------------------
*/

if (
    !empty($roster) &&
    $rosterType === 'annual'
) {

    $currentRow =
        $tableHeaderRow + 1;


    foreach ($roster as $index => $student) {

        $rowNumber =
            $index + 1;


        $studentCode = trim(
            (string) (
                $student['student_code']
                ?? ''
            )
        );


        $studentName = trim(
            (string) (
                $student['student_name']
                ?? $student['full_name']
                ?? ''
            )
        );


        /*
         * First Semester
         */
        $firstSum = getSemesterTotal(
            $student,
            'first',
            $subjectNames
        );


        $firstAverage = getSemesterAverage(
            $student,
            'first',
            $subjectNames
        );


        $firstRank = getSemesterRank(
            $student,
            'first'
        );


        /*
         * Second Semester
         */
        $secondSum = getSemesterTotal(
            $student,
            'second',
            $subjectNames
        );


        $secondAverage = getSemesterAverage(
            $student,
            'second',
            $subjectNames
        );


        $secondRank = getSemesterRank(
            $student,
            'second'
        );


        /*
         * Annual
         */
        $annualSum = getAnnualTotal(
            $student,
            $subjectNames
        );


        $annualAverage = getAnnualAverage(
            $student,
            $subjectNames
        );


        $annualRank = getAnnualRank(
            $student
        );


        /*
         * Merge No
         */
        $sheet->mergeCells(
            'A' .
            $currentRow .
            ':A' .
            ($currentRow + 2)
        );


        $sheet->setCellValue(
            'A' . $currentRow,
            $rowNumber
        );


        /*
         * Merge Student Code
         */
        $sheet->mergeCells(
            'B' .
            $currentRow .
            ':B' .
            ($currentRow + 2)
        );


        $sheet->setCellValueExplicit(
            'B' . $currentRow,
            $studentCode,
            DataType::TYPE_STRING
        );


        /*
         * Merge Student Name
         */
        $sheet->mergeCells(
            'C' .
            $currentRow .
            ':C' .
            ($currentRow + 2)
        );


        $sheet->setCellValue(
            'C' . $currentRow,
            $studentName
        );


        /*
         * ----------------------------------------------------------
         * First Semester
         * ----------------------------------------------------------
         */

        $sheet->setCellValue(
            'D' . $currentRow,
            '1st Semester'
        );


        $subjectColumnIndex = 5;


        foreach ($subjectNames as $subjectName) {

            $mark = getAnnualSemesterMark(
                $student,
                'first',
                $subjectName
            );


            $columnLetter =
                Coordinate::stringFromColumnIndex(
                    $subjectColumnIndex
                );


            $sheet->setCellValue(
                $columnLetter . $currentRow,
                formatExcelNumber($mark)
            );


            $subjectColumnIndex++;
        }


        $sheet->setCellValue(
            $sumColumn . $currentRow,
            formatExcelNumber($firstSum)
        );


        $sheet->setCellValue(
            $averageColumn . $currentRow,
            formatExcelAverage($firstAverage)
        );


        $sheet->setCellValue(
            $rankColumn . $currentRow,
            formatExcelRank($firstRank)
        );


        /*
         * ----------------------------------------------------------
         * Second Semester
         * ----------------------------------------------------------
         */

        $secondRow =
            $currentRow + 1;


        $sheet->setCellValue(
            'D' . $secondRow,
            '2nd Semester'
        );


        $subjectColumnIndex = 5;


        foreach ($subjectNames as $subjectName) {

            $mark = getAnnualSemesterMark(
                $student,
                'second',
                $subjectName
            );


            $columnLetter =
                Coordinate::stringFromColumnIndex(
                    $subjectColumnIndex
                );


            $sheet->setCellValue(
                $columnLetter . $secondRow,
                formatExcelNumber($mark)
            );


            $subjectColumnIndex++;
        }


        $sheet->setCellValue(
            $sumColumn . $secondRow,
            formatExcelNumber($secondSum)
        );


        $sheet->setCellValue(
            $averageColumn . $secondRow,
            formatExcelAverage($secondAverage)
        );


        $sheet->setCellValue(
            $rankColumn . $secondRow,
            formatExcelRank($secondRank)
        );


        /*
         * ----------------------------------------------------------
         * Annual Average
         * ----------------------------------------------------------
         */

        $annualRow =
            $currentRow + 2;


        $sheet->setCellValue(
            'D' . $annualRow,
            'Annual Average'
        );


        $subjectColumnIndex = 5;


        foreach ($subjectNames as $subjectName) {

            $annualMark =
                getAnnualSubjectMark(
                    $student,
                    $subjectName
                );


            $columnLetter =
                Coordinate::stringFromColumnIndex(
                    $subjectColumnIndex
                );


            $sheet->setCellValue(
                $columnLetter . $annualRow,
                formatExcelNumber($annualMark)
            );


            $subjectColumnIndex++;
        }


        $sheet->setCellValue(
            $sumColumn . $annualRow,
            formatExcelNumber($annualSum)
        );


        $sheet->setCellValue(
            $averageColumn . $annualRow,
            formatExcelAverage($annualAverage)
        );


        $sheet->setCellValue(
            $rankColumn . $annualRow,
            formatExcelRank($annualRank)
        );


        $currentRow += 3;
    }
}


/*
|--------------------------------------------------------------------------
| Determine Last Data Row
|--------------------------------------------------------------------------
*/

if (empty($roster)) {

    $lastDataRow =
        $tableHeaderRow + 1;

} elseif ($rosterType === 'annual') {

    $lastDataRow =
        $tableHeaderRow +
        (count($roster) * 3);

} else {

    $lastDataRow =
        $tableHeaderRow +
        count($roster);
}


/*
|--------------------------------------------------------------------------
| Main Table Styling
|--------------------------------------------------------------------------
*/

$tableRange =
    'A' .
    $tableHeaderRow .
    ':' .
    $lastColumn .
    $lastDataRow;


$sheet->getStyle(
    $tableRange
)->applyFromArray([

    'borders' => [

        'allBorders' => [

            'borderStyle' =>
                Border::BORDER_THIN,

            'color' => [
                'rgb' => '9CA3AF',
            ],
        ],
    ],

    'alignment' => [

        'vertical' =>
            Alignment::VERTICAL_CENTER,
    ],
]);


/*
|--------------------------------------------------------------------------
| Alignment
|--------------------------------------------------------------------------
*/

$sheet->getStyle(
    'A' .
    ($tableHeaderRow + 1) .
    ':A' .
    $lastDataRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );


$sheet->getStyle(
    'B' .
    ($tableHeaderRow + 1) .
    ':C' .
    $lastDataRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_LEFT
    );


/*
|--------------------------------------------------------------------------
| Annual Semester Column Alignment
|--------------------------------------------------------------------------
*/

if ($rosterType === 'annual') {

    $sheet->getStyle(
        'D' .
        ($tableHeaderRow + 1) .
        ':D' .
        $lastDataRow
    )->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        );
}


/*
|--------------------------------------------------------------------------
| Subject + Summary Alignment
|--------------------------------------------------------------------------
*/

$subjectStartColumn =
    $rosterType === 'annual'
        ? 5
        : 4;


$subjectAndSummaryStart =
    Coordinate::stringFromColumnIndex(
        $subjectStartColumn
    );


$sheet->getStyle(
    $subjectAndSummaryStart .
    ($tableHeaderRow + 1) .
    ':' .
    $lastColumn .
    $lastDataRow
)->getAlignment()
    ->setHorizontal(
        Alignment::HORIZONTAL_CENTER
    );


/*
|--------------------------------------------------------------------------
| Summary Background
|--------------------------------------------------------------------------
*/

$summaryStartColumn =
    Coordinate::stringFromColumnIndex(
        $columnNumber - 2
    );


$sheet->getStyle(
    $summaryStartColumn .
    ($tableHeaderRow + 1) .
    ':' .
    $lastColumn .
    $lastDataRow
)->applyFromArray([

    'fill' => [

        'fillType' =>
            Fill::FILL_SOLID,

        'startColor' => [
            'rgb' => 'F3F4F6',
        ],
    ],

    'font' => [
        'bold' => true,
    ],
]);


/*
|--------------------------------------------------------------------------
| Annual Average Row Styling
|--------------------------------------------------------------------------
*/

if (
    $rosterType === 'annual' &&
    !empty($roster)
) {

    for (
        $studentIndex = 0;
        $studentIndex < count($roster);
        $studentIndex++
    ) {

        $row =
            $tableHeaderRow +
            1 +
            ($studentIndex * 3) +
            2;


        $sheet->getStyle(
            'D' .
            $row .
            ':' .
            $lastColumn .
            $row
        )->applyFromArray([

            'font' => [
                'bold' => true,
            ],

            'fill' => [

                'fillType' =>
                    Fill::FILL_SOLID,

                'startColor' => [
                    'rgb' => 'EEF2FF',
                ],
            ],

            'borders' => [

                'top' => [

                    'borderStyle' =>
                        Border::BORDER_MEDIUM,

                    'color' => [
                        'rgb' => '1E3A8A',
                    ],
                ],
            ],
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Freeze Header
|--------------------------------------------------------------------------
*/

$sheet->freezePane(
    'A6'
);


/*
|--------------------------------------------------------------------------
| Auto Filter
|--------------------------------------------------------------------------
*/

if (!empty($roster)) {

    $sheet->setAutoFilter(
        'A' .
        $tableHeaderRow .
        ':' .
        $lastColumn .
        $tableHeaderRow
    );
}


/*
|--------------------------------------------------------------------------
| Print Settings
|--------------------------------------------------------------------------
*/

$sheet->getPageSetup()
    ->setOrientation(
        PageSetup::ORIENTATION_LANDSCAPE
    )
    ->setPaperSize(
        PageSetup::PAPERSIZE_A4
    )
    ->setFitToWidth(1)
    ->setFitToHeight(0);


$sheet->getPageMargins()
    ->setTop(0.35)
    ->setRight(0.35)
    ->setLeft(0.35)
    ->setBottom(0.45);


$sheet->getPageSetup()
    ->setHorizontalCentered(true);


/*
|--------------------------------------------------------------------------
| Footer
|--------------------------------------------------------------------------
*/

$footerRow =
    $lastDataRow + 2;


$sheet->mergeCells(
    'A' .
    $footerRow .
    ':' .
    $lastColumn .
    $footerRow
);


$sheet->setCellValue(
    'A' . $footerRow,
    'Bole Kale Hiwot School — Academic Result Roster'
);


$sheet->getStyle(
    'A' .
    $footerRow .
    ':' .
    $lastColumn .
    $footerRow
)->applyFromArray([

    'font' => [

        'italic' => true,

        'size' => 9,

        'color' => [
            'rgb' => '6B7280',
        ],
    ],

    'alignment' => [

        'horizontal' =>
            Alignment::HORIZONTAL_CENTER,
    ],
]);


/*
|--------------------------------------------------------------------------
| Document Header/Footer
|--------------------------------------------------------------------------
*/

$sheet->getHeaderFooter()
    ->setOddFooter(
        '&C&B Bole Kale Hiwot School — Academic Result Roster'
    );


/*
|--------------------------------------------------------------------------
| Output XLSX
|--------------------------------------------------------------------------
*/

if (ob_get_length()) {
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
    'Cache-Control: max-age=1'
);

header(
    'Expires: Mon, 26 Jul 1997 05:00:00 GMT'
);

header(
    'Last-Modified: ' .
    gmdate('D, d M Y H:i:s') .
    ' GMT'
);

header(
    'Pragma: public'
);


/*
|--------------------------------------------------------------------------
| Write XLSX
|--------------------------------------------------------------------------
*/

$writer = new Xlsx(
    $spreadsheet
);

$writer->save(
    'php://output'
);


$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;