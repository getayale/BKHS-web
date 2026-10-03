<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';

requireStudent();

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Only POST is allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToHomework();
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken = (string) ($_POST['csrf_token'] ?? '');

if (!verifyCsrf($csrfToken)) {
    setFlash(
        'danger',
        'Your session has expired. Please try again.'
    );

    redirectToHomework();
}

/*
|--------------------------------------------------------------------------
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = filter_var(
    $_POST['homework_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$homeworkId || $homeworkId <= 0) {
    setFlash(
        'danger',
        'Invalid homework.'
    );

    redirectToHomework();
}

$homeworkId = (int) $homeworkId;

$oldFilePath = null;
$newFilePath = null;

try {

    /*
    |--------------------------------------------------------------------------
    | Get active student registration
    |--------------------------------------------------------------------------
    */

    $student = getStudentActiveRegistration(
        $conn,
        $userId
    );

    if (!$student) {
        setFlash(
            'danger',
            'Your active student registration could not be found.'
        );

        redirectToHomework();
    }

    $studentId = (int) $student['student_id'];

    $academicYear = (string) $student['academic_year'];

    $grade = (int) $student['grade_number'];

    $section = (string) $student['section'];


    /*
    |--------------------------------------------------------------------------
    | Get homework and verify student's class
    |--------------------------------------------------------------------------
    */

    $homework = getStudentHomework(
        $conn,
        $homeworkId,
        $academicYear,
        $grade,
        $section
    );

    if (!$homework) {
        setFlash(
            'danger',
            'Homework not found or it does not belong to your class.'
        );

        redirectToHomework();
    }


    /*
    |--------------------------------------------------------------------------
    | Check homework status
    |--------------------------------------------------------------------------
    */

    $today = date('Y-m-d');

    $dueDate = (string) $homework['due_date'];

    $isClosed = (
        (string) $homework['status'] === 'Closed'
    );

    $isPastDue = (
        $dueDate < $today
    );

    if ($isClosed) {
        setFlash(
            'danger',
            'This homework has been closed by the teacher.'
        );

        redirectToHomework();
    }

    if ($isPastDue) {
        setFlash(
            'danger',
            'The submission deadline has passed.'
        );

        redirectToHomework();
    }


    /*
    |--------------------------------------------------------------------------
    | Validate uploaded file
    |--------------------------------------------------------------------------
    */

    if (!isset($_FILES['submission_file'])) {
        setFlash(
            'danger',
            'Please select a file to submit.'
        );

        header(
            'Location: upload.php?homework_id=' . $homeworkId
        );

        exit;
    }

    $upload = validateSubmissionUpload(
        $_FILES['submission_file']
    );

    if (!$upload['valid']) {

        setFlash(
            'danger',
            (string) $upload['error']
        );

        header(
            'Location: upload.php?homework_id=' . $homeworkId
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Prepare upload directory
    |--------------------------------------------------------------------------
    */

    ensureSubmissionUploadDirectory();

    $extension = (string) $upload['extension'];

    $filename = generateSubmissionFilename(
        $studentId,
        $homeworkId,
        $extension
    );

    $destination = submissionUploadDirectory()
        . DIRECTORY_SEPARATOR
        . $filename;

    $newFilePath = submissionRelativePath($filename);


    /*
    |--------------------------------------------------------------------------
    | Move uploaded file
    |--------------------------------------------------------------------------
    */

    if (
        !move_uploaded_file(
            (string) $upload['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'The uploaded file could not be saved.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check for existing submission
    |--------------------------------------------------------------------------
    */

    $existingSubmission = getStudentHomeworkSubmission(
        $conn,
        $homeworkId,
        $studentId
    );


    /*
    |--------------------------------------------------------------------------
    | Save database record
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        if ($existingSubmission) {

            $oldFilePath = (string) (
                $existingSubmission['file_path'] ?? ''
            );

            $sql = "
                UPDATE homework_submissions

                SET
                    file_path = ?,
                    original_file_name = ?,
                    file_type = ?,
                    file_size = ?,
                    submitted_at = NOW()

                WHERE homework_id = ?
                  AND student_id = ?
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException(
                    'Failed to prepare submission update.'
                );
            }

            $originalName = (string) $upload['original_name'];
            $mime = (string) $upload['mime'];
            $size = (int) $upload['size'];

            $stmt->bind_param(
                'sssiii',
                $newFilePath,
                $originalName,
                $mime,
                $size,
                $homeworkId,
                $studentId
            );

            if (!$stmt->execute()) {
                $stmt->close();

                throw new RuntimeException(
                    'Failed to update homework submission.'
                );
            }

            $stmt->close();

        } else {

            $sql = "
                INSERT INTO homework_submissions (
                    homework_id,
                    student_id,
                    file_path,
                    original_file_name,
                    file_type,
                    file_size,
                    submitted_at
                )

                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException(
                    'Failed to prepare submission insert.'
                );
            }

            $originalName = (string) $upload['original_name'];
            $mime = (string) $upload['mime'];
            $size = (int) $upload['size'];

            $stmt->bind_param(
                'iisssi',
                $homeworkId,
                $studentId,
                $newFilePath,
                $originalName,
                $mime,
                $size
            );

            if (!$stmt->execute()) {
                $stmt->close();

                throw new RuntimeException(
                    'Failed to save homework submission.'
                );
            }

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Mark student's homework as Done
        |--------------------------------------------------------------------------
        |
        | Submission and teacher's Done/Not Done status remain separate
        | concepts, but submitting a file means the student's submission
        | exists.
        |
        */

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();

        throw $e;
    }


    /*
    |--------------------------------------------------------------------------
    | Delete old physical file after successful DB update
    |--------------------------------------------------------------------------
    */

    if (
        $oldFilePath !== null &&
        $oldFilePath !== '' &&
        $oldFilePath !== $newFilePath
    ) {
        deleteSubmissionFile($oldFilePath);
    }


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    setFlash(
        'success',
        $existingSubmission
            ? 'Your homework submission was replaced successfully.'
            : 'Your homework was submitted successfully.'
    );

    redirectToHomework();

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Delete newly uploaded file if database operation failed
    |--------------------------------------------------------------------------
    */

    if (
        $newFilePath !== null &&
        $newFilePath !== ''
    ) {
        deleteSubmissionFile($newFilePath);
    }

    setFlash(
        'danger',
        'The homework submission could not be saved.'
    );

    header(
        'Location: upload.php?homework_id=' . $homeworkId
    );

    exit;
}