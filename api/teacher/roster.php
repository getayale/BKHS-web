<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/localhost(?::\d+)?$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true,
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../registrar/roster-data.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| API Response Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Only GET requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Bearer Token Authentication
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();

    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authorizationHeader = trim(
                (string) $value
            );

            break;
        }
    }
}

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {
    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$tokenHash = hash(
    'sha256',
    $rawToken
);

$tokenSql = "
    SELECT
        at.user_id,
        u.role,
        u.is_deleted
    FROM api_tokens AS at
    INNER JOIN users AS u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
";

$tokenStmt = $conn->prepare($tokenSql);

if (!$tokenStmt) {
    jsonResponse(
        false,
        'Database error.',
        [],
        500
    );
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

$tokenStmt->execute();

$tokenResult = $tokenStmt->get_result();

$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower(
        (string) $tokenUser['role']
    ) !== 'teacher'
) {
    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Request Parameters
|--------------------------------------------------------------------------
*/

$rosterType = strtolower(
    trim(
        (string) (
            $_GET['roster_type'] ?? 'first'
        )
    )
);

$allowedRosterTypes = [
    'first',
    'second',
    'annual',
];

if (
    !in_array(
        $rosterType,
        $allowedRosterTypes,
        true
    )
) {
    jsonResponse(
        false,
        'Invalid roster type. Allowed values are first, second, and annual.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Export Mode
|--------------------------------------------------------------------------
*/

$exportType = strtolower(
    trim(
        (string) (
            $_GET['export'] ?? ''
        )
    )
);

$isExcelExport = $exportType === 'xlsx';

/*
|--------------------------------------------------------------------------
| Active Academic Year
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
    jsonResponse(
        false,
        'No active academic year was found.',
        [],
        404
    );
}

$academicYearId = (int) $academicYear['id'];

$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Teacher Active Homeroom
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
    jsonResponse(
        false,
        'No active homeroom assignment was found for your account in the current academic year.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Homeroom Information
|--------------------------------------------------------------------------
*/

$academicYearId = (int) $homeroom['academic_year_id'];

$academicYearName = (string) $homeroom['academic_year_name'];

$gradeId = (int) $homeroom['grade_id'];

$sectionId = (int) $homeroom['section_id'];

$gradeNumber = (int) $homeroom['grade_number'];

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
| Annual Roster Validation
|--------------------------------------------------------------------------
*/

if ($rosterType === 'annual') {
    if (!function_exists('isAnnualRosterReady')) {
        jsonResponse(
            false,
            'Annual roster validation function is unavailable.',
            [],
            500
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
        jsonResponse(
            false,
            'Annual roster is not available. First Semester and Second Semester must both be completed.',
            [
                'roster_type' => 'annual',
                'academic_year' => $academicYearName,
                'grade' => $gradeName,
                'section' => $sectionName,
            ],
            409
        );
    }
}

/*
|--------------------------------------------------------------------------
| Get Roster
|--------------------------------------------------------------------------
*/

if (!function_exists('getRoster')) {
    jsonResponse(
        false,
        'Roster data function is unavailable.',
        [],
        500
    );
}

try {
    $rosterResult = getRoster(
        $conn,
        $academicYearId,
        $gradeId,
        $sectionId,
        $rosterType
    );
} catch (Throwable $e) {
    jsonResponse(
        false,
        'Unable to load roster data.',
        [],
        500
    );
}

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
| Mark Helpers
|--------------------------------------------------------------------------
*/

function nullableFloat(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);

        if ($value === '') {
            return null;
        }
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function extractMarkValue(mixed $value): ?float
{
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
            $mark = nullableFloat(
                $value[$key]
            );

            if ($mark !== null) {
                return $mark;
            }
        }
    }

    return null;
}

function getMarkFromCollection(
    mixed $collection,
    string $subjectName
): ?float {
    if (!is_array($collection)) {
        return null;
    }

    if (array_key_exists($subjectName, $collection)) {
        return extractMarkValue(
            $collection[$subjectName]
        );
    }

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

    foreach ($collection as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim(
            (string) (
                $item['subject_name']
                ?? $item['name']
                ?? $item['subject']
                ?? ''
            )
        );

        if (
            $name !== '' &&
            strcasecmp(
                $name,
                $subjectName
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
    $collections = [
        $student['subjects'] ?? null,
        $student['marks'] ?? null,
        $student['first_subjects'] ?? null,
        $student['second_subjects'] ?? null,
        $student['first_marks'] ?? null,
        $student['second_marks'] ?? null,

        (
            isset($student['first_semester']) &&
            is_array($student['first_semester'])
        )
            ? (
                $student['first_semester']['subjects']
                ?? null
            )
            : null,

        (
            isset($student['first_semester']) &&
            is_array($student['first_semester'])
        )
            ? (
                $student['first_semester']['marks']
                ?? null
            )
            : null,

        (
            isset($student['second_semester']) &&
            is_array($student['second_semester'])
        )
            ? (
                $student['second_semester']['subjects']
                ?? null
            )
            : null,

        (
            isset($student['second_semester']) &&
            is_array($student['second_semester'])
        )
            ? (
                $student['second_semester']['marks']
                ?? null
            )
            : null,
    ];

    foreach ($collections as $collection) {
        $mark = getMarkFromCollection(
            $collection,
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }

    if (function_exists('normalizeRosterMarks')) {
        try {
            $normalized = normalizeRosterMarks($student);

            $mark = getMarkFromCollection(
                $normalized,
                $subjectName
            );

            if ($mark !== null) {
                return $mark;
            }
        } catch (Throwable) {
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
    $subjectKey = $semesterKey . '_subjects';

    $marksKey = $semesterKey . '_marks';

    $semesterKeyName = $semesterKey . '_semester';

    $collections = [
        $student[$subjectKey] ?? null,

        (
            isset($student[$semesterKey]) &&
            is_array($student[$semesterKey])
        )
            ? (
                $student[$semesterKey]['subjects']
                ?? null
            )
            : null,

        (
            isset($student[$semesterKey]) &&
            is_array($student[$semesterKey])
        )
            ? (
                $student[$semesterKey]['marks']
                ?? null
            )
            : null,

        $student[$marksKey] ?? null,

        (
            isset($student[$semesterKeyName]) &&
            is_array($student[$semesterKeyName])
        )
            ? (
                $student[$semesterKeyName]['marks']
                ?? null
            )
            : null,
    ];

    foreach ($collections as $collection) {
        $mark = getMarkFromCollection(
            $collection,
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
    $collections = [
        $student['annual_subjects'] ?? null,

        (
            isset($student['annual']) &&
            is_array($student['annual'])
        )
            ? (
                $student['annual']['subjects']
                ?? null
            )
            : null,

        (
            isset($student['annual']) &&
            is_array($student['annual'])
        )
            ? (
                $student['annual']['marks']
                ?? null
            )
            : null,

        $student['annual_marks'] ?? null,
    ];

    foreach ($collections as $collection) {
        $mark = getMarkFromCollection(
            $collection,
            $subjectName
        );

        if ($mark !== null) {
            return $mark;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback:
    | Annual mark = average of first and second semester marks
    |--------------------------------------------------------------------------
    */

    $firstMark = getAnnualSemesterMark(
        $student,
        'first',
        $subjectName
    );

    $secondMark = getAnnualSemesterMark(
        $student,
        'second',
        $subjectName
    );

    if (
        $firstMark !== null &&
        $secondMark !== null
    ) {
        return (
            $firstMark +
            $secondMark
        ) / 2;
    }

    if ($firstMark !== null) {
        return $firstMark;
    }

    if ($secondMark !== null) {
        return $secondMark;
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
    array $subjectNames
): ?float {
    $topLevelKey = $semesterKey . '_sum';

    if (array_key_exists($topLevelKey, $student)) {
        $value = nullableFloat(
            $student[$topLevelKey]
        );

        if ($value !== null) {
            return $value;
        }
    }

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

    $semesterDataKey = $semesterKey . '_semester';

    if (
        isset($student[$semesterDataKey]) &&
        is_array($student[$semesterDataKey]) &&
        array_key_exists(
            'sum',
            $student[$semesterDataKey]
        )
    ) {
        $value = nullableFloat(
            $student[$semesterDataKey]['sum']
        );

        if ($value !== null) {
            return $value;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate from displayed marks
    |--------------------------------------------------------------------------
    */

    $total = 0.0;

    $found = false;

    foreach ($subjectNames as $subjectName) {
        $mark = getSemesterStudentMark(
            $student,
            $subjectName
        );

        if ($mark !== null) {
            $total += $mark;
            $found = true;
        }
    }

    return $found
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
    $topLevelKey = $semesterKey . '_average';

    if (array_key_exists($topLevelKey, $student)) {
        $value = nullableFloat(
            $student[$topLevelKey]
        );

        if ($value !== null) {
            return $value;
        }
    }

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

    $semesterDataKey = $semesterKey . '_semester';

    if (
        isset($student[$semesterDataKey]) &&
        is_array($student[$semesterDataKey]) &&
        array_key_exists(
            'average',
            $student[$semesterDataKey]
        )
    ) {
        $value = nullableFloat(
            $student[$semesterDataKey]['average']
        );

        if ($value !== null) {
            return $value;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate from displayed marks
    |--------------------------------------------------------------------------
    */

    $total = 0.0;

    $count = 0;

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
    $topLevelKey = $semesterKey . '_rank';

    if (array_key_exists($topLevelKey, $student)) {
        $value = nullableFloat(
            $student[$topLevelKey]
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    if (
        isset($student[$semesterKey]) &&
        is_array($student[$semesterKey]) &&
        array_key_exists(
            'rank',
            $student[$semesterKey]
        )
    ) {
        $value = nullableFloat(
            $student[$semesterKey]['rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    $semesterDataKey = $semesterKey . '_semester';

    if (
        isset($student[$semesterDataKey]) &&
        is_array($student[$semesterDataKey]) &&
        array_key_exists(
            'rank',
            $student[$semesterDataKey]
        )
    ) {
        $value = nullableFloat(
            $student[$semesterDataKey]['rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    if (array_key_exists('rank', $student)) {
        $value = nullableFloat(
            $student['rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    return null;
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
    if (array_key_exists('annual_sum', $student)) {
        $value = nullableFloat(
            $student['annual_sum']
        );

        if ($value !== null) {
            return $value;
        }
    }

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
    if (array_key_exists('annual_average', $student)) {
        $value = nullableFloat(
            $student['annual_average']
        );

        if ($value !== null) {
            return $value;
        }
    }

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
        $value = nullableFloat(
            $student['annual_rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    if (
        isset($student['annual']) &&
        is_array($student['annual']) &&
        array_key_exists(
            'rank',
            $student['annual']
        )
    ) {
        $value = nullableFloat(
            $student['annual']['rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    if (array_key_exists('rank', $student)) {
        $value = nullableFloat(
            $student['rank']
        );

        if ($value !== null) {
            return (int) $value;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Build Flutter-Friendly JSON Data
|--------------------------------------------------------------------------
*/

$students = [];

foreach ($roster as $student) {
    if (!is_array($student)) {
        continue;
    }

    $studentName = trim(
        (string) (
            $student['student_name']
            ?? $student['full_name']
            ?? ''
        )
    );

    /*
    |--------------------------------------------------------------------------
    | First / Second Semester
    |--------------------------------------------------------------------------
    */

    if (
        $rosterType === 'first' ||
        $rosterType === 'second'
    ) {
        $semesterKey = $rosterType;

        $marks = [];

        foreach ($subjectNames as $subjectName) {
            $marks[] = [
                'subject_name' => $subjectName,
                'mark' => getSemesterStudentMark(
                    $student,
                    $subjectName
                ),
            ];
        }

        $sum = getSemesterTotal(
            $student,
            $semesterKey,
            $subjectNames
        );

        $average = getSemesterAverage(
            $student,
            $semesterKey,
            $subjectNames
        );

        $rank = getSemesterRank(
            $student,
            $semesterKey
        );

        $students[] = [
            'no' => count($students) + 1,
            'student_name' => $studentName,
            'marks' => $marks,
            'sum' => $sum,
            'average' => $average,
            'rank' => $rank,
        ];

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Annual
    |--------------------------------------------------------------------------
    */

    if ($rosterType === 'annual') {
        $firstMarks = [];

        foreach ($subjectNames as $subjectName) {
            $firstMarks[] = [
                'subject_name' => $subjectName,
                'mark' => getAnnualSemesterMark(
                    $student,
                    'first',
                    $subjectName
                ),
            ];
        }

        $secondMarks = [];

        foreach ($subjectNames as $subjectName) {
            $secondMarks[] = [
                'subject_name' => $subjectName,
                'mark' => getAnnualSemesterMark(
                    $student,
                    'second',
                    $subjectName
                ),
            ];
        }

        $annualMarks = [];

        foreach ($subjectNames as $subjectName) {
            $annualMarks[] = [
                'subject_name' => $subjectName,
                'mark' => getAnnualSubjectMark(
                    $student,
                    $subjectName
                ),
            ];
        }

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

        $students[] = [
            'no' => count($students) + 1,
            'student_name' => $studentName,

            'first_semester' => [
                'marks' => $firstMarks,
                'sum' => $firstSum,
                'average' => $firstAverage,
                'rank' => $firstRank,
            ],

            'second_semester' => [
                'marks' => $secondMarks,
                'sum' => $secondSum,
                'average' => $secondAverage,
                'rank' => $secondRank,
            ],

            'annual' => [
                'marks' => $annualMarks,
                'sum' => $annualSum,
                'average' => $annualAverage,
                'rank' => $annualRank,
            ],
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Excel Export
|--------------------------------------------------------------------------
*/

if ($isExcelExport) {
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';

    if (!file_exists($autoloadPath)) {
        jsonResponse(
            false,
            'PhpSpreadsheet is not installed.',
            [],
            500
        );
    }

    require_once $autoloadPath;

    if (
        !class_exists(
            \PhpOffice\PhpSpreadsheet\Spreadsheet::class
        )
    ) {
        jsonResponse(
            false,
            'PhpSpreadsheet is unavailable.',
            [],
            500
        );
    }

    $spreadsheet =
        new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    $sheet =
        $spreadsheet->getActiveSheet();

    $sheet->setTitle('Roster');

    /*
    |--------------------------------------------------------------------------
    | Title
    |--------------------------------------------------------------------------
    */

    if ($rosterType === 'first') {
        $title =
            'First Semester Academic Result Roster';
    } elseif ($rosterType === 'second') {
        $title =
            'Second Semester Academic Result Roster';
    } else {
        $title =
            'Annual Academic Result Roster';
    }

    /*
    |--------------------------------------------------------------------------
    | Column Structure
    |--------------------------------------------------------------------------
    |
    | First / Second:
    |
    | A = No
    | B = Student Name
    | C... = Subjects
    | Last = Sum
    | Last+1 = Average
    | Last+2 = Rank
    |
    | Annual:
    |
    | A = No
    | B = Student Name
    | C = Semester
    | D... = Subjects
    | Last = Sum
    | Last+1 = Average
    | Last+2 = Rank
    |
    */

    $fixedColumns = $rosterType === 'annual'
        ? 3
        : 2;

    $lastColumnNumber =
        $fixedColumns +
        count($subjectNames) +
        3;

    $lastColumn =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            max(1, $lastColumnNumber)
        );

    /*
    |--------------------------------------------------------------------------
    | Title Rows
    |--------------------------------------------------------------------------
    */

    $sheet->mergeCells(
        'A1:' . $lastColumn . '1'
    );

    $sheet->setCellValue(
        'A1',
        'BOLE KALE HIWOT SCHOOL'
    );

    $sheet->mergeCells(
        'A2:' . $lastColumn . '2'
    );

    $sheet->setCellValue(
        'A2',
        $title
    );

    $sheet->mergeCells(
        'A3:' . $lastColumn . '3'
    );

    $sheet->setCellValue(
        'A3',
        'Academic Year: ' .
        $academicYearName .
        ' | Grade: ' .
        $gradeName .
        ' | Section: ' .
        $sectionCode
    );

    /*
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    */

    $headerRow = 5;

    $columnIndex = 1;

    $columnLetter =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex++
        );

    $sheet->setCellValue(
        $columnLetter . $headerRow,
        'No'
    );

    $columnLetter =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex++
        );

    $sheet->setCellValue(
        $columnLetter . $headerRow,
        'Student Name'
    );

    if ($rosterType === 'annual') {
        $columnLetter =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $columnIndex++
            );

        $sheet->setCellValue(
            $columnLetter . $headerRow,
            'Semester'
        );
    }

    foreach ($subjectNames as $subjectName) {
        $columnLetter =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $columnIndex++
            );

        $sheet->setCellValue(
            $columnLetter . $headerRow,
            $subjectName
        );
    }

    $columnLetter =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex++
        );

    $sheet->setCellValue(
        $columnLetter . $headerRow,
        'Sum'
    );

    $columnLetter =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex++
        );

    $sheet->setCellValue(
        $columnLetter . $headerRow,
        'Average'
    );

    $columnLetter =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $columnIndex++
        );

    $sheet->setCellValue(
        $columnLetter . $headerRow,
        'Rank'
    );

    /*
    |--------------------------------------------------------------------------
    | Styles
    |--------------------------------------------------------------------------
    |
    | No background colors are used.
    |
    */

    $titleStyle = [
        'font' => [
            'bold' => true,
            'size' => 14,
        ],
        'alignment' => [
            'horizontal' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];

    $subtitleStyle = [
        'font' => [
            'bold' => true,
            'size' => 12,
        ],
        'alignment' => [
            'horizontal' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];

    $headerStyle = [
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' =>
                    \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ],
        ],
    ];

    $bodyStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' =>
                    \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ],
        ],
        'alignment' => [
            'vertical' =>
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];

    $sheet->getStyle(
        'A1:' . $lastColumn . '1'
    )->applyFromArray($titleStyle);

    $sheet->getStyle(
        'A2:' . $lastColumn . '2'
    )->applyFromArray($subtitleStyle);

    $sheet->getStyle(
        'A3:' . $lastColumn . '3'
    )->applyFromArray($subtitleStyle);

    $sheet->getStyle(
        'A5:' . $lastColumn . '5'
    )->applyFromArray($headerStyle);

    /*
    |--------------------------------------------------------------------------
    | Write Excel Rows
    |--------------------------------------------------------------------------
    */

    $row = 6;

    $excelStudentNumber = 1;

    foreach ($roster as $student) {
        if (!is_array($student)) {
            continue;
        }

        $studentName = trim(
            (string) (
                $student['student_name']
                ?? $student['full_name']
                ?? ''
            )
        );

        /*
        |--------------------------------------------------------------------------
        | First / Second Semester
        |--------------------------------------------------------------------------
        */

        if (
            $rosterType === 'first' ||
            $rosterType === 'second'
        ) {
            $semesterKey = $rosterType;

            /*
            |--------------------------------------------------------------------------
            | No
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'A' . $row,
                $excelStudentNumber
            );

            /*
            |--------------------------------------------------------------------------
            | Student Name
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'B' . $row,
                $studentName
            );

            /*
            |--------------------------------------------------------------------------
            | Subjects
            |--------------------------------------------------------------------------
            */

            $column = 3;

            foreach ($subjectNames as $subjectName) {
                $mark = getSemesterStudentMark(
                    $student,
                    $subjectName
                );

                $columnLetter =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                        $column
                    );

                $sheet->setCellValue(
                    $columnLetter . $row,
                    $mark === null
                        ? ''
                        : $mark
                );

                $column++;
            }

            /*
            |--------------------------------------------------------------------------
            | Sum
            |--------------------------------------------------------------------------
            */

            $sum = getSemesterTotal(
                $student,
                $semesterKey,
                $subjectNames
            );

            /*
            |--------------------------------------------------------------------------
            | Average
            |--------------------------------------------------------------------------
            */

            $average = getSemesterAverage(
                $student,
                $semesterKey,
                $subjectNames
            );

            /*
            |--------------------------------------------------------------------------
            | Rank
            |--------------------------------------------------------------------------
            */

            $rank = getSemesterRank(
                $student,
                $semesterKey
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $row,
                $sum === null
                    ? ''
                    : $sum
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $row,
                $average === null
                    ? ''
                    : $average
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $row,
                $rank === null
                    ? ''
                    : $rank
            );

            $row++;

            $excelStudentNumber++;

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Annual
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Each student uses exactly THREE existing rows.
        |
        | Row 1 = 1st
        | Row 2 = 2nd
        | Row 3 = Annual
        |
        | A and B will be merged across these three rows.
        |
        */

        if ($rosterType === 'annual') {
            $studentStartRow = $row;

            $firstRow = $row;
            $secondRow = $row + 1;
            $annualRow = $row + 2;

            /*
            |--------------------------------------------------------------------------
            | No
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'A' . $firstRow,
                $excelStudentNumber
            );

            /*
            |--------------------------------------------------------------------------
            | Student Name
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'B' . $firstRow,
                $studentName
            );

            /*
            |--------------------------------------------------------------------------
            | FIRST SEMESTER
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'C' . $firstRow,
                '1st'
            );

            $column = 4;

            foreach ($subjectNames as $subjectName) {
                $mark = getAnnualSemesterMark(
                    $student,
                    'first',
                    $subjectName
                );

                $columnLetter =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                        $column++
                    );

                $sheet->setCellValue(
                    $columnLetter . $firstRow,
                    $mark === null
                        ? ''
                        : $mark
                );
            }

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

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $firstRow,
                $firstSum === null
                    ? ''
                    : $firstSum
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $firstRow,
                $firstAverage === null
                    ? ''
                    : $firstAverage
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $firstRow,
                $firstRank === null
                    ? ''
                    : $firstRank
            );

            /*
            |--------------------------------------------------------------------------
            | SECOND SEMESTER
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'C' . $secondRow,
                '2nd'
            );

            $column = 4;

            foreach ($subjectNames as $subjectName) {
                $mark = getAnnualSemesterMark(
                    $student,
                    'second',
                    $subjectName
                );

                $columnLetter =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                        $column++
                    );

                $sheet->setCellValue(
                    $columnLetter . $secondRow,
                    $mark === null
                        ? ''
                        : $mark
                );
            }

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

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $secondRow,
                $secondSum === null
                    ? ''
                    : $secondSum
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $secondRow,
                $secondAverage === null
                    ? ''
                    : $secondAverage
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $secondRow,
                $secondRank === null
                    ? ''
                    : $secondRank
            );

            /*
            |--------------------------------------------------------------------------
            | ANNUAL
            |--------------------------------------------------------------------------
            */

            $sheet->setCellValue(
                'C' . $annualRow,
                'Annual'
            );

            $column = 4;

            foreach ($subjectNames as $subjectName) {
                $mark = getAnnualSubjectMark(
                    $student,
                    $subjectName
                );

                $columnLetter =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                        $column++
                    );

                $sheet->setCellValue(
                    $columnLetter . $annualRow,
                    $mark === null
                        ? ''
                        : $mark
                );
            }

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

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $annualRow,
                $annualSum === null
                    ? ''
                    : $annualSum
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $annualRow,
                $annualAverage === null
                    ? ''
                    : $annualAverage
            );

            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $column++
                ) . $annualRow,
                $annualRank === null
                    ? ''
                    : $annualRank
            );

            /*
            |--------------------------------------------------------------------------
            | MERGE ONLY NO AND STUDENT NAME
            |--------------------------------------------------------------------------
            |
            | These are the same three rows already created above.
            | No rows are added.
            |
            */

            $sheet->mergeCells(
                'A' . $firstRow . ':A' . $annualRow
            );

            $sheet->mergeCells(
                'B' . $firstRow . ':B' . $annualRow
            );

            /*
            |--------------------------------------------------------------------------
            | Continue after exactly three rows
            |--------------------------------------------------------------------------
            */

            $row = $annualRow + 1;

            $excelStudentNumber++;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Data Range
    |--------------------------------------------------------------------------
    */

    $dataEndRow = max(
        5,
        $row - 1
    );

    /*
    |--------------------------------------------------------------------------
    | Apply Body Borders
    |--------------------------------------------------------------------------
    */

    if ($dataEndRow >= 6) {
        $sheet->getStyle(
            'A6:' . $lastColumn . $dataEndRow
        )->applyFromArray(
            $bodyStyle
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Re-apply borders to merged Annual No / Name cells
    |--------------------------------------------------------------------------
    */

    if (
        $rosterType === 'annual' &&
        $dataEndRow >= 6
    ) {
        for (
            $studentRow = 6;
            $studentRow <= $dataEndRow;
            $studentRow += 3
        ) {
            $studentEndRow = min(
                $studentRow + 2,
                $dataEndRow
            );

            if ($studentEndRow - $studentRow === 2) {
                $sheet->getStyle(
                    'A' . $studentRow . ':A' . $studentEndRow
                )->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' =>
                                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                    ],
                    'alignment' => [
                        'horizontal' =>
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' =>
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->getStyle(
                    'B' . $studentRow . ':B' . $studentEndRow
                )->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' =>
                                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                    ],
                    'alignment' => [
                        'horizontal' =>
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                        'vertical' =>
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ],
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Alignment
    |--------------------------------------------------------------------------
    */

    if ($dataEndRow >= 6) {
        $sheet->getStyle(
            'A6:' . $lastColumn . $dataEndRow
        )
            ->getAlignment()
            ->setVertical(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            );
    }

    if ($rosterType === 'annual' && $dataEndRow >= 6) {
        $sheet->getStyle(
            'C6:' . $lastColumn . $dataEndRow
        )
            ->getAlignment()
            ->setHorizontal(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
            );

        $sheet->getStyle(
            'C6:C' . $dataEndRow
        )
            ->getFont()
            ->setBold(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Number Formats
    |--------------------------------------------------------------------------
    */

    $sumColumn =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $fixedColumns +
            count($subjectNames) +
            1
        );

    $averageColumn =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $fixedColumns +
            count($subjectNames) +
            2
        );

    if ($dataEndRow >= 6) {
        $sheet->getStyle(
            $sumColumn .
            '6:' .
            $sumColumn .
            $dataEndRow
        )
            ->getNumberFormat()
            ->setFormatCode('0.##');

        $sheet->getStyle(
            $averageColumn .
            '6:' .
            $averageColumn .
            $dataEndRow
        )
            ->getNumberFormat()
            ->setFormatCode('0.##');
    }

    /*
    |--------------------------------------------------------------------------
    | Column Widths
    |--------------------------------------------------------------------------
    */

    $sheet->getColumnDimension('A')
        ->setWidth(7);

    $sheet->getColumnDimension('B')
        ->setWidth(28);

    if ($rosterType === 'annual') {
        $sheet->getColumnDimension('C')
            ->setWidth(14);

        $subjectStartColumn = 4;
    } else {
        $subjectStartColumn = 3;
    }

    for (
        $i = $subjectStartColumn;
        $i <= $fixedColumns + count($subjectNames);
        $i++
    ) {
        $columnLetter =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                $i
            );

        $sheet->getColumnDimension(
            $columnLetter
        )->setWidth(15);
    }

    $sheet->getColumnDimension(
        $sumColumn
    )->setWidth(12);

    $sheet->getColumnDimension(
        $averageColumn
    )->setWidth(12);

    $rankColumn =
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            $fixedColumns +
            count($subjectNames) +
            3
        );

    $sheet->getColumnDimension(
        $rankColumn
    )->setWidth(10);

    /*
    |--------------------------------------------------------------------------
    | Row Heights
    |--------------------------------------------------------------------------
    */

    $sheet->getRowDimension(1)
        ->setRowHeight(24);

    $sheet->getRowDimension(2)
        ->setRowHeight(22);

    $sheet->getRowDimension(3)
        ->setRowHeight(22);

    $sheet->getRowDimension(5)
        ->setRowHeight(30);

    if ($dataEndRow >= 6) {
        for ($i = 6; $i <= $dataEndRow; $i++) {
            $sheet->getRowDimension($i)
                ->setRowHeight(22);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Page Setup
    |--------------------------------------------------------------------------
    */

    $sheet->getPageSetup()
        ->setOrientation(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
        );

    $sheet->getPageSetup()
        ->setPaperSize(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4
        );

    $sheet->getPageSetup()
        ->setFitToWidth(1);

    $sheet->getPageSetup()
        ->setFitToHeight(0);

    $sheet->getPageMargins()
        ->setTop(0.4)
        ->setRight(0.3)
        ->setBottom(0.4)
        ->setLeft(0.3);

    /*
    |--------------------------------------------------------------------------
    | Freeze Header
    |--------------------------------------------------------------------------
    */

    $sheet->freezePane('A6');

    /*
    |--------------------------------------------------------------------------
    | Filename
    |--------------------------------------------------------------------------
    |
    | Grade7_A_First.xlsx
    | Grade8_B_Second.xlsx
    | Grade12_C_Annual.xlsx
    |
    */

    $safeGradeNumber = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        (string) $gradeNumber
    );

    $safeSection = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        $sectionCode
    );

    $safeRosterType = ucfirst(
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            $rosterType
        )
    );

    $filename =
        'Grade' .
        $safeGradeNumber .
        '_' .
        $safeSection .
        '_' .
        $safeRosterType .
        '.xlsx';

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
        'Cache-Control: no-store, no-cache, must-revalidate'
    );

    header('Pragma: no-cache');

    header('Expires: 0');

    $writer =
        new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(
            $spreadsheet
        );

    $writer->save('php://output');

    exit;
}

/*
|--------------------------------------------------------------------------
| Final JSON Response
|--------------------------------------------------------------------------
*/

$teacherName = '';

$teacherSql = "
    SELECT
        full_name
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'teacher'
      AND is_deleted = 0
    LIMIT 1
";

$teacherStmt = $conn->prepare($teacherSql);

if ($teacherStmt) {
    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacher = $teacherStmt
        ->get_result()
        ->fetch_assoc();

    $teacherStmt->close();

    if ($teacher) {
        $teacherName = trim(
            (string) (
                $teacher['full_name']
                ?? ''
            )
        );
    }
}

jsonResponse(
    true,
    'Roster loaded successfully.',
    [
        'teacher' => [
            'id' => $teacherUserId,
            'full_name' => $teacherName,
        ],

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
            'status' => (string) (
                $academicYear['status']
                ?? 'Active'
            ),
        ],

        'homeroom' => [
            'id' => (int) $homeroom['homeroom_id'],
            'grade_id' => $gradeId,
            'grade_number' => $gradeNumber,
            'grade_name' => $gradeName,
            'section_id' => $sectionId,
            'section_name' => $sectionName,
            'section_code' => $sectionCode,
        ],

        'roster_type' => $rosterType,

        'title' => match ($rosterType) {
            'first' =>
                'First Semester Academic Result Roster',

            'second' =>
                'Second Semester Academic Result Roster',

            'annual' =>
                'Annual Academic Result Roster',

            default =>
                'Academic Result Roster',
        },

        'subjects' => array_map(
            static fn(
                string $subjectName
            ): array => [
                'name' => $subjectName,
            ],
            $subjectNames
        ),

        'students' => $students,

        'total_students' => count($students),

        'export' => [
            'available' => true,
            'format' => 'xlsx',
            'url' =>
                'roster.php?roster_type=' .
                rawurlencode($rosterType) .
                '&export=xlsx',
        ],
    ]
);
?>
