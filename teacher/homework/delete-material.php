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
| Only POST requests are allowed
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
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = requestInt($_POST, 'homework_id');

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

$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify homework belongs to teacher
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
| Check whether material exists
|--------------------------------------------------------------------------
*/

$materialPath = !empty($homework['teacher_material_path'])
    ? (string) $homework['teacher_material_path']
    : '';

if ($materialPath === '') {
    setFlashMessage(
        'warning',
        'This homework does not have a teacher material.'
    );

    redirectTo('edit.php?id=' . $homeworkId);
}

/*
|--------------------------------------------------------------------------
| Physical file path
|--------------------------------------------------------------------------
*/

$physicalPath =
    dirname(__DIR__, 2) .
    DIRECTORY_SEPARATOR .
    str_replace(
        '/',
        DIRECTORY_SEPARATOR,
        $materialPath
    );

/*
|--------------------------------------------------------------------------
| Database update
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    $removed = removeHomeworkMaterial(
        $conn,
        $homeworkId,
        $teacherUserId,
        $academicYearName
    );

    if (!$removed) {
        throw new RuntimeException(
            'Teacher material could not be removed from the database.'
        );
    }

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | Delete physical file after successful DB update
    |--------------------------------------------------------------------------
    */

    if (is_file($physicalPath)) {
        if (!@unlink($physicalPath)) {

            /*
             * The database is already correct, so do not roll back.
             * The file can be cleaned up later if necessary.
             */
            setFlashMessage(
                'warning',
                'Teacher material was removed from the homework, but the physical file could not be deleted.'
            );

            redirectTo('edit.php?id=' . $homeworkId);
        }
    }

    setFlashMessage(
        'success',
        'Teacher material removed successfully.'
    );

} catch (Throwable $e) {

    $conn->rollback();

    setFlashMessage(
        'danger',
        'Teacher material could not be removed. Please try again.'
    );
}

redirectTo('edit.php?id=' . $homeworkId);