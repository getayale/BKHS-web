<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$studentUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Only POST requests are allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    !isset($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals(
        (string) $_SESSION['csrf_token'],
        (string) $_POST['csrf_token']
    )
) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid or expired request. Please try again.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = filter_input(
    INPUT_POST,
    'homework_id',
    FILTER_VALIDATE_INT
);

if (!$homeworkId || $homeworkId <= 0) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid homework.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get student
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "
    SELECT
        s.id AS student_id,
        s.student_code
    FROM students s
    INNER JOIN users u
        ON u.id = s.user_id
    WHERE u.id = ?
    LIMIT 1
    "
);

$stmt->bind_param(
    'i',
    $studentUserId
);

$stmt->execute();

$result = $stmt->get_result();

$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Student record could not be found.'
    ];

    header('Location: ../homework.php');
    exit;
}

$studentId = (int) $student['student_id'];

/*
|--------------------------------------------------------------------------
| Get homework
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "
    SELECT
        h.id,
        h.academic_year,
        h.teacher_user_id,
        h.grade,
        h.section,
        h.grade_subject_id,
        h.title,
        h.assigned_date,
        h.due_date,
        h.status
    FROM homeworks h
    WHERE h.id = ?
    LIMIT 1
    "
);

$stmt->bind_param(
    'i',
    $homeworkId
);

$stmt->execute();

$result = $stmt->get_result();

$homework = $result->fetch_assoc();

$stmt->close();

if (!$homework) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Homework was not found.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Homework must be active
|--------------------------------------------------------------------------
*/

if ((string) $homework['status'] !== 'Active') {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'This homework is closed.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Verify student belongs to homework class
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "
    SELECT sr.id
    FROM student_registrations sr
    INNER JOIN grades g
        ON g.id = sr.grade_id
    INNER JOIN sections sec
        ON sec.id = sr.section_id
    WHERE sr.student_id = ?
      AND sr.academic_year_id = (
          SELECT ay.id
          FROM academic_years ay
          WHERE ay.name = ?
            AND ay.status = 'Active'
          LIMIT 1
      )
      AND g.grade_number = ?
      AND sec.code = ?
    LIMIT 1
    "
);

$grade = (int) $homework['grade'];
$section = (string) $homework['section'];
$academicYear = (string) $homework['academic_year'];

$stmt->bind_param(
    'isis',
    $studentId,
    $academicYear,
    $grade,
    $section
);

$stmt->execute();

$result = $stmt->get_result();

$registration = $result->fetch_assoc();

$stmt->close();

if (!$registration) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'You are not registered in this homework class.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Check due date
|--------------------------------------------------------------------------
*/

$today = new DateTimeImmutable(
    'now',
    new DateTimeZone('Africa/Addis_Ababa')
);

$todayDate = $today->format('Y-m-d');

if ($todayDate > (string) $homework['due_date']) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'The submission deadline has passed.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['submission']) ||
    !is_array($_FILES['submission'])
) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Please select a file to submit.'
    ];

    header('Location: ../homework.php');
    exit;
}

$file = $_FILES['submission'];

$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError !== UPLOAD_ERR_OK) {

    $message = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded file is too large.',

        UPLOAD_ERR_PARTIAL =>
            'The file upload was incomplete.',

        UPLOAD_ERR_NO_FILE =>
            'Please select a file to submit.',

        default =>
            'The file could not be uploaded.'
    };

    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => $message
    ];

    header('Location: ../homework.php');
    exit;
}

if (!is_uploaded_file((string) $file['tmp_name'])) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid uploaded file.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Maximum file size: 10 MB
|--------------------------------------------------------------------------
*/

$maxFileSize = 10 * 1024 * 1024;

$fileSize = (int) ($file['size'] ?? 0);

if ($fileSize <= 0 || $fileSize > $maxFileSize) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'File size must be greater than 0 and cannot exceed 10 MB.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Allowed extensions
|--------------------------------------------------------------------------
*/

$allowedExtensions = [
    'pdf',
    'doc',
    'docx',
    'ppt',
    'pptx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png',
    'zip'
];

$originalName = basename(
    (string) ($file['name'] ?? '')
);

$extension = strtolower(
    pathinfo($originalName, PATHINFO_EXTENSION)
);

if (
    $originalName === '' ||
    !in_array($extension, $allowedExtensions, true)
) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'This file type is not allowed.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Detect MIME type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file(
    (string) $file['tmp_name']
);

$allowedMimeTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
    'application/zip',
    'application/x-zip-compressed',
    'application/octet-stream'
];

if (
    $mimeType === false ||
    !in_array($mimeType, $allowedMimeTypes, true)
) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'The uploaded file type could not be verified.'
    ];

    header('Location: ../homework.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Generate secure filename
|--------------------------------------------------------------------------
*/

$filename = bin2hex(random_bytes(20)) . '.' . $extension;

$uploadDirectory =
    dirname(__DIR__, 2) .
    DIRECTORY_SEPARATOR .
    'uploads' .
    DIRECTORY_SEPARATOR .
    'homeworks' .
    DIRECTORY_SEPARATOR .
    'submissions';

if (!is_dir($uploadDirectory)) {

    if (
        !mkdir(
            $uploadDirectory,
            0755,
            true
        ) &&
        !is_dir($uploadDirectory)
    ) {
        $_SESSION['homework_flash'] = [
            'type' => 'danger',
            'message' => 'The upload directory could not be created.'
        ];

        header('Location: ../homework.php');
        exit;
    }
}

$newPhysicalPath =
    $uploadDirectory .
    DIRECTORY_SEPARATOR .
    $filename;

if (
    !move_uploaded_file(
        (string) $file['tmp_name'],
        $newPhysicalPath
    )
) {
    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'The file could not be saved.'
    ];

    header('Location: ../homework.php');
    exit;
}

$newDatabasePath =
    'uploads/homeworks/submissions/' . $filename;

/*
|--------------------------------------------------------------------------
| Existing submission
|--------------------------------------------------------------------------
*/

$oldSubmission = null;

$stmt = $conn->prepare(
    "
    SELECT
        id,
        file_path
    FROM homework_submissions
    WHERE homework_id = ?
      AND student_id = ?
    LIMIT 1
    "
);

$stmt->bind_param(
    'ii',
    $homeworkId,
    $studentId
);

$stmt->execute();

$result = $stmt->get_result();

$oldSubmission = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Save submission
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    if ($oldSubmission) {

        $stmt = $conn->prepare(
            "
            UPDATE homework_submissions
            SET
                file_path = ?,
                original_file_name = ?,
                file_type = ?,
                file_size = ?,
                submitted_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND homework_id = ?
              AND student_id = ?
            "
        );

        $oldSubmissionId = (int) $oldSubmission['id'];

        $stmt->bind_param(
            'sssiiii',
            $newDatabasePath,
            $originalName,
            $mimeType,
            $fileSize,
            $oldSubmissionId,
            $homeworkId,
            $studentId
        );

        $stmt->execute();

        $affectedRows = $stmt->affected_rows;

        $stmt->close();

        if ($affectedRows < 0) {
            throw new RuntimeException(
                'Submission could not be replaced.'
            );
        }

    } else {

        $stmt = $conn->prepare(
            "
            INSERT INTO homework_submissions (
                homework_id,
                student_id,
                file_path,
                original_file_name,
                file_type,
                file_size,
                submitted_at
            )
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            "
        );

        $stmt->bind_param(
            'iisssi',
            $homeworkId,
            $studentId,
            $newDatabasePath,
            $originalName,
            $mimeType,
            $fileSize
        );

        $stmt->execute();

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Mark student homework as Done
    |--------------------------------------------------------------------------
    |
    | Submission and teacher status are separate concepts.
    | We only mark the student's own submission record here.
    |
    */

    $stmt = $conn->prepare(
        "
        INSERT INTO homework_student_status (
            homework_id,
            student_id,
            status,
            completed_at
        )
        VALUES (?, ?, 'Done', CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            status = 'Done',
            completed_at = CURRENT_TIMESTAMP
        "
    );

    $stmt->bind_param(
        'ii',
        $homeworkId,
        $studentId
    );

    $stmt->execute();

    $stmt->close();

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | Delete old physical file
    |--------------------------------------------------------------------------
    */

    if ($oldSubmission && !empty($oldSubmission['file_path'])) {

        $oldPhysicalPath =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                (string) $oldSubmission['file_path']
            );

        if (
            is_file($oldPhysicalPath) &&
            $oldPhysicalPath !== $newPhysicalPath
        ) {
            @unlink($oldPhysicalPath);
        }
    }

    $_SESSION['homework_flash'] = [
        'type' => 'success',
        'message' => $oldSubmission
            ? 'Your homework submission was replaced successfully.'
            : 'Your homework was submitted successfully.'
    ];

} catch (Throwable $e) {

    $conn->rollback();

    /*
    |--------------------------------------------------------------------------
    | Delete newly uploaded file if DB operation failed
    |--------------------------------------------------------------------------
    */

    if (is_file($newPhysicalPath)) {
        @unlink($newPhysicalPath);
    }

    $_SESSION['homework_flash'] = [
        'type' => 'danger',
        'message' => 'Your homework could not be submitted. Please try again.'
    ];
}

/*
|--------------------------------------------------------------------------
| Return to student homework page
|--------------------------------------------------------------------------
*/

header('Location: ../homework.php');
exit;