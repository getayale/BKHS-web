<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$calendarPath =
    __DIR__ .
    '/../../includes/EthiopianCalendar.php';

if (is_file($calendarPath)) {
    require_once $calendarPath;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatFileSize(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return 'Unknown size';
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

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format(
            $bytes / (1024 * 1024),
            1
        ) . ' MB';
    }

    return number_format(
        $bytes / (1024 * 1024 * 1024),
        1
    ) . ' GB';
}

function materialIcon(string $extension): string
{
    return match (strtolower($extension)) {

        'pdf' =>
            'bi-file-earmark-pdf-fill',

        'doc',
        'docx' =>
            'bi-file-earmark-word-fill',

        'ppt',
        'pptx' =>
            'bi-file-earmark-ppt-fill',

        'xls',
        'xlsx' =>
            'bi-file-earmark-excel-fill',

        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp' =>
            'bi-file-earmark-image-fill',

        'zip' =>
            'bi-file-earmark-zip-fill',

        default =>
            'bi-file-earmark-fill'
    };
}

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

$teacherUserId =
    (int) $_SESSION['user_id'];

$teacherName = 'Teacher';

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$ethiopianToday = date('d M Y');

if (class_exists('EthiopianCalendar')) {

    try {

        $ethiopianToday =
            EthiopianCalendar::todayFormatted('en');

    } catch (Throwable $e) {

        $ethiopianToday = date('d M Y');
    }
}

/*
|--------------------------------------------------------------------------
| Get Teacher Name
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT full_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $teacherUserId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $teacherName =
            (string) $row['full_name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Material ID
|--------------------------------------------------------------------------
*/

$materialId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    !$materialId ||
    $materialId < 1
) {

    header(
        'Location: ../materials.php'
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Material
|--------------------------------------------------------------------------
*/

$material = null;

$stmt = $conn->prepare("
    SELECT
        tm.id,
        tm.teacher_user_id,
        tm.grade_subject_id,
        tm.academic_year,
        tm.title,
        tm.description,
        tm.file_name,
        tm.file_path,
        tm.file_type,
        tm.file_size,
        tm.created_at,
        tm.updated_at,
        gs.grade,
        gs.subject_name
    FROM teacher_materials tm
    INNER JOIN grade_subjects gs
        ON gs.id = tm.grade_subject_id
    WHERE tm.id = ?
      AND tm.teacher_user_id = ?
      AND tm.is_active = 1
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'ii',
        $materialId,
        $teacherUserId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $material =
        $result->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Material Not Found
|--------------------------------------------------------------------------
*/

if (!$material) {

    http_response_code(404);

    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>
            Material Not Found | BKHS
        </title>
   <link
        rel="icon"
        type="image/webp"
        href="/../../public/image/logo.webp"
    >
        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
            rel="stylesheet"
        >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
            rel="stylesheet"
        >

        <style>

            body {
                background: #f8fafc;
                font-family:
                    Inter,
                    system-ui,
                    sans-serif;
            }

            .error-card {
                max-width: 520px;
                margin: 100px auto;
                padding: 40px;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 18px;
                text-align: center;
                box-shadow:
                    0 10px 30px
                    rgba(15,23,42,.06);
            }

            .error-icon {
                width: 70px;
                height: 70px;
                margin: 0 auto 20px;
                border-radius: 50%;
                background: #fee2e2;
                color: #dc2626;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 30px;
            }

        </style>

    </head>

    <body>

        <div class="error-card">

            <div class="error-icon">

                <i
                    class="bi bi-file-earmark-x"
                ></i>

            </div>

            <h4 class="fw-bold mb-2">
                Material Not Found
            </h4>

            <p class="text-muted mb-4">

                The requested material does not
                exist or you do not have permission
                to edit it.

            </p>

            <a
                href="../materials.php"
                class="btn btn-primary"
            >

                <i
                    class="bi bi-arrow-left me-1"
                ></i>

                Back to Materials

            </a>

        </div>

    </body>

    </html>

    <?php

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = '';

$stmt = $conn->prepare("
    SELECT name
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $activeAcademicYear =
            (string) $row['name'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Get Teacher Subject Assignments
|--------------------------------------------------------------------------
|
| Only assignments belonging to this teacher
| and the active academic year are loaded.
|
*/

$assignments = [];

if ($activeAcademicYear !== '') {

    $stmt = $conn->prepare("
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
    ");

    if ($stmt) {

        $stmt->bind_param(
            'is',
            $teacherUserId,
            $activeAcademicYear
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $assignments[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Current Assignment
|--------------------------------------------------------------------------
*/

$currentAssignmentId = 0;

/*
|--------------------------------------------------------------------------
| Find Assignment Matching Existing Material
|--------------------------------------------------------------------------
|
| teacher_materials stores teacher + subject + academic year.
| We therefore find a matching assignment for the
| existing material.
|
*/

foreach (
    $assignments as $assignment
) {

    if (
        (int) $assignment['grade_subject_id']
        ===
        (int) $material['grade_subject_id']
    ) {

        $currentAssignmentId =
            (int) $assignment['id'];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/

$title = (string) $material['title'];

$description =
    (string) (
        $material['description'] ?? ''
    );

$selectedAssignmentId =
    $currentAssignmentId;

$errors = [];

$successMessage = '';

/*
|--------------------------------------------------------------------------
| Current File Path
|--------------------------------------------------------------------------
*/

$currentFilePath =
    trim(
        (string) (
            $material['file_path'] ?? ''
        )
    );

$currentNormalizedPath =
    str_replace(
        '\\',
        '/',
        $currentFilePath
    );

$currentNormalizedPath =
    preg_replace(
        '#/+#',
        '/',
        $currentNormalizedPath
    );

$currentNormalizedPath =
    ltrim(
        (string) $currentNormalizedPath,
        '/'
    );

/*
|--------------------------------------------------------------------------
| Current Physical File
|--------------------------------------------------------------------------
*/

$currentPhysicalFilePath = '';

$currentPhysicalFileExists = false;

$isCurrentPathValid =
    str_starts_with(
        strtolower(
            $currentNormalizedPath
        ),
        'uploads/materials/'
    ) &&
    !str_contains(
        $currentNormalizedPath,
        '../'
    ) &&
    !str_contains(
        $currentNormalizedPath,
        '..\\'
    );

if ($isCurrentPathValid) {

    $currentPhysicalFilePath =
        dirname(__DIR__, 2) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $currentNormalizedPath
        );

    $currentPhysicalFileExists =
        is_file(
            $currentPhysicalFilePath
        );
}

/*
|--------------------------------------------------------------------------
| Allowed File Extensions
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
    'gif',
    'webp',
    'zip'

];

/*
|--------------------------------------------------------------------------
| Maximum Upload Size
|--------------------------------------------------------------------------
*/

$maxFileSize =
    20 * 1024 * 1024;

/*
|--------------------------------------------------------------------------
| Handle Update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF-like Session Form Token
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $_SESSION['material_edit_token']
        )
    ) {

        $_SESSION['material_edit_token'] =
            bin2hex(
                random_bytes(32)
            );
    }

    $submittedToken =
        (string) (
            $_POST['form_token'] ?? ''
        );

    if (
        !hash_equals(
            (string)
            $_SESSION['material_edit_token'],
            $submittedToken
        )
    ) {

        $errors[] =
            'Invalid form submission. Please refresh the page and try again.';
    }

    /*
    |--------------------------------------------------------------------------
    | Read Form Values
    |--------------------------------------------------------------------------
    */

    $title =
        trim(
            (string) (
                $_POST['title'] ?? ''
            )
        );

    $description =
        trim(
            (string) (
                $_POST['description'] ?? ''
            )
        );

    $selectedAssignmentId =
        filter_input(
            INPUT_POST,
            'assignment_id',
            FILTER_VALIDATE_INT
        );

    if (
        !$selectedAssignmentId ||
        $selectedAssignmentId < 1
    ) {

        $errors[] =
            'Please select a class and subject.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Title
    |--------------------------------------------------------------------------
    */

    if ($title === '') {

        $errors[] =
            'Please enter a material title.';

    } elseif (mb_strlen($title) > 255) {

        $errors[] =
            'Material title cannot exceed 255 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Assignment
    |--------------------------------------------------------------------------
    */

    $selectedAssignment = null;

    if (
        $selectedAssignmentId &&
        $activeAcademicYear !== ''
    ) {

        $stmt = $conn->prepare("
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
        ");

        if ($stmt) {

            $stmt->bind_param(
                'iis',
                $selectedAssignmentId,
                $teacherUserId,
                $activeAcademicYear
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $selectedAssignment =
                $result->fetch_assoc();

            $stmt->close();
        }
    }

    if (
        !$selectedAssignment &&
        empty($errors)
    ) {

        $errors[] =
            'The selected class and subject assignment is not valid.';
    }

    /*
    |--------------------------------------------------------------------------
    | File Upload Validation
    |--------------------------------------------------------------------------
    */

    $newFileUploaded = false;

    $newFileName = '';

    $newDatabaseFilePath = '';

    $newPhysicalFilePath = '';

    $newFileType = '';

    $newFileSize = null;

    if (
        isset($_FILES['material_file']) &&
        is_array(
            $_FILES['material_file']
        ) &&
        (
            (int) (
                $_FILES['material_file']['error']
                ?? UPLOAD_ERR_NO_FILE
            )
        ) !== UPLOAD_ERR_NO_FILE
    ) {

        $uploadError =
            (int) (
                $_FILES['material_file']['error']
                ?? UPLOAD_ERR_NO_FILE
            );

        if (
            $uploadError !== UPLOAD_ERR_OK
        ) {

            $uploadErrorMessages = [

                UPLOAD_ERR_INI_SIZE =>
                    'The uploaded file exceeds the server upload limit.',

                UPLOAD_ERR_FORM_SIZE =>
                    'The uploaded file exceeds the allowed form size.',

                UPLOAD_ERR_PARTIAL =>
                    'The file upload was incomplete.',

                UPLOAD_ERR_NO_FILE =>
                    'No file was uploaded.',

                UPLOAD_ERR_NO_TMP_DIR =>
                    'The server temporary upload directory is missing.',

                UPLOAD_ERR_CANT_WRITE =>
                    'The server could not write the uploaded file.',

                UPLOAD_ERR_EXTENSION =>
                    'The file upload was stopped by a server extension.'

            ];

            $errors[] =
                $uploadErrorMessages[$uploadError]
                ??
                'The file could not be uploaded.';

        } else {

            $uploadedTmpName =
                (string) (
                    $_FILES['material_file']['tmp_name']
                    ?? ''
                );

            $uploadedOriginalName =
                (string) (
                    $_FILES['material_file']['name']
                    ?? ''
                );

            $uploadedSize =
                (int) (
                    $_FILES['material_file']['size']
                    ?? 0
                );

            /*
            |--------------------------------------------------------------------------
            | Check Temporary File
            |--------------------------------------------------------------------------
            */

            if (
                $uploadedTmpName === '' ||
                !is_uploaded_file(
                    $uploadedTmpName
                )
            ) {

                $errors[] =
                    'The uploaded file is invalid.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | File Size
                |--------------------------------------------------------------------------
                */

                if (
                    $uploadedSize <= 0
                ) {

                    $errors[] =
                        'The uploaded file is empty.';

                } elseif (
                    $uploadedSize >
                    $maxFileSize
                ) {

                    $errors[] =
                        'The maximum file size is 20 MB.';
                }

                /*
                |--------------------------------------------------------------------------
                | Extension
                |--------------------------------------------------------------------------
                */

                $uploadedExtension =
                    strtolower(
                        pathinfo(
                            $uploadedOriginalName,
                            PATHINFO_EXTENSION
                        )
                    );

                if (
                    !in_array(
                        $uploadedExtension,
                        $allowedExtensions,
                        true
                    )
                ) {

                    $errors[] =
                        'This file type is not allowed.';
                }

                /*
                |--------------------------------------------------------------------------
                | MIME Type
                |--------------------------------------------------------------------------
                */

                $detectedMimeType = '';

                if (
                    class_exists('finfo')
                ) {

                    try {

                        $finfo =
                            new finfo(
                                FILEINFO_MIME_TYPE
                            );

                        $detectedMimeType =
                            (string) (
                                $finfo->file(
                                    $uploadedTmpName
                                )
                                ?: ''
                            );

                    } catch (Throwable $e) {

                        $detectedMimeType = '';
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Generate Safe File Name
                |--------------------------------------------------------------------------
                */

                if (
                    empty($errors)
                ) {

                    $newFileName =
                        date('YmdHis') .
                        '_' .
                        $teacherUserId .
                        '_' .
                        bin2hex(
                            random_bytes(16)
                        ) .
                        '.' .
                        $uploadedExtension;

                    /*
                    |--------------------------------------------------------------------------
                    | Upload Directory
                    |--------------------------------------------------------------------------
                    |
                    | BKHS/uploads/materials/
                    |
                    */

                    $uploadDirectory =
                        dirname(__DIR__, 2) .
                        DIRECTORY_SEPARATOR .
                        'uploads' .
                        DIRECTORY_SEPARATOR .
                        'materials';

                    if (
                        !is_dir(
                            $uploadDirectory
                        )
                    ) {

                        if (
                            !mkdir(
                                $uploadDirectory,
                                0755,
                                true
                            ) &&
                            !is_dir(
                                $uploadDirectory
                            )
                        ) {

                            $errors[] =
                                'The upload directory could not be created.';
                        }
                    }

                    if (
                        empty($errors)
                    ) {

                        $newPhysicalFilePath =
                            $uploadDirectory .
                            DIRECTORY_SEPARATOR .
                            $newFileName;

                        $newDatabaseFilePath =
                            'uploads/materials/' .
                            $newFileName;

                        /*
                        |--------------------------------------------------------------------------
                        | Move Uploaded File
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !move_uploaded_file(
                                $uploadedTmpName,
                                $newPhysicalFilePath
                            )
                        ) {

                            $errors[] =
                                'The uploaded file could not be saved on the server.';

                        } else {

                            $newFileUploaded =
                                true;

                            $newFileType =
                                $detectedMimeType;

                            $newFileSize =
                                $uploadedSize;
                        }
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Database
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        /*
        |--------------------------------------------------------------------------
        | Important:
        | teacher_materials does not currently store assignment ID.
        | It stores grade_subject_id + academic_year.
        |--------------------------------------------------------------------------
        */

        $newGradeSubjectId =
            (int) (
                $selectedAssignment[
                    'grade_subject_id'
                ]
            );

        /*
        |--------------------------------------------------------------------------
        | Existing File Values
        |--------------------------------------------------------------------------
        */

        $databaseFileName =
            (string) $material['file_name'];

        $databaseFilePath =
            (string) $material['file_path'];

        $databaseFileType =
            (string) (
                $material['file_type'] ?? ''
            );

        $databaseFileSize =
            isset(
                $material['file_size']
            )
                ? (int)
                    $material['file_size']
                : null;

        /*
        |--------------------------------------------------------------------------
        | If New File Uploaded
        |--------------------------------------------------------------------------
        */

        if ($newFileUploaded) {

            $databaseFileName =
                $newFileName;

            $databaseFilePath =
                $newDatabaseFilePath;

            $databaseFileType =
                $newFileType;

            $databaseFileSize =
                $newFileSize;
        }

        /*
        |--------------------------------------------------------------------------
        | Update With File
        |--------------------------------------------------------------------------
        */

        if ($newFileUploaded) {

            $stmt = $conn->prepare("
                UPDATE teacher_materials
                SET
                    grade_subject_id = ?,
                    title = ?,
                    description = ?,
                    file_name = ?,
                    file_path = ?,
                    file_type = ?,
                    file_size = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND teacher_user_id = ?
                  AND is_active = 1
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'isssssiii',
                    $newGradeSubjectId,
                    $title,
                    $description,
                    $databaseFileName,
                    $databaseFilePath,
                    $databaseFileType,
                    $databaseFileSize,
                    $materialId,
                    $teacherUserId
                );

                $updated =
                    $stmt->execute();

                $stmt->close();

            } else {

                $updated = false;
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | Update Without Replacing File
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE teacher_materials
                SET
                    grade_subject_id = ?,
                    title = ?,
                    description = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND teacher_user_id = ?
                  AND is_active = 1
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'issii',
                    $newGradeSubjectId,
                    $title,
                    $description,
                    $materialId,
                    $teacherUserId
                );

                $updated =
                    $stmt->execute();

                $stmt->close();

            } else {

                $updated = false;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Database Update Failed
        |--------------------------------------------------------------------------
        */

        if (!$updated) {

            /*
            |--------------------------------------------------------------------------
            | Remove Newly Uploaded File
            |--------------------------------------------------------------------------
            */

            if (
                $newFileUploaded &&
                $newPhysicalFilePath !== '' &&
                is_file(
                    $newPhysicalFilePath
                )
            ) {

                @unlink(
                    $newPhysicalFilePath
                );
            }

            $errors[] =
                'The material could not be updated. Please try again.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Delete Old Physical File
            |--------------------------------------------------------------------------
            |
            | Only delete the old file AFTER the database update
            | succeeds.
            |--------------------------------------------------------------------------
            */

            if (
                $newFileUploaded &&
                $currentPhysicalFileExists &&
                $currentPhysicalFilePath !== ''
            ) {

                /*
                |--------------------------------------------------------------------------
                | Make sure the old path is inside uploads/materials
                |--------------------------------------------------------------------------
                */

                $oldRealPath =
                    realpath(
                        $currentPhysicalFilePath
                    );

                $materialsDirectory =
                    realpath(
                        dirname(__DIR__, 2) .
                        DIRECTORY_SEPARATOR .
                        'uploads' .
                        DIRECTORY_SEPARATOR .
                        'materials'
                    );

                if (
                    $oldRealPath !== false &&
                    $materialsDirectory !== false &&
                    str_starts_with(
                        strtolower(
                            $oldRealPath
                        ),
                        strtolower(
                            $materialsDirectory .
                            DIRECTORY_SEPARATOR
                        )
                    )
                ) {

                    @unlink(
                        $oldRealPath
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Success Redirect
            |--------------------------------------------------------------------------
            */

            header(
                'Location: ../materials.php?success=' .
                urlencode(
                    'Material updated successfully.'
                )
            );

            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Regenerate Form Token After Submission Error
    |--------------------------------------------------------------------------
    */

    $_SESSION['material_edit_token'] =
        bin2hex(
            random_bytes(32)
        );
}

/*
|--------------------------------------------------------------------------
| Create Form Token
|--------------------------------------------------------------------------
*/

if (
    !isset(
        $_SESSION['material_edit_token']
    )
) {

    $_SESSION['material_edit_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$formToken =
    (string)
    $_SESSION['material_edit_token'];

/*
|--------------------------------------------------------------------------
| Current File Extension
|--------------------------------------------------------------------------
*/

$currentExtension =
    strtolower(
        pathinfo(
            (string)
            $material['file_name'],
            PATHINFO_EXTENSION
        )
    );

/*
|--------------------------------------------------------------------------
| Current File URL
|--------------------------------------------------------------------------
*/

$currentFileUrl =
    '';

if ($isCurrentPathValid) {

    $currentFileUrl =
        '../../' .
        $currentNormalizedPath;
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

    <title>
        Edit Material | BKHS Teacher Portal
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {

            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f8fafc;

        }

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            background:
                var(--background);

            color:
                var(--text);

            font-family:
                'Inter',
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

            width: 260px;

            height: 100vh;

            background:
                var(--sidebar);

            color: #fff;

            z-index: 1050;

            overflow-y: auto;

            transition:
                transform .25s ease;

        }

        .brand {

            height: 76px;

            display: flex;

            align-items: center;

            padding:
                0 22px;

            border-bottom:
                1px solid
                rgba(255,255,255,.08);

        }

        .brand-icon {

            width: 40px;
            height: 40px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                var(--primary);

            margin-right: 12px;

            font-size: 20px;

        }

        .brand-title {

            font-size: 16px;

            font-weight: 700;

            line-height: 1.2;

        }

        .brand-subtitle {

            font-size: 11px;

            color: #9ca3af;

            margin-top: 2px;

        }

        .sidebar-section {

            padding:
                20px 14px 8px;

            color: #6b7280;

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: .08em;

            font-weight: 700;

        }

        .sidebar-nav {

            padding:
                8px 12px 20px;

        }

        .sidebar-nav a {

            display: flex;

            align-items: center;

            gap: 12px;

            padding:
                11px 13px;

            margin-bottom: 4px;

            color: #d1d5db;

            text-decoration: none;

            border-radius: 9px;

            font-size: 13px;

            font-weight: 500;

            transition:
                .2s ease;

        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {

            color: #fff;

            background:
                var(--sidebar-hover);

        }

        .sidebar-nav a.active {

            background:
                var(--primary);

        }

        .sidebar-nav i {

            font-size: 17px;

            width: 20px;

            text-align: center;

        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {

            margin-left: 260px;

            min-height: 100vh;

        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {

            height: 76px;

            background: #fff;

            border-bottom:
                1px solid
                var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 28px;

            position: sticky;

            top: 0;

            z-index: 1000;

        }

        .page-title {

            font-size: 18px;

            font-weight: 700;

            margin: 0;

        }

        .page-subtitle {

            color:
                var(--muted);

            font-size: 12px;

            margin-top: 3px;

        }

        .topbar-right {

            display: flex;

            align-items: center;

            gap: 16px;

        }

        .ethiopian-date {

            color:
                var(--muted);

            font-size: 12px;

        }

        .teacher-avatar {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #dbeafe;

            color:
                var(--primary);

            font-weight: 700;

        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {

            padding: 28px;

            max-width: 1100px;

        }

        .back-link {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            text-decoration: none;

            color:
                var(--muted);

            font-size: 13px;

            margin-bottom: 18px;

        }

        .back-link:hover {

            color:
                var(--primary);

        }

        /*
        |--------------------------------------------------------------------------
        | Form Card
        |--------------------------------------------------------------------------
        */

        .form-card {

            background: #fff;

            border:
                1px solid
                var(--border);

            border-radius: 16px;

            box-shadow:
                0 4px 16px
                rgba(15,23,42,.04);

            overflow: hidden;

        }

        .form-header {

            padding:
                24px;

            border-bottom:
                1px solid
                var(--border);

        }

        .form-header-icon {

            width: 48px;
            height: 48px;

            border-radius: 12px;

            background: #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 22px;

            flex-shrink: 0;

        }

        .form-header h2 {

            font-size: 18px;

            font-weight: 700;

            margin: 0 0 4px;

        }

        .form-header p {

            color:
                var(--muted);

            font-size: 12px;

            margin: 0;

        }

        .form-body {

            padding: 24px;

        }

        /*
        |--------------------------------------------------------------------------
        | Form Controls
        |--------------------------------------------------------------------------
        */

        .form-label {

            font-size: 12px;

            font-weight: 600;

            color:
                #374151;

            margin-bottom: 7px;

        }

        .form-control,
        .form-select {

            min-height: 44px;

            border:
                1px solid
                #d1d5db;

            border-radius: 10px;

            font-size: 13px;

            padding:
                10px 12px;

        }

        .form-control:focus,
        .form-select:focus {

            border-color:
                var(--primary);

            box-shadow:
                0 0 0 .2rem
                rgba(37,99,235,.10);

        }

        textarea.form-control {

            min-height: 130px;

            resize: vertical;

        }

        .form-text {

            font-size: 11px;

            color:
                var(--muted);

        }

        .required {

            color:
                #dc2626;

        }

        /*
        |--------------------------------------------------------------------------
        | Current File
        |--------------------------------------------------------------------------
        */

        .current-file {

            display: flex;

            align-items: center;

            gap: 14px;

            padding:
                15px;

            background:
                #f8fafc;

            border:
                1px solid
                var(--border);

            border-radius: 12px;

        }

        .current-file-icon {

            width: 46px;
            height: 46px;

            border-radius: 11px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                #eff6ff;

            color:
                var(--primary);

            font-size: 21px;

            flex-shrink: 0;

        }

        .current-file-name {

            font-size: 13px;

            font-weight: 600;

            color:
                var(--text);

            word-break: break-all;

        }

        .current-file-meta {

            color:
                var(--muted);

            font-size: 11px;

            margin-top: 3px;

        }

        /*
        |--------------------------------------------------------------------------
        | Upload Area
        |--------------------------------------------------------------------------
        */

        .upload-area {

            border:
                2px dashed
                #d1d5db;

            border-radius: 12px;

            padding:
                25px;

            text-align: center;

            transition:
                .2s ease;

            background:
                #fafafa;

        }

        .upload-area:hover {

            border-color:
                var(--primary);

            background:
                #f8fbff;

        }

        .upload-icon {

            width: 48px;
            height: 48px;

            margin:
                0 auto 12px;

            border-radius: 12px;

            background:
                #eff6ff;

            color:
                var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 22px;

        }

        .file-input {

            display: none;

        }

        .choose-file {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 7px;

            padding:
                9px 15px;

            border-radius: 9px;

            background:
                var(--primary);

            color: #fff;

            font-size: 12px;

            font-weight: 600;

            cursor: pointer;

            text-decoration: none;

        }

        .choose-file:hover {

            background:
                var(--primary-dark);

            color: #fff;

        }

        .selected-file {

            margin-top: 12px;

            font-size: 12px;

            color:
                #374151;

            font-weight: 500;

            word-break: break-all;

        }

        /*
        |--------------------------------------------------------------------------
        | Alert
        |--------------------------------------------------------------------------
        */

        .alert {

            border-radius: 11px;

            font-size: 12px;

        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn {

            border-radius: 10px;

            font-size: 13px;

            font-weight: 600;

            padding:
                10px 17px;

        }

        .btn-primary {

            background:
                var(--primary);

            border-color:
                var(--primary);

        }

        .btn-primary:hover {

            background:
                var(--primary-dark);

            border-color:
                var(--primary-dark);

        }

        .btn-light {

            border:
                1px solid
                var(--border);

            background: #fff;

        }

        .btn-light:hover {

            background:
                #f8fafc;

        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Menu
        |--------------------------------------------------------------------------
        */

        .mobile-menu-button {

            display: none;

            border: 0;

            background: transparent;

            font-size: 24px;

            color:
                var(--text);

        }

        .sidebar-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(0,0,0,.45);

            z-index: 1040;

        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .bottom-nav {

            display: none;

        }

        /*
        |--------------------------------------------------------------------------
        | Tablet
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .sidebar {

                transform:
                    translateX(-100%);

            }

            .sidebar.show {

                transform:
                    translateX(0);

            }

            .sidebar-overlay.show {

                display: block;

            }

            .main {

                margin-left: 0;

                padding-bottom: 76px;

            }

            .mobile-menu-button {

                display: inline-block;

            }

            .topbar {

                padding:
                    0 16px;

            }

            .content {

                padding:
                    20px 16px;

            }

            .ethiopian-date {

                display: none;

            }

            .bottom-nav {

                position: fixed;

                display: grid;

                grid-template-columns:
                    repeat(5, 1fr);

                bottom: 0;

                left: 0;

                right: 0;

                height: 68px;

                background: #fff;

                border-top:
                    1px solid
                    var(--border);

                z-index: 1030;

                box-shadow:
                    0 -4px 15px
                    rgba(15,23,42,.06);

            }

            .bottom-nav a {

                display: flex;

                flex-direction: column;

                align-items: center;

                justify-content: center;

                gap: 3px;

                text-decoration: none;

                color: #6b7280;

                font-size: 10px;

                font-weight: 500;

            }

            .bottom-nav a i {

                font-size: 18px;

            }

            .bottom-nav a.active {

                color:
                    var(--primary);

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 575.98px) {

            .topbar {

                height: 68px;

            }

            .page-title {

                font-size: 16px;

            }

            .page-subtitle {

                display: none;

            }

            .teacher-avatar {

                width: 36px;
                height: 36px;

            }

            .form-header {

                padding:
                    18px;

            }

            .form-body {

                padding:
                    18px;

            }

            .form-header-icon {

                width: 42px;
                height: 42px;

                font-size: 19px;

            }

            .form-header h2 {

                font-size: 16px;

            }

            .upload-area {

                padding:
                    20px 14px;

            }

            .current-file {

                align-items:
                    flex-start;

            }

            .action-buttons {

                display: flex;

                flex-direction: column-reverse;

                gap: 8px !important;

            }

            .action-buttons .btn {

                width: 100%;

            }

        }

    </style>

</head>

<body>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="closeSidebar()"
></div>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="brand">

        <div class="brand-icon">

            <i class="bi bi-mortarboard-fill"></i>

        </div>

        <div>

            <div class="brand-title">
                BKHS Teacher
            </div>

            <div class="brand-subtitle">
                Teacher Portal
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="../dashboard.php">

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>

        <a href="../subjects.php">

            <i class="bi bi-book-fill"></i>

            <span>
                Subjects
            </span>

        </a>

        <a href="../classes.php">

            <i class="bi bi-people-fill"></i>

            <span>
                Classes
            </span>

        </a>

        <a href="../result.php">

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Result
            </span>

        </a>

        <a href="../daily-attendance.php">

            <i class="bi bi-calendar-check-fill"></i>

            <span>
                Daily Attendance
            </span>

        </a>

        <a href="../homework.php">

            <i class="bi bi-journal-text"></i>

            <span>
                Homework
            </span>

        </a>

        <a
            href="../materials.php"
            class="active"
        >

            <i class="bi bi-folder-fill"></i>

            <span>
                Materials
            </span>

        </a>

        <a href="../announcement.php">

            <i class="bi bi-megaphone-fill"></i>

            <span>
                Announcement
            </span>

        </a>

        <a href="../roster.php">

            <i class="bi bi-card-list"></i>

            <span>
                Roster
            </span>

        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="../profile.php">

            <i class="bi bi-person-circle"></i>

            <span>
                Profile
            </span>

        </a>

        <a href="../../auth/logout.php">

            <i
                class="bi bi-box-arrow-right"
            ></i>

            <span>
                Logout
            </span>

        </a>

    </nav>

</aside>

<!-- Main -->

<div class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-button"
                onclick="openSidebar()"
                aria-label="Open menu"
            >

                <i class="bi bi-list"></i>

            </button>

            <div>

                <h1 class="page-title">
                    Edit Material
                </h1>

                <div class="page-subtitle">
                    Update your uploaded teaching material
                </div>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">

                <i
                    class="bi bi-calendar3 me-1"
                ></i>

                <?= e($ethiopianToday) ?>

            </div>

            <div
                class="teacher-avatar"
                title="<?= e($teacherName) ?>"
            >

                <?= e(
                    strtoupper(
                        mb_substr(
                            $teacherName,
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

        </div>

    </header>

    <!-- Content -->

    <main class="content">

        <a
            href="view.php?id=<?= (int) $materialId ?>"
            class="back-link"
        >

            <i class="bi bi-arrow-left"></i>

            Back to Material

        </a>

        <div class="form-card">

            <!-- Header -->

            <div class="form-header">

                <div class="d-flex align-items-center gap-3">

                    <div class="form-header-icon">

                        <i
                            class="bi bi-pencil-square"
                        ></i>

                    </div>

                    <div>

                        <h2>
                            Edit Material
                        </h2>

                        <p>
                            Update the material information
                            or replace the uploaded file.
                        </p>

                    </div>

                </div>

            </div>

            <!-- Form -->

            <div class="form-body">

                <?php if (!empty($errors)): ?>

                    <div
                        class="
                            alert
                            alert-danger
                            d-flex
                            align-items-start
                            gap-2
                        "
                    >

                        <i
                            class="
                                bi
                                bi-exclamation-triangle-fill
                                mt-1
                            "
                        ></i>

                        <div>

                            <?php foreach (
                                $errors as $error
                            ): ?>

                                <div>
                                    <?= e($error) ?>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endif; ?>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    id="materialEditForm"
                >

                    <input
                        type="hidden"
                        name="form_token"
                        value="<?= e($formToken) ?>"
                    >

                    <!-- Class / Subject -->

                    <div class="mb-4">

                        <label
                            for="assignment_id"
                            class="form-label"
                        >

                            Class & Subject

                            <span class="required">
                                *
                            </span>

                        </label>

                        <?php if (
                            empty($assignments)
                        ): ?>

                            <div
                                class="
                                    alert
                                    alert-warning
                                    mb-0
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-exclamation-circle
                                        me-1
                                    "
                                ></i>

                                No active subject assignments
                                were found for the current
                                academic year.

                            </div>

                        <?php else: ?>

                            <select
                                name="assignment_id"
                                id="assignment_id"
                                class="form-select"
                                required
                            >

                                <option
                                    value=""
                                    disabled
                                >
                                    Select class and subject
                                </option>

                                <?php foreach (
                                    $assignments
                                    as $assignment
                                ): ?>

                                    <option
                                        value="<?= (int) $assignment['id'] ?>"
                                        <?= (
                                            (int)
                                            $selectedAssignmentId
                                            ===
                                            (int)
                                            $assignment['id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        Grade
                                        <?= (int) $assignment['grade'] ?>

                                        -
                                        Section
                                        <?= e(
                                            (string)
                                            $assignment['section']
                                        ) ?>

                                        -
                                        <?= e(
                                            (string)
                                            $assignment['subject_name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <div class="form-text">

                                Academic Year:
                                <strong>
                                    <?= e(
                                        $activeAcademicYear !== ''
                                            ? $activeAcademicYear
                                            : (string)
                                                $material[
                                                    'academic_year'
                                                ]
                                    ) ?>
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>

                    <!-- Title -->

                    <div class="mb-4">

                        <label
                            for="title"
                            class="form-label"
                        >

                            Material Title

                            <span class="required">
                                *
                            </span>

                        </label>

                        <input
                            type="text"
                            name="title"
                            id="title"
                            class="form-control"
                            maxlength="255"
                            value="<?= e($title) ?>"
                            placeholder="Enter material title"
                            required
                        >

                    </div>

                    <!-- Description -->

                    <div class="mb-4">

                        <label
                            for="description"
                            class="form-label"
                        >

                            Description

                            <span class="text-muted fw-normal">
                                (Optional)
                            </span>

                        </label>

                        <textarea
                            name="description"
                            id="description"
                            class="form-control"
                            placeholder="Describe this material..."
                        ><?= e($description) ?></textarea>

                    </div>

                    <!-- Current File -->

                    <div class="mb-4">

                        <label class="form-label">

                            Current File

                        </label>

                        <div class="current-file">

                            <div class="current-file-icon">

                                <i
                                    class="
                                        bi
                                        <?= e(
                                            materialIcon(
                                                $currentExtension
                                            )
                                        ) ?>
                                    "
                                ></i>

                            </div>

                            <div class="flex-grow-1">

                                <div class="current-file-name">

                                    <?= e(
                                        (string)
                                        $material['file_name']
                                    ) ?>

                                </div>

                                <div class="current-file-meta">

                                    <?= e(
                                        formatFileSize(
                                            isset(
                                                $material['file_size']
                                            )
                                                ? (int)
                                                    $material[
                                                        'file_size'
                                                    ]
                                                : null
                                        )
                                    ) ?>

                                    <?php if (
                                        $currentExtension !== ''
                                    ): ?>

                                        <span class="mx-1">
                                            •
                                        </span>

                                        <?= e(
                                            strtoupper(
                                                $currentExtension
                                            )
                                        ) ?>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <?php if (
                                $currentPhysicalFileExists &&
                                $currentFileUrl !== ''
                            ): ?>

                                <a
                                    href="<?= e(
                                        $currentFileUrl
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn btn-light btn-sm"
                                >

                                    <i
                                        class="
                                            bi
                                            bi-box-arrow-up-right
                                            me-1
                                        "
                                    ></i>

                                    Open

                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                    <!-- Replace File -->

                    <div class="mb-4">

                        <label class="form-label">

                            Replace File

                            <span class="text-muted fw-normal">
                                (Optional)
                            </span>

                        </label>

                        <div class="upload-area">

                            <div class="upload-icon">

                                <i
                                    class="bi bi-cloud-arrow-up-fill"
                                ></i>

                            </div>

                            <div class="fw-semibold small mb-1">

                                Choose a new file

                            </div>

                            <div
                                class="
                                    form-text
                                    mb-3
                                "
                            >

                                Leave empty to keep the
                                current file.

                                <br>

                                PDF, Word, PowerPoint,
                                Excel, images or ZIP.
                                Maximum 20 MB.

                            </div>

                            <label
                                for="material_file"
                                class="choose-file"
                            >

                                <i
                                    class="bi bi-folder2-open"
                                ></i>

                                Choose File

                            </label>

                            <input
                                type="file"
                                name="material_file"
                                id="material_file"
                                class="file-input"
                                accept="
                                    .pdf,
                                    .doc,
                                    .docx,
                                    .ppt,
                                    .pptx,
                                    .xls,
                                    .xlsx,
                                    .jpg,
                                    .jpeg,
                                    .png,
                                    .gif,
                                    .webp,
                                    .zip
                                "
                            >

                            <div
                                id="selectedFile"
                                class="selected-file"
                            ></div>

                        </div>

                    </div>

                    <!-- Actions -->

                    <div
                        class="
                            action-buttons
                            d-flex
                            justify-content-end
                            align-items-center
                            gap-2
                            pt-2
                        "
                    >

                        <a
                            href="view.php?id=<?= (int) $materialId ?>"
                            class="btn btn-light"
                        >

                            <i
                                class="bi bi-x-lg me-1"
                            ></i>

                            Cancel

                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary"
                            <?= empty($assignments)
                                ? 'disabled'
                                : '' ?>
                        >

                            <i
                                class="
                                    bi
                                    bi-check2-circle
                                    me-1
                                "
                            ></i>

                            Save Changes

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </main>

</div>

<!-- Mobile Bottom Navigation -->

<nav class="bottom-nav">

    <a href="../daily-attendance.php">

        <i
            class="bi bi-calendar-check-fill"
        ></i>

        <span>
            Attendance
        </span>

    </a>

    <a href="../homework.php">

        <i
            class="bi bi-journal-text"
        ></i>

        <span>
            Homework
        </span>

    </a>

    <a href="../result.php">

        <i
            class="bi bi-bar-chart-fill"
        ></i>

        <span>
            Result
        </span>

    </a>

    <a href="../announcement.php">

        <i
            class="bi bi-megaphone-fill"
        ></i>

        <span>
            Announce
        </span>

    </a>

    <a
        href="#"
        onclick="openSidebar(); return false;"
    >

        <i
            class="bi bi-three-dots"
        ></i>

        <span>
            More
        </span>

    </a>

</nav>

<script>

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    function openSidebar() {

        document
            .getElementById('sidebar')
            .classList
            .add('show');

        document
            .getElementById('sidebarOverlay')
            .classList
            .add('show');

        document.body.style.overflow =
            'hidden';
    }

    function closeSidebar() {

        document
            .getElementById('sidebar')
            .classList
            .remove('show');

        document
            .getElementById('sidebarOverlay')
            .classList
            .remove('show');

        document.body.style.overflow =
            '';
    }

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 991) {

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
            'material_file'
        );

    const selectedFile =
        document.getElementById(
            'selectedFile'
        );

    if (fileInput && selectedFile) {

        fileInput.addEventListener(
            'change',
            function () {

                if (
                    this.files &&
                    this.files.length > 0
                ) {

                    const file =
                        this.files[0];

                    const sizeInMB =
                        file.size /
                        (1024 * 1024);

                    selectedFile.innerHTML =
                        '<i class="bi bi-check-circle-fill text-success me-1"></i>' +
                        'Selected: <strong>' +
                        escapeHtml(file.name) +
                        '</strong>' +
                        ' (' +
                        sizeInMB.toFixed(1) +
                        ' MB)';

                } else {

                    selectedFile.innerHTML =
                        '';

                }

            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Small JavaScript HTML Escape
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(
                /'/g,
                '&#039;'
            );
    }

</script>

</body>
</html>