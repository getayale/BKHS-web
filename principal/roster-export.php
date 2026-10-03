<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Principal Authentication
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';
require_once 'roster-data.php';

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

            $number = nullableFloat(
                $value[$key]
            );

            if ($number !== null) {
                return $number;
            }
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Get Subject ID
|--------------------------------------------------------------------------
*/

function getSubjectIdFromRecord(
    mixed $subject
): ?int {

    if (!is_array($subject)) {
        return null;
    }

    $possibleKeys = [
        'id',
        'grade_subject_id',
        'subject_id',
    ];

    foreach ($possibleKeys as $key) {

        if (!array_key_exists($key, $subject)) {
            continue;
        }

        $id = nullableInt(
            $subject[$key]
        );

        if ($id !== null && $id > 0) {
            return $id;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Get Subject Name
|--------------------------------------------------------------------------
*/

function getSubjectNameFromRecord(
    mixed $subject
): string {

    if (is_string($subject)) {
        return trim($subject);
    }

    if (!is_array($subject)) {
        return '';
    }

    return trim(
        (string) (
            $subject['subject_name']
            ?? $subject['name']
            ?? $subject['subject']
            ?? ''
        )
    );
}


/*
|--------------------------------------------------------------------------
| Get Mark From Collection
|--------------------------------------------------------------------------
|
| Supports:
|
| 1. Subject ID keys
|    [
|        12 => 85,
|        13 => 76
|    ]
|
| 2. Subject name keys
|    [
|        'Maths' => 85
|    ]
|
| 3. Subject records
|    [
|        [
|            'grade_subject_id' => 12,
|            'mark' => 85
|        ]
|    ]
|
|--------------------------------------------------------------------------
*/

function getMarkFromCollection(
    mixed $collection,
    ?int $subjectId,
    string $subjectName
): ?float {

    if (!is_array($collection)) {
        return null;
    }


    /*
     * ---------------------------------------------------------------
     * 1. Direct subject ID key
     * ---------------------------------------------------------------
     */

    if ($subjectId !== null) {

        if (array_key_exists($subjectId, $collection)) {

            $mark = extractMarkValue(
                $collection[$subjectId]
            );

            if ($mark !== null) {
                return $mark;
            }
        }


        /*
         * Some arrays may use string versions of numeric IDs.
         */

        $subjectIdString = (string) $subjectId;

        if (array_key_exists(
            $subjectIdString,
            $collection
        )) {

            $mark = extractMarkValue(
                $collection[$subjectIdString]
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * 2. Direct exact subject name key
     * ---------------------------------------------------------------
     */

    if (
        $subjectName !== '' &&
        array_key_exists(
            $subjectName,
            $collection
        )
    ) {

        $mark = extractMarkValue(
            $collection[$subjectName]
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * ---------------------------------------------------------------
     * 3. Case-insensitive subject name key
     * ---------------------------------------------------------------
     */

    if ($subjectName !== '') {

        foreach ($collection as $key => $value) {

            if (!is_string($key)) {
                continue;
            }

            if (
                strcasecmp(
                    trim($key),
                    trim($subjectName)
                ) !== 0
            ) {
                continue;
            }

            $mark = extractMarkValue($value);

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * 4. List of subject records
     * ---------------------------------------------------------------
     */

    foreach ($collection as $item) {

        if (!is_array($item)) {
            continue;
        }


        /*
         * Check subject ID.
         */

        $itemSubjectId = getSubjectIdFromRecord(
            $item
        );

        if (
            $subjectId !== null &&
            $itemSubjectId !== null &&
            $itemSubjectId === $subjectId
        ) {

            $mark = extractMarkValue($item);

            if ($mark !== null) {
                return $mark;
            }
        }


        /*
         * Check subject name.
         */

        $itemSubjectName =
            getSubjectNameFromRecord(
                $item
            );

        if (
            $subjectName !== '' &&
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
    ?int $subjectId,
    string $subjectName
): ?float {

    /*
     * ---------------------------------------------------------------
     * Normal semester structure
     * ---------------------------------------------------------------
     */

    $subjects = $student['subjects'] ?? [];

    $mark = getMarkFromCollection(
        $subjects,
        $subjectId,
        $subjectName
    );

    if ($mark !== null) {
        return $mark;
    }


    /*
     * ---------------------------------------------------------------
     * Alternative marks structure
     * ---------------------------------------------------------------
     */

    $marks = $student['marks'] ?? [];

    $mark = getMarkFromCollection(
        $marks,
        $subjectId,
        $subjectName
    );

    if ($mark !== null) {
        return $mark;
    }


    /*
     * ---------------------------------------------------------------
     * Alternative result structure
     * ---------------------------------------------------------------
     */

    $results = $student['results'] ?? [];

    $mark = getMarkFromCollection(
        $results,
        $subjectId,
        $subjectName
    );

    if ($mark !== null) {
        return $mark;
    }


    /*
     * ---------------------------------------------------------------
     * normalizeRosterMarks() fallback
     * ---------------------------------------------------------------
     */

    if (
        function_exists('normalizeRosterMarks') &&
        is_array($subjects)
    ) {

        try {

            $normalized = normalizeRosterMarks(
                $subjects
            );

            $mark = getMarkFromCollection(
                $normalized,
                $subjectId,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }

        } catch (Throwable $e) {
            // Ignore and continue.
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
    ?int $subjectId,
    string $subjectName
): ?float {

    /*
     * ---------------------------------------------------------------
     * Example:
     *
     * first_subjects
     * second_subjects
     * ---------------------------------------------------------------
     */

    $subjectsKey =
        $semesterKey . '_subjects';

    if (
        isset($student[$subjectsKey]) &&
        is_array($student[$subjectsKey])
    ) {

        $mark = getMarkFromCollection(
            $student[$subjectsKey],
            $subjectId,
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * ---------------------------------------------------------------
     * Example:
     *
     * first => [
     *     'subjects' => [...]
     * ]
     * ---------------------------------------------------------------
     */

    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey])
    ) {

        $semester =
            $student[$semesterKey];


        /*
         * subjects
         */

        if (
            isset($semester['subjects']) &&
            is_array($semester['subjects'])
        ) {

            $mark = getMarkFromCollection(
                $semester['subjects'],
                $subjectId,
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
                $subjectId,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }


        /*
         * results
         */

        if (
            isset($semester['results']) &&
            is_array($semester['results'])
        ) {

            $mark = getMarkFromCollection(
                $semester['results'],
                $subjectId,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * Alternative naming:
     *
     * first_marks
     * second_marks
     * ---------------------------------------------------------------
     */

    $semesterMarksKey =
        $semesterKey . '_marks';

    if (
        isset($student[$semesterMarksKey]) &&
        is_array($student[$semesterMarksKey])
    ) {

        $mark = getMarkFromCollection(
            $student[$semesterMarksKey],
            $subjectId,
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
    ?int $subjectId,
    string $subjectName
): ?float {

    /*
     * ---------------------------------------------------------------
     * annual_subjects
     * ---------------------------------------------------------------
     */

    if (
        isset($student['annual_subjects']) &&
        is_array($student['annual_subjects'])
    ) {

        $mark = getMarkFromCollection(
            $student['annual_subjects'],
            $subjectId,
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * ---------------------------------------------------------------
     * annual.subjects / annual.marks
     * ---------------------------------------------------------------
     */

    if (
        isset($student['annual']) &&
        is_array($student['annual'])
    ) {

        $annual =
            $student['annual'];


        /*
         * subjects
         */

        if (
            isset($annual['subjects']) &&
            is_array($annual['subjects'])
        ) {

            $mark = getMarkFromCollection(
                $annual['subjects'],
                $subjectId,
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
            isset($annual['marks']) &&
            is_array($annual['marks'])
        ) {

            $mark = getMarkFromCollection(
                $annual['marks'],
                $subjectId,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }


        /*
         * results
         */

        if (
            isset($annual['results']) &&
            is_array($annual['results'])
        ) {

            $mark = getMarkFromCollection(
                $annual['results'],
                $subjectId,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * annual_marks
     * ---------------------------------------------------------------
     */

    if (
        isset($student['annual_marks']) &&
        is_array($student['annual_marks'])
    ) {

        $mark = getMarkFromCollection(
            $student['annual_marks'],
            $subjectId,
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }


    /*
     * ---------------------------------------------------------------
     * annual_results
     * ---------------------------------------------------------------
     */

    if (
        isset($student['annual_results']) &&
        is_array($student['annual_results'])
    ) {

        $mark = getMarkFromCollection(
            $student['annual_results'],
            $subjectId,
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
*/

function getSemesterTotal(
    array $student,
    string $semesterKey,
    array $subjects
): ?float {

    /*
     * Existing top-level total.
     */

    $key =
        $semesterKey . '_sum';

    if (array_key_exists(
        $key,
        $student
    )) {

        $value =
            nullableFloat(
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

        $value =
            nullableFloat(
                $student[$semesterKey]['sum']
            );

        if ($value !== null) {
            return $value;
        }
    }


    /*
     * Calculate from semester subjects.
     */

    $total = 0.0;

    $count = 0;


    foreach ($subjects as $subject) {

        $subjectId =
            getSubjectIdFromRecord(
                $subject
            );

        $subjectName =
            getSubjectNameFromRecord(
                $subject
            );


        $mark =
            getAnnualSemesterMark(
                $student,
                $semesterKey,
                $subjectId,
                $subjectName
            );


        if ($mark === null) {

            $mark =
                getSemesterStudentMark(
                    $student,
                    $subjectId,
                    $subjectName
                );
        }


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
| Semester Average
|--------------------------------------------------------------------------
*/

function getSemesterAverage(
    array $student,
    string $semesterKey,
    array $subjects
): ?float {

    /*
     * Existing top-level average.
     */

    $key =
        $semesterKey . '_average';

    if (array_key_exists(
        $key,
        $student
    )) {

        $value =
            nullableFloat(
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

        $value =
            nullableFloat(
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


    foreach ($subjects as $subject) {

        $subjectId =
            getSubjectIdFromRecord(
                $subject
            );

        $subjectName =
            getSubjectNameFromRecord(
                $subject
            );


        $mark =
            getAnnualSemesterMark(
                $student,
                $semesterKey,
                $subjectId,
                $subjectName
            );


        if ($mark === null) {

            $mark =
                getSemesterStudentMark(
                    $student,
                    $subjectId,
                    $subjectName
                );
        }


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
| Semester Rank
|--------------------------------------------------------------------------
*/

function getSemesterRank(
    array $student,
    string $semesterKey
): ?int {

    $key =
        $semesterKey . '_rank';

    if (array_key_exists(
        $key,
        $student
    )) {

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
    array $subjects
): ?float {

    /*
     * Existing annual_sum.
     */

    if (array_key_exists(
        'annual_sum',
        $student
    )) {

        $value =
            nullableFloat(
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

        $value =
            nullableFloat(
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


    foreach ($subjects as $subject) {

        $subjectId =
            getSubjectIdFromRecord(
                $subject
            );

        $subjectName =
            getSubjectNameFromRecord(
                $subject
            );


        $mark =
            getAnnualSubjectMark(
                $student,
                $subjectId,
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
    array $subjects
): ?float {

    /*
     * Existing annual_average.
     */

    if (array_key_exists(
        'annual_average',
        $student
    )) {

        $value =
            nullableFloat(
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

        $value =
            nullableFloat(
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


    foreach ($subjects as $subject) {

        $subjectId =
            getSubjectIdFromRecord(
                $subject
            );

        $subjectName =
            getSubjectNameFromRecord(
                $subject
            );


        $mark =
            getAnnualSubjectMark(
                $student,
                $subjectId,
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

    if (array_key_exists(
        'annual_rank',
        $student
    )) {

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

function formatExcelNumber(
    ?float $value
): string {

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


function formatExcelAverage(
    ?float $value
): string {

    if ($value === null) {
        return '—';
    }


    return number_format(
        $value,
        2
    );
}


function formatExcelRank(
    ?int $value
): string {

    if ($value === null) {
        return '—';
    }


    return (string) $value;
}


/*
|--------------------------------------------------------------------------
| Principal Selected Parameters
|--------------------------------------------------------------------------
*/

$academicYearId = filter_input(
    INPUT_GET,
    'academic_year_id',
    FILTER_VALIDATE_INT
);

$gradeId = filter_input(
    INPUT_GET,
    'grade_id',
    FILTER_VALIDATE_INT
);

$sectionId = filter_input(
    INPUT_GET,
    'section_id',
    FILTER_VALIDATE_INT
);

$rosterType = strtolower(
    trim(
        (string) (
            $_GET['roster_type']
            ?? 'first'
        )
    )
);


/*
|--------------------------------------------------------------------------
| Validate IDs
|--------------------------------------------------------------------------
*/

if (
    $academicYearId === false ||
    $academicYearId === null ||
    $academicYearId < 1
) {
    exit('Invalid academic year.');
}


if (
    $gradeId === false ||
    $gradeId === null ||
    $gradeId < 1
) {
    exit('Invalid grade.');
}


if (
    $sectionId === false ||
    $sectionId === null ||
    $sectionId < 1
) {
    exit('Invalid section.');
}


/*
|--------------------------------------------------------------------------
| Validate Roster Type
|--------------------------------------------------------------------------
*/

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
| Get Academic Year
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
     WHERE id = ?
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Failed to prepare academic year query.'
    );
}

$stmt->bind_param(
    'i',
    $academicYearId
);

$stmt->execute();

$academicYear = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$academicYear) {

    exit(
        'The selected academic year was not found.'
    );
}


$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];


/*
|--------------------------------------------------------------------------
| Get Grade
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    'SELECT
        id,
        name,
        grade_number
     FROM grades
     WHERE id = ?
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Failed to prepare grade query.'
    );
}

$stmt->bind_param(
    'i',
    $gradeId
);

$stmt->execute();

$grade = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$grade) {

    exit(
        'The selected grade was not found.'
    );
}


$gradeId =
    (int) $grade['id'];

$gradeName =
    (string) $grade['name'];


/*
|--------------------------------------------------------------------------
| Get Section
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    'SELECT
        id,
        name,
        code
     FROM sections
     WHERE id = ?
     LIMIT 1'
);

if (!$stmt) {
    exit(
        'Failed to prepare section query.'
    );
}

$stmt->bind_param(
    'i',
    $sectionId
);

$stmt->execute();

$section = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (!$section) {

    exit(
        'The selected section was not found.'
    );
}


$sectionId =
    (int) $section['id'];

$sectionName =
    (string) $section['name'];

$sectionCode = trim(
    (string) (
        $section['code']
        ?: $sectionName
    )
);


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

    if (!function_exists(
        'isAnnualRosterReady'
    )) {

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

$roster =
    $rosterResult['students'] ?? [];

$subjects =
    $rosterResult['subjects'] ?? [];


/*
|--------------------------------------------------------------------------
| Normalize Subjects
|--------------------------------------------------------------------------
|
| Keep BOTH:
|
| $subjectRecords
|     Contains subject ID + name.
|
| $subjectNames
|     Used for Excel column headings.
|
|--------------------------------------------------------------------------
*/

$subjectRecords = [];

$subjectNames = [];

$seenSubjectIds = [];

$seenSubjectNames = [];


foreach ($subjects as $subject) {

    $subjectId =
        getSubjectIdFromRecord(
            $subject
        );

    $subjectName =
        getSubjectNameFromRecord(
            $subject
        );


    if ($subjectName === '') {
        continue;
    }


    /*
     * If subject has an ID, avoid duplicates by ID.
     */

    if ($subjectId !== null) {

        if (
            isset(
                $seenSubjectIds[$subjectId]
            )
        ) {
            continue;
        }

        $seenSubjectIds[$subjectId] = true;
    }


    /*
     * Also avoid duplicate subject names.
     */

    $nameKey =
        strtolower(
            trim($subjectName)
        );

    if (
        isset(
            $seenSubjectNames[$nameKey]
        )
    ) {
        continue;
    }

    $seenSubjectNames[$nameKey] = true;


    $subjectRecords[] = [
        'id' => $subjectId,
        'name' => $subjectName,
    ];

    $subjectNames[] =
        $subjectName;
}


$subjectNames =
    array_values(
        array_unique(
            $subjectNames
        )
    );


/*
|--------------------------------------------------------------------------
| Excel Filename
|--------------------------------------------------------------------------
*/

$safeAcademicYear =
    preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        $academicYearName
    );

$safeGrade =
    preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        $gradeName
    );

$safeSection =
    preg_replace(
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

$spreadsheet =
    new Spreadsheet();


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


$sheet =
    $spreadsheet->getActiveSheet();


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
*/

$columnNumber =
    count($subjectNames) + (
        $rosterType === 'annual'
            ? 7
            : 6
    );


$lastColumn =
    Coordinate::stringFromColumnIndex(
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

    $headers[] =
        'Semester';
}


foreach ($subjectNames as $subjectName) {

    $headers[] =
        $subjectName;
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

$sumColumn =
    Coordinate::stringFromColumnIndex(
        $columnNumber - 2
    );


$averageColumn =
    Coordinate::stringFromColumnIndex(
        $columnNumber - 1
    );


$rankColumn =
    Coordinate::stringFromColumnIndex(
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


    foreach (
        $roster as $index => $student
    ) {

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
         * Semester key.
         */

        $semesterKey =
            $rosterType === 'first'
                ? 'first'
                : 'second';


        /*
         * Calculate Sum.
         */

        $sum =
            getSemesterTotal(
                $student,
                $semesterKey,
                $subjectRecords
            );


        /*
         * Calculate Average.
         */

        $average =
            getSemesterAverage(
                $student,
                $semesterKey,
                $subjectRecords
            );


        /*
         * Rank.
         */

        $rank =
            getSemesterRank(
                $student,
                $semesterKey
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


        foreach (
            $subjectRecords as $subject
        ) {

            $subjectId =
                $subject['id']
                ?? null;

            $subjectName =
                $subject['name']
                ?? '';


            /*
             * IMPORTANT:
             *
             * The subject ID is now passed to the
             * mark lookup.
             */

            $mark =
                getSemesterStudentMark(
                    $student,
                    $subjectId,
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


    foreach (
        $roster as $index => $student
    ) {

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

        $firstSum =
            getSemesterTotal(
                $student,
                'first',
                $subjectRecords
            );


        $firstAverage =
            getSemesterAverage(
                $student,
                'first',
                $subjectRecords
            );


        $firstRank =
            getSemesterRank(
                $student,
                'first'
            );


        /*
         * Second Semester
         */

        $secondSum =
            getSemesterTotal(
                $student,
                'second',
                $subjectRecords
            );


        $secondAverage =
            getSemesterAverage(
                $student,
                'second',
                $subjectRecords
            );


        $secondRank =
            getSemesterRank(
                $student,
                'second'
            );


        /*
         * Annual
         */

        $annualSum =
            getAnnualTotal(
                $student,
                $subjectRecords
            );


        $annualAverage =
            getAnnualAverage(
                $student,
                $subjectRecords
            );


        $annualRank =
            getAnnualRank(
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
         * First Semester
         */

        $sheet->setCellValue(
            'D' . $currentRow,
            '1st Semester'
        );


        $subjectColumnIndex = 5;


        foreach (
            $subjectRecords as $subject
        ) {

            $subjectId =
                $subject['id']
                ?? null;

            $subjectName =
                $subject['name']
                ?? '';


            $mark =
                getAnnualSemesterMark(
                    $student,
                    'first',
                    $subjectId,
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
         * Second Semester
         */

        $secondRow =
            $currentRow + 1;


        $sheet->setCellValue(
            'D' . $secondRow,
            '2nd Semester'
        );


        $subjectColumnIndex = 5;


        foreach (
            $subjectRecords as $subject
        ) {

            $subjectId =
                $subject['id']
                ?? null;

            $subjectName =
                $subject['name']
                ?? '';


            $mark =
                getAnnualSemesterMark(
                    $student,
                    'second',
                    $subjectId,
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
         * Annual Average
         */

        $annualRow =
            $currentRow + 2;


        $sheet->setCellValue(
            'D' . $annualRow,
            'Annual Average'
        );


        $subjectColumnIndex = 5;


        foreach (
            $subjectRecords as $subject
        ) {

            $subjectId =
                $subject['id']
                ?? null;

            $subjectName =
                $subject['name']
                ?? '';


            $annualMark =
                getAnnualSubjectMark(
                    $student,
                    $subjectId,
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

$writer =
    new Xlsx(
        $spreadsheet
    );


$writer->save(
    'php://output'
);


$spreadsheet->disconnectWorksheets();

unset($spreadsheet);

exit;