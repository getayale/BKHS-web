<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if (!isPostRequest()) {
    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| CSRF validation
|--------------------------------------------------------------------------
*/

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlashMessage(
        'danger',
        'Your session has expired or the request is invalid.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Read request data
|--------------------------------------------------------------------------
*/

$homeworkId = requestInt($_POST, 'homework_id');
$studentId = requestInt($_POST, 'student_id');
$status = requestString($_POST, 'status');
$action = requestString($_POST, 'action');

/*
|--------------------------------------------------------------------------
| Validate homework ID
|--------------------------------------------------------------------------
*/

if ($homeworkId <= 0) {
    setFlashMessage(
        'danger',
        'Invalid homework ID.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Active academic year
|--------------------------------------------------------------------------
*/

$academicYear = getActiveAcademicYear($conn);

if ($academicYear === null) {
    setFlashMessage(
        'danger',
        'No active academic year was found.'
    );

    redirectTo('../homework.php');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify homework belongs to this teacher
|--------------------------------------------------------------------------
*/

$homework = getTeacherHomework(
    $conn,
    $homeworkId,
    $teacherUserId,
    $academicYearName
);

if ($homework === null) {
    setFlashMessage(
        'danger',
        'Homework was not found or you do not have permission to modify it.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| MARK ALL STUDENTS AS DONE
|--------------------------------------------------------------------------
*/

if ($action === 'all_done') {

    try {

        /*
        |------------------------------------------------------------------
        | Get all students in the homework class
        |------------------------------------------------------------------
        */

        $students = getStudentsForHomeworkClass(
            $conn,
            $academicYearId,
            (int) $homework['grade'],
            (string) $homework['section']
        );

        if (empty($students)) {

            setFlashMessage(
                'warning',
                'There are no students in this homework class.'
            );

            redirectTo('view.php?id=' . $homeworkId);
        }

        /*
        |------------------------------------------------------------------
        | Start transaction
        |------------------------------------------------------------------
        */

        $conn->begin_transaction();

        $updatedCount = 0;

        foreach ($students as $student) {

            $studentIdForUpdate = (int) (
                $student['student_id']
                ?? $student['id']
                ?? 0
            );

            if ($studentIdForUpdate <= 0) {
                throw new RuntimeException(
                    'Invalid student ID found.'
                );
            }

            /*
            |--------------------------------------------------------------
            | Verify student belongs to homework class
            |--------------------------------------------------------------
            |
            | IMPORTANT:
            | studentBelongsToHomeworkClass() expects:
            |
            |   $studentId
            |   $academicYearId
            |   $grade
            |   $section
            |
            */

            $studentBelongs = studentBelongsToHomeworkClass(
                $conn,
                $studentIdForUpdate,
                $academicYearId,
                (int) $homework['grade'],
                (string) $homework['section']
            );

            if (!$studentBelongs) {
                continue;
            }

            /*
            |--------------------------------------------------------------
            | Mark student as Done
            |--------------------------------------------------------------
            */

            $updated = updateHomeworkStudentStatus(
                $conn,
                $homeworkId,
                $studentIdForUpdate,
                'Done'
            );

            if (!$updated) {
                throw new RuntimeException(
                    'Failed to update homework status.'
                );
            }

            $updatedCount++;
        }

        /*
        |------------------------------------------------------------------
        | Commit transaction
        |------------------------------------------------------------------
        */

        $conn->commit();

        setFlashMessage(
            'success',
            $updatedCount . ' student(s) marked as Done successfully.'
        );

    } catch (Throwable $e) {

        if ($conn->in_transaction) {
            $conn->rollback();
        }

        setFlashMessage(
            'danger',
            'The homework statuses could not be updated.'
        );
    }

    redirectTo('view.php?id=' . $homeworkId);
}

/*
|--------------------------------------------------------------------------
| INDIVIDUAL STUDENT UPDATE
|--------------------------------------------------------------------------
*/

/*
| Student ID is required only for an individual update.
*/

if ($studentId <= 0) {

    setFlashMessage(
        'danger',
        'Invalid student.'
    );

    redirectTo('view.php?id=' . $homeworkId);
}

/*
|--------------------------------------------------------------------------
| Validate individual status
|--------------------------------------------------------------------------
*/

if (!isValidHomeworkStatus($status)) {

    setFlashMessage(
        'danger',
        'Invalid homework status.'
    );

    redirectTo('view.php?id=' . $homeworkId);
}

/*
|--------------------------------------------------------------------------
| Verify student belongs to homework class
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The correct order is:
|
|   $conn
|   $studentId
|   $academicYearId
|   $grade
|   $section
|
*/

$studentBelongs = studentBelongsToHomeworkClass(
    $conn,
    $studentId,
    $academicYearId,
    (int) $homework['grade'],
    (string) $homework['section']
);

if (!$studentBelongs) {

    setFlashMessage(
        'danger',
        'This student does not belong to the homework class.'
    );

    redirectTo('view.php?id=' . $homeworkId);
}

/*
|--------------------------------------------------------------------------
| Update individual status
|--------------------------------------------------------------------------
*/

try {

    $updated = updateHomeworkStudentStatus(
        $conn,
        $homeworkId,
        $studentId,
        $status
    );

    if (!$updated) {
        throw new RuntimeException(
            'Homework status could not be updated.'
        );
    }

    setFlashMessage(
        'success',
        'Student homework status updated successfully.'
    );

} catch (Throwable $e) {

    setFlashMessage(
        'danger',
        'The student homework status could not be updated.'
    );
}

/*
|--------------------------------------------------------------------------
| Return to homework view
|--------------------------------------------------------------------------
*/

redirectTo('view.php?id=' . $homeworkId);