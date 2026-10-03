<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;

    header('Location: subjectassignment.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

$flashType = $_SESSION['flash_type'] ?? '';
$flashMessage = $_SESSION['flash_message'] ?? '';

unset(
    $_SESSION['flash_type'],
    $_SESSION['flash_message']
);

/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$editMode = false;
$editId = 0;
$editGrade = '';
$editSubjectName = '';
$editBookPdf = '';

$formGrade = '';
$formSubjectName = '';

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDirectory = __DIR__ . '/../uploads/subjects/books/';
$databaseUploadPath = 'uploads/subjects/books/';

if (!is_dir($uploadDirectory)) {
    @mkdir($uploadDirectory, 0777, true);
}

/*
|--------------------------------------------------------------------------
| DELETE SUBJECT ASSIGNMENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_assignment'])
) {
    $deleteId = (int) ($_POST['delete_id'] ?? 0);

    if ($deleteId <= 0) {
        redirectWithMessage(
            'danger',
            'Invalid subject assignment.'
        );
    }

    $stmt = $conn->prepare("
        SELECT book_pdf
        FROM grade_subjects
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        redirectWithMessage(
            'danger',
            'Unable to process the request.'
        );
    }

    $stmt->bind_param('i', $deleteId);
    $stmt->execute();

    $result = $stmt->get_result();
    $assignment = $result->fetch_assoc();

    $stmt->close();

    if (!$assignment) {
        redirectWithMessage(
            'danger',
            'Subject assignment not found.'
        );
    }

    $bookPdf = $assignment['book_pdf'] ?? null;

    $stmt = $conn->prepare("
        DELETE FROM grade_subjects
        WHERE id = ?
    ");

    if (!$stmt) {
        redirectWithMessage(
            'danger',
            'Unable to delete subject assignment.'
        );
    }

    $stmt->bind_param('i', $deleteId);

    if (!$stmt->execute()) {
        $stmt->close();

        redirectWithMessage(
            'danger',
            'Failed to delete the subject assignment.'
        );
    }

    $stmt->close();

    /*
     * Delete physical PDF after successful database deletion.
     */
    if (!empty($bookPdf)) {
        $filePath = __DIR__ . '/../' . ltrim($bookPdf, '/');

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    redirectWithMessage(
        'success',
        'Subject assignment deleted successfully.'
    );
}

/*
|--------------------------------------------------------------------------
| CREATE / UPDATE SUBJECT ASSIGNMENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_assignment'])
) {
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

    $grade = (int) ($_POST['grade'] ?? 0);
    $subjectName = trim((string) ($_POST['subject_name'] ?? ''));

    $formGrade = $grade > 0 ? (string) $grade : '';
    $formSubjectName = $subjectName;

    $isUpdate = $assignmentId > 0;

    /*
     * Validation
     */
    if ($grade < 1 || $grade > 12) {
        $errorMessage = 'Please select a valid grade.';
    } elseif ($subjectName === '') {
        $errorMessage = 'Please enter the subject name.';
    } elseif (mb_strlen($subjectName) > 150) {
        $errorMessage = 'Subject name cannot exceed 150 characters.';
    }

    /*
     * Get existing assignment when updating.
     */
    $existingBookPdf = null;

    if ($errorMessage === '' && $isUpdate) {
        $stmt = $conn->prepare("
            SELECT
                id,
                grade,
                subject_name,
                book_pdf
            FROM grade_subjects
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errorMessage =
                'Unable to load the subject assignment.';
        } else {
            $stmt->bind_param('i', $assignmentId);
            $stmt->execute();

            $result = $stmt->get_result();
            $existingAssignment = $result->fetch_assoc();

            $stmt->close();

            if (!$existingAssignment) {
                $errorMessage =
                    'Subject assignment not found.';
            } else {
                $existingBookPdf =
                    $existingAssignment['book_pdf'];
            }
        }
    }

    /*
     * Check duplicate grade + subject.
     */
    if ($errorMessage === '') {
        if ($isUpdate) {
            $stmt = $conn->prepare("
                SELECT id
                FROM grade_subjects
                WHERE grade = ?
                  AND subject_name = ?
                  AND id != ?
                LIMIT 1
            ");

            if (!$stmt) {
                $errorMessage =
                    'Unable to validate the subject assignment.';
            } else {
                $stmt->bind_param(
                    'isi',
                    $grade,
                    $subjectName,
                    $assignmentId
                );

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $errorMessage =
                        'This subject is already assigned to the selected grade.';
                }

                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM grade_subjects
                WHERE grade = ?
                  AND subject_name = ?
                LIMIT 1
            ");

            if (!$stmt) {
                $errorMessage =
                    'Unable to validate the subject assignment.';
            } else {
                $stmt->bind_param(
                    'is',
                    $grade,
                    $subjectName
                );

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $errorMessage =
                        'This subject is already assigned to the selected grade.';
                }

                $stmt->close();
            }
        }
    }

    /*
     * Handle PDF upload.
     */
    $newBookPdf = $existingBookPdf;

    $uploadedNewFile = false;
    $newUploadedFilePath = '';

    if (
        $errorMessage === '' &&
        isset($_FILES['book_pdf']) &&
        $_FILES['book_pdf']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $file = $_FILES['book_pdf'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage =
                'There was a problem uploading the PDF.';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $errorMessage =
                'The PDF file must not exceed 20 MB.';
        } else {
            $originalName = $file['name'];

            $extension = strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );

            if ($extension !== 'pdf') {
                $errorMessage =
                    'Only PDF files are allowed.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);

                $mimeType = $finfo
                    ? finfo_file(
                        $finfo,
                        $file['tmp_name']
                    )
                    : '';

                if ($finfo) {
                    finfo_close($finfo);
                }

                if ($mimeType !== 'application/pdf') {
                    $errorMessage =
                        'The uploaded file is not a valid PDF.';
                } else {
                    if (!is_dir($uploadDirectory)) {
                        @mkdir(
                            $uploadDirectory,
                            0777,
                            true
                        );
                    }

                    if (!is_dir($uploadDirectory)) {
                        $errorMessage =
                            'Unable to create the book upload directory.';
                    } else {
                        try {
                            $uniqueName =
                                'book_' .
                                date('YmdHis') .
                                '_' .
                                bin2hex(
                                    random_bytes(8)
                                ) .
                                '.pdf';
                        } catch (Throwable $e) {
                            $errorMessage =
                                'Unable to generate a secure file name.';
                        }

                        if ($errorMessage === '') {
                            $destination =
                                $uploadDirectory .
                                $uniqueName;

                            if (
                                !move_uploaded_file(
                                    $file['tmp_name'],
                                    $destination
                                )
                            ) {
                                $errorMessage =
                                    'Failed to save the uploaded PDF.';
                            } else {
                                $newBookPdf =
                                    $databaseUploadPath .
                                    $uniqueName;

                                $newUploadedFilePath =
                                    $destination;

                                $uploadedNewFile = true;
                            }
                        }
                    }
                }
            }
        }
    }

    /*
     * Save to database.
     */
    if ($errorMessage === '') {
        if ($isUpdate) {
            $stmt = $conn->prepare("
                UPDATE grade_subjects
                SET
                    grade = ?,
                    subject_name = ?,
                    book_pdf = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                $errorMessage =
                    'Unable to prepare the update request.';
            } else {
                $stmt->bind_param(
                    'issi',
                    $grade,
                    $subjectName,
                    $newBookPdf,
                    $assignmentId
                );

                if (!$stmt->execute()) {
                    if ($stmt->errno === 1062) {
                        $errorMessage =
                            'This subject is already assigned to the selected grade.';
                    } else {
                        $errorMessage =
                            'Failed to update the subject assignment.';
                    }

                    $stmt->close();
                } else {
                    $stmt->close();

                    /*
                     * Delete old PDF only after successful update.
                     */
                    if (
                        $uploadedNewFile &&
                        !empty($existingBookPdf)
                    ) {
                        $oldFilePath =
                            __DIR__ .
                            '/../' .
                            ltrim(
                                $existingBookPdf,
                                '/'
                            );

                        if (
                            is_file($oldFilePath) &&
                            $oldFilePath !==
                            $newUploadedFilePath
                        ) {
                            @unlink($oldFilePath);
                        }
                    }

                    redirectWithMessage(
                        'success',
                        'Subject assignment updated successfully.'
                    );
                }
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO grade_subjects
                (
                    grade,
                    subject_name,
                    book_pdf
                )
                VALUES
                (?, ?, ?)
            ");

            if (!$stmt) {
                $errorMessage =
                    'Unable to prepare the save request.';
            } else {
                $stmt->bind_param(
                    'iss',
                    $grade,
                    $subjectName,
                    $newBookPdf
                );

                if (!$stmt->execute()) {
                    if ($stmt->errno === 1062) {
                        $errorMessage =
                            'This subject is already assigned to the selected grade.';
                    } else {
                        $errorMessage =
                            'Failed to create the subject assignment.';
                    }

                    $stmt->close();
                } else {
                    $stmt->close();

                    redirectWithMessage(
                        'success',
                        'Subject assigned successfully.'
                    );
                }
            }
        }
    }

    /*
     * If database operation failed after a new upload,
     * remove the newly uploaded file.
     */
    if (
        $errorMessage !== '' &&
        $uploadedNewFile &&
        $newUploadedFilePath !== '' &&
        is_file($newUploadedFilePath)
    ) {
        @unlink($newUploadedFilePath);
    }

    if ($isUpdate) {
        $editMode = true;
        $editId = $assignmentId;
        $editGrade = (string) $grade;
        $editSubjectName = $subjectName;
        $editBookPdf = (string) (
            $existingBookPdf ?? ''
        );
    }
}

/*
|--------------------------------------------------------------------------
| EDIT MODE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['edit'])
) {
    $requestedEditId = (int) $_GET['edit'];

    if ($requestedEditId > 0) {
        $stmt = $conn->prepare("
            SELECT
                id,
                grade,
                subject_name,
                book_pdf
            FROM grade_subjects
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'i',
                $requestedEditId
            );

            $stmt->execute();

            $result = $stmt->get_result();
            $assignment = $result->fetch_assoc();

            $stmt->close();

            if ($assignment) {
                $editMode = true;

                $editId = (int) $assignment['id'];

                $editGrade =
                    (string) $assignment['grade'];

                $editSubjectName =
                    (string) $assignment['subject_name'];

                $editBookPdf =
                    (string) (
                        $assignment['book_pdf'] ?? ''
                    );

                $formGrade = $editGrade;
                $formSubjectName = $editSubjectName;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$filterGrade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$searchSubject = trim(
    (string) ($_GET['subject'] ?? '')
);

if ($filterGrade < 1 || $filterGrade > 12) {
    $filterGrade = 0;
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$itemsPerPage = 20;

$currentPage = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

/*
|--------------------------------------------------------------------------
| Total Count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM grade_subjects
    WHERE 1 = 1
";

$countParams = [];
$countTypes = '';

if ($filterGrade > 0) {
    $countSql .= " AND grade = ?";
    $countTypes .= 'i';
    $countParams[] = $filterGrade;
}

if ($searchSubject !== '') {
    $countSql .= " AND subject_name LIKE ?";
    $countTypes .= 's';
    $countParams[] = '%' . $searchSubject . '%';
}

$totalAssignments = 0;

$stmt = $conn->prepare($countSql);

if ($stmt) {
    if (!empty($countParams)) {
        $bindValues = [$countTypes];

        foreach ($countParams as $key => $value) {
            $bindValues[] = &$countParams[$key];
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bindValues
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();
    $countRow = $result->fetch_assoc();

    $totalAssignments =
        (int) ($countRow['total'] ?? 0);

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalAssignments / $itemsPerPage
    )
);

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset =
    ($currentPage - 1) *
    $itemsPerPage;

/*
|--------------------------------------------------------------------------
| Fetch Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

$sql = "
    SELECT
        id,
        grade,
        subject_name,
        book_pdf,
        is_active,
        created_at,
        updated_at
    FROM grade_subjects
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($filterGrade > 0) {
    $sql .= " AND grade = ?";
    $types .= 'i';
    $params[] = $filterGrade;
}

if ($searchSubject !== '') {
    $sql .= " AND subject_name LIKE ?";
    $types .= 's';
    $params[] = '%' . $searchSubject . '%';
}

$sql .= "
    ORDER BY
        grade ASC,
        subject_name ASC
    LIMIT ? OFFSET ?
";

$types .= 'ii';
$params[] = $itemsPerPage;
$params[] = $offset;

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $bindValues = [$types];

        foreach ($params as $key => $value) {
            $bindValues[] = &$params[$key];
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bindValues
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination Helper
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $page,
    int $filterGrade,
    string $searchSubject
): string {
    $query = [
        'page' => $page
    ];

    if ($filterGrade > 0) {
        $query['grade'] = $filterGrade;
    }

    if ($searchSubject !== '') {
        $query['subject'] = $searchSubject;
    }

    return 'subjectassignment.php?' .
        http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Display Range
|--------------------------------------------------------------------------
*/

$displayStart = $totalAssignments > 0
    ? $offset + 1
    : 0;

$displayEnd = min(
    $offset + count($assignments),
    $totalAssignments
);

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
        Subject Assignment | BKHS Admin
    </title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="/BKHS/public/logo.webp?v=1"
    >

    <link
        rel="shortcut icon"
        type="image/webp"
        href="/BKHS/public/logo.webp?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/BKHS/public/logo.webp?v=1"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../public/css/admin-users.css"
    >

    <style>

        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 26px;
            font-weight: 800;
            color: #172033;
            margin-bottom: 5px;
        }

        .page-subtitle {
            color: #6b7280;
            margin: 0;
            font-size: 14px;
        }

        .assignment-card {
            background: #fff;
            border: 1px solid #e8ebf0;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        .assignment-card-header {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .assignment-card-header h5 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #172033;
        }

        .assignment-card-body {
            padding: 22px;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-radius: 10px;
            border-color: #dfe3e8;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #6366f1;
            box-shadow:
                0 0 0 3px
                rgba(99, 102, 241, 0.10);
        }

        .btn-primary {
            min-height: 46px;
            border-radius: 10px;
            font-weight: 600;
        }

        .filter-card {
            background: #f8fafc;
            border: 1px solid #e8ebf0;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 12px;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px 16px;
            vertical-align: middle;
            border-color: #f0f2f5;
            color: #374151;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            padding: 7px 12px;
            border-radius: 20px;
            background: #eef2ff;
            color: #4f46e5;
            font-size: 12px;
            font-weight: 700;
        }

        .subject-name {
            font-weight: 650;
            color: #172033;
        }

        .book-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 8px;
            background: #ecfdf5;
            color: #047857;
            font-size: 12px;
            font-weight: 600;
        }

        .no-book {
            color: #9ca3af;
            font-size: 13px;
        }

        .action-buttons {
            display: flex;
            gap: 7px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid #e5e7eb;
            background: #fff;
            transition: .2s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .action-btn.edit {
            color: #4f46e5;
        }

        .action-btn.edit:hover {
            background: #eef2ff;
        }

        .action-btn.book {
            color: #059669;
        }

        .action-btn.book:hover {
            background: #ecfdf5;
        }

        .action-btn.delete {
            color: #dc2626;
        }

        .action-btn.delete:hover {
            background: #fef2f2;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 700;
            color: #475569;
        }

        .current-book {
            margin-top: 10px;
            padding: 10px 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .current-book-info {
            font-size: 13px;
            color: #475569;
        }

        .current-book-actions {
            display: flex;
            align-items: center;
        }

        .active-badge {
            padding: 6px 10px;
            border-radius: 20px;
            background: #ecfdf5;
            color: #047857;
            font-size: 11px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        .pagination-wrapper {
            padding: 18px 22px;
            border-top: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: #64748b;
            font-size: 13px;
        }

        .pagination-info strong {
            color: #334155;
        }

        .pagination {
            margin: 0;
        }

        .pagination .page-link {
            min-width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            margin: 0 3px;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
        }

        .pagination .page-link:hover {
            background: #f8fafc;
            color: #4f46e5;
            border-color: #c7d2fe;
        }

        .pagination .page-item.active .page-link {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: #cbd5e1;
            background: #f8fafc;
            border-color: #edf0f4;
        }

        .page-number {
            display: inline-flex;
        }

        @media (max-width: 768px) {

            .assignment-card-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .assignment-card-body {
                padding: 16px;
            }

            .table-responsive {
                border-radius: 10px;
            }

            .page-title {
                font-size: 22px;
            }

            .pagination-wrapper {
                padding: 16px;
                flex-direction: column;
                align-items: stretch;
            }

            .pagination {
                justify-content: center;
            }

            .pagination .page-link {
                min-width: 34px;
                height: 34px;
                margin: 0 2px;
            }

            .page-number:nth-child(n + 7):not(:last-child) {
                display: none;
            }

        }

    </style>

</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->
    <aside class="admin-sidebar">

        <div class="sidebar-header">

            <div class="sidebar-brand">

                <div class="brand-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>

                <div>
                    <div class="brand-title">
                        BKHS
                    </div>
                </div>

            </div>

        </div>

        <nav class="sidebar-nav">

            <a
                href="dashboard.php"
                class="sidebar-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="users.php"
                class="sidebar-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>

          

            <a
                href="subjectassignment.php"
                class="sidebar-link active"
            >
                <i class="bi bi-journal-bookmark-fill"></i>
                <span>Subject Assignment</span>
            </a>

            <a
                href="../auth/logout.php"
                class="sidebar-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </nav>

    </aside>

    <!-- Main -->
    <main class="admin-main">

        <!-- Topbar -->
        <header class="admin-topbar">

            <button
                type="button"
                class="mobile-menu-button"
                onclick="toggleSidebar()"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">
                Subject Assignment
            </div>

            <div class="topbar-user">

                <div class="topbar-user-info">

                    <div class="topbar-user-name">
                        <?= e(
                            $_SESSION['full_name']
                            ?? 'Administrator'
                        ) ?>
                    </div>

                    <div class="topbar-user-role">
                        Administrator
                    </div>

                </div>

                <div class="avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

            </div>

        </header>

        <!-- Content -->
        <div class="admin-content">

            <div class="page-header">

                <h1 class="page-title">
                    Subject Assignment
                </h1>

                <p class="page-subtitle">
                    Assign subjects to grades and manage their optional book PDFs.
                </p>

            </div>

            <?php if ($flashMessage !== ''): ?>

                <div
                    class="alert alert-<?= e(
                        $flashType ?: 'info'
                    ) ?> alert-dismissible fade show"
                    role="alert"
                >

                    <i class="bi bi-check-circle-fill me-2"></i>

                    <?= e($flashMessage) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>

                <div
                    class="alert alert-danger alert-dismissible fade show"
                    role="alert"
                >

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= e($errorMessage) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <!-- Create / Edit -->
            <div class="assignment-card mb-4">

                <div class="assignment-card-header">

                    <div>

                        <h5>

                            <i
                                class="bi bi-<?=
                                    $editMode
                                        ? 'pencil-square'
                                        : 'plus-circle'
                                ?> me-2"
                            ></i>

                            <?= $editMode
                                ? 'Edit Subject Assignment'
                                : 'Assign New Subject'
                            ?>

                        </h5>

                    </div>

                    <?php if ($editMode): ?>

                        <a
                            href="subjectassignment.php"
                            class="btn btn-sm btn-outline-secondary"
                        >

                            <i class="bi bi-x-lg me-1"></i>

                            Cancel Edit

                        </a>

                    <?php endif; ?>

                </div>

                <div class="assignment-card-body">

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <?php if ($editMode): ?>

                            <input
                                type="hidden"
                                name="assignment_id"
                                value="<?= (int) $editId ?>"
                            >

                        <?php endif; ?>

                        <div class="row g-3">

                            <div class="col-md-3">

                                <label
                                    for="grade"
                                    class="form-label"
                                >
                                    Grade
                                </label>

                                <select
                                    name="grade"
                                    id="grade"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Grade
                                    </option>

                                    <?php
                                    for (
                                        $grade = 1;
                                        $grade <= 12;
                                        $grade++
                                    ):
                                    ?>

                                        <option
                                            value="<?= $grade ?>"
                                            <?=
                                                (
                                                    (string) $formGrade ===
                                                    (string) $grade
                                                )
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Grade <?= $grade ?>
                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <div class="col-md-5">

                                <label
                                    for="subject_name"
                                    class="form-label"
                                >
                                    Subject Name
                                </label>

                                <input
                                    type="text"
                                    name="subject_name"
                                    id="subject_name"
                                    class="form-control"
                                    maxlength="150"
                                    placeholder="e.g. Mathematics"
                                    value="<?= e(
                                        $formSubjectName
                                    ) ?>"
                                    required
                                >

                            </div>

                            <div class="col-md-4">

                                <label
                                    for="book_pdf"
                                    class="form-label"
                                >
                                    Book PDF

                                    <span
                                        class="text-muted fw-normal"
                                    >
                                        (Optional)
                                    </span>

                                </label>

                                <input
                                    type="file"
                                    name="book_pdf"
                                    id="book_pdf"
                                    class="form-control"
                                    accept="application/pdf,.pdf"
                                >

                                <small class="text-muted">
                                    PDF only, maximum 20 MB.
                                </small>

                                <?php if (
                                    $editMode &&
                                    !empty($editBookPdf)
                                ): ?>

                                    <div class="current-book">

                                        <div class="current-book-info">

                                            <i
                                                class="bi bi-file-earmark-pdf text-danger me-1"
                                            ></i>

                                            Current book

                                        </div>

                                        <div
                                            class="current-book-actions"
                                        >

                                            <a
                                                href="../<?= e(
                                                    ltrim(
                                                        $editBookPdf,
                                                        '/'
                                                    )
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-sm btn-outline-success"
                                            >

                                                <i
                                                    class="bi bi-eye me-1"
                                                ></i>

                                                View

                                            </a>

                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="col-12">

                                <div
                                    class="d-flex justify-content-end"
                                >

                                    <button
                                        type="submit"
                                        name="save_assignment"
                                        class="btn btn-primary px-4"
                                    >

                                        <i
                                            class="bi bi-<?=
                                                $editMode
                                                    ? 'save'
                                                    : 'plus-lg'
                                            ?> me-1"
                                        ></i>

                                        <?= $editMode
                                            ? 'Update Assignment'
                                            : 'Assign Subject'
                                        ?>

                                    </button>

                                </div>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- Filter -->
            <div class="assignment-card mb-4">

                <div class="assignment-card-body">

                    <form
                        method="GET"
                        action="subjectassignment.php"
                    >

                        <div class="filter-title">

                            <i
                                class="bi bi-funnel-fill me-1"
                            ></i>

                            Filter Subjects

                        </div>

                        <div class="row g-3 align-items-end">

                            <div class="col-md-3">

                                <label
                                    for="filter_grade"
                                    class="form-label"
                                >
                                    Grade
                                </label>

                                <select
                                    name="grade"
                                    id="filter_grade"
                                    class="form-select"
                                >

                                    <option value="">
                                        All Grades
                                    </option>

                                    <?php
                                    for (
                                        $grade = 1;
                                        $grade <= 12;
                                        $grade++
                                    ):
                                    ?>

                                        <option
                                            value="<?= $grade ?>"
                                            <?=
                                                $filterGrade === $grade
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Grade <?= $grade ?>
                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <div class="col-md-5">

                                <label
                                    for="filter_subject"
                                    class="form-label"
                                >
                                    Subject Name
                                </label>

                                <input
                                    type="text"
                                    name="subject"
                                    id="filter_subject"
                                    class="form-control"
                                    placeholder="Search subject name..."
                                    value="<?= e(
                                        $searchSubject
                                    ) ?>"
                                >

                            </div>

                            <div class="col-md-4">

                                <div class="d-flex gap-2">

                                    <button
                                        type="submit"
                                        class="btn btn-primary flex-grow-1"
                                    >

                                        <i
                                            class="bi bi-search me-1"
                                        ></i>

                                        Apply Filter

                                    </button>

                                    <a
                                        href="subjectassignment.php"
                                        class="btn btn-outline-secondary"
                                        title="Clear Filter"
                                    >

                                        <i
                                            class="bi bi-arrow-counterclockwise"
                                        ></i>

                                    </a>

                                </div>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- Assignment List -->
            <div class="assignment-card">

                <div class="assignment-card-header">

                    <h5>

                        <i
                            class="bi bi-list-ul me-2"
                        ></i>

                        Assigned Subjects

                    </h5>

                    <span
                        class="badge bg-light text-dark"
                    >

                        <?= $totalAssignments ?>

                        <?= $totalAssignments === 1
                            ? 'Subject'
                            : 'Subjects'
                        ?>

                    </span>

                </div>

                <div class="table-responsive">

                    <?php if (empty($assignments)): ?>

                        <div class="empty-state">

                            <i class="bi bi-journal-x"></i>

                            <h6>
                                No subject assignments found
                            </h6>

                            <p class="mb-0">
                                Try changing your filter or assign a new subject.
                            </p>

                        </div>

                    <?php else: ?>

                        <table class="table align-middle">

                            <thead>

                                <tr>

                                    <th>
                                        #
                                    </th>

                                    <th>
                                        Grade
                                    </th>

                                    <th>
                                        Subject
                                    </th>

                                    <th>
                                        Book
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th class="text-end">
                                        Actions
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php
                                foreach (
                                    $assignments
                                    as $index => $assignment
                                ):
                                ?>

                                    <tr>

                                        <td class="text-muted">

                                            <?= $offset + $index + 1 ?>

                                        </td>

                                        <td>

                                            <span
                                                class="grade-badge"
                                            >
                                                Grade
                                                <?= (int) $assignment['grade'] ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span
                                                class="subject-name"
                                            >
                                                <?= e(
                                                    $assignment['subject_name']
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $assignment['book_pdf']
                                                )
                                            ): ?>

                                                <a
                                                    href="../<?= e(
                                                        ltrim(
                                                            $assignment['book_pdf'],
                                                            '/'
                                                        )
                                                    ) ?>"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="book-badge text-decoration-none"
                                                    title="View Book"
                                                >

                                                    <i
                                                        class="bi bi-file-earmark-pdf"
                                                    ></i>

                                                    View Book

                                                </a>

                                            <?php else: ?>

                                                <span class="no-book">
                                                    No book
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if (
                                                (int) $assignment['is_active'] === 1
                                            ): ?>

                                                <span
                                                    class="active-badge"
                                                >
                                                    Active
                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="badge bg-secondary"
                                                >
                                                    Inactive
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <div
                                                class="action-buttons justify-content-end"
                                            >

                                                <?php if (
                                                    !empty(
                                                        $assignment['book_pdf']
                                                    )
                                                ): ?>

                                                    <a
                                                        href="../<?= e(
                                                            ltrim(
                                                                $assignment['book_pdf'],
                                                                '/'
                                                            )
                                                        ) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="action-btn book"
                                                        title="View Book"
                                                    >

                                                        <i
                                                            class="bi bi-file-earmark-pdf"
                                                        ></i>

                                                    </a>

                                                <?php endif; ?>

                                                <a
                                                    href="subjectassignment.php?edit=<?= (int) $assignment['id'] ?>"
                                                    class="action-btn edit"
                                                    title="Edit"
                                                >

                                                    <i
                                                        class="bi bi-pencil"
                                                    ></i>

                                                </a>

                                                <form
                                                    method="POST"
                                                    class="d-inline"
                                                    onsubmit="return confirmDelete(
                                                        <?= (int) $assignment['id'] ?>,
                                                        <?= htmlspecialchars(
                                                            json_encode(
                                                                $assignment['subject_name'],
                                                                JSON_HEX_TAG |
                                                                JSON_HEX_APOS |
                                                                JSON_HEX_QUOT |
                                                                JSON_HEX_AMP
                                                            ),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>,
                                                        <?= (int) $assignment['grade'] ?>
                                                    );"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="delete_id"
                                                        value="<?= (int) $assignment['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        name="delete_assignment"
                                                        class="action-btn delete"
                                                        title="Delete"
                                                    >

                                                        <i
                                                            class="bi bi-trash3"
                                                        ></i>

                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    <?php endif; ?>

                </div>

                <!-- Pagination -->
                <?php if (
                    $totalAssignments > 0 &&
                    $totalPages > 1
                ): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing
                            <strong>
                                <?= $displayStart ?>
                            </strong>

                            to

                            <strong>
                                <?= $displayEnd ?>
                            </strong>

                            of

                            <strong>
                                <?= $totalAssignments ?>
                            </strong>

                            subjects

                        </div>

                        <nav
                            aria-label="Subject assignment pagination"
                        >

                            <ul class="pagination">

                                <!-- Previous -->
                                <li
                                    class="page-item <?= $currentPage <= 1
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <?php if ($currentPage > 1): ?>

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                paginationUrl(
                                                    $currentPage - 1,
                                                    $filterGrade,
                                                    $searchSubject
                                                )
                                            ) ?>"
                                            aria-label="Previous"
                                        >

                                            <i
                                                class="bi bi-chevron-left"
                                            ></i>

                                        </a>

                                    <?php else: ?>

                                        <span
                                            class="page-link"
                                        >

                                            <i
                                                class="bi bi-chevron-left"
                                            ></i>

                                        </span>

                                    <?php endif; ?>

                                </li>

                                <?php
                                /*
                                 * Keep pagination compact when there
                                 * are many pages.
                                 */
                                $startPage = max(
                                    1,
                                    $currentPage - 2
                                );

                                $endPage = min(
                                    $totalPages,
                                    $currentPage + 2
                                );

                                if ($startPage > 1):
                                ?>

                                    <li class="page-item page-number">

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                paginationUrl(
                                                    1,
                                                    $filterGrade,
                                                    $searchSubject
                                                )
                                            ) ?>"
                                        >
                                            1
                                        </a>

                                    </li>

                                    <?php if ($startPage > 2): ?>

                                        <li
                                            class="page-item disabled page-number"
                                        >

                                            <span class="page-link">
                                                …
                                            </span>

                                        </li>

                                    <?php endif; ?>

                                <?php endif; ?>

                                <?php
                                for (
                                    $page = $startPage;
                                    $page <= $endPage;
                                    $page++
                                ):
                                ?>

                                    <li
                                        class="page-item page-number <?= $page === $currentPage
                                            ? 'active'
                                            : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                paginationUrl(
                                                    $page,
                                                    $filterGrade,
                                                    $searchSubject
                                                )
                                            ) ?>"
                                        >
                                            <?= $page ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <?php if (
                                    $endPage < $totalPages
                                ): ?>

                                    <?php if (
                                        $endPage < $totalPages - 1
                                    ): ?>

                                        <li
                                            class="page-item disabled page-number"
                                        >

                                            <span class="page-link">
                                                …
                                            </span>

                                        </li>

                                    <?php endif; ?>

                                    <li class="page-item page-number">

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                paginationUrl(
                                                    $totalPages,
                                                    $filterGrade,
                                                    $searchSubject
                                                )
                                            ) ?>"
                                        >
                                            <?= $totalPages ?>
                                        </a>

                                    </li>

                                <?php endif; ?>

                                <!-- Next -->
                                <li
                                    class="page-item <?= $currentPage >= $totalPages
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <?php if (
                                        $currentPage < $totalPages
                                    ): ?>

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                paginationUrl(
                                                    $currentPage + 1,
                                                    $filterGrade,
                                                    $searchSubject
                                                )
                                            ) ?>"
                                            aria-label="Next"
                                        >

                                            <i
                                                class="bi bi-chevron-right"
                                            ></i>

                                        </a>

                                    <?php else: ?>

                                        <span
                                            class="page-link"
                                        >

                                            <i
                                                class="bi bi-chevron-right"
                                            ></i>

                                        </span>

                                    <?php endif; ?>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

function toggleSidebar() {

    const sidebar =
        document.querySelector('.admin-sidebar');

    if (sidebar) {
        sidebar.classList.toggle('show');
    }

}

function confirmDelete(
    id,
    subjectName,
    grade
) {

    return confirm(
        'Delete "' +
        subjectName +
        '" from Grade ' +
        grade +
        '?\n\n' +
        'If this subject has a book PDF, the uploaded book will also be deleted.'
    );

}

</script>

</body>

</html>

