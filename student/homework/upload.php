<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';

requireStudent();

$userId = (int) $_SESSION['user_id'];

$homeworkId = filter_input(
    INPUT_GET,
    'homework_id',
    FILTER_VALIDATE_INT
);

if (!$homeworkId || $homeworkId <= 0) {
    setFlash(
        'danger',
        'Invalid homework.'
    );

    redirectToHomework();
}

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
    | Get homework
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
    | Get existing submission
    |--------------------------------------------------------------------------
    */

    $submission = getStudentHomeworkSubmission(
        $conn,
        $homeworkId,
        $studentId
    );

    /*
    |--------------------------------------------------------------------------
    | Determine submission status
    |--------------------------------------------------------------------------
    */

    $today = date('Y-m-d');

    $dueDate = (string) $homework['due_date'];

    $isClosed = (
        (string) $homework['status'] === 'Closed'
    );

    $isPastDue = (
        !$isClosed &&
        $dueDate < $today
    );

    $canSubmit = (
        !$isClosed &&
        !$isPastDue
    );

    /*
    |--------------------------------------------------------------------------
    | Flash message
    |--------------------------------------------------------------------------
    */

    $flash = getFlash();

} catch (Throwable $e) {

    setFlash(
        'danger',
        'Unable to load the homework submission page.'
    );

    redirectToHomework();
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
        Submit Homework | BKHS
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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f7fb;
        }

        .page-card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .homework-header {
            border-radius: 16px 16px 0 0;
        }

        .info-label {
            font-size: 0.78rem;
            color: #6c757d;
            margin-bottom: 3px;
        }

        .info-value {
            font-weight: 600;
        }

        .upload-area {
            border: 2px dashed #ced4da;
            border-radius: 14px;
            padding: 35px 20px;
            text-align: center;
            background: #fafbfc;
            transition: 0.2s ease;
        }

        .upload-area:hover {
            border-color: #6c757d;
            background: #f8f9fa;
        }

        .upload-icon {
            font-size: 42px;
            margin-bottom: 12px;
        }

        .existing-file {
            border-radius: 12px;
            background: #f8f9fa;
            padding: 15px;
        }

        .description-box {
            white-space: pre-wrap;
            line-height: 1.7;
        }

        @media (max-width: 576px) {

            .page-card {
                border-radius: 12px;
            }

            .upload-area {
                padding: 25px 15px;
            }

        }

    </style>

</head>

<body>

<div class="container py-4">

    <div class="row justify-content-center">

        <div class="col-12 col-lg-9">

            <?php if ($flash): ?>

                <div
                    class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"
                    role="alert"
                >
                    <?= e($flash['message']) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>

            <?php endif; ?>


            <!-- Back -->

            <div class="mb-3">

                <a
                    href="../homework.php"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Homework
                </a>

            </div>


            <!-- Main Card -->

            <div class="card page-card">

                <!-- Header -->

                <div class="card-body homework-header bg-primary text-white">

                    <div class="d-flex align-items-start gap-3">

                        <div>

                            <i class="bi bi-journal-text fs-2"></i>

                        </div>

                        <div>

                            <h4 class="mb-1">

                                <?= e((string) $homework['title']) ?>

                            </h4>

                            <div class="opacity-75">

                                <?= e((string) $homework['subject_name']) ?>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- Homework information -->

                <div class="card-body">

                    <div class="row g-4">

                        <div class="col-6 col-md-3">

                            <div class="info-label">
                                Subject
                            </div>

                            <div class="info-value">

                                <?= e((string) $homework['subject_name']) ?>

                            </div>

                        </div>


                        <div class="col-6 col-md-3">

                            <div class="info-label">
                                Teacher
                            </div>

                            <div class="info-value">

                                <?= e((string) $homework['teacher_name']) ?>

                            </div>

                        </div>


                        <div class="col-6 col-md-3">

                            <div class="info-label">
                                Assigned Date
                            </div>

                            <div class="info-value">

                                <?= e(
                                    formatEthiopianDate(
                                        (string) $homework['assigned_date']
                                    )
                                ) ?>

                            </div>

                        </div>


                        <div class="col-6 col-md-3">

                            <div class="info-label">
                                Due Date
                            </div>

                            <div class="info-value">

                                <?= e(
                                    formatEthiopianDate(
                                        $dueDate
                                    )
                                ) ?>

                            </div>

                        </div>

                    </div>


                    <hr class="my-4">


                    <!-- Description -->

                    <div class="mb-4">

                        <h5 class="mb-3">

                            <i class="bi bi-info-circle me-2"></i>

                            Instructions

                        </h5>

                        <?php if (
                            trim((string) $homework['description']) !== ''
                        ): ?>

                            <div class="description-box text-secondary">

                                <?= e(
                                    (string) $homework['description']
                                ) ?>

                            </div>

                        <?php else: ?>

                            <div class="text-muted">

                                No instructions were provided.

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- Teacher material -->

                    <?php if (
                        !empty($homework['teacher_material_path'])
                    ): ?>

                        <div class="mb-4">

                            <h5 class="mb-3">

                                <i class="bi bi-paperclip me-2"></i>

                                Teacher Material

                            </h5>

                            <a
                                href="../../<?= e(
                                    ltrim(
                                        (string) $homework['teacher_material_path'],
                                        '/\\'
                                    )
                                ) ?>"
                                target="_blank"
                                class="btn btn-outline-primary"
                            >

                                <i class="bi bi-download me-1"></i>

                                <?= e(
                                    (string) (
                                        $homework['teacher_material_original_name']
                                        ?: 'Download Material'
                                    )
                                ) ?>

                            </a>

                        </div>

                    <?php endif; ?>


                    <!-- Existing submission -->

                    <?php if ($submission): ?>

                        <div class="mb-4">

                            <h5 class="mb-3">

                                <i class="bi bi-check-circle me-2"></i>

                                Your Current Submission

                            </h5>

                            <div class="existing-file">

                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

                                    <div>

                                        <div class="fw-semibold">

                                            <i class="bi bi-file-earmark me-2"></i>

                                            <?= e(
                                                (string) $submission['original_file_name']
                                            ) ?>

                                        </div>

                                        <small class="text-muted">

                                            Submitted:
                                            <?= e(
                                                formatEthiopianDate(
                                                    substr(
                                                        (string) $submission['submitted_at'],
                                                        0,
                                                        10
                                                    )
                                                )
                                            ) ?>

                                        </small>

                                    </div>


                                    <a
                                        href="../../<?= e(
                                            ltrim(
                                                (string) $submission['file_path'],
                                                '/\\'
                                            )
                                        ) ?>"
                                        target="_blank"
                                        class="btn btn-sm btn-outline-primary"
                                    >

                                        <i class="bi bi-eye me-1"></i>

                                        View File

                                    </a>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>


                    <!-- Status -->

                    <?php if ($isClosed): ?>

                        <div class="alert alert-secondary">

                            <i class="bi bi-lock me-2"></i>

                            This homework has been closed by the teacher.

                        </div>


                    <?php elseif ($isPastDue): ?>

                        <div class="alert alert-danger">

                            <i class="bi bi-clock-history me-2"></i>

                            The submission deadline has passed.

                        </div>


                    <?php else: ?>

                        <!-- Upload form -->

                        <div class="mt-4">

                            <h5 class="mb-3">

                                <?php if ($submission): ?>

                                    <i class="bi bi-arrow-repeat me-2"></i>

                                    Replace Submission

                                <?php else: ?>

                                    <i class="bi bi-upload me-2"></i>

                                    Submit Homework

                                <?php endif; ?>

                            </h5>


                            <form
                                action="save.php"
                                method="POST"
                                enctype="multipart/form-data"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrfToken()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="homework_id"
                                    value="<?= (int) $homework['id'] ?>"
                                >


                                <div class="upload-area mb-3">

                                    <div class="upload-icon">

                                        <i class="bi bi-cloud-arrow-up"></i>

                                    </div>

                                    <h6>

                                        Choose your homework file

                                    </h6>

                                    <p class="text-muted mb-3">

                                        Maximum size: 10 MB

                                    </p>

                                    <input
                                        type="file"
                                        name="submission_file"
                                        id="submission_file"
                                        class="form-control"
                                        required
                                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                                    >

                                    <div class="small text-muted mt-2">

                                        PDF, DOC, DOCX, PPT, PPTX,
                                        XLS, XLSX, JPG, JPEG, PNG or ZIP

                                    </div>

                                </div>


                                <div class="d-grid d-md-flex justify-content-md-end">

                                    <button
                                        type="submit"
                                        class="btn btn-primary btn-lg px-4"
                                    >

                                        <i class="bi bi-upload me-1"></i>

                                        <?= $submission
                                            ? 'Replace Submission'
                                            : 'Submit Homework'
                                        ?>

                                    </button>

                                </div>

                            </form>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>