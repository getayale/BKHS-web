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

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlashMessage(
        'danger',
        'Your session has expired or the request is invalid. Please try again.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Get active academic year
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
| Get homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = requestInt(
    $_POST,
    'homework_id'
);

if ($homeworkId <= 0) {
    setFlashMessage(
        'danger',
        'Invalid homework ID.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Verify homework ownership
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
        'Homework was not found or you do not have permission to edit it.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Read editable fields
|--------------------------------------------------------------------------
*/

$title = requestString(
    $_POST,
    'title'
);

$description = requestString(
    $_POST,
    'description'
);

$dueYear = requestInt(
    $_POST,
    'due_year'
);

$dueMonth = requestInt(
    $_POST,
    'due_month'
);

$dueDay = requestInt(
    $_POST,
    'due_day'
);

/*
|--------------------------------------------------------------------------
| Validate title
|--------------------------------------------------------------------------
*/

if ($title === '') {
    setFlashMessage(
        'danger',
        'Please enter a homework title.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

if (mb_strlen($title) > 255) {
    setFlashMessage(
        'danger',
        'Homework title cannot exceed 255 characters.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

/*
|--------------------------------------------------------------------------
| Validate description
|--------------------------------------------------------------------------
*/

if (mb_strlen($description) > 10000) {
    setFlashMessage(
        'danger',
        'Homework description is too long.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

/*
|--------------------------------------------------------------------------
| Validate Ethiopian due date
|--------------------------------------------------------------------------
*/

if (
    !isValidEthiopianDate(
        $dueYear,
        $dueMonth,
        $dueDay
    )
) {
    setFlashMessage(
        'danger',
        'Please select a valid Ethiopian due date.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

$dueDate = ethiopianDateToGregorian(
    $dueYear,
    $dueMonth,
    $dueDay
);

if ($dueDate === null) {
    setFlashMessage(
        'danger',
        'The selected due date could not be converted.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

/*
|--------------------------------------------------------------------------
| Due date cannot be before assigned date
|--------------------------------------------------------------------------
*/

$assignedDate = (string) $homework['assigned_date'];

if ($dueDate < $assignedDate) {
    setFlashMessage(
        'danger',
        'Due date cannot be earlier than the assigned date.'
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}

/*
|--------------------------------------------------------------------------
| Existing material information
|--------------------------------------------------------------------------
*/

$teacherMaterialPath =
    !empty($homework['teacher_material_path'])
        ? (string) $homework['teacher_material_path']
        : null;

$teacherMaterialOriginalName =
    !empty($homework['teacher_material_original_name'])
        ? (string) $homework['teacher_material_original_name']
        : null;

$teacherMaterialType =
    !empty($homework['teacher_material_type'])
        ? (string) $homework['teacher_material_type']
        : null;

$teacherMaterialSize =
    !empty($homework['teacher_material_size'])
        ? (int) $homework['teacher_material_size']
        : null;

/*
|--------------------------------------------------------------------------
| Track files
|--------------------------------------------------------------------------
*/

$oldMaterialPath = null;
$newMaterialPhysicalPath = null;

/*
|--------------------------------------------------------------------------
| Handle new / replacement teacher material
|--------------------------------------------------------------------------
*/

if (
    isset($_FILES['teacher_material']) &&
    is_array($_FILES['teacher_material'])
) {

    $fileError = (int) (
        $_FILES['teacher_material']['error']
        ?? UPLOAD_ERR_NO_FILE
    );

    /*
    |--------------------------------------------------------------------------
    | A file was actually selected
    |--------------------------------------------------------------------------
    */

    if ($fileError !== UPLOAD_ERR_NO_FILE) {

        /*
        |--------------------------------------------------------------------------
        | Check PHP upload error before helper
        |--------------------------------------------------------------------------
        */

        if ($fileError !== UPLOAD_ERR_OK) {

            $message = match ($fileError) {

                UPLOAD_ERR_INI_SIZE =>
                    'The selected file is larger than the server upload limit.',

                UPLOAD_ERR_FORM_SIZE =>
                    'The selected file is larger than the allowed form size.',

                UPLOAD_ERR_PARTIAL =>
                    'The file was only partially uploaded. Please try again.',

                UPLOAD_ERR_NO_TMP_DIR =>
                    'PHP could not find the temporary upload directory.',

                UPLOAD_ERR_CANT_WRITE =>
                    'PHP could not write the uploaded file to the server.',

                UPLOAD_ERR_EXTENSION =>
                    'A PHP extension stopped the file upload.',

                default =>
                    'The teacher material upload failed. Upload error code: ' . $fileError
            };

            setFlashMessage(
                'danger',
                $message
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate file
        |--------------------------------------------------------------------------
        */

        $uploadResult = validateHomeworkUpload(
            $_FILES['teacher_material']
        );

        if (
            !is_array($uploadResult) ||
            !isset($uploadResult['valid'])
        ) {
            setFlashMessage(
                'danger',
                'The uploaded teacher material could not be validated.'
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        if (!$uploadResult['valid']) {
            setFlashMessage(
                'danger',
                (string) (
                    $uploadResult['message']
                    ?? 'The selected teacher material is invalid.'
                )
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get extension
        |--------------------------------------------------------------------------
        */

        $extension = strtolower(
            (string) (
                $uploadResult['extension']
                ?? pathinfo(
                    (string) $_FILES['teacher_material']['name'],
                    PATHINFO_EXTENSION
                )
            )
        );

        if ($extension === '') {
            setFlashMessage(
                'danger',
                'The uploaded file does not have a valid extension.'
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate filename
        |--------------------------------------------------------------------------
        */

        $filename = generateHomeworkFilename(
            $extension
        );

        /*
        |--------------------------------------------------------------------------
        | Upload directory
        |--------------------------------------------------------------------------
        */

        $uploadDirectory =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            'uploads' .
            DIRECTORY_SEPARATOR .
            'homeworks' .
            DIRECTORY_SEPARATOR .
            'teacher';

        /*
        |--------------------------------------------------------------------------
        | Make sure directory exists
        |--------------------------------------------------------------------------
        */

        if (!ensureDirectoryExists($uploadDirectory)) {
            setFlashMessage(
                'danger',
                'The teacher material upload directory does not exist and could not be created.'
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Make sure directory is writable
        |--------------------------------------------------------------------------
        */

        if (!is_writable($uploadDirectory)) {
            setFlashMessage(
                'danger',
                'The teacher material upload directory is not writable. Check permissions for: uploads/homeworks/teacher'
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Physical destination
        |--------------------------------------------------------------------------
        */

        $newMaterialPhysicalPath =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $filename;

        /*
        |--------------------------------------------------------------------------
        | Move uploaded file
        |--------------------------------------------------------------------------
        */

        if (
            !move_uploaded_file(
                (string) $uploadResult['tmp_name'],
                $newMaterialPhysicalPath
            )
        ) {

            /*
            |--------------------------------------------------------------------------
            | Clean path information for debugging
            |--------------------------------------------------------------------------
            */

            $temporaryPath =
                (string) (
                    $uploadResult['tmp_name']
                    ?? ''
                );

            $errorMessage =
                'The new teacher material could not be saved.';

            if (
                $temporaryPath === '' ||
                !is_uploaded_file($temporaryPath)
            ) {
                $errorMessage .=
                    ' PHP no longer recognizes the uploaded file as a valid HTTP upload.';
            } else {
                $errorMessage .=
                    ' The destination directory may not be writable.';
            }

            setFlashMessage(
                'danger',
                $errorMessage
            );

            redirectTo(
                'edit.php?id=' . $homeworkId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save old path for deletion after successful DB update
        |--------------------------------------------------------------------------
        */

        $oldMaterialPath =
            !empty($homework['teacher_material_path'])
                ? (string) $homework['teacher_material_path']
                : null;

        /*
        |--------------------------------------------------------------------------
        | Save new database values
        |--------------------------------------------------------------------------
        */

        $teacherMaterialPath =
            'uploads/homeworks/teacher/' .
            $filename;

        $teacherMaterialOriginalName =
            (string) (
                $uploadResult['original_name']
                ?? $_FILES['teacher_material']['name']
            );

        $teacherMaterialType =
            !empty($uploadResult['mime_type'])
                ? (string) $uploadResult['mime_type']
                : (
                    !empty($_FILES['teacher_material']['type'])
                        ? (string) $_FILES['teacher_material']['type']
                        : null
                );

        $teacherMaterialSize =
            isset($uploadResult['size'])
                ? (int) $uploadResult['size']
                : (int) $_FILES['teacher_material']['size'];
    }
}

/*
|--------------------------------------------------------------------------
| Update database
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    $updated = updateHomework(
        $conn,
        $homeworkId,
        $teacherUserId,
        $academicYearName,
        $title,
        $description !== ''
            ? $description
            : null,
        $teacherMaterialPath,
        $teacherMaterialOriginalName,
        $teacherMaterialType,
        $teacherMaterialSize,
        $dueDate
    );

    if (!$updated) {
        throw new RuntimeException(
            'Homework could not be updated.'
        );
    }

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | Delete old physical material
    |--------------------------------------------------------------------------
    */

    if (
        $oldMaterialPath !== null &&
        $newMaterialPhysicalPath !== null
    ) {

        $oldPhysicalPath =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $oldMaterialPath
            );

        if (
            is_file($oldPhysicalPath) &&
            realpath($oldPhysicalPath) !==
            realpath($newMaterialPhysicalPath)
        ) {
            @unlink($oldPhysicalPath);
        }
    }

    setFlashMessage(
        'success',
        'Homework updated successfully.'
    );

    redirectTo(
        'view.php?id=' . $homeworkId
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Rollback
    |--------------------------------------------------------------------------
    */

    try {
        $conn->rollback();
    } catch (Throwable) {
        // Ignore rollback failure.
    }

    /*
    |--------------------------------------------------------------------------
    | Delete newly uploaded file
    |--------------------------------------------------------------------------
    */

    if (
        $newMaterialPhysicalPath !== null &&
        is_file($newMaterialPhysicalPath)
    ) {
        @unlink($newMaterialPhysicalPath);
    }

    setFlashMessage(
        'danger',
        'Homework could not be updated. Database error: ' .
        $e->getMessage()
    );

    redirectTo(
        'edit.php?id=' . $homeworkId
    );
}