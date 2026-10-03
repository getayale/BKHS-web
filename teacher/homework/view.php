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
| Get homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = requestInt($_GET, 'id');

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
| Get homework
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
        'Homework was not found or you do not have permission to view it.'
    );

    redirectTo('../homework.php');
}

/*
|--------------------------------------------------------------------------
| Get students and their statuses
|--------------------------------------------------------------------------
*/

$students = getHomeworkStudentStatuses(
    $conn,
    $homeworkId,
    $academicYearId,
    (int) $homework['grade'],
    (string) $homework['section']
);

/*
|--------------------------------------------------------------------------
| Ethiopian dates
|--------------------------------------------------------------------------
*/

$assignedDateEthiopian = gregorianDateToEthiopian(
    (string) $homework['assigned_date']
);

$dueDateEthiopian = gregorianDateToEthiopian(
    (string) $homework['due_date']
);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalStudents = count($students);
$doneCount = 0;
$submittedCount = 0;

foreach ($students as $student) {
    if (
        (string) ($student['homework_status'] ?? 'Not Done')
        === 'Done'
    ) {
        $doneCount++;
    }

    if (!empty($student['submission_id'])) {
        $submittedCount++;
    }
}

$notDoneCount = $totalStudents - $doneCount;
$notSubmittedCount = $totalStudents - $submittedCount;

$flash = getFlashMessage();

/*
|--------------------------------------------------------------------------
| Status badge helpers
|--------------------------------------------------------------------------
*/

function homeworkStatusBadge(string $status): string
{
    if ($status === 'Done') {
        return '
            <span class="badge text-bg-success">
                <i class="bi bi-check-circle me-1"></i>
                Done
            </span>
        ';
    }

    return '
        <span class="badge text-bg-warning">
            <i class="bi bi-clock me-1"></i>
            Not Done
        </span>
    ';
}

function submissionStatusBadge(bool $submitted): string
{
    if ($submitted) {
        return '
            <span class="badge text-bg-primary">
                <i class="bi bi-file-earmark-check me-1"></i>
                Submitted
            </span>
        ';
    }

    return '
        <span class="badge text-bg-secondary">
            <i class="bi bi-file-earmark-x me-1"></i>
            Not Submitted
        </span>
    ';
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
    <?= e((string) $homework['title']) ?> | Homework
</title>

<!-- Favicon -->
<link
    rel="icon"
    type="image/webp"
    href="../../public/image/logo.webp"
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
        font-family:
            Inter,
            system-ui,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
    }

    .page-wrapper {
        max-width: 1250px;
        margin: 0 auto;
        padding: 30px 20px 60px;
    }

    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 700;
        line-height: 1.3;
        word-break: break-word;
    }

    .page-header p {
        margin: 6px 0 0;
        color: #6c757d;
    }

    .card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.06);
    }

    .card-header {
        background: #fff;
        border-bottom: 1px solid #edf0f5;
        padding: 18px 22px;
        border-radius: 16px 16px 0 0 !important;
    }

    .card-body {
        padding: 24px;
    }

    .info-label {
        color: #6c757d;
        font-size: 13px;
        margin-bottom: 4px;
    }

    .info-value {
        font-weight: 600;
    }

    .description-box {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 16px;
        white-space: pre-wrap;
        line-height: 1.6;
        word-break: break-word;
    }

    .stat-card {
        height: 100%;
        padding: 20px;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
    }

    .stat-number {
        font-size: 28px;
        font-weight: 700;
    }

    .stat-label {
        color: #6c757d;
        font-size: 14px;
    }

    .material-box {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 14px;
    }

    .table-card {
        overflow: hidden;
    }

    .table-responsive {
        border-radius: 0 0 16px 16px;
    }

    .student-name {
        font-weight: 600;
    }

    .student-code {
        font-size: 13px;
        color: #6c757d;
    }

    .status-form {
        display: inline;
    }

    .status-button {
        border: 0;
        background: transparent;
        padding: 0;
    }

    .status-button:hover {
        opacity: 0.85;
    }

    .submission-file {
        max-width: 240px;
        word-break: break-word;
    }

    .empty-state {
        padding: 50px 20px;
        text-align: center;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 45px;
        display: block;
        margin-bottom: 12px;
    }

    .mobile-stat {
        min-height: 105px;
    }

    .mark-all-form {
        margin: 0;
    }

    @media (max-width: 768px) {

        .page-wrapper {
            padding: 20px 12px 40px;
        }

        .page-header {
            flex-direction: column;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 22px;
        }

        .page-header > div:last-child {
            width: 100%;
            display: flex;
        }

        .page-header > div:last-child .btn {
            flex: 1;
        }

        .card-body {
            padding: 18px;
        }

        .card-header {
            padding: 15px 18px;
        }

        .stat-card {
            padding: 16px;
        }

        .stat-number {
            font-size: 24px;
        }

        .material-box {
            flex-direction: column;
            align-items: flex-start !important;
        }

        .material-box .btn {
            width: 100%;
        }

        .table {
            min-width: 850px;
        }
    }

    @media (max-width: 576px) {

        .student-status-header {
            align-items: stretch !important;
            flex-direction: column;
        }

        .student-status-header .mark-all-form {
            width: 100%;
        }

        .student-status-header .mark-all-form .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {

        .page-wrapper {
            padding-left: 10px;
            padding-right: 10px;
        }

        .page-header h1 {
            font-size: 20px;
        }

        .page-header p {
            font-size: 14px;
        }

        .stat-card {
            padding: 14px;
        }

        .stat-number {
            font-size: 22px;
        }

        .stat-label {
            font-size: 13px;
        }
    }

</style>


</head>

<body>

<div class="page-wrapper">


<!-- ==============================================================
     HEADER
     ============================================================== -->

<div class="page-header">

    <div>

        <h1>
            <i class="bi bi-journal-check me-2"></i>
            <?= e((string) $homework['title']) ?>
        </h1>

        <p>
            Grade
            <?= (int) $homework['grade'] ?>
            -
            Section
            <?= e((string) $homework['section']) ?>
            -
            <?= e((string) $homework['subject_name']) ?>
        </p>

    </div>

    <div class="d-flex gap-2">

        <a
            href="edit.php?id=<?= (int) $homework['id'] ?>"
            class="btn btn-primary"
        >
            <i class="bi bi-pencil me-1"></i>
            Edit
        </a>

        <a
            href="../homework.php"
            class="btn btn-outline-secondary"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Back
        </a>

    </div>

</div>

<!-- ==============================================================
     FLASH MESSAGE
     ============================================================== -->

<?php if ($flash !== null): ?>

    <div
        class="alert alert-<?= e((string) $flash['type']) ?>"
        role="alert"
    >
        <?= e((string) $flash['message']) ?>
    </div>

<?php endif; ?>

<!-- ==============================================================
     HOMEWORK INFORMATION
     ============================================================== -->

<div class="card mb-4">

    <div class="card-header">

        <strong>
            <i class="bi bi-info-circle me-2"></i>
            Homework Information
        </strong>

    </div>

    <div class="card-body">

        <div class="row g-4">

            <!-- Academic Year -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Academic Year
                </div>

                <div class="info-value">
                    <?= e($academicYearName) ?>
                </div>

            </div>

            <!-- Subject -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Subject
                </div>

                <div class="info-value">
                    <?= e((string) $homework['subject_name']) ?>
                </div>

            </div>

            <!-- Class -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Class
                </div>

                <div class="info-value">
                    Grade
                    <?= (int) $homework['grade'] ?>
                    -
                    <?= e((string) $homework['section']) ?>
                </div>

            </div>

            <!-- Status -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Status
                </div>

                <div class="info-value">

                    <?php if (
                        (string) $homework['status'] === 'Active'
                    ): ?>

                        <span class="badge text-bg-success">
                            Active
                        </span>

                    <?php else: ?>

                        <span class="badge text-bg-secondary">
                            Closed
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <!-- Assigned Date -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Assigned Date
                </div>

                <div class="info-value">

                    <?php if (
                        $assignedDateEthiopian !== null
                    ): ?>

                        <?= e(
                            formatEthiopianDate(
                                $assignedDateEthiopian
                            )
                        ) ?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </div>

                <small class="text-muted">

                    <?= e(
                        (string) $homework['assigned_date']
                    ) ?>

                </small>

            </div>

            <!-- Due Date -->

            <div class="col-6 col-md-3">

                <div class="info-label">
                    Due Date
                </div>

                <div class="info-value">

                    <?php if (
                        $dueDateEthiopian !== null
                    ): ?>

                        <?= e(
                            formatEthiopianDate(
                                $dueDateEthiopian
                            )
                        ) ?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </div>

                <small class="text-muted">

                    <?= e(
                        (string) $homework['due_date']
                    ) ?>

                </small>

            </div>

            <!-- Description -->

            <div class="col-12">

                <div class="info-label">
                    Instructions / Description
                </div>

                <?php if (
                    trim(
                        (string) (
                            $homework['description'] ?? ''
                        )
                    ) !== ''
                ): ?>

                    <div class="description-box">

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

        </div>

    </div>

</div>

<!-- ==============================================================
     TEACHER MATERIAL
     ============================================================== -->

<?php if (
    !empty($homework['teacher_material_path'])
): ?>

    <div class="card mb-4">

        <div class="card-header">

            <strong>
                <i class="bi bi-paperclip me-2"></i>
                Teacher Material
            </strong>

        </div>

        <div class="card-body">

            <div
                class="material-box d-flex align-items-center justify-content-between gap-3"
            >

                <div>

                    <i class="bi bi-file-earmark-text me-2"></i>

                    <strong>

                        <?= e(
                            (string) (
                                $homework[
                                    'teacher_material_original_name'
                                ]
                                ?? 'Teacher Material'
                            )
                        ) ?>

                    </strong>

                </div>

                <a
                    href="../../<?= e(
                        (string) $homework[
                            'teacher_material_path'
                        ]
                    ) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn btn-sm btn-outline-primary"
                >

                    <i class="bi bi-download me-1"></i>
                    Open

                </a>

            </div>

        </div>

    </div>

<?php endif; ?>

<!-- ==============================================================
     STATISTICS
     ============================================================== -->

<div class="row g-3 mb-4">

    <!-- Total -->

    <div class="col-6 col-lg-3">

        <div class="stat-card mobile-stat">

            <div class="stat-number">
                <?= $totalStudents ?>
            </div>

            <div class="stat-label">
                Total Students
            </div>

        </div>

    </div>

    <!-- Done -->

    <div class="col-6 col-lg-3">

        <div class="stat-card mobile-stat">

            <div class="stat-number text-success">
                <?= $doneCount ?>
            </div>

            <div class="stat-label">
                Homework Done
            </div>

        </div>

    </div>

    <!-- Submitted -->

    <div class="col-6 col-lg-3">

        <div class="stat-card mobile-stat">

            <div class="stat-number text-primary">
                <?= $submittedCount ?>
            </div>

            <div class="stat-label">
                Submitted
            </div>

        </div>

    </div>

    <!-- Not Done -->

    <div class="col-6 col-lg-3">

        <div class="stat-card mobile-stat">

            <div class="stat-number text-warning">
                <?= $notDoneCount ?>
            </div>

            <div class="stat-label">
                Not Done
            </div>

        </div>

    </div>

</div>

<!-- ==============================================================
     STUDENTS
     ============================================================== -->

<div class="card table-card">

    <div
        class="card-header student-status-header d-flex align-items-center justify-content-between flex-wrap gap-2"
    >

        <div class="d-flex align-items-center gap-2">

            <strong>
                <i class="bi bi-people me-2"></i>
                Student Homework Status
            </strong>

            <span class="text-muted">
                <?= $totalStudents ?>
                students
            </span>

        </div>

        <!-- ======================================================
             MARK ALL DONE
             ====================================================== -->

        <?php if (
            $totalStudents > 0 &&
            $notDoneCount > 0
        ): ?>

            <form
                action="status.php"
                method="POST"
                class="mark-all-form"
                onsubmit="return confirm('Are you sure you want to mark all students as Done?');"
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

                <input
                    type="hidden"
                    name="action"
                    value="all_done"
                >

                <button
                    type="submit"
                    class="btn btn-sm btn-success"
                >

                    <i class="bi bi-check-all me-1"></i>
                    Mark All Done

                </button>

            </form>

        <?php endif; ?>

    </div>

    <?php if (empty($students)): ?>

        <div class="empty-state">

            <i class="bi bi-people"></i>

            <h5>
                No students found
            </h5>

            <p class="mb-0">
                There are currently no students registered
                in this class.
            </p>

        </div>

    <?php else: ?>

        <div class="table-responsive">

            <table class="table table-hover align-middle mb-0">

                <thead class="table-light">

                    <tr>

                        <th style="width: 60px;">
                            #
                        </th>

                        <th>
                            Student
                        </th>

                        <th>
                            Homework Status
                        </th>

                        <th>
                            Submission
                        </th>

                        <th>
                            Submitted File
                        </th>

                        <th>
                            Submitted At
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach (
                    $students as $index => $student
                ): ?>

                    <?php

                    $studentStatus =
                        (string) (
                            $student['homework_status']
                            ?? 'Not Done'
                        );

                    $submitted =
                        !empty(
                            $student['submission_id']
                        );

                    ?>

                    <tr>

                        <!-- Number -->

                        <td>
                            <?= $index + 1 ?>
                        </td>

                        <!-- Student -->

                        <td>

                            <div class="student-name">

                                <?= e(
                                    (string) $student['full_name']
                                ) ?>

                            </div>

                            <div class="student-code">

                                <?= e(
                                    (string) $student['student_code']
                                ) ?>

                            </div>

                        </td>

                        <!-- Teacher Completion Status -->

                        <td>

                            <form
                                action="status.php"
                                method="POST"
                                class="status-form"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(
                                        csrfToken()
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="homework_id"
                                    value="<?= (int) $homework['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="student_id"
                                    value="<?= (int) $student['student_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="status"
                                    value="<?= $studentStatus === 'Done'
                                        ? 'Not Done'
                                        : 'Done' ?>"
                                >

                                <button
                                    type="submit"
                                    class="status-button"
                                    title="Click to change homework status"
                                >

                                    <?= homeworkStatusBadge(
                                        $studentStatus
                                    ) ?>

                                </button>

                            </form>

                        </td>

                        <!-- Submission Status -->

                        <td>

                            <?= submissionStatusBadge(
                                $submitted
                            ) ?>

                        </td>

                        <!-- Submitted File -->

                        <td>

                            <?php if ($submitted): ?>

                                <div class="submission-file">

                                    <a
                                        href="download-submission.php?id=<?= (int) $student['submission_id'] ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-decoration-none"
                                    >

                                        <i class="bi bi-file-earmark-arrow-down me-1"></i>

                                        <?= e(
                                            (string) (
                                                $student[
                                                    'original_file_name'
                                                ]
                                                ?? 'View submission'
                                            )
                                        ) ?>

                                    </a>

                                </div>

                            <?php else: ?>

                                <span class="text-muted">
                                    —
                                </span>

                            <?php endif; ?>

                        </td>

                        <!-- Submitted At -->

                        <td>

                            <?php if (
                                !empty(
                                    $student['submitted_at']
                                )
                            ): ?>

                                <?php

                                $submissionDate =
                                    gregorianDateToEthiopian(
                                        substr(
                                            (string) $student[
                                                'submitted_at'
                                            ],
                                            0,
                                            10
                                        )
                                    );

                                ?>

                                <?php if (
                                    $submissionDate !== null
                                ): ?>

                                    <div>

                                        <?= e(
                                            formatEthiopianDate(
                                                $submissionDate
                                            )
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                                <small class="text-muted">

                                    <?= e(
                                        (string) $student[
                                            'submitted_at'
                                        ]
                                    ) ?>

                                </small>

                            <?php else: ?>

                                <span class="text-muted">
                                    —
                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>


</div>

</body>

</html>
