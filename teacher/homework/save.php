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
| Only POST requests are accepted
|--------------------------------------------------------------------------
*/
if (!isPostRequest()) {
    setFlashMessage(
        'warning',
        'Invalid request.'
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| CSRF validation
|--------------------------------------------------------------------------
*/
$csrfToken = requestString(
    $_POST,
    'csrf_token'
);

if (!verifyCsrfToken($csrfToken)) {
    setFlashMessage(
        'danger',
        'Your session has expired or the security token is invalid. Please try again.'
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| Get active academic year
|--------------------------------------------------------------------------
*/
try {
    $academicYear = getActiveAcademicYear($conn);
} catch (Throwable $e) {
    setFlashMessage(
        'danger',
        'Unable to load the active academic year: '
        . $e->getMessage()
    );

    redirectTo('../homework.php');
}

if ($academicYear === null) {
    setFlashMessage(
        'warning',
        'There is no active academic year. Homework cannot be created.'
    );

    redirectTo('../homework.php');
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];


/*
|--------------------------------------------------------------------------
| Read form values
|--------------------------------------------------------------------------
*/
$assignmentId = requestInt(
    $_POST,
    'assignment_id'
);

$title = requestString(
    $_POST,
    'title'
);

$description = requestString(
    $_POST,
    'description'
);

$description = $description !== ''
    ? $description
    : null;


/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/
$errors = [];

if ($assignmentId <= 0) {
    $errors[] =
        'Please select a valid subject assignment.';
}

if ($title === '') {
    $errors[] =
        'Homework title is required.';
} elseif (mb_strlen($title) > 255) {
    $errors[] =
        'Homework title cannot exceed 255 characters.';
}

if (
    $description !== null &&
    mb_strlen($description) > 10000
) {
    $errors[] =
        'Homework description cannot exceed 10,000 characters.';
}


/*
|--------------------------------------------------------------------------
| Ethiopian Assigned Date
|--------------------------------------------------------------------------
*/
$assignedYear = requestInt(
    $_POST,
    'assigned_year'
);

$assignedMonth = requestInt(
    $_POST,
    'assigned_month'
);

$assignedDay = requestInt(
    $_POST,
    'assigned_day'
);


/*
|--------------------------------------------------------------------------
| Ethiopian Due Date
|--------------------------------------------------------------------------
*/
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
| Validate Assigned Date
|--------------------------------------------------------------------------
*/
$assignedDate = null;

if (
    !isValidEthiopianDate(
        $assignedYear,
        $assignedMonth,
        $assignedDay
    )
) {
    $errors[] =
        'Please select a valid Ethiopian assigned date.';
} else {
    $assignedDate = ethiopianDateToGregorian(
        $assignedYear,
        $assignedMonth,
        $assignedDay
    );

    if ($assignedDate === null) {
        $errors[] =
            'The assigned date could not be converted.';
    }
}


/*
|--------------------------------------------------------------------------
| Validate Due Date
|--------------------------------------------------------------------------
*/
$dueDate = null;

if (
    !isValidEthiopianDate(
        $dueYear,
        $dueMonth,
        $dueDay
    )
) {
    $errors[] =
        'Please select a valid Ethiopian due date.';
} else {
    $dueDate = ethiopianDateToGregorian(
        $dueYear,
        $dueMonth,
        $dueDay
    );

    if ($dueDate === null) {
        $errors[] =
            'The due date could not be converted.';
    }
}


/*
|--------------------------------------------------------------------------
| Assigned date cannot be after due date
|--------------------------------------------------------------------------
*/
if (
    $assignedDate !== null &&
    $dueDate !== null &&
    $assignedDate > $dueDate
) {
    $errors[] =
        'The due date cannot be earlier than the assigned date.';
}


/*
|--------------------------------------------------------------------------
| Stop before database changes if validation failed
|--------------------------------------------------------------------------
*/
if (!empty($errors)) {
    setFlashMessage(
        'danger',
        implode(' ', $errors)
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| Safety check
|--------------------------------------------------------------------------
*/
if (
    $assignedDate === null ||
    $dueDate === null
) {
    setFlashMessage(
        'danger',
        'The homework dates could not be processed.'
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| Verify teacher assignment
|--------------------------------------------------------------------------
*/
try {
    $assignment = getTeacherAssignment(
        $conn,
        $assignmentId,
        $teacherUserId,
        $academicYearName
    );
} catch (Throwable $e) {
    setFlashMessage(
        'danger',
        'Unable to verify your subject assignment: '
        . $e->getMessage()
    );

    redirectTo('../homework.php');
}

if ($assignment === null) {
    setFlashMessage(
        'danger',
        'The selected class or subject is not assigned to your teacher account.'
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| Authoritative class/subject information
|--------------------------------------------------------------------------
*/
$grade = (int) $assignment['grade'];

$section = (string) $assignment['section'];

$gradeSubjectId = (int) $assignment['grade_subject_id'];

$subjectName = (string) $assignment['subject_name'];


/*
|--------------------------------------------------------------------------
| Teacher material upload
|--------------------------------------------------------------------------
*/
$teacherMaterialPath = null;
$teacherMaterialOriginalName = null;
$teacherMaterialType = null;
$teacherMaterialSize = null;

$uploadedMaterial = $_FILES['teacher_material'] ?? null;

if (
    is_array($uploadedMaterial) &&
    isset($uploadedMaterial['error']) &&
    (int) $uploadedMaterial['error'] !== UPLOAD_ERR_NO_FILE
) {
    $uploadValidation = validateHomeworkUpload(
        $uploadedMaterial
    );

    if (!$uploadValidation['valid']) {
        setFlashMessage(
            'danger',
            (string) (
                $uploadValidation['error']
                ?? 'The teacher material is invalid.'
            )
        );

        redirectTo('../homework.php');
    }

    $extension = (string) $uploadValidation['extension'];

    $filename = generateHomeworkFilename(
        $extension
    );


    /*
    |--------------------------------------------------------------------------
    | Physical upload directory
    |--------------------------------------------------------------------------
    */
    $uploadDirectory =
        dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'homeworks'
        . DIRECTORY_SEPARATOR
        . 'teacher';


    if (!ensureDirectoryExists($uploadDirectory)) {
        setFlashMessage(
            'danger',
            'The homework upload directory could not be created.'
        );

        redirectTo('../homework.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Store uploaded file
    |--------------------------------------------------------------------------
    */
    $destination =
        $uploadDirectory
        . DIRECTORY_SEPARATOR
        . $filename;

    if (
        !move_uploaded_file(
            (string) $uploadedMaterial['tmp_name'],
            $destination
        )
    ) {
        setFlashMessage(
            'danger',
            'The teacher material could not be uploaded.'
        );

        redirectTo('../homework.php');
    }


    /*
    |--------------------------------------------------------------------------
    | Store relative path in database
    |--------------------------------------------------------------------------
    */
    $teacherMaterialPath =
        'uploads/homeworks/teacher/' . $filename;

    $teacherMaterialOriginalName =
        basename(
            (string) $uploadedMaterial['name']
        );

    $teacherMaterialType =
        $uploadValidation['mime_type'];

    $teacherMaterialSize =
        (int) $uploadedMaterial['size'];
}


/*
|--------------------------------------------------------------------------
| Create homework
|--------------------------------------------------------------------------
*/
try {
    $conn->begin_transaction();


    /*
    |--------------------------------------------------------------------------
    | Insert homework
    |--------------------------------------------------------------------------
    */
    $homeworkId = insertHomework(
        $conn,
        $academicYearName,
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

    if ($homeworkId <= 0) {
        throw new RuntimeException(
            'Homework could not be created.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initialize student homework status
    |--------------------------------------------------------------------------
    */
    initializeHomeworkStudentStatuses(
        $conn,
        $homeworkId,
        $academicYearId,
        $grade,
        $section
    );


    /*
    |--------------------------------------------------------------------------
    | Commit transaction
    |--------------------------------------------------------------------------
    */
    $conn->commit();

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Roll back database transaction
    |--------------------------------------------------------------------------
    */
    try {
        $conn->rollback();
    } catch (Throwable) {
        // Ignore rollback errors.
    }


    /*
    |--------------------------------------------------------------------------
    | Remove uploaded material if database creation failed
    |--------------------------------------------------------------------------
    */
    if ($teacherMaterialPath !== null) {
        $uploadedFilePath =
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $teacherMaterialPath
            );

        if (is_file($uploadedFilePath)) {
            @unlink($uploadedFilePath);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | TEMPORARY DEBUG MESSAGE
    |--------------------------------------------------------------------------
    |
    | This displays the actual exception so we can identify
    | the database problem.
    |
    |--------------------------------------------------------------------------
    */
    setFlashMessage(
        'danger',
        'Homework could not be created. Database error: '
        . $e->getMessage()
    );

    redirectTo('../homework.php');
}


/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/
setFlashMessage(
    'success',
    'Homework created successfully for Grade '
    . $grade
    . ' - Section '
    . $section
    . ' - '
    . $subjectName
    . '.'
);

redirectTo('../homework.php');