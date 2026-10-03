
<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';

$months = [
    1  => 'Meskerem',
    2  => 'Tikimt',
    3  => 'Hidar',
    4  => 'Tahsas',
    5  => 'Tir',
    6  => 'Yekatit',
    7  => 'Megabit',
    8  => 'Miazia',
    9  => 'Ginbot',
    10 => 'Sene',
    11 => 'Hamle',
    12 => 'Nehase',
    13 => 'Pagume'
];

$errors = [];

$name = '';
$startYear = '';
$startMonth = '';
$startDay = '';
$endYear = '';
$endMonth = '';
$endDay = '';

/*
|--------------------------------------------------------------------------
| Ethiopian Date Validation
|--------------------------------------------------------------------------
*/

function isValidEthiopianDate(
    int $year,
    int $month,
    int $day
): bool {
    if ($year < 1) {
        return false;
    }

    if ($month < 1 || $month > 13) {
        return false;
    }

    $maxDay = $month === 13 ? 5 : 30;

    return $day >= 1 && $day <= $maxDay;
}

/*
|--------------------------------------------------------------------------
| Compare Ethiopian Dates
|--------------------------------------------------------------------------
*/

function compareEthiopianDates(
    int $year1,
    int $month1,
    int $day1,
    int $year2,
    int $month2,
    int $day2
): int {
    if ($year1 !== $year2) {
        return $year1 <=> $year2;
    }

    if ($month1 !== $month2) {
        return $month1 <=> $month2;
    }

    return $day1 <=> $day2;
}

/*
|--------------------------------------------------------------------------
| Escape Form Values
|--------------------------------------------------------------------------
*/

function oldValue(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Form Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');

    $startYear = (int) ($_POST['start_year'] ?? 0);
    $startMonth = (int) ($_POST['start_month'] ?? 0);
    $startDay = (int) ($_POST['start_day'] ?? 0);

    $endYear = (int) ($_POST['end_year'] ?? 0);
    $endMonth = (int) ($_POST['end_month'] ?? 0);
    $endDay = (int) ($_POST['end_day'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Validate Academic Year
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        $errors[] = 'Academic year is required.';

    } elseif (!preg_match('/^\d{4}$/', $name)) {

        $errors[] =
            'Academic year must be a 4-digit Ethiopian year. Example: 2022.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Start Date
    |--------------------------------------------------------------------------
    */

    if (!isValidEthiopianDate(
        $startYear,
        $startMonth,
        $startDay
    )) {
        $errors[] =
            'The academic year start date is not a valid Ethiopian date.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate End Date
    |--------------------------------------------------------------------------
    */

    if (!isValidEthiopianDate(
        $endYear,
        $endMonth,
        $endDay
    )) {
        $errors[] =
            'The academic year end date is not a valid Ethiopian date.';
    }

    /*
    |--------------------------------------------------------------------------
    | Start and End Must Belong to Same Ethiopian Year
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $academicYearNumber = (int) $name;

        if ($startYear !== $academicYearNumber) {

            $errors[] =
                "The start date must belong to Ethiopian year {$academicYearNumber}.";
        }

        if ($endYear !== $academicYearNumber) {

            $errors[] =
                "The end date must belong to Ethiopian year {$academicYearNumber}.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | End Date Must Be After Start Date
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        if (
            compareEthiopianDates(
                $startYear,
                $startMonth,
                $startDay,
                $endYear,
                $endMonth,
                $endDay
            ) >= 0
        ) {
            $errors[] =
                'The academic year end date must be after the start date.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Academic Year
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $stmt = $conn->prepare(
            "SELECT id
             FROM academic_years
             WHERE name = ?
             LIMIT 1"
        );

        if (!$stmt) {

            $errors[] =
                'Unable to check the academic year.';

        } else {

            $stmt->bind_param(
                's',
                $name
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $errors[] =
                    'This academic year already exists.';
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create Academic Year and Semesters
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $conn->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Academic Year
            |--------------------------------------------------------------------------
            */

            $status = 'Not Completed';

            $stmt = $conn->prepare(
                "INSERT INTO academic_years (
                    name,
                    start_year,
                    start_month,
                    start_day,
                    end_year,
                    end_month,
                    end_day,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception(
                    'Failed to prepare academic year creation.'
                );
            }

            $stmt->bind_param(
                'siiiiii' . 's',
                $name,
                $startYear,
                $startMonth,
                $startDay,
                $endYear,
                $endMonth,
                $endDay,
                $status
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Failed to create academic year.'
                );
            }

            $academicYearId = $stmt->insert_id;

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Four Fixed Semesters
            |--------------------------------------------------------------------------
            */

            $semesters = [
                [
                    'name' => 'Mid Semester',
                    'order_number' => 1,
                    'max_mark' => 50
                ],
                [
                    'name' => 'First Semester',
                    'order_number' => 2,
                    'max_mark' => 100
                ],
                [
                    'name' => 'Quarter Semester',
                    'order_number' => 3,
                    'max_mark' => 50
                ],
                [
                    'name' => 'Second Semester',
                    'order_number' => 4,
                    'max_mark' => 100
                ]
            ];

            /*
             * The academic year dates are used initially.
             * Exact semester dates can be changed later
             * from manage-semesters.php.
             */

            $semesterStatus = 'Not Completed';

            $semesterStmt = $conn->prepare(
                "INSERT INTO semesters (
                    academic_year_id,
                    name,
                    order_number,
                    max_mark,
                    start_year,
                    start_month,
                    start_day,
                    end_year,
                    end_month,
                    end_day,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$semesterStmt) {
                throw new Exception(
                    'Failed to prepare semester creation.'
                );
            }

            foreach ($semesters as $semester) {

                $semesterName = $semester['name'];
                $orderNumber = $semester['order_number'];
                $maxMark = $semester['max_mark'];

                $semesterStmt->bind_param(
                    'isidiiiiii' . 's',
                    $academicYearId,
                    $semesterName,
                    $orderNumber,
                    $maxMark,
                    $startYear,
                    $startMonth,
                    $startDay,
                    $endYear,
                    $endMonth,
                    $endDay,
                    $semesterStatus
                );

                if (!$semesterStmt->execute()) {

                    throw new Exception(
                        "Failed to create {$semesterName}."
                    );
                }
            }

            $semesterStmt->close();

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $conn->commit();

            $_SESSION['success_message'] =
                "Academic year {$name} was created successfully with four semesters.";

            header('Location: index.php');
            exit;

        } catch (Throwable $e) {

            $conn->rollback();

            /*
             * Keep the actual error visible during development.
             * Once the system is stable, this can be changed
             * to a generic message.
             */
            $errors[] = $e->getMessage();
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

    <title>Create Academic Year | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >
    <link rel="shortcut icon" type="image/webp" href="public/image/logo.webp">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="../../public/css/admin-users.css"
        rel="stylesheet"
    >

    <style>

        .calendar-form-wrapper {
            max-width: 1050px;
            margin: 0 auto;
        }

        .calendar-form-card {
            border: 0;
            border-radius: 22px;
            background: #ffffff;
            box-shadow:
                0 10px 35px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .form-card-header {
            padding: 28px 30px;
            border-bottom: 1px solid #edf1f5;
        }

        .form-card-header h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #172033;
        }

        .form-card-header p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 0.9rem;
        }

        .form-card-body {
            padding: 30px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #172033;
            font-size: 1rem;
            font-weight: 700;
        }

        .section-title i {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .form-label {
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            min-height: 48px;
            border-radius: 12px;
            border-color: #dbe2ea;
            padding: 10px 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #86b7fe;
            box-shadow:
                0 0 0 0.2rem rgba(13, 110, 253, 0.12);
        }

        .date-group {
            padding: 20px;
            border: 1px solid #e5eaf0;
            border-radius: 16px;
            background: #f8fafc;
        }

        .date-group-title {
            margin-bottom: 16px;
            font-weight: 700;
            color: #1e293b;
        }

        .format-hint {
            margin-top: 7px;
            font-size: 0.78rem;
            color: #64748b;
        }

        .semester-info {
            margin-top: 25px;
            padding: 18px 20px;
            border-radius: 15px;
            background: #f8fafc;
            border: 1px solid #e5eaf0;
        }

        .semester-info-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
        }

        .semester-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .semester-list li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 13px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #edf1f5;
            font-size: 0.88rem;
        }

        .semester-list span:first-child {
            font-weight: 600;
            color: #334155;
        }

        .semester-mark {
            color: #0d6efd;
            font-weight: 700;
            white-space: nowrap;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid #edf1f5;
        }

        .form-actions .btn {
            min-height: 46px;
            border-radius: 11px;
            padding: 9px 18px;
            font-weight: 600;
        }

        @media (max-width: 767.98px) {

            .form-card-header,
            .form-card-body {
                padding: 20px;
            }

            .semester-list {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .form-actions .btn {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->
    <aside class="admin-sidebar">

        <div class="sidebar-brand">

            <div class="brand-mark">
                <i class="bi bi-mortarboard-fill"></i>
            </div>

            <div class="brand-text">
                <strong>Bole Kale Hiwot</strong>
                <span>School Management</span>
            </div>

            <button
                type="button"
                class="sidebar-close"
                id="sidebarClose"
            >
                <i class="bi bi-x-lg"></i>
            </button>

        </div>

        <nav class="sidebar-nav">

            <div class="nav-section-title">
                MAIN
            </div>

            <a
                href="../dashboard.php"
                class="sidebar-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <div class="nav-section-title">
                MANAGEMENT
            </div>

            <a
                href="../users/index.php"
                class="sidebar-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>

            <a
                href="../students/index.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-vcard-fill"></i>
                <span>Students</span>
            </a>

            <a
                href="../teachers/index.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-workspace"></i>
                <span>Teachers</span>
            </a>

            <div class="nav-section-title">
                ACADEMIC
            </div>

            <a
                href="index.php"
                class="sidebar-link active"
            >
                <i class="bi bi-calendar3"></i>
                <span>Academic Calendar</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-book"></i>
                <span>Subjects</span>
            </a>

            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-building"></i>
                <span>Classes</span>
            </a>

            <div class="nav-section-title">
                SYSTEM
            </div>

            <a
                href="../settings.php"
                class="sidebar-link"
            >
                <i class="bi bi-gear-fill"></i>
                <span>Settings</span>
            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="../../auth/logout.php"
                class="logout-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <!-- Main -->
    <main class="admin-main">

        <!-- Topbar -->
        <header class="admin-topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">
                <span>Administration</span>
            </div>

            <div class="topbar-profile">

                <div class="profile-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div class="profile-info">

                    <strong>
                        <?= htmlspecialchars(
                            $_SESSION['full_name'] ?? 'Administrator'
                        ) ?>
                    </strong>

                    <span>
                        Administrator
                    </span>

                </div>

            </div>

        </header>

        <div class="admin-content">

            <!-- Page Header -->
            <div class="page-header">

                <div>

                    <h1>
                        Create Academic Year
                    </h1>

                    <p>
                        Set up a new Ethiopian academic year and its four semesters.
                    </p>

                </div>

            </div>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>

                <div
                    class="alert alert-danger border-0 shadow-sm"
                    role="alert"
                >

                    <div class="d-flex gap-2">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <div>

                            <strong>
                                Please fix the following:
                            </strong>

                            <ul class="mb-0 mt-2">

                                <?php foreach ($errors as $error): ?>

                                    <li>
                                        <?= htmlspecialchars($error) ?>
                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

            <!-- Form -->
            <div class="calendar-form-wrapper">

                <div class="calendar-form-card">

                    <div class="form-card-header">

                        <h4>
                            <i class="bi bi-calendar-plus me-2"></i>
                            Academic Year Details
                        </h4>

                        <p>
                            All dates use the Ethiopian calendar.
                        </p>

                    </div>

                    <div class="form-card-body">

                        <form
                            method="POST"
                            action=""
                        >

                            <!-- Academic Year -->
                            <div class="section-title">

                                <i class="bi bi-calendar-event"></i>

                                Academic Year

                            </div>

                            <div class="row g-4">

                                <div class="col-12 col-md-6">

                                    <label
                                        for="name"
                                        class="form-label"
                                    >
                                        Academic Year
                                    </label>

                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        class="form-control"
                                        value="<?= oldValue($name) ?>"
                                        placeholder="Example: 2022"
                                        maxlength="4"
                                        inputmode="numeric"
                                        pattern="[0-9]{4}"
                                        required
                                    >

                                    <div class="format-hint">
                                        Enter the Ethiopian academic year,
                                        for example <strong>2022</strong>.
                                    </div>

                                </div>

                            </div>

                            <hr class="my-4">

                            <!-- Academic Year Period -->
                            <div class="section-title">

                                <i class="bi bi-calendar-range"></i>

                                Academic Year Period

                            </div>

                            <div class="row g-4">

                                <!-- Start Date -->
                                <div class="col-12 col-lg-6">

                                    <div class="date-group">

                                        <div class="date-group-title">
                                            Start Date
                                        </div>

                                        <div class="row g-3">

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="start_year"
                                                    class="form-label"
                                                >
                                                    Year
                                                </label>

                                                <input
                                                    type="number"
                                                    id="start_year"
                                                    name="start_year"
                                                    class="form-control"
                                                    value="<?= oldValue($startYear) ?>"
                                                    min="1"
                                                    required
                                                >

                                            </div>

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="start_month"
                                                    class="form-label"
                                                >
                                                    Month
                                                </label>

                                                <select
                                                    id="start_month"
                                                    name="start_month"
                                                    class="form-select"
                                                    required
                                                >

                                                    <option value="">
                                                        Select
                                                    </option>

                                                    <?php foreach ($months as $number => $month): ?>

                                                        <option
                                                            value="<?= $number ?>"
                                                            <?= ((int) $startMonth === $number)
                                                                ? 'selected'
                                                                : '' ?>
                                                        >
                                                            <?= htmlspecialchars($month) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="start_day"
                                                    class="form-label"
                                                >
                                                    Day
                                                </label>

                                                <input
                                                    type="number"
                                                    id="start_day"
                                                    name="start_day"
                                                    class="form-control"
                                                    value="<?= oldValue($startDay) ?>"
                                                    min="1"
                                                    max="30"
                                                    required
                                                >

                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <!-- End Date -->
                                <div class="col-12 col-lg-6">

                                    <div class="date-group">

                                        <div class="date-group-title">
                                            End Date
                                        </div>

                                        <div class="row g-3">

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="end_year"
                                                    class="form-label"
                                                >
                                                    Year
                                                </label>

                                                <input
                                                    type="number"
                                                    id="end_year"
                                                    name="end_year"
                                                    class="form-control"
                                                    value="<?= oldValue($endYear) ?>"
                                                    min="1"
                                                    required
                                                >

                                            </div>

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="end_month"
                                                    class="form-label"
                                                >
                                                    Month
                                                </label>

                                                <select
                                                    id="end_month"
                                                    name="end_month"
                                                    class="form-select"
                                                    required
                                                >

                                                    <option value="">
                                                        Select
                                                    </option>

                                                    <?php foreach ($months as $number => $month): ?>

                                                        <option
                                                            value="<?= $number ?>"
                                                            <?= ((int) $endMonth === $number)
                                                                ? 'selected'
                                                                : '' ?>
                                                        >
                                                            <?= htmlspecialchars($month) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                            <div class="col-12 col-sm-4">

                                                <label
                                                    for="end_day"
                                                    class="form-label"
                                                >
                                                    Day
                                                </label>

                                                <input
                                                    type="number"
                                                    id="end_day"
                                                    name="end_day"
                                                    class="form-control"
                                                    value="<?= oldValue($endDay) ?>"
                                                    min="1"
                                                    max="30"
                                                    required
                                                >

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                            <!-- Semesters -->
                            <div class="semester-info">

                                <div class="semester-info-title">
                                    <i class="bi bi-list-check me-1"></i>
                                    Semesters Created Automatically
                                </div>

                                <ul class="semester-list">

                                    <li>
                                        <span>
                                            1. Mid Semester
                                        </span>

                                        <span class="semester-mark">
                                            50 Marks
                                        </span>
                                    </li>

                                    <li>
                                        <span>
                                            2. First Semester
                                        </span>

                                        <span class="semester-mark">
                                            100 Marks
                                        </span>
                                    </li>

                                    <li>
                                        <span>
                                            3. Quarter Semester
                                        </span>

                                        <span class="semester-mark">
                                            50 Marks
                                        </span>
                                    </li>

                                    <li>
                                        <span>
                                            4. Second Semester
                                        </span>

                                        <span class="semester-mark">
                                            100 Marks
                                        </span>
                                    </li>

                                </ul>

                            </div>

                            <!-- Actions -->
                            <div class="form-actions">

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    <i class="bi bi-check2-circle me-1"></i>
                                    Create Academic Year
                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<script>

    const sidebar =
        document.querySelector('.admin-sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenuButton =
        document.getElementById('mobileMenuButton');

    const sidebarClose =
        document.getElementById('sidebarClose');

    function openSidebar() {

        sidebar?.classList.add('show');
        overlay?.classList.add('show');

        document.body.classList.add(
            'sidebar-open'
        );
    }

    function closeSidebar() {

        sidebar?.classList.remove('show');
        overlay?.classList.remove('show');

        document.body.classList.remove(
            'sidebar-open'
        );
    }

    mobileMenuButton?.addEventListener(
        'click',
        openSidebar
    );

    sidebarClose?.addEventListener(
        'click',
        closeSidebar
    );

    overlay?.addEventListener(
        'click',
        closeSidebar
    );

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth >= 992) {
                closeSidebar();
            }

        }
    );

</script>

</body>
</html>

