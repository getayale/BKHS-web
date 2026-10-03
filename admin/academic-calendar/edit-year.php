
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

/*
|--------------------------------------------------------------------------
| Ethiopian Months
|--------------------------------------------------------------------------
*/

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

$statuses = [
    'Not Completed',
    'Active',
    'Completed'
];

$errors = [];

$academicYearId = (int) (
    $_GET['id']
    ?? $_POST['id']
    ?? 0
);

if ($academicYearId <= 0) {
    $_SESSION['error_message'] = 'Invalid academic year.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper Functions
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

    /*
     * Months 1-12 have 30 days.
     * Pagume normally has 5 days.
     */
    $maxDay = $month === 13 ? 5 : 30;

    return $day >= 1 && $day <= $maxDay;
}

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

function oldValue($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Load Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        start_year,
        start_month,
        start_day,
        end_year,
        end_month,
        end_day,
        status
     FROM academic_years
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    $_SESSION['error_message'] =
        'Unable to prepare the academic year request.';

    header('Location: index.php');
    exit;
}

$stmt->bind_param(
    'i',
    $academicYearId
);

$stmt->execute();

$result = $stmt->get_result();

$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    $_SESSION['error_message'] =
        'Academic year not found.';

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/

$name = $academicYear['name'];

$startYear = (int) $academicYear['start_year'];
$startMonth = (int) $academicYear['start_month'];
$startDay = (int) $academicYear['start_day'];

$endYear = (int) $academicYear['end_year'];
$endMonth = (int) $academicYear['end_month'];
$endDay = (int) $academicYear['end_day'];

$status = $academicYear['status'];

/*
|--------------------------------------------------------------------------
| Handle Update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim(
        $_POST['name'] ?? ''
    );

    $startYear = (int) (
        $_POST['start_year'] ?? 0
    );

    $startMonth = (int) (
        $_POST['start_month'] ?? 0
    );

    $startDay = (int) (
        $_POST['start_day'] ?? 0
    );

    $endYear = (int) (
        $_POST['end_year'] ?? 0
    );

    $endMonth = (int) (
        $_POST['end_month'] ?? 0
    );

    $endDay = (int) (
        $_POST['end_day'] ?? 0
    );

    $status = trim(
        $_POST['status'] ?? 'Not Completed'
    );

    /*
    |--------------------------------------------------------------------------
    | Validate Academic Year
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        $errors[] =
            'Academic year is required.';

    } elseif (!preg_match('/^\d{4}$/', $name)) {

        $errors[] =
            'Academic year must be a 4-digit Ethiopian year. Example: 2022.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Status
    |--------------------------------------------------------------------------
    */

    if (!in_array($status, $statuses, true)) {

        $errors[] =
            'Invalid academic year status.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Start Date
    |--------------------------------------------------------------------------
    */

    if (
        !isValidEthiopianDate(
            $startYear,
            $startMonth,
            $startDay
        )
    ) {
        $errors[] =
            'The academic year start date is not a valid Ethiopian date.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate End Date
    |--------------------------------------------------------------------------
    */

    if (
        !isValidEthiopianDate(
            $endYear,
            $endMonth,
            $endDay
        )
    ) {
        $errors[] =
            'The academic year end date is not a valid Ethiopian date.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Date Year
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
    | Validate Date Order
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
               AND id <> ?
             LIMIT 1"
        );

        if (!$stmt) {

            $errors[] =
                'Unable to check the academic year.';

        } else {

            $stmt->bind_param(
                'si',
                $name,
                $academicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $errors[] =
                    'Another academic year with this name already exists.';
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Active Academic Year
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $status === 'Active'
    ) {

        $stmt = $conn->prepare(
            "SELECT id
             FROM academic_years
             WHERE status = 'Active'
               AND id <> ?
             LIMIT 1"
        );

        if (!$stmt) {

            $errors[] =
                'Unable to check the active academic year.';

        } else {

            $stmt->bind_param(
                'i',
                $academicYearId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $errors[] =
                    'Another academic year is already Active. Activate this year from the Academic Calendar page after completing the current active year.';
            }

            $stmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Completed Year Validation
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $status === 'Completed'
    ) {

        $semesterStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN status = 'Completed'
                        THEN 1
                        ELSE 0
                    END
                ) AS completed_count
             FROM semesters
             WHERE academic_year_id = ?"
        );

        if (!$semesterStmt) {

            $errors[] =
                'Unable to verify semester completion.';

        } else {

            $semesterStmt->bind_param(
                'i',
                $academicYearId
            );

            $semesterStmt->execute();

            $semesterResult =
                $semesterStmt->get_result();

            $semesterData =
                $semesterResult->fetch_assoc();

            $semesterStmt->close();

            $totalSemesters =
                (int) ($semesterData['total'] ?? 0);

            $completedSemesters =
                (int) ($semesterData['completed_count'] ?? 0);

            if ($totalSemesters !== 4) {

                $errors[] =
                    'The academic year must have exactly four semesters before it can be completed.';

            } elseif ($completedSemesters !== 4) {

                $errors[] =
                    'All four semesters must be Completed before the academic year can be marked Completed.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Database
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $conn->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Update Academic Year
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare(
                "UPDATE academic_years
                 SET
                    name = ?,
                    start_year = ?,
                    start_month = ?,
                    start_day = ?,
                    end_year = ?,
                    end_month = ?,
                    end_day = ?,
                    status = ?
                 WHERE id = ?"
            );

            if (!$stmt) {

                throw new Exception(
                    'Unable to prepare academic year update.'
                );
            }

            /*
             * IMPORTANT:
             *
             * 9 variables:
             *
             * name           = s
             * start_year     = i
             * start_month    = i
             * start_day      = i
             * end_year       = i
             * end_month      = i
             * end_day        = i
             * status         = s
             * academicYearId = i
             *
             * Correct type string:
             * siiiiii si
             *
             * Without the space:
             * siiiiiisi
             */

            $stmt->bind_param(
                'siiiiiisi',
                $name,
                $startYear,
                $startMonth,
                $startDay,
                $endYear,
                $endMonth,
                $endDay,
                $status,
                $academicYearId
            );

            if (!$stmt->execute()) {

                throw new Exception(
                    'Unable to update the academic year.'
                );
            }

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | If Academic Year Is Active
            |--------------------------------------------------------------------------
            */

            if ($status === 'Active') {

                /*
                |--------------------------------------------------------------------------
                | Reset Other Active Academic Years
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare(
                    "UPDATE academic_years
                     SET status = 'Not Completed'
                     WHERE id <> ?
                       AND status = 'Active'"
                );

                if (!$stmt) {

                    throw new Exception(
                        'Unable to update other academic years.'
                    );
                }

                $stmt->bind_param(
                    'i',
                    $academicYearId
                );

                if (!$stmt->execute()) {

                    throw new Exception(
                        'Unable to update other academic year statuses.'
                    );
                }

                $stmt->close();

                /*
                |--------------------------------------------------------------------------
                | Check Active Semester
                |--------------------------------------------------------------------------
                */

                $semesterCheck = $conn->prepare(
                    "SELECT id
                     FROM semesters
                     WHERE academic_year_id = ?
                       AND status = 'Active'
                     LIMIT 1"
                );

                if (!$semesterCheck) {

                    throw new Exception(
                        'Unable to check active semester.'
                    );
                }

                $semesterCheck->bind_param(
                    'i',
                    $academicYearId
                );

                $semesterCheck->execute();

                $semesterResult =
                    $semesterCheck->get_result();

                $activeSemester =
                    $semesterResult->fetch_assoc();

                $semesterCheck->close();

                /*
                |--------------------------------------------------------------------------
                | Activate First Semester If None Is Active
                |--------------------------------------------------------------------------
                */

                if (!$activeSemester) {

                    $activateSemester = $conn->prepare(
                        "UPDATE semesters
                         SET status = 'Active'
                         WHERE academic_year_id = ?
                           AND order_number = 1"
                    );

                    if (!$activateSemester) {

                        throw new Exception(
                            'Unable to activate the first semester.'
                        );
                    }

                    $activateSemester->bind_param(
                        'i',
                        $academicYearId
                    );

                    if (!$activateSemester->execute()) {

                        throw new Exception(
                            'Unable to activate the first semester.'
                        );
                    }

                    if ($activateSemester->affected_rows === 0) {

                        throw new Exception(
                            'The first semester was not found for this academic year.'
                        );
                    }

                    $activateSemester->close();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $conn->commit();

            $_SESSION['success_message'] =
                "Academic year {$name} was updated successfully.";

            header('Location: index.php');
            exit;

        } catch (Throwable $e) {

            $conn->rollback();

            $errors[] =
                $e->getMessage();
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

    <title>
        Edit Academic Year - BKHS
    </title>

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

    <style>

        body {
            font-family: 'Inter', sans-serif;
        }

        .academic-page {
            padding-bottom: 40px;
        }

        .page-intro {
            margin-bottom: 28px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 800;
            color: #172033;
            margin-bottom: 8px;
        }

        .page-subtitle {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .form-card {
            background: #ffffff;
            border: 1px solid #e7eaf0;
            border-radius: 18px;
            overflow: hidden;
            box-shadow:
                0 8px 30px rgba(15, 23, 42, 0.05);
        }

        .form-card-header {
            padding: 22px;
            border-bottom: 1px solid #edf0f4;
            background: #fbfcfe;
        }

        .form-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-size: 17px;
            font-weight: 750;
            color: #172033;
        }

        .form-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef4ff;
            color: #315efb;
            font-size: 18px;
        }

        .form-card-body {
            padding: 24px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 45px;
            border-radius: 10px;
            border-color: #dfe4eb;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #6d8cff;
            box-shadow:
                0 0 0 3px rgba(49, 94, 251, 0.10);
        }

        .date-card {
            padding: 18px;
            border: 1px solid #edf0f4;
            border-radius: 14px;
            background: #fafbfc;
        }

        .date-card-title {
            font-size: 13px;
            font-weight: 800;
            color: #172033;
            margin-bottom: 15px;
        }

        .date-card-title i {
            color: #315efb;
        }

        .form-hint {
            color: #7b8495;
            font-size: 11px;
            margin-top: 6px;
        }

        .status-info {
            padding: 14px 16px;
            border-radius: 12px;
            background: #f5f7fb;
            border: 1px solid #e7eaf0;
            color: #667085;
            font-size: 12px;
            line-height: 1.6;
        }

        .status-info strong {
            color: #344054;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid #edf0f4;
        }

        .btn-save {
            min-height: 44px;
            padding: 0 20px;
            border: 0;
            border-radius: 10px;
            background: #315efb;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            transition: .2s ease;
        }

        .btn-save:hover {
            background: #244de0;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-cancel {
            min-height: 44px;
            padding: 0 18px;
            border: 1px solid #dfe4eb;
            border-radius: 10px;
            background: #ffffff;
            color: #475467;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-cancel:hover {
            background: #f8fafc;
            color: #172033;
        }

        .alert {
            border-radius: 12px;
            font-size: 13px;
        }

        @media (max-width: 767px) {

            .page-title {
                font-size: 23px;
            }

            .form-card-header,
            .form-card-body {
                padding: 17px;
            }

            .date-card {
                padding: 15px;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .btn-save,
            .btn-cancel {
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
                <i class="bi bi-calendar3-fill"></i>
                <span>Academic Calendar</span>
            </a>

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

            <div class="topbar-spacer"></div>

            <div class="topbar-profile">

                <div class="profile-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div class="profile-info">

                    <strong>
                        <?= oldValue(
                            $_SESSION['full_name']
                            ?? 'Administrator'
                        ) ?>
                    </strong>

                    <span>
                        Administrator
                    </span>

                </div>

            </div>

        </header>

        <div class="academic-page">

            <!-- Page Header -->

            <div class="page-intro">

                <h1 class="page-title">
                    Edit Academic Year
                </h1>

                <p class="page-subtitle">
                    Update the Ethiopian academic year information and dates.
                </p>

            </div>

            <!-- Errors -->

            <?php if (!empty($errors)): ?>

                <div class="alert alert-danger mb-4">

                    <div class="d-flex gap-2">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <div>

                            <?php foreach ($errors as $error): ?>

                                <div>
                                    <?= oldValue($error) ?>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

            <!-- Form Card -->

            <div class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <span class="form-card-icon">
                            <i class="bi bi-calendar3"></i>
                        </span>

                        Academic Year Information

                    </h2>

                </div>

                <div class="form-card-body">

                    <form
                        method="POST"
                        action="edit-year.php?id=<?= $academicYearId ?>"
                    >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= $academicYearId ?>"
                        >

                        <!-- Academic Year + Status -->

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label
                                    for="name"
                                    class="form-label"
                                >
                                    Academic Year
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="name"
                                    name="name"
                                    value="<?= oldValue($name) ?>"
                                    placeholder="Example: 2022"
                                    maxlength="4"
                                    pattern="\d{4}"
                                    inputmode="numeric"
                                    required
                                >

                                <div class="form-hint">
                                    Enter one Ethiopian year, for example
                                    <strong>2022</strong>.
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label
                                    for="status"
                                    class="form-label"
                                >
                                    Academic Year Status
                                </label>

                                <select
                                    class="form-select"
                                    id="status"
                                    name="status"
                                    required
                                >

                                    <?php foreach ($statuses as $statusOption): ?>

                                        <option
                                            value="<?= oldValue($statusOption) ?>"
                                            <?= $status === $statusOption
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= oldValue($statusOption) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <div class="form-hint">
                                    Only one academic year can be Active.
                                </div>

                            </div>

                        </div>

                        <!-- Dates -->

                        <div class="row g-4 mt-1">

                            <!-- Start Date -->

                            <div class="col-lg-6">

                                <div class="date-card">

                                    <div class="date-card-title">

                                        <i class="bi bi-calendar-event me-1"></i>

                                        Start Date

                                    </div>

                                    <div class="row g-2">

                                        <div class="col-4">

                                            <label
                                                for="start_year"
                                                class="form-label"
                                            >
                                                Year
                                            </label>

                                            <input
                                                type="number"
                                                class="form-control"
                                                id="start_year"
                                                name="start_year"
                                                value="<?= oldValue($startYear) ?>"
                                                min="1"
                                                max="9999"
                                                required
                                            >

                                        </div>

                                        <div class="col-4">

                                            <label
                                                for="start_month"
                                                class="form-label"
                                            >
                                                Month
                                            </label>

                                            <select
                                                class="form-select"
                                                id="start_month"
                                                name="start_month"
                                                required
                                            >

                                                <?php foreach ($months as $monthNumber => $monthName): ?>

                                                    <option
                                                        value="<?= $monthNumber ?>"
                                                        <?= $startMonth === $monthNumber
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= oldValue($monthName) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-4">

                                            <label
                                                for="start_day"
                                                class="form-label"
                                            >
                                                Day
                                            </label>

                                            <input
                                                type="number"
                                                class="form-control"
                                                id="start_day"
                                                name="start_day"
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

                            <div class="col-lg-6">

                                <div class="date-card">

                                    <div class="date-card-title">

                                        <i class="bi bi-calendar-check me-1"></i>

                                        End Date

                                    </div>

                                    <div class="row g-2">

                                        <div class="col-4">

                                            <label
                                                for="end_year"
                                                class="form-label"
                                            >
                                                Year
                                            </label>

                                            <input
                                                type="number"
                                                class="form-control"
                                                id="end_year"
                                                name="end_year"
                                                value="<?= oldValue($endYear) ?>"
                                                min="1"
                                                max="9999"
                                                required
                                            >

                                        </div>

                                        <div class="col-4">

                                            <label
                                                for="end_month"
                                                class="form-label"
                                            >
                                                Month
                                            </label>

                                            <select
                                                class="form-select"
                                                id="end_month"
                                                name="end_month"
                                                required
                                            >

                                                <?php foreach ($months as $monthNumber => $monthName): ?>

                                                    <option
                                                        value="<?= $monthNumber ?>"
                                                        <?= $endMonth === $monthNumber
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= oldValue($monthName) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-4">

                                            <label
                                                for="end_day"
                                                class="form-label"
                                            >
                                                Day
                                            </label>

                                            <input
                                                type="number"
                                                class="form-control"
                                                id="end_day"
                                                name="end_day"
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

                        <!-- Information -->

                        <div class="status-info mt-4">

                            <i class="bi bi-info-circle me-1"></i>

                            <strong>Important:</strong>

                            Editing the academic year does not change
                            the names, order, or maximum marks of its
                            four semesters. Use
                            <strong>Manage Semesters</strong>
                            to configure their Ethiopian dates and statuses.

                        </div>

                        <!-- Actions -->

                        <div class="form-actions">

                            <a
                                href="index.php"
                                class="btn-cancel"
                            >
                                Cancel
                            </a>

                            <button
                                type="submit"
                                class="btn-save"
                            >
                                <i class="bi bi-check2-circle me-1"></i>
                                Save Changes
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </main>

</div>

<!-- Mobile Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<script>

    const sidebar =
        document.querySelector('.admin-sidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenuButton =
        document.getElementById('mobileMenuButton');

    function openSidebar() {

        sidebar?.classList.add('show');

        overlay?.classList.add('show');

        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {

        sidebar?.classList.remove('show');

        overlay?.classList.remove('show');

        document.body.classList.remove('sidebar-open');
    }

    mobileMenuButton?.addEventListener(
        'click',
        openSidebar
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

