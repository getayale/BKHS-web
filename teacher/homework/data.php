<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homework Data Layer
|--------------------------------------------------------------------------
| Database queries used by the teacher homework module.
|--------------------------------------------------------------------------
*/


/**
 * Get the currently active academic year.
 */
function getActiveAcademicYear(mysqli $conn): ?array
{
    $sql = "
        SELECT
            id,
            name,
            status
        FROM academic_years
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare active academic year query: '
            . $conn->error
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load active academic year: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $academicYear = $result->fetch_assoc();

    $stmt->close();

    if ($academicYear === null) {
        return null;
    }

    return [
        'id' => (int) $academicYear['id'],
        'name' => (string) $academicYear['name'],
        'status' => (string) $academicYear['status'],
    ];
}


/**
 * Get all active subject assignments belonging to the teacher.
 */
function getTeacherSubjectAssignments(
    mysqli $conn,
    int $teacherUserId,
    string $academicYear
): array {
    $sql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare teacher assignment query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'is',
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load teacher assignments: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $assignments = [];

    while ($row = $result->fetch_assoc()) {
        $assignments[] = [
            'id' => (int) $row['id'],
            'grade' => (int) $row['grade'],
            'section' => (string) $row['section'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'subject_name' => (string) $row['subject_name'],
        ];
    }

    $stmt->close();

    return $assignments;
}


/**
 * Get one teacher assignment.
 *
 * The assignment must belong to the logged-in teacher
 * and the active academic year.
 */
function getTeacherAssignment(
    mysqli $conn,
    int $assignmentId,
    int $teacherUserId,
    string $academicYear
): ?array {
    $sql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.id = ?
          AND sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare teacher assignment lookup: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iis',
        $assignmentId,
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to verify teacher assignment: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();

    if ($row === null) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'grade' => (int) $row['grade'],
        'section' => (string) $row['section'],
        'grade_subject_id' => (int) $row['grade_subject_id'],
        'subject_name' => (string) $row['subject_name'],
    ];
}


/**
 * Get all classes assigned to a teacher.
 *
 * A class is a unique Grade + Section combination.
 */
function getTeacherClasses(
    mysqli $conn,
    int $teacherUserId,
    string $academicYear
): array {
    $sql = "
        SELECT DISTINCT
            sta.grade,
            sta.section
        FROM subject_teacher_assignments sta
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare teacher classes query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'is',
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load teacher classes: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $classes = [];

    while ($row = $result->fetch_assoc()) {
        $classes[] = [
            'grade' => (int) $row['grade'],
            'section' => (string) $row['section'],
        ];
    }

    $stmt->close();

    return $classes;
}


/**
 * Get subjects assigned to the teacher for a specific class.
 */
function getTeacherSubjectsForClass(
    mysqli $conn,
    int $teacherUserId,
    string $academicYear,
    int $grade,
    string $section
): array {
    $sql = "
        SELECT
            sta.id,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.grade = ?
          AND sta.section = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            gs.subject_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare teacher subjects query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'isis',
        $teacherUserId,
        $academicYear,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load teacher subjects: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $subjects = [];

    while ($row = $result->fetch_assoc()) {
        $subjects[] = [
            'id' => (int) $row['id'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'subject_name' => (string) $row['subject_name'],
        ];
    }

    $stmt->close();

    return $subjects;
}


/**
 * Get a homework record owned by the teacher.
 */
function getTeacherHomework(
    mysqli $conn,
    int $homeworkId,
    int $teacherUserId,
    string $academicYear
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
            h.created_at,
            h.updated_at,
            gs.subject_name
        FROM homeworks h
        INNER JOIN grade_subjects gs
            ON gs.id = h.grade_subject_id
        WHERE h.id = ?
          AND h.teacher_user_id = ?
          AND h.academic_year = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework lookup: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iis',
        $homeworkId,
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load homework: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();

    if ($row === null) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'academic_year' => (string) $row['academic_year'],
        'teacher_user_id' => (int) $row['teacher_user_id'],
        'grade' => (int) $row['grade'],
        'section' => (string) $row['section'],
        'grade_subject_id' => (int) $row['grade_subject_id'],
        'title' => (string) $row['title'],

        'description' => $row['description'] !== null
            ? (string) $row['description']
            : null,

        'teacher_material_path' => $row['teacher_material_path'] !== null
            ? (string) $row['teacher_material_path']
            : null,

        'teacher_material_original_name' =>
            $row['teacher_material_original_name'] !== null
                ? (string) $row['teacher_material_original_name']
                : null,

        'teacher_material_type' => $row['teacher_material_type'] !== null
            ? (string) $row['teacher_material_type']
            : null,

        'teacher_material_size' => $row['teacher_material_size'] !== null
            ? (int) $row['teacher_material_size']
            : null,

        'assigned_date' => (string) $row['assigned_date'],
        'due_date' => (string) $row['due_date'],
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'subject_name' => (string) $row['subject_name'],
    ];
}


/**
 * Get homework history for a teacher.
 *
 * Supports filtering by:
 *
 * - Grade
 * - Section
 * - Subject
 * - Assigned date range
 * - Homework status
 *
 * Homework status values:
 *
 * - Active
 * - Closed
 */
function getTeacherHomeworkHistory(
    mysqli $conn,
    int $teacherUserId,
    string $academicYear,
    ?int $grade = null,
    ?string $section = null,
    ?int $gradeSubjectId = null,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?string $status = null
): array {
    $sql = "
        SELECT
            h.id,
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
            h.created_at,
            gs.subject_name
        FROM homeworks h
        INNER JOIN grade_subjects gs
            ON gs.id = h.grade_subject_id
        WHERE h.teacher_user_id = ?
          AND h.academic_year = ?
    ";

    $types = 'is';

    $params = [
        $teacherUserId,
        $academicYear,
    ];

    if ($grade !== null) {
        $sql .= "
            AND h.grade = ?
        ";

        $types .= 'i';
        $params[] = $grade;
    }

    if (
        $section !== null &&
        $section !== ''
    ) {
        $sql .= "
            AND h.section = ?
        ";

        $types .= 's';
        $params[] = $section;
    }

    if ($gradeSubjectId !== null) {
        $sql .= "
            AND h.grade_subject_id = ?
        ";

        $types .= 'i';
        $params[] = $gradeSubjectId;
    }

    if (
        $fromDate !== null &&
        $fromDate !== ''
    ) {
        $sql .= "
            AND h.assigned_date >= ?
        ";

        $types .= 's';
        $params[] = $fromDate;
    }

    if (
        $toDate !== null &&
        $toDate !== ''
    ) {
        $sql .= "
            AND h.assigned_date <= ?
        ";

        $types .= 's';
        $params[] = $toDate;
    }

    if (
        $status !== null &&
        $status !== ''
    ) {
        $sql .= "
            AND h.status = ?
        ";

        $types .= 's';
        $params[] = $status;
    }

    $sql .= "
        ORDER BY
            h.assigned_date DESC,
            h.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework history query: '
            . $conn->error
        );
    }

    bindDynamicParameters(
        $stmt,
        $types,
        $params
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load homework history: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $homeworks = [];

    while ($row = $result->fetch_assoc()) {
        $homeworks[] = [
            'id' => (int) $row['id'],
            'grade' => (int) $row['grade'],
            'section' => (string) $row['section'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'title' => (string) $row['title'],

            'description' => $row['description'] !== null
                ? (string) $row['description']
                : null,

            'teacher_material_path' => $row['teacher_material_path'] !== null
                ? (string) $row['teacher_material_path']
                : null,

            'teacher_material_original_name' =>
                $row['teacher_material_original_name'] !== null
                    ? (string) $row['teacher_material_original_name']
                    : null,

            'assigned_date' => (string) $row['assigned_date'],
            'due_date' => (string) $row['due_date'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'subject_name' => (string) $row['subject_name'],
        ];
    }

    $stmt->close();

    return $homeworks;
}


/**
 * Get students currently registered in a homework class.
 */
function getStudentsForHomeworkClass(
    mysqli $conn,
    int $academicYearId,
    int $grade,
    string $section
): array {
    $sql = "
        SELECT DISTINCT
            s.id AS student_id,
            s.student_code,
            s.full_name
        FROM student_registrations sr

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN students s
            ON s.id = sr.student_id

        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?

        ORDER BY
            s.full_name ASC,
            s.student_code ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework student query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iis',
        $academicYearId,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load homework students: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $students = [];

    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => (int) $row['student_id'],
            'student_code' => (string) $row['student_code'],
            'full_name' => (string) $row['full_name'],
        ];
    }

    $stmt->close();

    return $students;
}


/**
 * Get students together with homework status and submission.
 */
function getHomeworkStudentStatuses(
    mysqli $conn,
    int $homeworkId,
    int $academicYearId,
    int $grade,
    string $section
): array {
    $sql = "
        SELECT
            s.id AS student_id,
            s.student_code,
            s.full_name,

            COALESCE(
                hss.status,
                'Not Done'
            ) AS homework_status,

            hss.completed_at,

            hs.id AS submission_id,
            hs.original_file_name,
            hs.file_path,
            hs.file_type,
            hs.file_size,
            hs.submitted_at

        FROM student_registrations sr

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN students s
            ON s.id = sr.student_id

        LEFT JOIN homework_student_status hss
            ON hss.homework_id = ?
            AND hss.student_id = s.id

        LEFT JOIN homework_submissions hs
            ON hs.homework_id = ?
            AND hs.student_id = s.id

        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?

        ORDER BY
            s.full_name ASC,
            s.student_code ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework student status query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iiiss',
        $homeworkId,
        $homeworkId,
        $academicYearId,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Failed to load homework student statuses: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $students = [];

    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => (int) $row['student_id'],
            'student_code' => (string) $row['student_code'],
            'full_name' => (string) $row['full_name'],

            'homework_status' =>
                (string) $row['homework_status'],

            'completed_at' =>
                $row['completed_at'] !== null
                    ? (string) $row['completed_at']
                    : null,

            'submission_id' =>
                $row['submission_id'] !== null
                    ? (int) $row['submission_id']
                    : null,

            'original_file_name' =>
                $row['original_file_name'] !== null
                    ? (string) $row['original_file_name']
                    : null,

            'file_path' =>
                $row['file_path'] !== null
                    ? (string) $row['file_path']
                    : null,

            'file_type' =>
                $row['file_type'] !== null
                    ? (string) $row['file_type']
                    : null,

            'file_size' =>
                $row['file_size'] !== null
                    ? (int) $row['file_size']
                    : null,

            'submitted_at' =>
                $row['submitted_at'] !== null
                    ? (string) $row['submitted_at']
                    : null,
        ];
    }

    $stmt->close();

    return $students;
}


/**
 * Insert a homework record.
 *
 * The teacher, grade, section and subject are already
 * verified by getTeacherAssignment().
 */
function insertHomework(
    mysqli $conn,
    string $academicYear,
    int $teacherUserId,
    int $grade,
    string $section,
    int $gradeSubjectId,
    string $title,
    ?string $description,
    ?string $teacherMaterialPath,
    ?string $teacherMaterialOriginalName,
    ?string $teacherMaterialType,
    ?int $teacherMaterialSize,
    string $assignedDate,
    string $dueDate
): int {
    $sql = "
        INSERT INTO homeworks (
            academic_year,
            teacher_user_id,
            grade,
            section,
            grade_subject_id,
            title,
            description,
            teacher_material_path,
            teacher_material_original_name,
            teacher_material_type,
            teacher_material_size,
            assigned_date,
            due_date,
            status
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'Active'
        )
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework creation query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'siisisssssiss',
        $academicYear,
        $teacherUserId,
        $grade,
        $section,
        $gradeSubjectId,
        $title,
        $description,
        $teacherMaterialPath,
        $teacherMaterialOriginalName,
        $teacherMaterialType,
        $teacherMaterialSize,
        $assignedDate,
        $dueDate
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to insert homework: '
            . $error
        );
    }

    $homeworkId = (int) $stmt->insert_id;

    $stmt->close();

    if ($homeworkId <= 0) {
        throw new RuntimeException(
            'Homework was inserted but no homework ID was returned.'
        );
    }

    return $homeworkId;
}


/**
 * Update editable homework fields.
 *
 * Teacher, academic year, grade, section, subject
 * and assigned date are deliberately NOT changed.
 */
function updateHomework(
    mysqli $conn,
    int $homeworkId,
    int $teacherUserId,
    string $academicYear,
    string $title,
    ?string $description,
    ?string $teacherMaterialPath,
    ?string $teacherMaterialOriginalName,
    ?string $teacherMaterialType,
    ?int $teacherMaterialSize,
    string $dueDate
): bool {
    $sql = "
        UPDATE homeworks
        SET
            title = ?,
            description = ?,
            teacher_material_path = ?,
            teacher_material_original_name = ?,
            teacher_material_type = ?,
            teacher_material_size = ?,
            due_date = ?
        WHERE id = ?
          AND teacher_user_id = ?
          AND academic_year = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework update query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'sssssisiss',
        $title,
        $description,
        $teacherMaterialPath,
        $teacherMaterialOriginalName,
        $teacherMaterialType,
        $teacherMaterialSize,
        $dueDate,
        $homeworkId,
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to update homework: '
            . $error
        );
    }

    $updated = $stmt->affected_rows > 0;

    $stmt->close();

    return $updated;
}


/**
 * Update homework student status.
 */
function updateHomeworkStudentStatus(
    mysqli $conn,
    int $homeworkId,
    int $studentId,
    string $status
): bool {
    $sql = "
        INSERT INTO homework_student_status (
            homework_id,
            student_id,
            status,
            completed_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            completed_at = VALUES(completed_at)
    ";

    $completedAt = $status === 'Done'
        ? date('Y-m-d H:i:s')
        : null;

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework status query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iiss',
        $homeworkId,
        $studentId,
        $status,
        $completedAt
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to update homework student status: '
            . $error
        );
    }

    $stmt->close();

    return true;
}


/**
 * Check whether a student belongs to a homework class.
 *
 * The student must be registered in the same:
 *
 * - academic year
 * - grade
 * - section
 *
 * as the homework.
 */
function studentBelongsToHomeworkClass(
    mysqli $conn,
    int $studentId,
    int $academicYearId,
    int $grade,
    string $section
): bool {
    $sql = "
        SELECT
            sr.id
        FROM student_registrations sr

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        WHERE sr.student_id = ?
          AND sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare student class verification query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iiis',
        $studentId,
        $academicYearId,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to verify student class: '
            . $error
        );
    }

    $result = $stmt->get_result();

    $exists = $result->num_rows > 0;

    $stmt->close();

    return $exists;
}


/**
 * Add initial Not Done status records for all students.
 */
function initializeHomeworkStudentStatuses(
    mysqli $conn,
    int $homeworkId,
    int $academicYearId,
    int $grade,
    string $section
): int {
    $sql = "
        INSERT IGNORE INTO homework_student_status (
            homework_id,
            student_id,
            status
        )
        SELECT
            ?,
            sr.student_id,
            'Not Done'
        FROM student_registrations sr

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare homework status initialization query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iiis',
        $homeworkId,
        $academicYearId,
        $grade,
        $section
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to initialize homework student statuses: '
            . $error
        );
    }

    $count = $stmt->affected_rows;

    $stmt->close();

    return $count;
}


/**
 * Delete the teacher material reference from a homework.
 *
 * The physical file is removed by the file-handling layer.
 */
function removeHomeworkMaterial(
    mysqli $conn,
    int $homeworkId,
    int $teacherUserId,
    string $academicYear
): bool {
    $sql = "
        UPDATE homeworks
        SET
            teacher_material_path = NULL,
            teacher_material_original_name = NULL,
            teacher_material_type = NULL,
            teacher_material_size = NULL
        WHERE id = ?
          AND teacher_user_id = ?
          AND academic_year = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException(
            'Failed to prepare material removal query: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'iis',
        $homeworkId,
        $teacherUserId,
        $academicYear
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Failed to remove homework material: '
            . $error
        );
    }

    $updated = $stmt->affected_rows > 0;

    $stmt->close();

    return $updated;
}


/**
 * Dynamically bind mysqli parameters.
 *
 * Used by queries with optional filters.
 */
function bindDynamicParameters(
    mysqli_stmt $stmt,
    string $types,
    array $params
): void {
    if ($types === '') {
        return;
    }

    if (strlen($types) !== count($params)) {
        throw new RuntimeException(
            'Parameter type count does not match parameter count.'
        );
    }

    if (empty($params)) {
        throw new RuntimeException(
            'Parameter types were provided but no parameters were supplied.'
        );
    }

    $references = [];

    $references[] = $types;

    foreach ($params as $key => $value) {
        $references[] = &$params[$key];
    }

    $stmt->bind_param(...$references);
}
