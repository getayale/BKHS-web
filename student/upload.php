<?php

declare(strict_types=1);

session_start();

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Student Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
) {
    header('Location: ../../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectToHomework(
    string $message = '',
    string $type = 'success'
): never {

    if ($message !== '') {

        $_SESSION['homework_upload_message'] =
            $message;

        $_SESSION['homework_upload_message_type'] =
            $type;
    }

    header(
        'Location: /BKHS/student/homework.php'
    );

    exit;
}

function formatEthiopianDate(
    ?string $gregorianDate
): string {

    if (
        empty($gregorianDate)
    ) {
        return '—';
    }

    try {

        $timestamp =
            strtotime($gregorianDate);

        if ($timestamp === false) {
            return '—';
        }

        $date =
            date(
                'Y-m-d',
                $timestamp
            );

        return EthiopianCalendar::fromGregorian(
            $date
        )->format('d M Y');

    } catch (Throwable $e) {

        $timestamp =
            strtotime($gregorianDate);

        if ($timestamp === false) {
            return '—';
        }

        return date(
            'd M Y',
            $timestamp
        );
    }
}

function csrfToken(): string
{
    if (
        !isset(
            $_SESSION['student_homework_csrf']
        ) ||
        !is_string(
            $_SESSION['student_homework_csrf']
        ) ||
        $_SESSION['student_homework_csrf'] === ''
    ) {

        $_SESSION['student_homework_csrf'] =
            bin2hex(
                random_bytes(32)
            );
    }

    return $_SESSION['student_homework_csrf'];
}

function verifyCsrf(
    string $token
): bool {

    return isset(
        $_SESSION['student_homework_csrf']
    )
        && is_string(
            $_SESSION['student_homework_csrf']
        )
        && hash_equals(
            $_SESSION['student_homework_csrf'],
            $token
        );
}

function formatFileSize(
    ?int $bytes
): string {

    if (
        $bytes === null ||
        $bytes <= 0
    ) {
        return '—';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format(
            $bytes / 1024,
            1
        ) . ' KB';
    }

    return number_format(
        $bytes / (1024 * 1024),
        1
    ) . ' MB';
}

function isSafeSubmissionPath(
    string $relativePath
): bool {

    $normalized =
        ltrim(
            str_replace(
                '\\',
                '/',
                $relativePath
            ),
            '/'
        );

    return str_starts_with(
        $normalized,
        'uploads/homework/submissions/'
    );
}

function physicalSubmissionPath(
    string $relativePath
): string {

    return '../../' .
        ltrim(
            str_replace(
                '\\',
                '/',
                $relativePath
            ),
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Get Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = filter_input(
    INPUT_GET,
    'homework_id',
    FILTER_VALIDATE_INT
);

if (
    !$homeworkId ||
    $homeworkId < 1
) {

    redirectToHomework(
        'Invalid homework.',
        'danger'
    );
}

/*
|--------------------------------------------------------------------------
| Get Current Student Registration
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,

        sr.id AS registration_id,

        g.grade_number,

        sec.code AS section,

        ay.id AS academic_year_id,
        ay.name AS academic_year

    FROM students s

    INNER JOIN student_registrations sr
        ON sr.student_id = s.id

    INNER JOIN academic_years ay
        ON ay.id = sr.academic_year_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    WHERE s.user_id = ?
      AND s.is_deleted = 0
      AND ay.status = 'Active'

    ORDER BY sr.id DESC

    LIMIT 1
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$student =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$student) {

    redirectToHomework(
        'Your active student registration could not be found.',
        'danger'
    );
}

$studentId =
    (int) $student['student_id'];

$registrationId =
    (int) $student['registration_id'];

$academicYearId =
    (int) $student['academic_year_id'];

$academicYear =
    (string) $student['academic_year'];

$gradeNumber =
    (int) $student['grade_number'];

$section =
    (string) $student['section'];

/*
|--------------------------------------------------------------------------
| Get Homework
|--------------------------------------------------------------------------
|
| The homework must belong to:
|
| 1. The current active academic year
| 2. The student's grade
| 3. The student's section
|
*/

$stmt = $conn->prepare("
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
      AND h.academic_year = ?
      AND h.grade = ?
      AND h.section = ?

    LIMIT 1
");

$stmt->bind_param(
    'isis',
    $homeworkId,
    $academicYear,
    $gradeNumber,
    $section
);

$stmt->execute();

$homework =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$homework) {

    redirectToHomework(
        'You are not allowed to access this homework.',
        'danger'
    );
}

/*
|--------------------------------------------------------------------------
| Get Existing Submission
|--------------------------------------------------------------------------
*/

$submission = null;

$stmt = $conn->prepare("
    SELECT
        id,
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
");

$stmt->bind_param(
    'ii',
    $homeworkId,
    $studentId
);

$stmt->execute();

$submission =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

$hasSubmission =
    $submission !== null;

/*
|--------------------------------------------------------------------------
| Submission Availability
|--------------------------------------------------------------------------
|
| Database dates are Gregorian.
| We compare Gregorian dates.
|
| Example:
| Due date = 2026-09-20
| Today    = 2026-09-20
|
| Submission is still allowed.
|
*/

$today =
    date('Y-m-d');

$dueDate =
    (string) $homework['due_date'];

$isClosed =
    strtolower(
        (string) $homework['status']
    ) === 'closed';

$isPastDue =
    $today > $dueDate;

$canSubmit =
    !$isClosed &&
    !$isPastDue;

/*
|--------------------------------------------------------------------------
| POST - Upload
|--------------------------------------------------------------------------
*/

$errors = [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $token =
        $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !verifyCsrf($token)
    ) {

        $errors[] =
            'Your session has expired. Please refresh the page and try again.';
    }

    /*
    |--------------------------------------------------------------------------
    | Re-check Submission Availability
    |--------------------------------------------------------------------------
    */

    if (!$canSubmit) {

        if ($isClosed) {

            $errors[] =
                'This homework has been closed by the teacher.';

        } elseif ($isPastDue) {

            $errors[] =
                'The due date has passed. This homework can no longer be submitted.';

        } else {

            $errors[] =
                'This homework is not currently available for submission.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Uploaded File
    |--------------------------------------------------------------------------
    */

    $uploadedFile = null;

    if (empty($errors)) {

        if (
            !isset($_FILES['submission']) ||
            !is_array($_FILES['submission'])
        ) {

            $errors[] =
                'Please select a file to submit.';

        } else {

            $uploadedFile =
                $_FILES['submission'];

            if (
                !isset($uploadedFile['error']) ||
                !isset($uploadedFile['tmp_name']) ||
                !isset($uploadedFile['name']) ||
                !isset($uploadedFile['size'])
            ) {

                $errors[] =
                    'Invalid file upload.';

            } else {

                $uploadError =
                    (int) $uploadedFile['error'];

                if (
                    $uploadError !== UPLOAD_ERR_OK
                ) {

                    $errors[] =
                        match ($uploadError) {

                            UPLOAD_ERR_INI_SIZE,
                            UPLOAD_ERR_FORM_SIZE =>
                                'The selected file is too large.',

                            UPLOAD_ERR_PARTIAL =>
                                'The file upload was incomplete.',

                            UPLOAD_ERR_NO_FILE =>
                                'Please select a file.',

                            UPLOAD_ERR_NO_TMP_DIR =>
                                'The server temporary upload directory is missing.',

                            UPLOAD_ERR_CANT_WRITE =>
                                'The server could not write the uploaded file.',

                            UPLOAD_ERR_EXTENSION =>
                                'The upload was stopped by a server extension.',

                            default =>
                                'The file could not be uploaded.'
                        };

                } else {

                    $originalName =
                        (string) $uploadedFile['name'];

                    $tmpName =
                        (string) $uploadedFile['tmp_name'];

                    $fileSize =
                        (int) $uploadedFile['size'];

                    /*
                    |--------------------------------------------------------------------------
                    | Maximum File Size
                    |--------------------------------------------------------------------------
                    */

                    $maxSize =
                        10 * 1024 * 1024;

                    if ($fileSize <= 0) {

                        $errors[] =
                            'The selected file is empty.';

                    } elseif (
                        $fileSize > $maxSize
                    ) {

                        $errors[] =
                            'The maximum file size is 10 MB.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Extension
                    |--------------------------------------------------------------------------
                    */

                    $extension =
                        strtolower(
                            pathinfo(
                                $originalName,
                                PATHINFO_EXTENSION
                            )
                        );

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

                    if (
                        !in_array(
                            $extension,
                            $allowedExtensions,
                            true
                        )
                    ) {

                        $errors[] =
                            'This file type is not allowed.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Validate Physical Upload
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !is_uploaded_file($tmpName)
                    ) {

                        $errors[] =
                            'Invalid uploaded file.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | MIME Validation
                    |--------------------------------------------------------------------------
                    */

                    $mimeType = '';

                    if (
                        empty($errors)
                    ) {

                        try {

                            $finfo =
                                new finfo(
                                    FILEINFO_MIME_TYPE
                                );

                            $mimeType =
                                (string) $finfo->file(
                                    $tmpName
                                );

                        } catch (Throwable $e) {

                            $errors[] =
                                'The uploaded file type could not be verified.';
                        }
                    }

                    $allowedMimeTypes = [

                        'pdf' => [
                            'application/pdf'
                        ],

                        'doc' => [
                            'application/msword',
                            'application/octet-stream'
                        ],

                        'docx' => [
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/zip',
                            'application/octet-stream'
                        ],

                        'ppt' => [
                            'application/vnd.ms-powerpoint',
                            'application/octet-stream'
                        ],

                        'pptx' => [
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'application/zip',
                            'application/octet-stream'
                        ],

                        'xls' => [
                            'application/vnd.ms-excel',
                            'application/octet-stream'
                        ],

                        'xlsx' => [
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/zip',
                            'application/octet-stream'
                        ],

                        'jpg' => [
                            'image/jpeg'
                        ],

                        'jpeg' => [
                            'image/jpeg'
                        ],

                        'png' => [
                            'image/png'
                        ],

                        'zip' => [
                            'application/zip',
                            'application/x-zip-compressed',
                            'application/octet-stream'
                        ]

                    ];

                    if (
                        empty($errors) &&
                        isset(
                            $allowedMimeTypes[
                                $extension
                            ]
                        ) &&
                        !in_array(
                            $mimeType,
                            $allowedMimeTypes[
                                $extension
                            ],
                            true
                        )
                    ) {

                        $errors[] =
                            'The uploaded file content does not match its file extension.';
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save Upload
    |--------------------------------------------------------------------------
    */

    $destination = null;
    $relativePath = null;

    if (
        empty($errors) &&
        is_array($uploadedFile)
    ) {

        /*
        |--------------------------------------------------------------------------
        | Upload Directory
        |--------------------------------------------------------------------------
        */

        $uploadDirectory =
            __DIR__ .
            '/../../uploads/homework/submissions/';

        if (
            !is_dir($uploadDirectory)
        ) {

            if (
                !mkdir(
                    $uploadDirectory,
                    0755,
                    true
                ) &&
                !is_dir($uploadDirectory)
            ) {

                $errors[] =
                    'The upload directory could not be created.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Directory Is Writable
        |--------------------------------------------------------------------------
        */

        if (
            empty($errors) &&
            !is_writable($uploadDirectory)
        ) {

            $errors[] =
                'The homework upload directory is not writable.';
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Safe Filename
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $extension =
                strtolower(
                    pathinfo(
                        (string) $uploadedFile['name'],
                        PATHINFO_EXTENSION
                    )
                );

            $safeFileName =
                'submission_' .
                $homeworkId .
                '_' .
                $studentId .
                '_' .
                bin2hex(
                    random_bytes(12)
                ) .
                '.' .
                $extension;

            $destination =
                $uploadDirectory .
                $safeFileName;

            $relativePath =
                'uploads/homework/submissions/' .
                $safeFileName;

            /*
            |--------------------------------------------------------------------------
            | Move File
            |--------------------------------------------------------------------------
            */

            if (
                !move_uploaded_file(
                    (string) $uploadedFile['tmp_name'],
                    $destination
                )
            ) {

                $errors[] =
                    'The uploaded file could not be saved.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Database Save
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $destination !== null &&
        $relativePath !== null
    ) {

        $originalName =
            (string) $uploadedFile['name'];

        $fileSize =
            (int) $uploadedFile['size'];

        $fileType = '';

        try {

            $finfo =
                new finfo(
                    FILEINFO_MIME_TYPE
                );

            $fileType =
                (string) $finfo->file(
                    $destination
                );

        } catch (Throwable $e) {

            $fileType =
                'application/octet-stream';
        }

        $oldFilePath =
            $hasSubmission
                ? (string) (
                    $submission['file_path'] ?? ''
                )
                : '';

        try {

            $conn->begin_transaction();

            if ($hasSubmission) {

                /*
                |--------------------------------------------------------------------------
                | Update Existing Submission
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    UPDATE homework_submissions

                    SET
                        file_path = ?,
                        original_file_name = ?,
                        file_type = ?,
                        file_size = ?,
                        submitted_at = NOW(),
                        updated_at = NOW()

                    WHERE homework_id = ?
                      AND student_id = ?
                ");

                $stmt->bind_param(
                    'sssiii',
                    $relativePath,
                    $originalName,
                    $fileType,
                    $fileSize,
                    $homeworkId,
                    $studentId
                );

                $stmt->execute();

                $stmt->close();

            } else {

                /*
                |--------------------------------------------------------------------------
                | Insert New Submission
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    INSERT INTO homework_submissions (
                        homework_id,
                        student_id,
                        file_path,
                        original_file_name,
                        file_type,
                        file_size,
                        submitted_at,
                        updated_at
                    )

                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW(),
                        NOW()
                    )
                ");

                $stmt->bind_param(
                    'iisssi',
                    $homeworkId,
                    $studentId,
                    $relativePath,
                    $originalName,
                    $fileType,
                    $fileSize
                );

                $stmt->execute();

                $stmt->close();
            }

            $conn->commit();

            /*
            |--------------------------------------------------------------------------
            | Delete Old Physical File
            |--------------------------------------------------------------------------
            |
            | Only delete the old file after the database update succeeds.
            |
            */

            if (
                $hasSubmission &&
                $oldFilePath !== '' &&
                isSafeSubmissionPath(
                    $oldFilePath
                )
            ) {

                $oldPhysicalPath =
                    physicalSubmissionPath(
                        $oldFilePath
                    );

                if (
                    is_file($oldPhysicalPath) &&
                    realpath($oldPhysicalPath) !==
                    realpath($destination)
                ) {

                    @unlink(
                        $oldPhysicalPath
                    );
                }
            }

            redirectToHomework(
                $hasSubmission
                    ? 'Your homework submission was replaced successfully.'
                    : 'Your homework was submitted successfully.',
                'success'
            );

        } catch (Throwable $e) {

            $conn->rollback();

            /*
            |--------------------------------------------------------------------------
            | Remove New File If Database Save Failed
            |--------------------------------------------------------------------------
            */

            if (
                $destination !== null &&
                is_file($destination)
            ) {

                @unlink($destination);
            }

            $errors[] =
                'The submission could not be saved. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Submit homework - BKHS Student Portal"
    >

    <title>
        <?= $hasSubmission
            ? 'Replace Submission'
            : 'Submit Homework'
        ?>
        | BKHS
    </title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --topbar-height: 68px;
            --primary: #1d4ed8;
            --primary-dark: #1e3a8a;
            --background: #f5f7fb;
            --border: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .sidebar-brand strong {
            font-size: 17px;
        }

        .sidebar-nav {
            padding: 16px 12px;
        }

        .sidebar-section {
            color: #9ca3af;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 10px 12px 7px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 13px;
            margin-bottom: 3px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            transition: .2s;
        }

        .sidebar-link:hover {
            background: rgba(255,255,255,.07);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 9px;
            font-size: 20px;
        }

        .page-content {
            max-width: 900px;
            padding: 25px 28px 40px;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Header
        |--------------------------------------------------------------------------
        */

        .page-title {
            margin-bottom: 18px;
        }

        .page-title h2 {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
        }

        .page-title p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Homework Summary
        |--------------------------------------------------------------------------
        */

        .homework-summary {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 18px;
            margin-bottom: 15px;
        }

        .subject-name {
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 4px;
        }

        .homework-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .homework-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 20px;
            color: #4b5563;
            font-size: 12px;
        }

        .homework-meta span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /*
        |--------------------------------------------------------------------------
        | Form Card
        |--------------------------------------------------------------------------
        */

        .form-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 20px;
        }

        .form-card-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .form-card-description {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 18px;
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Submission
        |--------------------------------------------------------------------------
        */

        .existing-submission {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 18px;
        }

        .existing-title {
            color: #1e40af;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .existing-file {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .existing-file-name {
            min-width: 0;
            font-size: 12px;
            word-break: break-word;
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Area
        |--------------------------------------------------------------------------
        */

        .upload-area {
            display: block;
            border: 2px dashed #cbd5e1;
            border-radius: 11px;
            padding: 25px 18px;
            text-align: center;
            cursor: pointer;
            transition: .2s;
            background: #fafbfc;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #f8fbff;
        }

        .upload-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 10px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .upload-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .upload-description {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 0;
        }

        #submission {
            display: none;
        }

        .selected-file {
            display: none;
            margin-top: 10px;
            padding: 9px 11px;
            border-radius: 8px;
            background: #f3f4f6;
            font-size: 12px;
            text-align: left;
            word-break: break-word;
        }

        /*
        |--------------------------------------------------------------------------
        | Notices
        |--------------------------------------------------------------------------
        */

        .deadline-notice {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 11px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 9px;
            font-size: 12px;
            margin-top: 15px;
        }

        .closed-notice {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .topbar {
                padding: 0 18px;
            }

            .page-content {
                padding: 20px;
            }
        }

        @media (max-width: 575.98px) {

            .page-content {
                padding: 14px 13px 30px;
            }

            .homework-summary {
                padding: 15px;
            }

            .homework-title {
                font-size: 18px;
            }

            .homework-meta {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 7px;
            }

            .form-card {
                padding: 15px;
            }

            .upload-area {
                padding: 22px 12px;
            }

            .form-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .form-actions .btn {
                width: 100%;
            }

            .existing-file {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 400px) {

            .homework-meta {
                grid-template-columns: 1fr;
            }
        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>

            <strong>BKHS</strong>

            <div
                style="font-size:11px;color:#9ca3af;"
            >
                Student Portal
            </div>

        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="sidebar-section">
            Main
        </div>

        <a
            href="../dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="../subjects.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-fill"></i>
            <span>My Subjects</span>
        </a>

        <a
            href="../materials.php"
            class="sidebar-link"
        >
            <i class="bi bi-folder-fill"></i>
            <span>Materials</span>
        </a>

        <a
            href="../result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Results</span>
        </a>

        <a
            href="../attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="../homework.php"
            class="sidebar-link active"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="../announcements.php"
            class="sidebar-link"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcements</span>
        </a>

        <div class="sidebar-section mt-2">
            Account
        </div>

        <a
            href="../profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-fill"></i>
            <span>Profile</span>
        </a>

        <a
            href="../../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<!-- Main -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <div
                    style="font-size:16px;font-weight:700;"
                >
                    <?= $hasSubmission
                        ? 'Replace Submission'
                        : 'Submit Homework'
                    ?>
                </div>

                <div
                    class="d-none d-sm-block"
                    style="font-size:11px;color:#6b7280;"
                >
                    <?= e($academicYear) ?>
                </div>

            </div>

        </div>

        <div class="d-flex align-items-center gap-2">

            <div class="d-none d-sm-block text-end">

                <div
                    style="font-size:12px;font-weight:600;"
                >
                    <?= e(
                        (string) $student['full_name']
                    ) ?>
                </div>

                <div
                    style="font-size:10px;color:#6b7280;"
                >
                    <?= e(
                        (string) $student['student_code']
                    ) ?>
                </div>

            </div>

            <div
                class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                style="width:38px;height:38px;"
            >
                <i class="bi bi-person-fill"></i>
            </div>

        </div>

    </header>

    <div class="page-content">

        <!-- Page Title -->

        <div class="page-title">

            <h2>

                <?= $hasSubmission
                    ? 'Replace Submission'
                    : 'Submit Homework'
                ?>

            </h2>

            <p>
                Upload your homework before the due date.
            </p>

        </div>

        <!-- Errors -->

        <?php if (!empty($errors)): ?>

            <div
                class="alert alert-danger"
                role="alert"
            >

                <div
                    class="fw-semibold mb-1"
                    style="font-size:13px;"
                >

                    Submission could not be completed.

                </div>

                <ul
                    class="mb-0 ps-3"
                    style="font-size:12px;"
                >

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= e($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <!-- Homework Summary -->

        <section class="homework-summary">

            <div class="subject-name">

                <i class="bi bi-book me-1"></i>

                <?= e(
                    (string) $homework['subject_name']
                ) ?>

            </div>

            <h1 class="homework-title">

                <?= e(
                    (string) $homework['title']
                ) ?>

            </h1>

            <div class="homework-meta">

                <span>

                    <i class="bi bi-person-fill"></i>

                    <?= e(
                        (string) $homework['teacher_name']
                    ) ?>

                </span>

                <span>

                    <i class="bi bi-calendar-event"></i>

                    Assigned:

                    <?= e(
                        formatEthiopianDate(
                            (string) $homework['assigned_date']
                        )
                    ) ?>

                </span>

                <span
                    class="<?= $isPastDue
                        ? 'text-danger fw-semibold'
                        : ''
                    ?>"
                >

                    <i class="bi bi-calendar-x"></i>

                    Due:

                    <?= e(
                        formatEthiopianDate(
                            (string) $homework['due_date']
                        )
                    ) ?>

                </span>

            </div>

        </section>

        <!-- Form Card -->

        <section class="form-card">

            <div class="form-card-title">

                <?= $hasSubmission
                    ? 'Replace Your Submission'
                    : 'Upload Your Homework'
                ?>

            </div>

            <div class="form-card-description">

                <?= $hasSubmission
                    ? 'Select a new file to replace your existing submission.'
                    : 'Select the file you want to submit for this homework.'
                ?>

            </div>

            <!-- Existing Submission -->

            <?php if ($hasSubmission): ?>

                <div class="existing-submission">

                    <div class="existing-title">

                        <i class="bi bi-cloud-check me-1"></i>

                        Current Submission

                    </div>

                    <div class="existing-file">

                        <div class="existing-file-name">

                            <i class="bi bi-file-earmark me-1"></i>

                            <?= e(
                                (string) $submission[
                                    'original_file_name'
                                ]
                            ) ?>

                            <?php if (
                                isset(
                                    $submission['file_size']
                                )
                            ): ?>

                                <span
                                    class="text-muted ms-1"
                                    style="font-size:10px;"
                                >

                                    (
                                    <?= e(
                                        formatFileSize(
                                            (int) $submission[
                                                'file_size'
                                            ]
                                        )
                                    ) ?>
                                    )

                                </span>

                            <?php endif; ?>

                        </div>

                        <span
                            class="badge bg-primary-subtle text-primary"
                            style="font-size:10px;"
                        >
                            Submitted
                        </span>

                    </div>

                </div>

            <?php endif; ?>

            <?php if ($canSubmit): ?>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    id="uploadForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(
                            csrfToken()
                        ) ?>"
                    >

                    <label
                        for="submission"
                        class="upload-area"
                    >

                        <div class="upload-icon">

                            <i class="bi bi-cloud-arrow-up"></i>

                        </div>

                        <div class="upload-title">

                            Click to select your file

                        </div>

                        <p class="upload-description">

                            PDF, DOC, DOCX, PPT, PPTX,
                            XLS, XLSX, JPG, JPEG, PNG or ZIP

                            <br>

                            Maximum file size: 10 MB

                        </p>

                        <div
                            class="selected-file"
                            id="selectedFile"
                        >

                            <i class="bi bi-file-earmark me-1"></i>

                            <span
                                id="selectedFileName"
                            ></span>

                        </div>

                    </label>

                    <input
                        type="file"
                        id="submission"
                        name="submission"
                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                        required
                    >

                    <!-- Deadline -->

                    <div class="deadline-notice">

                        <i class="bi bi-info-circle-fill"></i>

                        <div>

                            Your submission must be uploaded on or before

                            <strong>
                                <?= e(
                                    formatEthiopianDate(
                                        (string) $homework['due_date']
                                    )
                                ) ?>
                            </strong>.

                            <?php if ($hasSubmission): ?>

                                You can replace your existing file
                                until the deadline.

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="form-actions">

                        <a
                            href="../homework.php"
                            class="btn btn-outline-secondary"
                        >

                            <i class="bi bi-arrow-left me-1"></i>

                            Back to Homework

                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary"
                            id="submitButton"
                        >

                            <i class="bi bi-upload me-1"></i>

                            <?= $hasSubmission
                                ? 'Replace Submission'
                                : 'Submit Homework'
                            ?>

                        </button>

                    </div>

                </form>

            <?php else: ?>

                <div class="deadline-notice closed-notice">

                    <i class="bi bi-lock-fill"></i>

                    <div>

                        <?php if ($isClosed): ?>

                            This homework has been closed by the teacher.
                            New submissions are no longer accepted.

                        <?php elseif ($isPastDue): ?>

                            The due date has passed.
                            New submissions are no longer accepted.

                        <?php else: ?>

                            This homework is not currently available
                            for submission.

                        <?php endif; ?>

                    </div>

                </div>

                <?php if ($hasSubmission): ?>

                    <div
                        class="alert alert-info mt-3 mb-0"
                        style="font-size:12px;"
                    >

                        <i class="bi bi-check-circle me-1"></i>

                        Your previous submission is still recorded.

                    </div>

                <?php endif; ?>

                <div class="form-actions">

                    <a
                        href="../homework.php"
                        class="btn btn-outline-secondary"
                    >

                        <i class="bi bi-arrow-left me-1"></i>

                        Back to Homework

                    </a>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<script>

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar =
        document.getElementById(
            'sidebar'
        );

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    const menuButton =
        document.getElementById(
            'mobileMenuBtn'
        );

    function openSidebar() {

        sidebar.classList.add(
            'show'
        );

        overlay.classList.add(
            'show'
        );

        document.body.style.overflow =
            'hidden';
    }

    function closeSidebar() {

        sidebar.classList.remove(
            'show'
        );

        overlay.classList.remove(
            'show'
        );

        document.body.style.overflow =
            '';
    }

    menuButton?.addEventListener(
        'click',
        openSidebar
    );

    overlay?.addEventListener(
        'click',
        closeSidebar
    );

    document.querySelectorAll(
        '.sidebar-link'
    ).forEach(link => {

        link.addEventListener(
            'click',
            () => {

                if (
                    window.innerWidth < 992
                ) {
                    closeSidebar();
                }

            }
        );

    });

    window.addEventListener(
        'resize',
        () => {

            if (
                window.innerWidth >= 992
            ) {
                closeSidebar();
            }

        }
    );

    /*
    |--------------------------------------------------------------------------
    | File Selection
    |--------------------------------------------------------------------------
    */

    const fileInput =
        document.getElementById(
            'submission'
        );

    const selectedFile =
        document.getElementById(
            'selectedFile'
        );

    const selectedFileName =
        document.getElementById(
            'selectedFileName'
        );

    fileInput?.addEventListener(
        'change',
        () => {

            if (
                fileInput.files &&
                fileInput.files.length > 0
            ) {

                const file =
                    fileInput.files[0];

                selectedFileName.textContent =
                    file.name;

                selectedFile.style.display =
                    'block';

            } else {

                selectedFileName.textContent =
                    '';

                selectedFile.style.display =
                    'none';
            }

        }
    );

    /*
    |--------------------------------------------------------------------------
    | Prevent Double Submission
    |--------------------------------------------------------------------------
    */

    const uploadForm =
        document.getElementById(
            'uploadForm'
        );

    const submitButton =
        document.getElementById(
            'submitButton'
        );

    uploadForm?.addEventListener(
        'submit',
        event => {

            if (
                !fileInput ||
                !fileInput.files ||
                fileInput.files.length === 0
            ) {

                event.preventDefault();

                alert(
                    'Please select a file before submitting.'
                );

                return;
            }

            if (submitButton) {

                submitButton.disabled =
                    true;

                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>' +
                    'Uploading...';
            }

        }
    );

</script>

</body>

</html>