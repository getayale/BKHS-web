<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| transcript-data.php
|--------------------------------------------------------------------------
| Provides complete student transcript data.
|
| Transcript:
| - No academic year selection
| - No semester selection
| - Retrieves all academic years automatically
| - One academic year = one transcript page
| - Shows First Semester and Second Semester only
| - Mid Semester and Quarter Semester are excluded
| - Historical academic records are preserved
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';


/*
|--------------------------------------------------------------------------
| Get Student
|--------------------------------------------------------------------------
*/

function getTranscriptStudent(
    mysqli $conn,
    int $studentId
): ?array {

    $stmt = $conn->prepare("
        SELECT
            id,
            student_code,
            full_name,
            date_of_birth,
            gender,
            photo_path
        FROM students
        WHERE id = ?
          AND is_deleted = 0
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare student query: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'i',
        $studentId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to load student: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    $student = $result->fetch_assoc();

    $stmt->close();

    return $student ?: null;
}


/*
|--------------------------------------------------------------------------
| Get All Student Registrations
|--------------------------------------------------------------------------
|
| Every registration identifies:
| - Academic Year
| - Grade
| - Section
|
| The transcript automatically includes every registration belonging
| to the student.
|--------------------------------------------------------------------------
*/

function getTranscriptRegistrations(
    mysqli $conn,
    int $studentId
): array {

    $stmt = $conn->prepare("
        SELECT
            sr.id AS registration_id,

            sr.student_id,
            sr.academic_year_id,
            sr.grade_id,
            sr.section_id,

            sr.registration_type,
            sr.result,
            sr.registration_date,

            ay.name AS academic_year_name,
            ay.start_year,
            ay.start_month,
            ay.start_day,

            g.name AS grade_name,
            g.grade_number,

            s.name AS section_name,
            s.code AS section_code

        FROM student_registrations sr

        INNER JOIN academic_years ay
            ON ay.id = sr.academic_year_id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections s
            ON s.id = sr.section_id

        WHERE sr.student_id = ?

        ORDER BY
            ay.start_year ASC,
            ay.start_month ASC,
            ay.start_day ASC,
            sr.id ASC
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare registration query: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'i',
        $studentId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to load student registrations: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    $registrations = [];

    while ($row = $result->fetch_assoc()) {

        $registrations[] = $row;

    }

    $stmt->close();

    return $registrations;
}


/*
|--------------------------------------------------------------------------
| Get Results For One Registration
|--------------------------------------------------------------------------
|
| Only First Semester and Second Semester are included.
|
| Mid Semester:
|     excluded
|
| Quarter Semester:
|     excluded
|--------------------------------------------------------------------------
*/

function getTranscriptResults(
    mysqli $conn,
    int $registrationId
): array {

    $stmt = $conn->prepare("
        SELECT
            r.id AS result_id,

            r.student_registration_id,
            r.grade_subject_id,
            r.semester_id,

            r.mark,

            gs.subject_name,

            sem.name AS semester_name,
            sem.order_number AS semester_order,
            sem.max_mark

        FROM results r

        INNER JOIN grade_subjects gs
            ON gs.id = r.grade_subject_id

        INNER JOIN semesters sem
            ON sem.id = r.semester_id

        WHERE r.student_registration_id = ?

          AND sem.name IN (
              'First Semester',
              'Second Semester'
          )

        ORDER BY
            gs.subject_name ASC,
            sem.order_number ASC,
            r.id ASC
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare results query: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'i',
        $registrationId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to load transcript results: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    $results = [];

    while ($row = $result->fetch_assoc()) {

        $results[] = $row;

    }

    $stmt->close();

    return $results;
}


/*
|--------------------------------------------------------------------------
| Build Subject-Based Academic Record
|--------------------------------------------------------------------------
|
| Converts:
|
| Subject A + First Semester
| Subject A + Second Semester
|
| into:
|
| Subject A
|    First Semester
|    Second Semester
|    Annual Average
|--------------------------------------------------------------------------
*/

function buildTranscriptSubjects(
    array $results
): array {

    $subjects = [];


    foreach ($results as $result) {

        $subjectName = trim(
            (string) $result['subject_name']
        );


        if ($subjectName === '') {
            continue;
        }


        if (!isset($subjects[$subjectName])) {

            $subjects[$subjectName] = [

                'subject_name' =>
                    $subjectName,

                'first_semester' =>
                    null,

                'second_semester' =>
                    null,

                'annual_average' =>
                    null

            ];
        }


        $semesterName = trim(
            (string) $result['semester_name']
        );


        $mark = is_numeric($result['mark'])
            ? (float) $result['mark']
            : null;


        if ($semesterName === 'First Semester') {

            $subjects[$subjectName]['first_semester'] =
                $mark;

        } elseif ($semesterName === 'Second Semester') {

            $subjects[$subjectName]['second_semester'] =
                $mark;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate Annual Average Per Subject
    |--------------------------------------------------------------------------
    |
    | First Semester = /100
    | Second Semester = /100
    |
    | If both exist:
    |
    |     (First + Second) / 2
    |
    | If only one exists:
    |
    |     use the available mark
    |--------------------------------------------------------------------------
    */

    foreach ($subjects as &$subject) {

        $first =
            $subject['first_semester'];

        $second =
            $subject['second_semester'];


        if (
            $first !== null &&
            $second !== null
        ) {

            $subject['annual_average'] =
                ($first + $second) / 2;

        } elseif ($first !== null) {

            $subject['annual_average'] =
                $first;

        } elseif ($second !== null) {

            $subject['annual_average'] =
                $second;

        } else {

            $subject['annual_average'] =
                null;
        }
    }

    unset($subject);


    return array_values($subjects);
}


/*
|--------------------------------------------------------------------------
| Calculate Semester Average
|--------------------------------------------------------------------------
*/

function calculateSemesterAverage(
    array $subjects,
    string $semesterKey
): ?float {

    $total = 0.0;

    $count = 0;


    foreach ($subjects as $subject) {

        if (
            isset($subject[$semesterKey]) &&
            $subject[$semesterKey] !== null
        ) {

            $total += (float) $subject[$semesterKey];

            $count++;
        }
    }


    if ($count === 0) {
        return null;
    }


    return $total / $count;
}


/*
|--------------------------------------------------------------------------
| Calculate Annual Average
|--------------------------------------------------------------------------
*/

function calculateAnnualAverage(
    array $subjects
): ?float {

    $total = 0.0;

    $count = 0;


    foreach ($subjects as $subject) {

        if (
            isset($subject['annual_average']) &&
            $subject['annual_average'] !== null
        ) {

            $total +=
                (float) $subject['annual_average'];

            $count++;
        }
    }


    if ($count === 0) {
        return null;
    }


    return $total / $count;
}


/*
|--------------------------------------------------------------------------
| Build One Academic Year
|--------------------------------------------------------------------------
*/

function buildTranscriptYear(
    mysqli $conn,
    array $registration
): array {

    /*
    |--------------------------------------------------------------------------
    | Get Results
    |--------------------------------------------------------------------------
    */

    $results = getTranscriptResults(
        $conn,
        (int) $registration['registration_id']
    );


    /*
    |--------------------------------------------------------------------------
    | Build Subjects
    |--------------------------------------------------------------------------
    */

    $subjects = buildTranscriptSubjects(
        $results
    );


    /*
    |--------------------------------------------------------------------------
    | Return Academic Year Record
    |--------------------------------------------------------------------------
    */

    return [

        'registration_id' =>
            (int) $registration['registration_id'],


        'academic_year_id' =>
            (int) $registration['academic_year_id'],


        'academic_year_name' =>
            $registration['academic_year_name'],


        'grade_id' =>
            (int) $registration['grade_id'],


        'grade_name' =>
            $registration['grade_name'],


        'grade_number' =>
            (int) $registration['grade_number'],


        'section_id' =>
            (int) $registration['section_id'],


        'section_name' =>
            $registration['section_name'],


        'section_code' =>
            $registration['section_code'],


        'registration_type' =>
            $registration['registration_type'],


        'result_status' =>
            $registration['result'],


        'registration_date' =>
            $registration['registration_date'],


        'subjects' =>
            $subjects,


        'first_semester_average' =>
            calculateSemesterAverage(
                $subjects,
                'first_semester'
            ),


        'second_semester_average' =>
            calculateSemesterAverage(
                $subjects,
                'second_semester'
            ),


        'annual_average' =>
            calculateAnnualAverage(
                $subjects
            )

    ];
}


/*
|--------------------------------------------------------------------------
| Get Complete Student Transcript
|--------------------------------------------------------------------------
|
| This is the main function used by transcript-view.php.
|
| It automatically retrieves:
|
| Student
|    ↓
| All Registrations
|    ↓
| All Academic Years
|    ↓
| First + Second Semester Results
|
| No academic year or semester is supplied by the user.
|--------------------------------------------------------------------------
*/

function getStudentTranscript(
    mysqli $conn,
    int $studentId
): ?array {

    /*
    |--------------------------------------------------------------------------
    | Validate Student ID
    |--------------------------------------------------------------------------
    */

    if ($studentId <= 0) {
        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | Get Student
    |--------------------------------------------------------------------------
    */

    $student = getTranscriptStudent(
        $conn,
        $studentId
    );


    if (!$student) {
        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | Get All Registrations
    |--------------------------------------------------------------------------
    */

    $registrations = getTranscriptRegistrations(
        $conn,
        $studentId
    );


    /*
    |--------------------------------------------------------------------------
    | Build Academic Year Records
    |--------------------------------------------------------------------------
    */

    $academicYears = [];


    foreach ($registrations as $registration) {

        $academicYearId =
            (int) $registration['academic_year_id'];


        /*
        |--------------------------------------------------------------------------
        | One academic year should represent one registration.
        |
        | If duplicate registrations exist for the same academic year,
        | keep the first registration already loaded instead of silently
        | replacing it with another record.
        |--------------------------------------------------------------------------
        */

        if (isset($academicYears[$academicYearId])) {
            continue;
        }


        $academicYears[$academicYearId] =
            buildTranscriptYear(
                $conn,
                $registration
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Return Complete Transcript
    |--------------------------------------------------------------------------
    */

    return [

        'student' => [

            'id' =>
                (int) $student['id'],


            'student_code' =>
                $student['student_code'],


            'full_name' =>
                $student['full_name'],


            'date_of_birth' =>
                $student['date_of_birth'],


            'gender' =>
                $student['gender'],


            'photo_path' =>
                $student['photo_path']

        ],


        'academic_years' =>
            array_values($academicYears)

    ];
}