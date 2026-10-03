<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Get logged-in student's active registration
|--------------------------------------------------------------------------
*/

function getStudentActiveRegistration(
    mysqli $conn,
    int $userId
): ?array {

    $sql = "
        SELECT
            s.id AS student_id,
            s.user_id,
            s.student_code,
            s.full_name,

            sr.id AS registration_id,
            sr.academic_year_id,
            sr.grade_id,
            sr.section_id,

            g.grade_number,
            sec.code AS section,

            ay.name AS academic_year

        FROM students s

        INNER JOIN student_registrations sr
            ON sr.student_id = s.id

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN academic_years ay
            ON ay.id = sr.academic_year_id

        WHERE s.user_id = ?
          AND s.is_deleted = 0
          AND ay.status = 'Active'

        ORDER BY sr.id DESC

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare student registration query: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'i',
        $userId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to execute student registration query: ' .
            $stmt->error
        );
    }

    $result = $stmt->get_result();

    $registration = $result->fetch_assoc();

    $stmt->close();

    return $registration ?: null;
}


/*
|--------------------------------------------------------------------------
| Get one homework belonging to student's current class
|--------------------------------------------------------------------------
*/

function getStudentHomework(
    mysqli $conn,
    int $homeworkId,
    string $academicYear,
    int $grade,
    string $section
): ?array {

    $sql = "
        SELECT
            h.id,
            h.academic_year,
            h.teacher_user_id,

            h.grade,
            h.section,

            h.grade_subject_id,

            h.title,
            h.description,

            h.teacher_material_path,
            h.teacher_material_original_name,
            h.teacher_material_type,
            h.teacher_material_size,

            h.assigned_date,
            h.due_date,
            h.status,

            gs.subject_name,

            u.full_name AS teacher_name

        FROM homeworks h

        INNER JOIN grade_subjects gs
            ON gs.id = h.grade_subject_id

        INNER JOIN users u
            ON u.id = h.teacher_user_id

        WHERE h.id = ?

          AND TRIM(h.academic_year) = TRIM(?)

          AND h.grade = ?

          AND h.section = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare homework query: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'isis',
        $homeworkId,
        $academicYear,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to execute homework query: ' .
            $stmt->error
        );
    }

    $result = $stmt->get_result();

    $homework = $result->fetch_assoc();

    $stmt->close();

    return $homework ?: null;
}


/*
|--------------------------------------------------------------------------
| Get student's existing submission
|--------------------------------------------------------------------------
*/

function getStudentHomeworkSubmission(
    mysqli $conn,
    int $homeworkId,
    int $studentId
): ?array {

    $sql = "
        SELECT
            id,
            homework_id,
            student_id,
            file_path,
            original_file_name,
            file_type,
            file_size,
            submitted_at,
            updated_at

        FROM homework_submissions

        WHERE homework_id = ?
          AND student_id = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare submission query: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'ii',
        $homeworkId,
        $studentId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to execute submission query: ' .
            $stmt->error
        );
    }

    $result = $stmt->get_result();

    $submission = $result->fetch_assoc();

    $stmt->close();

    return $submission ?: null;
}


/*
|--------------------------------------------------------------------------
| Get current and upcoming homework
|--------------------------------------------------------------------------
|
| Past-due homework is NOT returned here.
|
| The normal student homework page only shows:
|
| due_date >= today
|
*/

function getStudentHomeworks(
    mysqli $conn,
    string $academicYear,
    int $grade,
    string $section
): array {

    $sql = "
        SELECT
            h.id,
            h.academic_year,
            h.teacher_user_id,

            h.grade,
            h.section,

            h.grade_subject_id,

            h.title,
            h.description,

            h.teacher_material_path,
            h.teacher_material_original_name,

            h.assigned_date,
            h.due_date,
            h.status,

            gs.subject_name,

            u.full_name AS teacher_name

        FROM homeworks h

        INNER JOIN grade_subjects gs
            ON gs.id = h.grade_subject_id

        INNER JOIN users u
            ON u.id = h.teacher_user_id

        WHERE TRIM(h.academic_year) = TRIM(?)

          AND h.grade = ?

          AND h.section = ?

          AND h.due_date >= CURRENT_DATE()

        ORDER BY
            h.due_date ASC,
            h.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare homework list query: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'sis',
        $academicYear,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to execute homework list query: ' .
            $stmt->error
        );
    }

    $result = $stmt->get_result();

    $homeworks = [];

    while ($row = $result->fetch_assoc()) {

        $homeworks[] = $row;
    }

    $stmt->close();

    return $homeworks;
}


/*
|--------------------------------------------------------------------------
| Get undone homework from the previous one month
|--------------------------------------------------------------------------
|
| Conditions:
|
| 1. Current academic year.
| 2. Current grade.
| 3. Current section.
| 4. Due date has passed.
| 5. Due date is within the previous one month.
| 6. Student has not submitted the homework.
|
*/

function getStudentUndoneHomeworks(
    mysqli $conn,
    string $academicYear,
    int $grade,
    string $section,
    int $studentId
): array {

    $sql = "
        SELECT
            h.id,
            h.academic_year,
            h.teacher_user_id,

            h.grade,
            h.section,

            h.grade_subject_id,

            h.title,
            h.description,

            h.teacher_material_path,
            h.teacher_material_original_name,

            h.assigned_date,
            h.due_date,
            h.status,

            gs.subject_name,

            u.full_name AS teacher_name

        FROM homeworks h

        INNER JOIN grade_subjects gs
            ON gs.id = h.grade_subject_id

        INNER JOIN users u
            ON u.id = h.teacher_user_id

        LEFT JOIN homework_submissions hs
            ON hs.homework_id = h.id
            AND hs.student_id = ?

        WHERE TRIM(h.academic_year) = TRIM(?)

          AND h.grade = ?

          AND h.section = ?

          AND h.due_date < CURRENT_DATE()

          AND h.due_date >= DATE_SUB(
              CURRENT_DATE(),
              INTERVAL 1 MONTH
          )

          AND hs.id IS NULL

        ORDER BY
            h.due_date DESC,
            h.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare undone homework query: ' .
            $conn->error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Binding
    |--------------------------------------------------------------------------
    |
    | ? = studentId
    | ? = academicYear
    | ? = grade
    | ? = section
    |
    */

    $stmt->bind_param(
        'isis',
        $studentId,
        $academicYear,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to execute undone homework query: ' .
            $stmt->error
        );
    }

    $result = $stmt->get_result();

    $homeworks = [];

    while ($row = $result->fetch_assoc()) {

        $homeworks[] = $row;
    }

    $stmt->close();

    return $homeworks;
}


/*
|--------------------------------------------------------------------------
| Get student's submission for a homework
|--------------------------------------------------------------------------
*/

function getHomeworkSubmissionStatus(
    mysqli $conn,
    int $homeworkId,
    int $studentId
): ?array {

    return getStudentHomeworkSubmission(
        $conn,
        $homeworkId,
        $studentId
    );
}