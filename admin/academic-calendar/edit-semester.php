
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
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

$ethiopianMonths = [
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

$monthDays = [
    1  => 30,
    2  => 30,
    3  => 30,
    4  => 30,
    5  => 30,
    6  => 30,
    7  => 30,
    8  => 30,
    9  => 30,
    10 => 30,
    11 => 30,
    12 => 30,
    13 => 5
];

/*
|--------------------------------------------------------------------------
| Fixed Semester Rules
|--------------------------------------------------------------------------
*/

$semesterRules = [
    1 => [
        'name' => 'Mid Semester',
        'max_mark' => 50
    ],
    2 => [
        'name' => 'First Semester',
        'max_mark' => 100
    ],
    3 => [
        'name' => 'Quarter Semester',
        'max_mark' => 50
    ],
    4 => [
        'name' => 'Second Semester',
        'max_mark' => 100
    ]
];

$statuses = [
    'Not Completed',
    'Active',
    'Completed'
];

/*
|--------------------------------------------------------------------------
| Request Parameters
|--------------------------------------------------------------------------
*/

$academicYearId = filter_input(
    INPUT_GET,
    'academic_year_id',
    FILTER_VALIDATE_INT
);

if (!$academicYearId) {
    $academicYearId = filter_input(
        INPUT_POST,
        'academic_year_id',
        FILTER_VALIDATE_INT
    );
}

$semesterId = filter_input(
    INPUT_GET,
    'semester_id',
    FILTER_VALIDATE_INT
);

if (!$semesterId) {
    $semesterId = filter_input(
        INPUT_POST,
        'semester_id',
        FILTER_VALIDATE_INT
    );
}

$orderNumber = filter_input(
    INPUT_GET,
    'order_number',
    FILTER_VALIDATE_INT
);

if (!$academicYearId) {

    $_SESSION['error_message'] =
        'Invalid academic year.';

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
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
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $academicYearId
);

$stmt->execute();

$academicYear =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$academicYear) {

    $_SESSION['error_message'] =
        'Academic year not found.';

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Semester
|--------------------------------------------------------------------------
*/

$semester = null;

if ($semesterId) {

    $stmt = $conn->prepare("
        SELECT
            id,
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
        FROM semesters
        WHERE id = ?
          AND academic_year_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'ii',
        $semesterId,
        $academicYearId
    );

    $stmt->execute();

    $semester =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$semester) {

        $_SESSION['error_message'] =
            'Semester not found.';

        header(
            'Location: manage-semesters.php?academic_year_id='
            . $academicYearId
        );

        exit;
    }

    $orderNumber =
        (int)$semester['order_number'];

} elseif (
    $orderNumber &&
    isset($semesterRules[$orderNumber])
) {

    /*
     * Find semester using fixed order number.
     */

    $stmt = $conn->prepare("
        SELECT
            id,
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
        FROM semesters
        WHERE academic_year_id = ?
          AND order_number = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'ii',
        $academicYearId,
        $orderNumber
    );

    $stmt->execute();

    $semester =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();

} else {

    /*
     * Default to first semester.
     */

    $orderNumber = 1;

    $stmt = $conn->prepare("
        SELECT
            id,
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
        FROM semesters
        WHERE academic_year_id = ?
          AND order_number = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'ii',
        $academicYearId,
        $orderNumber
    );

    $stmt->execute();

    $semester =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate Semester Rule
|--------------------------------------------------------------------------
*/

if (
    !$orderNumber ||
    !isset($semesterRules[$orderNumber])
) {

    $_SESSION['error_message'] =
        'Invalid semester.';

    header(
        'Location: manage-semesters.php?academic_year_id='
        . $academicYearId
    );

    exit;
}

$rule =
    $semesterRules[$orderNumber];

/*
|--------------------------------------------------------------------------
| Form Defaults
|--------------------------------------------------------------------------
*/

$startYear = $semester
    ? (int)$semester['start_year']
    : (int)$academicYear['start_year'];

$startMonth = $semester
    ? (int)$semester['start_month']
    : (int)$academicYear['start_month'];

$startDay = $semester
    ? (int)$semester['start_day']
    : (int)$academicYear['start_day'];

$endYear = $semester
    ? (int)$semester['end_year']
    : (int)$academicYear['end_year'];

$endMonth = $semester
    ? (int)$semester['end_month']
    : (int)$academicYear['end_month'];

$endDay = $semester
    ? (int)$semester['end_day']
    : (int)$academicYear['end_day'];

$status = $semester
    ? $semester['status']
    : 'Not Completed';

$errors = [];

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function isValidEthiopianDate(
    int $year,
    int $month,
    int $day,
    array $monthDays
): bool {

    if ($year < 1) {
        return false;
    }

    if (!isset($monthDays[$month])) {
        return false;
    }

    return
        $day >= 1 &&
        $day <= $monthDays[$month];
}

function ethiopianDateValue(
    int $year,
    int $month,
    int $day
): int {

    return
        ($year * 13 * 30)
        + (($month - 1) * 30)
        + $day;
}

/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $startYear = filter_input(
        INPUT_POST,
        'start_year',
        FILTER_VALIDATE_INT
    );

    $startMonth = filter_input(
        INPUT_POST,
        'start_month',
        FILTER_VALIDATE_INT
    );

    $startDay = filter_input(
        INPUT_POST,
        'start_day',
        FILTER_VALIDATE_INT
    );

    $endYear = filter_input(
        INPUT_POST,
        'end_year',
        FILTER_VALIDATE_INT
    );

    $endMonth = filter_input(
        INPUT_POST,
        'end_month',
        FILTER_VALIDATE_INT
    );

    $endDay = filter_input(
        INPUT_POST,
        'end_day',
        FILTER_VALIDATE_INT
    );

    $status = trim(
        $_POST['status'] ?? 'Not Completed'
    );

    /*
     * Required values
     */

    if (
        !$startYear ||
        !$startMonth ||
        !$startDay ||
        !$endYear ||
        !$endMonth ||
        !$endDay
    ) {

        $errors[] =
            'Please select all start and end date values.';
    }

    /*
     * Academic year is fixed.
     */

    if (
        $startYear &&
        (int)$startYear !==
        (int)$academicYear['name']
    ) {

        $errors[] =
            'The semester start year must match the academic year.';
    }

    if (
        $endYear &&
        (int)$endYear !==
        (int)$academicYear['name']
    ) {

        $errors[] =
            'The semester end year must match the academic year.';
    }

    /*
     * Validate Ethiopian dates.
     */

    if (
        $startYear &&
        $startMonth &&
        $startDay &&
        !isValidEthiopianDate(
            (int)$startYear,
            (int)$startMonth,
            (int)$startDay,
            $monthDays
        )
    ) {

        $errors[] =
            'The semester start date is invalid.';
    }

    if (
        $endYear &&
        $endMonth &&
        $endDay &&
        !isValidEthiopianDate(
            (int)$endYear,
            (int)$endMonth,
            (int)$endDay,
            $monthDays
        )
    ) {

        $errors[] =
            'The semester end date is invalid.';
    }

    /*
     * Start must be before end.
     */

    if (!$errors) {

        $startValue =
            ethiopianDateValue(
                (int)$startYear,
                (int)$startMonth,
                (int)$startDay
            );

        $endValue =
            ethiopianDateValue(
                (int)$endYear,
                (int)$endMonth,
                (int)$endDay
            );

        if ($startValue >= $endValue) {

            $errors[] =
                'The semester start date must be before the end date.';
        }
    }

    /*
     * Validate status.
     */

    if (!in_array(
        $status,
        $statuses,
        true
    )) {

        $errors[] =
            'Invalid semester status.';
    }

    /*
     * Active semester requires active academic year.
     */

    if (
        !$errors &&
        $status === 'Active' &&
        $academicYear['status'] !== 'Active'
    ) {

        $errors[] =
            'A semester can only be Active when the academic year is Active.';
    }

    /*
     * Completed academic year cannot have active semester.
     */

    if (
        !$errors &&
        $academicYear['status'] === 'Completed' &&
        $status === 'Active'
    ) {

        $errors[] =
            'A completed academic year cannot have an Active semester.';
    }

    /*
     * Completed semester cannot move backwards.
     */

    if (
        !$errors &&
        $semester &&
        $semester['status'] === 'Completed' &&
        $status !== 'Completed'
    ) {

        $errors[] =
            'A Completed semester cannot be changed back to another status.';
    }

    /*
     * Load previous semester.
     */

    if (
        !$errors &&
        $orderNumber > 1
    ) {

        $previousOrder =
            $orderNumber - 1;

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                status
            FROM semesters
            WHERE academic_year_id = ?
              AND order_number = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'ii',
            $academicYearId,
            $previousOrder
        );

        $stmt->execute();

        $previousSemester =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$previousSemester) {

            $errors[] =
                'The previous semester must be configured first.';

        } elseif (
            $status === 'Active' &&
            $previousSemester['status'] !== 'Completed'
        ) {

            $errors[] =
                'You must complete the previous semester before activating this semester.';

        } elseif (
            $status === 'Completed' &&
            $previousSemester['status'] !== 'Completed'
        ) {

            $errors[] =
                'You must complete the previous semester before completing this semester.';
        }
    }

    /*
     * First semester activation.
     */

    if (
        !$errors &&
        $orderNumber === 1 &&
        $status === 'Active' &&
        $academicYear['status'] !== 'Active'
    ) {

        $errors[] =
            'Activate the academic year before activating the first semester.';
    }

    /*
     * Only one active semester.
     */

    if (
        !$errors &&
        $status === 'Active'
    ) {

        if ($semester) {

            $stmt = $conn->prepare("
                SELECT
                    id
                FROM semesters
                WHERE academic_year_id = ?
                  AND status = 'Active'
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ii',
                $academicYearId,
                $semester['id']
            );

        } else {

            $stmt = $conn->prepare("
                SELECT
                    id
                FROM semesters
                WHERE academic_year_id = ?
                  AND status = 'Active'
                LIMIT 1
            ");

            $stmt->bind_param(
                'i',
                $academicYearId
            );
        }

        $stmt->execute();

        $activeSemester =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($activeSemester) {

            $errors[] =
                'Another semester is already Active for this academic year.';
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Save
     |--------------------------------------------------------------------------
     */

    if (!$errors) {

        try {

            $conn->begin_transaction();

            /*
             * UPDATE existing semester.
             */

            if ($semester) {

                $stmt = $conn->prepare("
                    UPDATE semesters
                    SET
                        start_year = ?,
                        start_month = ?,
                        start_day = ?,
                        end_year = ?,
                        end_month = ?,
                        end_day = ?,
                        status = ?
                    WHERE id = ?
                      AND academic_year_id = ?
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Failed to prepare semester update.'
                    );
                }

                /*
                 * 9 variables:
                 *
                 * startYear      = i
                 * startMonth     = i
                 * startDay       = i
                 * endYear        = i
                 * endMonth       = i
                 * endDay         = i
                 * status         = s
                 * semester id    = i
                 * academic year  = i
                 */

                $stmt->bind_param(
                    'iiiiiisii',
                    $startYear,
                    $startMonth,
                    $startDay,
                    $endYear,
                    $endMonth,
                    $endDay,
                    $status,
                    $semester['id'],
                    $academicYearId
                );

                if (!$stmt->execute()) {

                    throw new Exception(
                        'Failed to update semester.'
                    );
                }

                $stmt->close();

            } else {

                /*
                 * INSERT new semester.
                 */

                $semesterName =
                    $rule['name'];

                $maxMark =
                    (int)$rule['max_mark'];

                $stmt = $conn->prepare("
                    INSERT INTO semesters (
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
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {

                    throw new Exception(
                        'Failed to prepare semester creation.'
                    );
                }

                /*
                 * 11 variables:
                 *
                 * academicYearId = i
                 * semesterName   = s
                 * orderNumber    = i
                 * maxMark        = i
                 * startYear      = i
                 * startMonth     = i
                 * startDay       = i
                 * endYear        = i
                 * endMonth       = i
                 * endDay         = i
                 * status         = s
                 */

                $stmt->bind_param(
                    'isiiiiiiiis',
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
                    $status
                );

                if (!$stmt->execute()) {

                    throw new Exception(
                        'Failed to create semester.'
                    );
                }

                $semesterId =
                    $stmt->insert_id;

                $stmt->close();
            }

            $conn->commit();

            $_SESSION['success_message'] =
                $rule['name']
                . ' for academic year '
                . $academicYear['name']
                . ' was saved successfully.';

            header(
                'Location: manage-semesters.php?academic_year_id='
                . $academicYearId
            );

            exit;

        } catch (Throwable $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            $errors[] =
                'Unable to save the semester: '
                . $e->getMessage();
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
        <?= htmlspecialchars($rule['name']) ?> | BKHS
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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../public/css/admin-users.css"
    >

    <style>

        :root {
            --calendar-primary: #4f46e5;
            --calendar-primary-dark: #4338ca;
            --calendar-success: #16a34a;
            --calendar-warning: #d97706;
            --calendar-danger: #dc2626;
            --calendar-text: #172033;
            --calendar-muted: #667085;
            --calendar-border: #e7eaf0;
            --calendar-bg: #f6f8fc;
        }

        body {
            background: var(--calendar-bg);
        }

        .calendar-main {
            padding-bottom: 45px;
        }

        .page-heading {
            margin-bottom: 24px;
        }

        .page-heading h1 {
            font-size: clamp(1.45rem, 3vw, 2rem);
            font-weight: 800;
            color: var(--calendar-text);
            margin-bottom: 7px;
        }

        .page-heading p {
            color: var(--calendar-muted);
            margin: 0;
            font-size: 0.92rem;
        }

        .semester-editor {
            max-width: 900px;
        }

        .semester-header-card {
            background: #fff;
            border: 1px solid var(--calendar-border);
            border-radius: 20px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.05);
        }

        .semester-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .semester-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .semester-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(79, 70, 229, 0.10);
            color: var(--calendar-primary);
            font-size: 1.35rem;
        }

        .semester-heading h2 {
            margin: 0 0 4px;
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--calendar-text);
        }

        .semester-heading p {
            margin: 0;
            color: var(--calendar-muted);
            font-size: 0.82rem;
        }

        .max-mark {
            padding: 9px 13px;
            border-radius: 11px;
            background: #f6f7fb;
            color: var(--calendar-text);
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .form-card {
            background: #fff;
            border: 1px solid var(--calendar-border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.05);
        }

        .form-section {
            margin-bottom: 27px;
        }

        .form-section:last-of-type {
            margin-bottom: 0;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 15px;
            color: var(--calendar-text);
            font-size: 0.94rem;
            font-weight: 800;
        }

        .form-section-title i {
            color: var(--calendar-primary);
        }

        .date-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 13px;
        }

        .form-label {
            color: #344054;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-select {
            min-height: 46px;
            border-color: #dfe3eb;
            border-radius: 11px;
            font-size: 0.84rem;
            color: var(--calendar-text);
        }

        .form-select:focus {
            border-color: rgba(79, 70, 229, 0.55);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.10);
        }

        .date-group {
            padding: 17px;
            border: 1px solid #eef0f4;
            border-radius: 15px;
            background: #fbfcfe;
        }

        .date-group-title {
            margin-bottom: 13px;
            color: var(--calendar-text);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .status-help {
            margin-top: 8px;
            color: var(--calendar-muted);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        .rules-box {
            display: flex;
            gap: 11px;
            padding: 14px;
            border-radius: 13px;
            background: #f7f8ff;
            color: #475467;
            font-size: 0.78rem;
            line-height: 1.55;
        }

        .rules-box i {
            color: var(--calendar-primary);
            font-size: 1rem;
            margin-top: 2px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 27px;
            padding-top: 20px;
            border-top: 1px solid #eef0f4;
        }

        .btn-cancel,
        .btn-save {
            min-height: 44px;
            padding: 0 17px;
            border-radius: 11px;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .btn-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 1px solid #dfe3eb;
            color: var(--calendar-text);
            background: #fff;
            text-decoration: none;
        }

        .btn-cancel:hover {
            background: #f7f7f8;
            color: var(--calendar-text);
        }

        .btn-save {
            border: 0;
            background: var(--calendar-primary);
            color: #fff;
        }

        .btn-save:hover {
            background: var(--calendar-primary-dark);
            color: #fff;
        }

        .alert {
            border: 0;
            border-radius: 13px;
        }

        .error-list {
            margin: 0;
            padding-left: 20px;
        }

        .error-list li + li {
            margin-top: 4px;
        }

        @media (max-width: 767.98px) {

            .calendar-main {
                padding-left: 14px;
                padding-right: 14px;
            }

            .semester-header-card,
            .form-card {
                padding: 18px;
                border-radius: 17px;
            }

            .semester-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .date-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .btn-cancel,
            .btn-save {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->

    <aside
        class="admin-sidebar"
        id="adminSidebar"
    >

        <div class="sidebar-brand">

            <div class="brand-mark">
                <i class="bi bi-mortarboard-fill"></i>
            </div>

            <div class="brand-text">

                <strong>
                    Bole Kale Hiwot
                </strong>

                <span>
                    School Management
                </span>

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

                <span>
                    Dashboard
                </span>

            </a>

            <div class="nav-section-title">
                MANAGEMENT
            </div>

            <a
                href="../users/index.php"
                class="sidebar-link"
            >

                <i class="bi bi-people-fill"></i>

                <span>
                    Users
                </span>

            </a>

            <a
                href="../students/index.php"
                class="sidebar-link"
            >

                <i class="bi bi-person-vcard-fill"></i>

                <span>
                    Students
                </span>

            </a>

            <a
                href="../teachers/index.php"
                class="sidebar-link"
            >

                <i class="bi bi-person-workspace"></i>

                <span>
                    Teachers
                </span>

            </a>

            <a
                href="index.php"
                class="sidebar-link active"
            >

                <i class="bi bi-calendar3-fill"></i>

                <span>
                    Academic Calendar
                </span>

            </a>

            <a
                href="../settings.php"
                class="sidebar-link"
            >

                <i class="bi bi-gear-fill"></i>

                <span>
                    Settings
                </span>

            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="../../auth/logout.php"
                class="logout-link"
            >

                <i class="bi bi-box-arrow-right"></i>

                <span>
                    Logout
                </span>

            </a>

        </div>

    </aside>

    <!-- Mobile overlay -->

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

                <span>
                    Academic Calendar
                </span>

            </div>

            <div class="topbar-profile">

                <div class="profile-avatar">

                    <i class="bi bi-person-fill"></i>

                </div>

                <div class="profile-info">

                    <strong>
                        <?= htmlspecialchars(
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

        <div class="calendar-main container-fluid">

            <div class="page-heading">

                <h1>
                    <?= htmlspecialchars($rule['name']) ?>
                </h1>

                <p>

                    Configure the dates and status for academic year

                    <strong>
                        <?= htmlspecialchars(
                            $academicYear['name']
                        ) ?>
                    </strong>.

                </p>

            </div>

            <?php if ($errors): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >

                    <div class="d-flex gap-2">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        <div>

                            <strong>
                                Please correct the following:
                            </strong>

                            <ul class="error-list mt-2">

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

            <div class="semester-editor">

                <!-- Semester header -->

                <section class="semester-header-card">

                    <div class="semester-header">

                        <div class="semester-heading">

                            <div class="semester-icon">

                                <i class="bi bi-calendar-event-fill"></i>

                            </div>

                            <div>

                                <h2>
                                    <?= htmlspecialchars(
                                        $rule['name']
                                    ) ?>
                                </h2>

                                <p>

                                    Semester <?= $orderNumber ?>

                                    · Academic Year

                                    <?= htmlspecialchars(
                                        $academicYear['name']
                                    ) ?>

                                </p>

                            </div>

                        </div>

                        <div class="max-mark">

                            Maximum Mark:
                            <?= (int)$rule['max_mark'] ?>

                        </div>

                    </div>

                </section>

                <!-- Form -->

                <form
                    method="POST"
                    class="form-card"
                    novalidate
                >

                    <input
                        type="hidden"
                        name="academic_year_id"
                        value="<?= (int)$academicYearId ?>"
                    >

                    <?php if ($semesterId): ?>

                        <input
                            type="hidden"
                            name="semester_id"
                            value="<?= (int)$semesterId ?>"
                        >

                    <?php endif; ?>

                    <!-- Start date -->

                    <section class="form-section">

                        <div class="form-section-title">

                            <i class="bi bi-calendar-plus"></i>

                            <span>
                                Semester Start Date
                            </span>

                        </div>

                        <div class="date-group">

                            <div class="date-group-title">
                                Select Ethiopian start date
                            </div>

                            <div class="date-grid">

                                <div>

                                    <label
                                        for="start_year"
                                        class="form-label"
                                    >
                                        Year
                                    </label>

                                    <select
                                        id="start_year"
                                        name="start_year"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select year
                                        </option>

                                        <option
                                            value="<?= (int)$academicYear['name'] ?>"
                                            <?= (int)$startYear ===
                                                (int)$academicYear['name']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= htmlspecialchars(
                                                $academicYear['name']
                                            ) ?>

                                        </option>

                                    </select>

                                </div>

                                <div>

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
                                            Select month
                                        </option>

                                        <?php foreach (
                                            $ethiopianMonths
                                            as $monthNumber => $monthName
                                        ): ?>

                                            <option
                                                value="<?= $monthNumber ?>"
                                                <?= (int)$startMonth ===
                                                    $monthNumber
                                                    ? 'selected'
                                                    : '' ?>
                                            >

                                                <?= htmlspecialchars(
                                                    $monthName
                                                ) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div>

                                    <label
                                        for="start_day"
                                        class="form-label"
                                    >
                                        Day
                                    </label>

                                    <select
                                        id="start_day"
                                        name="start_day"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select day
                                        </option>

                                        <?php
                                        $startMaxDays =
                                            $monthDays[$startMonth] ?? 30;

                                        for (
                                            $day = 1;
                                            $day <= $startMaxDays;
                                            $day++
                                        ):
                                        ?>

                                            <option
                                                value="<?= $day ?>"
                                                <?= (int)$startDay === $day
                                                    ? 'selected'
                                                    : '' ?>
                                            >

                                                <?= $day ?>

                                            </option>

                                        <?php endfor; ?>

                                    </select>

                                </div>

                            </div>

                        </div>

                    </section>

                    <!-- End date -->

                    <section class="form-section">

                        <div class="form-section-title">

                            <i class="bi bi-calendar-minus"></i>

                            <span>
                                Semester End Date
                            </span>

                        </div>

                        <div class="date-group">

                            <div class="date-group-title">
                                Select Ethiopian end date
                            </div>

                            <div class="date-grid">

                                <div>

                                    <label
                                        for="end_year"
                                        class="form-label"
                                    >
                                        Year
                                    </label>

                                    <select
                                        id="end_year"
                                        name="end_year"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select year
                                        </option>

                                        <option
                                            value="<?= (int)$academicYear['name'] ?>"
                                            <?= (int)$endYear ===
                                                (int)$academicYear['name']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= htmlspecialchars(
                                                $academicYear['name']
                                            ) ?>

                                        </option>

                                    </select>

                                </div>

                                <div>

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
                                            Select month
                                        </option>

                                        <?php foreach (
                                            $ethiopianMonths
                                            as $monthNumber => $monthName
                                        ): ?>

                                            <option
                                                value="<?= $monthNumber ?>"
                                                <?= (int)$endMonth ===
                                                    $monthNumber
                                                    ? 'selected'
                                                    : '' ?>
                                            >

                                                <?= htmlspecialchars(
                                                    $monthName
                                                ) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div>

                                    <label
                                        for="end_day"
                                        class="form-label"
                                    >
                                        Day
                                    </label>

                                    <select
                                        id="end_day"
                                        name="end_day"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select day
                                        </option>

                                        <?php
                                        $endMaxDays =
                                            $monthDays[$endMonth] ?? 30;

                                        for (
                                            $day = 1;
                                            $day <= $endMaxDays;
                                            $day++
                                        ):
                                        ?>

                                            <option
                                                value="<?= $day ?>"
                                                <?= (int)$endDay === $day
                                                    ? 'selected'
                                                    : '' ?>
                                            >

                                                <?= $day ?>

                                            </option>

                                        <?php endfor; ?>

                                    </select>

                                </div>

                            </div>

                        </div>

                    </section>

                    <!-- Status -->

                    <section class="form-section">

                        <div class="form-section-title">

                            <i class="bi bi-toggle-on"></i>

                            <span>
                                Semester Status
                            </span>

                        </div>

                        <label
                            for="status"
                            class="form-label"
                        >
                            Status
                        </label>

                        <select
                            id="status"
                            name="status"
                            class="form-select"
                            required
                        >

                            <?php foreach (
                                $statuses
                                as $statusOption
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        $statusOption
                                    ) ?>"
                                    <?= $status === $statusOption
                                        ? 'selected'
                                        : '' ?>
                                >

                                    <?= htmlspecialchars(
                                        $statusOption
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="status-help">

                            Only one semester can be Active at a time.
                            A later semester cannot be activated or
                            completed before the previous semester is
                            completed.

                        </div>

                    </section>

                    <!-- Rules -->

                    <div class="rules-box">

                        <i class="bi bi-info-circle-fill"></i>

                        <div>

                            <strong>
                                Academic calendar rule:
                            </strong>

                            <?= htmlspecialchars(
                                $rule['name']
                            ) ?>

                            has a fixed maximum mark of

                            <strong>
                                <?= (int)$rule['max_mark'] ?>
                            </strong>.

                            Ethiopian dates are used throughout the
                            school system.

                        </div>

                    </div>

                    <!-- Actions -->

                    <div class="form-actions">

                        <a
                            href="manage-semesters.php?academic_year_id=<?= (int)$academicYearId ?>"
                            class="btn-cancel"
                        >

                            <i class="bi bi-arrow-left"></i>

                            Cancel

                        </a>

                        <button
                            type="submit"
                            class="btn-save"
                        >

                            <i class="bi bi-check2-circle me-1"></i>

                            Save Semester

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </main>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    /*
     |--------------------------------------------------------------------------
     | Sidebar
     |--------------------------------------------------------------------------
     */

    const sidebar =
        document.getElementById('adminSidebar');

    const overlay =
        document.getElementById('sidebarOverlay');

    const mobileMenuButton =
        document.getElementById('mobileMenuButton');

    const sidebarClose =
        document.getElementById('sidebarClose');

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

    /*
     |--------------------------------------------------------------------------
     | Ethiopian Month Day Limits
     |--------------------------------------------------------------------------
     */

    const monthDays = {
        1: 30,
        2: 30,
        3: 30,
        4: 30,
        5: 30,
        6: 30,
        7: 30,
        8: 30,
        9: 30,
        10: 30,
        11: 30,
        12: 30,
        13: 5
    };

    function updateDays(
        monthSelectId,
        daySelectId
    ) {

        const monthSelect =
            document.getElementById(
                monthSelectId
            );

        const daySelect =
            document.getElementById(
                daySelectId
            );

        if (
            !monthSelect ||
            !daySelect
        ) {
            return;
        }

        const selectedDay =
            parseInt(
                daySelect.value || '0',
                10
            );

        const month =
            parseInt(
                monthSelect.value || '0',
                10
            );

        const maxDays =
            monthDays[month] || 30;

        daySelect.innerHTML =
            '<option value="">Select day</option>';

        for (
            let day = 1;
            day <= maxDays;
            day++
        ) {

            const option =
                document.createElement(
                    'option'
                );

            option.value = day;

            option.textContent = day;

            if (day === selectedDay) {
                option.selected = true;
            }

            daySelect.appendChild(
                option
            );
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Start Month
     |--------------------------------------------------------------------------
     */

    document
        .getElementById('start_month')
        ?.addEventListener(
            'change',
            function () {

                updateDays(
                    'start_month',
                    'start_day'
                );

            }
        );

    /*
     |--------------------------------------------------------------------------
     | End Month
     |--------------------------------------------------------------------------
     */

    document
        .getElementById('end_month')
        ?.addEventListener(
            'change',
            function () {

                updateDays(
                    'end_month',
                    'end_day'
                );

            }
        );

</script>

</body>
</html>

