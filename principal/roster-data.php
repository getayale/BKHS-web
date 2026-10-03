<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Roster Data
|--------------------------------------------------------------------------
|
| Handles:
| - Academic years
| - Grades
| - Sections
| - Semesters
| - Subjects
| - Students
| - Results
| - First Semester roster
| - Second Semester roster
| - Annual roster
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Academic Years
|--------------------------------------------------------------------------
*/

function getRosterAcademicYears(mysqli $conn): array
{
    $sql = "
        SELECT
            id,
            name,
            status
        FROM academic_years
        ORDER BY start_year DESC, id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    $academicYears = [];

    while ($row = $result->fetch_assoc()) {
        $academicYears[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status']
        ];
    }

    $result->free();

    return $academicYears;
}


/*
|--------------------------------------------------------------------------
| Grades
|--------------------------------------------------------------------------
*/

function getRosterGrades(mysqli $conn): array
{
    $sql = "
        SELECT
            id,
            name,
            grade_number
        FROM grades
        ORDER BY grade_number ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    $grades = [];

    while ($row = $result->fetch_assoc()) {
        $grades[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'grade_number' => (int) $row['grade_number']
        ];
    }

    $result->free();

    return $grades;
}


/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

function getRosterSections(mysqli $conn): array
{
    $sql = "
        SELECT
            id,
            name,
            code
        FROM sections
        ORDER BY code ASC, id ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    $sections = [];

    while ($row = $result->fetch_assoc()) {
        $sections[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'code' => (string) $row['code']
        ];
    }

    $result->free();

    return $sections;
}


/*
|--------------------------------------------------------------------------
| Get Semester
|--------------------------------------------------------------------------
*/

function getRosterSemester(
    mysqli $conn,
    int $academicYearId,
    string $semesterName
): ?array {
    $stmt = $conn->prepare("
        SELECT
            id,
            academic_year_id,
            name,
            order_number,
            max_mark,
            status
        FROM semesters
        WHERE academic_year_id = ?
          AND name = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'is',
        $academicYearId,
        $semesterName
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();

    $semester = $result->fetch_assoc() ?: null;

    $stmt->close();

    if (!$semester) {
        return null;
    }

    return [
        'id' => (int) $semester['id'],
        'academic_year_id' => (int) $semester['academic_year_id'],
        'name' => (string) $semester['name'],
        'order_number' => (int) $semester['order_number'],
        'max_mark' => (int) $semester['max_mark'],
        'status' => (string) $semester['status']
    ];
}


/*
|--------------------------------------------------------------------------
| Check Semester Completion
|--------------------------------------------------------------------------
*/

function isSemesterCompleted(
    mysqli $conn,
    int $academicYearId,
    string $semesterName
): bool {
    $semester = getRosterSemester(
        $conn,
        $academicYearId,
        $semesterName
    );

    if (!$semester) {
        return false;
    }

    return strtolower(
        trim((string) $semester['status'])
    ) === 'completed';
}


/*
|--------------------------------------------------------------------------
| Check Annual Roster Readiness
|--------------------------------------------------------------------------
|
| Annual roster requires:
|
| - First Semester completed
| - Second Semester completed
|
|--------------------------------------------------------------------------
*/

function isAnnualRosterReady(
    mysqli $conn,
    int $academicYearId
): bool {
    return
        isSemesterCompleted(
            $conn,
            $academicYearId,
            'First Semester'
        )
        &&
        isSemesterCompleted(
            $conn,
            $academicYearId,
            'Second Semester'
        );
}


/*
|--------------------------------------------------------------------------
| Get Subjects
|--------------------------------------------------------------------------
|
| Subjects are retrieved dynamically from grade_subjects.
|
| IMPORTANT:
| The subject ID is preserved.
| We do NOT convert subjects to names only.
|
|--------------------------------------------------------------------------
*/

function getRosterSubjects(
    mysqli $conn,
    int $gradeId
): array {
    /*
    |--------------------------------------------------------------------------
    | Get Numeric Grade
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            grade_number
        FROM grades
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param(
        'i',
        $gradeId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();

    $grade = $result->fetch_assoc();

    $stmt->close();

    if (!$grade) {
        return [];
    }

    $gradeNumber = (int) $grade['grade_number'];


    /*
    |--------------------------------------------------------------------------
    | Get Active Subjects
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            id,
            subject_name
        FROM grade_subjects
        WHERE grade = ?
          AND is_active = 1
        ORDER BY id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param(
        'i',
        $gradeNumber
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();

    $subjects = [];

    while ($row = $result->fetch_assoc()) {

        $subjects[] = [
            'id' => (int) $row['id'],
            'name' => trim((string) $row['subject_name'])
        ];
    }

    $stmt->close();

    return $subjects;
}


/*
|--------------------------------------------------------------------------
| Get Students
|--------------------------------------------------------------------------
*/

function getRosterStudents(
    mysqli $conn,
    int $academicYearId,
    int $gradeId,
    int $sectionId
): array {
    $sql = "
        SELECT
            s.id AS student_id,
            s.student_code,
            s.full_name,
            sr.id AS registration_id
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND s.is_deleted = 0
        ORDER BY
            s.full_name ASC,
            s.id ASC,
            sr.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param(
        'iii',
        $academicYearId,
        $gradeId,
        $sectionId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();

    $students = [];

    $seenStudents = [];

    while ($row = $result->fetch_assoc()) {

        $studentId = (int) $row['student_id'];

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate student rows
        |--------------------------------------------------------------------------
        */

        if (isset($seenStudents[$studentId])) {
            continue;
        }

        $seenStudents[$studentId] = true;

        $students[] = [
            'student_id' => $studentId,
            'registration_id' => (int) $row['registration_id'],
            'student_code' => (string) $row['student_code'],
            'student_name' => (string) $row['full_name']
        ];
    }

    $stmt->close();

    return $students;
}


/*
|--------------------------------------------------------------------------
| Get Marks
|--------------------------------------------------------------------------
|
| Marks are retrieved using the actual database relationships:
|
| results.student_registration_id
| results.grade_subject_id
| results.semester_id
|
|--------------------------------------------------------------------------
*/

function getRosterMarks(
    mysqli $conn,
    array $registrationIds,
    array $subjectIds,
    int $semesterId
): array {

    if (
        empty($registrationIds) ||
        empty($subjectIds) ||
        $semesterId <= 0
    ) {
        return [];
    }


    /*
    |--------------------------------------------------------------------------
    | Clean Registration IDs
    |--------------------------------------------------------------------------
    */

    $registrationIds = array_values(
        array_unique(
            array_map(
                'intval',
                $registrationIds
            )
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Clean Subject IDs
    |--------------------------------------------------------------------------
    */

    $subjectIds = array_values(
        array_unique(
            array_map(
                'intval',
                $subjectIds
            )
        )
    );


    if (
        empty($registrationIds) ||
        empty($subjectIds)
    ) {
        return [];
    }


    /*
    |--------------------------------------------------------------------------
    | Dynamic IN placeholders
    |--------------------------------------------------------------------------
    */

    $registrationPlaceholders = implode(
        ',',
        array_fill(
            0,
            count($registrationIds),
            '?'
        )
    );

    $subjectPlaceholders = implode(
        ',',
        array_fill(
            0,
            count($subjectIds),
            '?'
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Query Results
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            r.student_registration_id,
            r.grade_subject_id,
            r.mark
        FROM results r
        WHERE r.semester_id = ?
          AND r.student_registration_id IN (
              {$registrationPlaceholders}
          )
          AND r.grade_subject_id IN (
              {$subjectPlaceholders}
          )
        ORDER BY
            r.student_registration_id ASC,
            r.grade_subject_id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }


    /*
    |--------------------------------------------------------------------------
    | Bind Parameters
    |--------------------------------------------------------------------------
    */

    $types = 'i';

    $params = [
        $semesterId
    ];

    foreach ($registrationIds as $registrationId) {
        $types .= 'i';
        $params[] = $registrationId;
    }

    foreach ($subjectIds as $subjectId) {
        $types .= 'i';
        $params[] = $subjectId;
    }


    /*
    |--------------------------------------------------------------------------
    | mysqli bind_param requires references
    |--------------------------------------------------------------------------
    */

    $bindParams = [];

    $bindParams[] = $types;

    foreach ($params as $key => &$value) {
        $bindParams[] = &$value;
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindParams
    );

    unset($value);


    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();

    $marks = [];


    /*
    |--------------------------------------------------------------------------
    | Build:
    |
    | [registration_id][grade_subject_id] = mark
    |--------------------------------------------------------------------------
    */

    while ($row = $result->fetch_assoc()) {

        $registrationId =
            (int) $row['student_registration_id'];

        $subjectId =
            (int) $row['grade_subject_id'];

        if (!isset($marks[$registrationId])) {
            $marks[$registrationId] = [];
        }

        $marks[$registrationId][$subjectId] =
            $row['mark'] !== null
                ? (float) $row['mark']
                : null;
    }

    $stmt->close();

    return $marks;
}


/*
|--------------------------------------------------------------------------
| Build Semester Roster
|--------------------------------------------------------------------------
*/

function buildSemesterRoster(
    array $students,
    array $subjects,
    array $marks
): array {

    $roster = [];

    $subjectCount = count($subjects);

    if ($subjectCount === 0) {
        return [];
    }


    foreach ($students as $student) {

        $registrationId =
            (int) $student['registration_id'];

        /*
        |--------------------------------------------------------------------------
        | Keep marks keyed by SUBJECT ID
        |--------------------------------------------------------------------------
        |
        | This is the important correction.
        |
        | No subject-name matching is used.
        |
        |--------------------------------------------------------------------------
        */

        $studentSubjects = [];

        $sum = 0.0;

        $allSubjectsMarked = true;


        foreach ($subjects as $subject) {

            $subjectId =
                (int) $subject['id'];

            $mark =
                $marks[$registrationId][$subjectId]
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Store using subject ID
            |--------------------------------------------------------------------------
            */

            $studentSubjects[$subjectId] =
                $mark;

            if ($mark === null) {

                $allSubjectsMarked = false;

            } else {

                $sum += (float) $mark;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Average
        |--------------------------------------------------------------------------
        */

        $average = null;

        if ($allSubjectsMarked) {

            $average =
                $sum / $subjectCount;
        }


        $roster[] = [

            'row_number' => 0,

            'student_id' =>
                (int) $student['student_id'],

            'registration_id' =>
                $registrationId,

            'student_code' =>
                (string) $student['student_code'],

            'student_name' =>
                (string) $student['student_name'],

            /*
            |------------------------------------------------------------------
            | Subject marks keyed by grade_subject_id
            |------------------------------------------------------------------
            */

            'subjects' =>
                $studentSubjects,

            'sum' =>
                $allSubjectsMarked
                    ? $sum
                    : null,

            'average' =>
                $average,

            'rank' =>
                null
        ];
    }


    assignSemesterRanks($roster);

    sortRosterAlphabetically($roster);

    assignRosterRowNumbers($roster);

    return $roster;
}


/*
|--------------------------------------------------------------------------
| Assign Semester Ranks
|--------------------------------------------------------------------------
*/

function assignSemesterRanks(
    array &$roster
): void {

    $rankRows = [];

    foreach ($roster as $student) {

        $rankRows[] = [

            'registration_id' =>
                (int) $student['registration_id'],

            'average' =>
                $student['average'] ?? null
        ];
    }


    usort(
        $rankRows,
        static function (
            array $a,
            array $b
        ): int {

            $averageA =
                $a['average'] ?? null;

            $averageB =
                $b['average'] ?? null;


            if (
                $averageA === null &&
                $averageB === null
            ) {
                return 0;
            }

            if ($averageA === null) {
                return 1;
            }

            if ($averageB === null) {
                return -1;
            }


            return
                (float) $averageB
                <=>
                (float) $averageA;
        }
    );


    $previousAverage = null;

    $rank = 0;

    $rankedCount = 0;


    foreach ($rankRows as $row) {

        $currentAverage =
            $row['average'] ?? null;


        if ($currentAverage === null) {
            continue;
        }


        $rankedCount++;


        if (
            $previousAverage === null ||
            (float) $currentAverage !==
            (float) $previousAverage
        ) {

            $rank = $rankedCount;
        }


        foreach ($roster as $key => $student) {

            if (
                (int) $student['registration_id'] ===
                (int) $row['registration_id']
            ) {

                $roster[$key]['rank'] =
                    $rank;

                break;
            }
        }


        $previousAverage =
            $currentAverage;
    }
}


/*
|--------------------------------------------------------------------------
| Build Annual Roster
|--------------------------------------------------------------------------
*/

function buildAnnualRoster(
    array $students,
    array $subjects,
    array $firstMarks,
    array $secondMarks
): array {

    $roster = [];

    $subjectCount = count($subjects);

    if ($subjectCount === 0) {
        return [];
    }


    foreach ($students as $student) {

        $registrationId =
            (int) $student['registration_id'];


        /*
        |--------------------------------------------------------------------------
        | First Semester
        |--------------------------------------------------------------------------
        */

        $firstSubjects = [];

        $firstSum = 0.0;

        $firstComplete = true;


        /*
        |--------------------------------------------------------------------------
        | Second Semester
        |--------------------------------------------------------------------------
        */

        $secondSubjects = [];

        $secondSum = 0.0;

        $secondComplete = true;


        /*
        |--------------------------------------------------------------------------
        | Annual
        |--------------------------------------------------------------------------
        */

        $annualSubjects = [];

        $annualSum = 0.0;

        $annualComplete = true;


        foreach ($subjects as $subject) {

            $subjectId =
                (int) $subject['id'];


            /*
            |--------------------------------------------------------------------------
            | First Semester Mark
            |--------------------------------------------------------------------------
            */

            $firstMark =
                $firstMarks[$registrationId][$subjectId]
                ?? null;

            $firstSubjects[$subjectId] =
                $firstMark;


            if ($firstMark === null) {

                $firstComplete = false;

            } else {

                $firstSum +=
                    (float) $firstMark;
            }


            /*
            |--------------------------------------------------------------------------
            | Second Semester Mark
            |--------------------------------------------------------------------------
            */

            $secondMark =
                $secondMarks[$registrationId][$subjectId]
                ?? null;

            $secondSubjects[$subjectId] =
                $secondMark;


            if ($secondMark === null) {

                $secondComplete = false;

            } else {

                $secondSum +=
                    (float) $secondMark;
            }


            /*
            |--------------------------------------------------------------------------
            | Annual Subject Mark
            |--------------------------------------------------------------------------
            |
            | Annual subject mark =
            |
            | (First Semester + Second Semester) / 2
            |
            |--------------------------------------------------------------------------
            */

            if (
                $firstMark !== null &&
                $secondMark !== null
            ) {

                $annualMark =
                    (
                        (float) $firstMark +
                        (float) $secondMark
                    ) / 2;


                $annualSubjects[$subjectId] =
                    $annualMark;


                $annualSum +=
                    $annualMark;

            } else {

                $annualSubjects[$subjectId] =
                    null;

                $annualComplete = false;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | First Average
        |--------------------------------------------------------------------------
        */

        $firstAverage = null;

        if ($firstComplete) {

            $firstAverage =
                $firstSum / $subjectCount;
        }


        /*
        |--------------------------------------------------------------------------
        | Second Average
        |--------------------------------------------------------------------------
        */

        $secondAverage = null;

        if ($secondComplete) {

            $secondAverage =
                $secondSum / $subjectCount;
        }


        /*
        |--------------------------------------------------------------------------
        | Annual Average
        |--------------------------------------------------------------------------
        */

        $annualAverage = null;

        if ($annualComplete) {

            $annualAverage =
                $annualSum / $subjectCount;
        }


        $roster[] = [

            'row_number' => 0,

            'student_id' =>
                (int) $student['student_id'],

            'registration_id' =>
                $registrationId,

            'student_code' =>
                (string) $student['student_code'],

            'student_name' =>
                (string) $student['student_name'],


            /*
            |------------------------------------------------------------------
            | Subject marks are keyed by grade_subject_id
            |------------------------------------------------------------------
            */

            'first_subjects' =>
                $firstSubjects,

            'first_sum' =>
                $firstComplete
                    ? $firstSum
                    : null,

            'first_average' =>
                $firstAverage,

            'first_rank' =>
                null,


            'second_subjects' =>
                $secondSubjects,

            'second_sum' =>
                $secondComplete
                    ? $secondSum
                    : null,

            'second_average' =>
                $secondAverage,

            'second_rank' =>
                null,


            'annual_subjects' =>
                $annualSubjects,

            'annual_sum' =>
                $annualComplete
                    ? $annualSum
                    : null,

            'annual_average' =>
                $annualAverage,

            'annual_rank' =>
                null
        ];
    }


    assignAnnualComponentRanks(
        $roster,
        'first'
    );

    assignAnnualComponentRanks(
        $roster,
        'second'
    );

    assignAnnualComponentRanks(
        $roster,
        'annual'
    );


    sortRosterAlphabetically($roster);

    assignRosterRowNumbers($roster);

    return $roster;
}


/*
|--------------------------------------------------------------------------
| Assign Annual Component Ranks
|--------------------------------------------------------------------------
*/

function assignAnnualComponentRanks(
    array &$roster,
    string $component
): void {

    $rankRows = [];


    foreach ($roster as $student) {

        if ($component === 'first') {

            $average =
                $student['first_average'] ?? null;

        } elseif ($component === 'second') {

            $average =
                $student['second_average'] ?? null;

        } else {

            $average =
                $student['annual_average'] ?? null;
        }


        $rankRows[] = [

            'registration_id' =>
                (int) $student['registration_id'],

            'average' =>
                $average
        ];
    }


    usort(
        $rankRows,
        static function (
            array $a,
            array $b
        ): int {

            $averageA =
                $a['average'] ?? null;

            $averageB =
                $b['average'] ?? null;


            if (
                $averageA === null &&
                $averageB === null
            ) {
                return 0;
            }

            if ($averageA === null) {
                return 1;
            }

            if ($averageB === null) {
                return -1;
            }


            return
                (float) $averageB
                <=>
                (float) $averageA;
        }
    );


    $previousAverage = null;

    $rank = 0;

    $rankedCount = 0;


    foreach ($rankRows as $row) {

        $currentAverage =
            $row['average'] ?? null;


        if ($currentAverage === null) {
            continue;
        }


        $rankedCount++;


        if (
            $previousAverage === null ||
            (float) $currentAverage !==
            (float) $previousAverage
        ) {

            $rank = $rankedCount;
        }


        foreach ($roster as $key => $student) {

            if (
                (int) $student['registration_id'] ===
                (int) $row['registration_id']
            ) {

                if ($component === 'first') {

                    $roster[$key]['first_rank'] =
                        $rank;

                } elseif ($component === 'second') {

                    $roster[$key]['second_rank'] =
                        $rank;

                } else {

                    $roster[$key]['annual_rank'] =
                        $rank;
                }

                break;
            }
        }


        $previousAverage =
            $currentAverage;
    }
}


/*
|--------------------------------------------------------------------------
| Sort Roster Alphabetically
|--------------------------------------------------------------------------
*/

function sortRosterAlphabetically(
    array &$roster
): void {

    usort(
        $roster,
        static function (
            array $a,
            array $b
        ): int {

            $nameComparison =
                strcasecmp(
                    (string) $a['student_name'],
                    (string) $b['student_name']
                );


            if ($nameComparison !== 0) {
                return $nameComparison;
            }


            return
                (int) $a['student_id']
                <=>
                (int) $b['student_id'];
        }
    );
}


/*
|--------------------------------------------------------------------------
| Assign Display Row Numbers
|--------------------------------------------------------------------------
*/

function assignRosterRowNumbers(
    array &$roster
): void {

    foreach ($roster as $index => &$student) {

        $student['row_number'] =
            $index + 1;
    }

    unset($student);
}


/*
|--------------------------------------------------------------------------
| Get Complete Roster
|--------------------------------------------------------------------------
*/

function getRoster(
    mysqli $conn,
    int $academicYearId,
    int $gradeId,
    int $sectionId,
    string $rosterType
): array {

    $rosterType =
        strtolower(
            trim($rosterType)
        );


    if (
        !in_array(
            $rosterType,
            ['first', 'second', 'annual'],
            true
        )
    ) {

        return [
            'students' => [],
            'subjects' => []
        ];
    }


    if (
        $academicYearId <= 0 ||
        $gradeId <= 0 ||
        $sectionId <= 0
    ) {

        return [
            'students' => [],
            'subjects' => []
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */

    $students =
        getRosterStudents(
            $conn,
            $academicYearId,
            $gradeId,
            $sectionId
        );


    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */

    $subjects =
        getRosterSubjects(
            $conn,
            $gradeId
        );


    if (empty($subjects)) {

        return [
            'students' => [],
            'subjects' => []
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | No students
    |--------------------------------------------------------------------------
    |
    | Keep the complete subject records.
    |
    |--------------------------------------------------------------------------
    */

    if (empty($students)) {

        return [
            'students' => [],
            'subjects' => $subjects
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Registration IDs
    |--------------------------------------------------------------------------
    */

    $registrationIds = array_map(
        static function (
            array $student
        ): int {

            return (int) $student['registration_id'];

        },
        $students
    );


    /*
    |--------------------------------------------------------------------------
    | Subject IDs
    |--------------------------------------------------------------------------
    */

    $subjectIds = array_map(
        static function (
            array $subject
        ): int {

            return (int) $subject['id'];

        },
        $subjects
    );


    /*
    |--------------------------------------------------------------------------
    | First Semester
    |--------------------------------------------------------------------------
    */

    if ($rosterType === 'first') {

        $semester =
            getRosterSemester(
                $conn,
                $academicYearId,
                'First Semester'
            );


        if (!$semester) {

            return [
                'students' => [],
                'subjects' => $subjects
            ];
        }


        $marks =
            getRosterMarks(
                $conn,
                $registrationIds,
                $subjectIds,
                (int) $semester['id']
            );


        return [

            'students' =>
                buildSemesterRoster(
                    $students,
                    $subjects,
                    $marks
                ),

            'subjects' =>
                $subjects
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Second Semester
    |--------------------------------------------------------------------------
    */

    if ($rosterType === 'second') {

        $semester =
            getRosterSemester(
                $conn,
                $academicYearId,
                'Second Semester'
            );


        if (!$semester) {

            return [
                'students' => [],
                'subjects' => $subjects
            ];
        }


        $marks =
            getRosterMarks(
                $conn,
                $registrationIds,
                $subjectIds,
                (int) $semester['id']
            );


        return [

            'students' =>
                buildSemesterRoster(
                    $students,
                    $subjects,
                    $marks
                ),

            'subjects' =>
                $subjects
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Annual Roster
    |--------------------------------------------------------------------------
    */

    if ($rosterType === 'annual') {

        if (
            !isAnnualRosterReady(
                $conn,
                $academicYearId
            )
        ) {

            return [
                'students' => [],
                'subjects' => $subjects
            ];
        }


        $firstSemester =
            getRosterSemester(
                $conn,
                $academicYearId,
                'First Semester'
            );


        $secondSemester =
            getRosterSemester(
                $conn,
                $academicYearId,
                'Second Semester'
            );


        if (
            !$firstSemester ||
            !$secondSemester
        ) {

            return [
                'students' => [],
                'subjects' => $subjects
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | First Semester Marks
        |--------------------------------------------------------------------------
        */

        $firstMarks =
            getRosterMarks(
                $conn,
                $registrationIds,
                $subjectIds,
                (int) $firstSemester['id']
            );


        /*
        |--------------------------------------------------------------------------
        | Second Semester Marks
        |--------------------------------------------------------------------------
        */

        $secondMarks =
            getRosterMarks(
                $conn,
                $registrationIds,
                $subjectIds,
                (int) $secondSemester['id']
            );


        return [

            'students' =>
                buildAnnualRoster(
                    $students,
                    $subjects,
                    $firstMarks,
                    $secondMarks
                ),

            'subjects' =>
                $subjects
        ];
    }


    return [
        'students' => [],
        'subjects' => $subjects
    ];
}


/*
|--------------------------------------------------------------------------
| Normalize Roster Marks
|--------------------------------------------------------------------------
|
| Supports both:
|
| 1. A single numeric mark
| 2. A subject => mark array
|
| This prevents arrays from being incorrectly converted to null.
|
|--------------------------------------------------------------------------
*/

function normalizeRosterMarks(
    mixed $marks
): array {

    /*
    |--------------------------------------------------------------------------
    | Not an array
    |--------------------------------------------------------------------------
    |
    | The Roster page may sometimes pass a single value.
    | Return an empty map because this helper represents a collection
    | of subject marks.
    |
    |--------------------------------------------------------------------------
    */

    if (!is_array($marks)) {
        return [];
    }


    $normalized = [];


    foreach ($marks as $subjectId => $mark) {

        /*
        |--------------------------------------------------------------------------
        | Preserve subject ID
        |--------------------------------------------------------------------------
        */

        $subjectKey = (int) $subjectId;


        /*
        |--------------------------------------------------------------------------
        | Handle nested mark structures if present
        |--------------------------------------------------------------------------
        */

        if (is_array($mark)) {

            if (
                array_key_exists(
                    'mark',
                    $mark
                )
            ) {

                $mark =
                    $mark['mark'];

            } elseif (
                array_key_exists(
                    'value',
                    $mark
                )
            ) {

                $mark =
                    $mark['value'];

            } else {

                $mark = null;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Empty mark
        |--------------------------------------------------------------------------
        */

        if (
            $mark === null ||
            $mark === ''
        ) {

            $normalized[$subjectKey] =
                null;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Numeric mark
        |--------------------------------------------------------------------------
        */

        if (is_numeric($mark)) {

            $normalized[$subjectKey] =
                (float) $mark;

        } else {

            $normalized[$subjectKey] =
                null;
        }
    }


    return $normalized;
}


/*
|--------------------------------------------------------------------------
| Format Mark
|--------------------------------------------------------------------------
*/

function formatRosterMark(
    mixed $mark
): string {

    if (
        $mark === null ||
        $mark === ''
    ) {
        return '—';
    }


    if (!is_numeric($mark)) {
        return '—';
    }


    $value = (float) $mark;


    if (floor($value) === $value) {

        return (string) (int) $value;
    }


    return number_format(
        $value,
        2,
        '.',
        ''
    );
}


/*
|--------------------------------------------------------------------------
| Format Number
|--------------------------------------------------------------------------
*/

function formatRosterNumber(
    mixed $value
): string {

    if (
        $value === null ||
        $value === ''
    ) {
        return '—';
    }


    if (!is_numeric($value)) {
        return '—';
    }


    $number = (float) $value;


    if (floor($number) === $number) {

        return (string) (int) $number;
    }


    return number_format(
        $number,
        2,
        '.',
        ''
    );
}


/*
|--------------------------------------------------------------------------
| Format Rank
|--------------------------------------------------------------------------
*/

function formatRosterRank(
    mixed $rank
): string {

    if (
        $rank === null ||
        $rank === ''
    ) {
        return '—';
    }


    if (!is_numeric($rank)) {
        return '—';
    }


    return (string) (int) $rank;
}

