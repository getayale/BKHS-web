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

$homeworkId = requestInt($_GET, 'id');

if ($homeworkId <= 0) {
    setFlashMessage('danger', 'Invalid homework ID.');
    redirectTo('../homework.php');
}

$academicYear = getActiveAcademicYear($conn);

if ($academicYear === null) {
    setFlashMessage('danger', 'No active academic year was found.');
    redirectTo('../homework.php');
}

$academicYearName = (string) $academicYear['name'];

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

$dueDateEthiopian = gregorianDateToEthiopian(
    (string) $homework['due_date']
);

if ($dueDateEthiopian === null) {
    setFlashMessage(
        'danger',
        'The homework due date could not be converted.'
    );

    redirectTo('../homework.php');
}

$ethiopianMonths = EthiopianCalendar::months('en');
$todayEthiopian = EthiopianCalendar::today();
$flash = getFlashMessage();

$teacherMaterialExists =
    !empty($homework['teacher_material_path']) &&
    is_file(
        dirname(__DIR__, 2) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            (string) $homework['teacher_material_path']
        )
    );

$materialExtension = '';

if (!empty($homework['teacher_material_original_name'])) {
    $materialExtension = strtolower(
        pathinfo(
            (string) $homework['teacher_material_original_name'],
            PATHINFO_EXTENSION
        )
    );
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

    <title>Edit Homework | BKHS</title>

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
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }

        .page-header p {
            margin: 5px 0 0;
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

        .form-label {
            font-weight: 600;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-radius: 9px;
        }

        textarea.form-control {
            min-height: 130px;
        }

        .readonly-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 9px;
            padding: 11px 14px;
            min-height: 45px;
        }

        .locked-note {
            font-size: 13px;
            color: #6c757d;
            margin-top: 5px;
        }

        .material-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 15px;
        }

        .material-name {
            font-weight: 600;
            word-break: break-word;
        }

        .material-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .date-selects {
            display: grid;
            grid-template-columns: 1fr 1.4fr 1fr;
            gap: 10px;
        }

        .alert {
            border-radius: 10px;
        }

        @media (max-width: 700px) {

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-selects {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<div class="page-wrapper">

    <div class="page-header">

        <div>

            <h1>

                <i class="bi bi-pencil-square me-2"></i>

                Edit Homework

            </h1>

            <p>
                Update the homework details without changing its assigned
                class or subject.
            </p>

        </div>

        <a
            href="../homework.php"
            class="btn btn-outline-secondary"
        >

            <i class="bi bi-arrow-left me-1"></i>

            Back

        </a>

    </div>

    <?php if ($flash !== null): ?>

        <div
            class="alert alert-<?= e((string) $flash['type']) ?> mb-4"
        >

            <?= e((string) $flash['message']) ?>

        </div>

    <?php endif; ?>

    <form
        action="update.php"
        method="POST"
        enctype="multipart/form-data"
        id="editHomeworkForm"
        novalidate
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

        <div class="card mb-4">

            <div class="card-header">

                <strong>

                    <i class="bi bi-info-circle me-2"></i>

                    Homework Information

                </strong>

            </div>

            <div class="card-body">

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="form-label">
                            Academic Year
                        </label>

                        <div class="readonly-box">
                            <?= e($academicYearName) ?>
                        </div>

                        <div class="locked-note">
                            Academic year cannot be changed.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Subject
                        </label>

                        <div class="readonly-box">
                            <?= e((string) $homework['subject_name']) ?>
                        </div>

                        <div class="locked-note">
                            Subject cannot be changed.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Grade
                        </label>

                        <div class="readonly-box">
                            Grade <?= (int) $homework['grade'] ?>
                        </div>

                        <div class="locked-note">
                            Grade cannot be changed.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Section
                        </label>

                        <div class="readonly-box">
                            Section <?= e((string) $homework['section']) ?>
                        </div>

                        <div class="locked-note">
                            Section cannot be changed.
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="card mb-4">

            <div class="card-header">

                <strong>

                    <i class="bi bi-journal-text me-2"></i>

                    Homework Details

                </strong>

            </div>

            <div class="card-body">

                <div class="mb-4">

                    <label
                        for="title"
                        class="form-label"
                    >

                        Title
                        <span class="text-danger">*</span>

                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="title"
                        name="title"
                        maxlength="255"
                        value="<?= e((string) $homework['title']) ?>"
                        required
                    >

                </div>

                <div class="mb-4">

                    <label
                        for="description"
                        class="form-label"
                    >

                        Instructions / Description

                    </label>

                    <textarea
                        class="form-control"
                        id="description"
                        name="description"
                        maxlength="10000"
                    ><?= e((string) ($homework['description'] ?? '')) ?></textarea>

                </div>

                <div class="mb-2">

                    <label class="form-label">
                        Assigned Date
                    </label>

                    <div class="readonly-box">

                        <?= e(
                            formatEthiopianDate(
                                gregorianDateToEthiopian(
                                    (string) $homework['assigned_date']
                                )
                            )
                        ) ?>

                    </div>

                    <div class="locked-note">
                        The original assigned date cannot be changed.
                    </div>

                </div>

            </div>

        </div>

        <div class="card mb-4">

            <div class="card-header">

                <strong>

                    <i class="bi bi-calendar-event me-2"></i>

                    Due Date

                </strong>

            </div>

            <div class="card-body">

                <label class="form-label">

                    Due Date
                    <span class="text-danger">*</span>

                </label>

                <div class="date-selects">

                    <select
                        class="form-select"
                        name="due_year"
                        id="due_year"
                        required
                    >

                        <?php

                        $startYear =
                            (int) $todayEthiopian['year'] - 1;

                        $endYear =
                            (int) $todayEthiopian['year'] + 3;

                        for (
                            $year = $startYear;
                            $year <= $endYear;
                            $year++
                        ):

                        ?>

                            <option
                                value="<?= $year ?>"
                                <?= $year === (int) $dueDateEthiopian['year']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= $year ?>

                            </option>

                        <?php endfor; ?>

                    </select>

                    <select
                        class="form-select"
                        name="due_month"
                        id="due_month"
                        required
                    >

                        <?php foreach (
                            $ethiopianMonths
                            as $monthNumber => $monthName
                        ): ?>

                            <option
                                value="<?= (int) $monthNumber ?>"
                                <?= (int) $monthNumber ===
                                    (int) $dueDateEthiopian['month']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= e((string) $monthName) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <select
                        class="form-select"
                        name="due_day"
                        id="due_day"
                        required
                    >

                        <?php

                        $selectedDueYear =
                            (int) $dueDateEthiopian['year'];

                        $selectedDueMonth =
                            (int) $dueDateEthiopian['month'];

                        $selectedDueDay =
                            (int) $dueDateEthiopian['day'];

                        $daysInMonth =
                            EthiopianCalendar::daysInMonth(
                                $selectedDueYear,
                                $selectedDueMonth
                            );

                        for (
                            $day = 1;
                            $day <= $daysInMonth;
                            $day++
                        ):

                        ?>

                            <option
                                value="<?= $day ?>"
                                <?= $day === $selectedDueDay
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= $day ?>

                            </option>

                        <?php endfor; ?>

                    </select>

                </div>

                <div class="form-text mt-2">

                    Due date is entered in the Ethiopian calendar.

                </div>

            </div>

        </div>

        <div class="card mb-4">

            <div class="card-header">

                <strong>

                    <i class="bi bi-paperclip me-2"></i>

                    Teacher Material

                </strong>

            </div>

            <div class="card-body">

                <?php if ($teacherMaterialExists): ?>

                    <div class="material-box mb-3">

                        <div class="d-flex align-items-start gap-3">

                            <i class="bi bi-file-earmark-text fs-3"></i>

                            <div class="flex-grow-1">

                                <div class="material-name">

                                    <?= e(
                                        (string) $homework[
                                            'teacher_material_original_name'
                                        ]
                                    ) ?>

                                </div>

                                <?php if (
                                    !empty(
                                        $homework['teacher_material_size']
                                    )
                                ): ?>

                                    <small class="text-muted">

                                        <?= number_format(
                                            ((int) $homework[
                                                'teacher_material_size'
                                            ]) / 1024,
                                            1
                                        ) ?>

                                        KB

                                    </small>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="material-actions">

                            <a
                                href="../../<?= e(
                                    (string) $homework[
                                        'teacher_material_path'
                                    ]
                                ) ?>"
                                target="_blank"
                                class="btn btn-sm btn-outline-primary"
                            >

                                <i class="bi bi-eye me-1"></i>

                                View

                            </a>

                            <a
                                href="delete-material.php?id=<?= (int) $homework['id'] ?>"
                                class="btn btn-sm btn-outline-danger"
                                onclick="return confirm('Remove this teacher material?');"
                            >

                                <i class="bi bi-trash me-1"></i>

                                Remove

                            </a>

                        </div>

                    </div>

                    <div class="form-text mb-3">

                        Uploading a new file will replace the existing
                        material.

                    </div>

                <?php else: ?>

                    <div class="alert alert-light border">

                        <i class="bi bi-info-circle me-1"></i>

                        No teacher material is currently attached.

                    </div>

                <?php endif; ?>

                <label
                    for="teacher_material"
                    class="form-label"
                >

                    <?= $teacherMaterialExists
                        ? 'Replace Material'
                        : 'Add Material' ?>

                </label>

                <input
                    type="file"
                    class="form-control"
                    id="teacher_material"
                    name="teacher_material"
                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                >

                <div class="form-text">

                    Allowed: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, JPG,
                    JPEG, PNG and ZIP.
                    Maximum size: 10 MB.

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-body d-flex justify-content-end gap-2">

                <a
                    href="../homework.php"
                    class="btn btn-outline-secondary"
                >

                    Cancel

                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="updateButton"
                >

                    <i class="bi bi-check-lg me-1"></i>

                    Save Changes

                </button>

            </div>

        </div>

    </form>

</div>

<script>

const form = document.getElementById('editHomeworkForm');

const updateButton =
    document.getElementById('updateButton');

const dueYear =
    document.getElementById('due_year');

const dueMonth =
    document.getElementById('due_month');

const dueDay =
    document.getElementById('due_day');

function getDaysInEthiopianMonth(year, month) {

    if (month >= 1 && month <= 12) {
        return 30;
    }

    return (year % 4 === 3) ? 6 : 5;

}

function updateDueDays() {

    const year =
        parseInt(dueYear.value, 10);

    const month =
        parseInt(dueMonth.value, 10);

    if (!year || !month) {
        return;
    }

    const currentDay =
        parseInt(dueDay.value, 10) || 1;

    const days =
        getDaysInEthiopianMonth(year, month);

    dueDay.innerHTML = '';

    for (
        let day = 1;
        day <= days;
        day++
    ) {

        const option =
            document.createElement('option');

        option.value = day;

        option.textContent = day;

        if (
            day === Math.min(currentDay, days)
        ) {

            option.selected = true;

        }

        dueDay.appendChild(option);

    }

}

dueYear.addEventListener(
    'change',
    updateDueDays
);

dueMonth.addEventListener(
    'change',
    updateDueDays
);

form.addEventListener(
    'submit',
    function (event) {

        const title =
            document.getElementById('title')
                .value
                .trim();

        if (!title) {

            event.preventDefault();

            alert(
                'Please enter a homework title.'
            );

            document
                .getElementById('title')
                .focus();

            return;

        }

        updateButton.disabled = true;

        updateButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1"></span>' +
            'Saving...';

    }
);

</script>

</body>

</html>