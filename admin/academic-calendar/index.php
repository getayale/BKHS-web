
<?php

session_start();


// =====================================================
// AUTHENTICATION CHECK
// =====================================================

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    strtolower($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}


// =====================================================
// DATABASE
// =====================================================

require_once '../../config/database.php';


// =====================================================
// FLASH MESSAGES
// =====================================================

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);


// =====================================================
// ETHIOPIAN MONTHS
// =====================================================

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
    13 => 'Pagume',
];


// =====================================================
// FORMAT ETHIOPIAN DATE
// =====================================================

function formatEthiopianDate(
    int $year,
    int $month,
    int $day
): string {

    global $ethiopianMonths;

    $monthName = $ethiopianMonths[$month] ?? 'Unknown';

    return $day . ' ' . $monthName . ' ' . $year . ' ዓ.ም.';
}


// =====================================================
// LOAD ACADEMIC YEARS
// =====================================================

$sql = "
    SELECT
        ay.id,
        ay.name,
        ay.start_year,
        ay.start_month,
        ay.start_day,
        ay.end_year,
        ay.end_month,
        ay.end_day,
        ay.status,

        COUNT(s.id) AS semester_count

    FROM academic_years ay

    LEFT JOIN semesters s
        ON s.academic_year_id = ay.id

    GROUP BY
        ay.id,
        ay.name,
        ay.start_year,
        ay.start_month,
        ay.start_day,
        ay.end_year,
        ay.end_month,
        ay.end_day,
        ay.status

    ORDER BY
        ay.start_year DESC,
        ay.start_month DESC,
        ay.start_day DESC
";

$result = $conn->query($sql);

if (!$result) {
    $error = 'Unable to load academic years.';
}


// =====================================================
// STATISTICS
// =====================================================

$totalYears = 0;
$activeYears = 0;
$completedYears = 0;
$activeSemester = null;

$academicYears = [];

if ($result) {

    while ($year = $result->fetch_assoc()) {

        $academicYears[] = $year;

        $totalYears++;

        if ($year['status'] === 'Active') {
            $activeYears++;
        }

        if ($year['status'] === 'Completed') {
            $completedYears++;
        }
    }
}


// =====================================================
// LOAD ACTIVE SEMESTER
// =====================================================

$activeSemesterSql = "
    SELECT
        s.id,
        s.name,
        s.order_number,
        s.max_mark,
        s.status,
        ay.name AS academic_year_name
    FROM semesters s
    INNER JOIN academic_years ay
        ON ay.id = s.academic_year_id
    WHERE s.status = 'Active'
    LIMIT 1
";

$activeSemesterResult = $conn->query($activeSemesterSql);

if ($activeSemesterResult && $activeSemesterResult->num_rows > 0) {
    $activeSemester = $activeSemesterResult->fetch_assoc();
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

    <title>Academic Calendar | Admin</title>
      <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >
    <link rel="shortcut icon" type="image/webp" href="public/image/logo.webp">


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Admin Users CSS -->

    <link
        rel="stylesheet"
        href="../../public/css/admin-users.css"
    >


    <style>

        /* =====================================================
           ACADEMIC CALENDAR
        ===================================================== */

        .calendar-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }


        .calendar-stat-card {
            background: #ffffff;
            border: 1px solid #e8edf3;
            border-radius: 18px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.04);
        }


        .calendar-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef4ff;
            color: #2563eb;
            font-size: 21px;
            flex-shrink: 0;
        }


        .calendar-stat-icon.active {
            background: #ecfdf3;
            color: #16a34a;
        }


        .calendar-stat-icon.completed {
            background: #f3f4f6;
            color: #64748b;
        }


        .calendar-stat-icon.semester {
            background: #fff7ed;
            color: #ea580c;
        }


        .calendar-stat-content span {
            display: block;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 4px;
        }


        .calendar-stat-content strong {
            display: block;
            font-size: 24px;
            line-height: 1;
            color: #0f172a;
            font-weight: 800;
        }


        /* =====================================================
           ACTIVE ACADEMIC YEAR
        ===================================================== */

        .active-year-card {
            background: linear-gradient(
                135deg,
                #0f172a 0%,
                #1e3a8a 100%
            );
            color: #ffffff;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
        }


        .active-year-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }


        .active-year-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }


        .active-year-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            opacity: .7;
            margin-bottom: 4px;
        }


        .active-year-content h3 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
        }


        .active-semester {
            text-align: right;
        }


        .active-semester-label {
            display: block;
            font-size: 12px;
            opacity: .7;
            margin-bottom: 3px;
        }


        .active-semester strong {
            font-size: 15px;
        }


        /* =====================================================
           CALENDAR CARD
        ===================================================== */

        .calendar-card {
            background: #ffffff;
            border: 1px solid #e8edf3;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.04);
        }


        .calendar-card-header {
            padding: 22px 24px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }


        .calendar-card-header h3 {
            margin: 0 0 5px;
            font-size: 18px;
            font-weight: 800;
            color: #111827;
        }


        .calendar-card-header p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }


        .calendar-search {
            position: relative;
            width: 260px;
        }


        .calendar-search i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }


        .calendar-search input {
            width: 100%;
            height: 42px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 14px 0 38px;
            outline: none;
            font-size: 13px;
            transition: .2s ease;
        }


        .calendar-search input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .calendar-table {
            width: 100%;
            border-collapse: collapse;
        }


        .calendar-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 700;
            padding: 14px 18px;
            border-bottom: 1px solid #edf0f4;
            white-space: nowrap;
        }


        .calendar-table td {
            padding: 18px;
            border-bottom: 1px solid #edf0f4;
            vertical-align: middle;
            color: #334155;
            font-size: 13px;
        }


        .calendar-table tbody tr {
            transition: background .2s ease;
        }


        .calendar-table tbody tr:hover {
            background: #f8fafc;
        }


        .year-cell strong {
            display: block;
            color: #0f172a;
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 4px;
        }


        .year-cell span {
            color: #64748b;
            font-size: 12px;
        }


        .date-range {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }


        .date-range span {
            color: #475569;
        }


        .date-range i {
            color: #94a3b8;
            margin-right: 5px;
        }


        .semester-count {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 10px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }


        /* =====================================================
           STATUS
        ===================================================== */

        .calendar-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }


        .calendar-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }


        .status-not-completed {
            background: #fff7ed;
            color: #c2410c;
        }


        .status-not-completed .calendar-status-dot {
            background: #f97316;
        }


        .status-active {
            background: #ecfdf3;
            color: #15803d;
        }


        .status-active .calendar-status-dot {
            background: #22c55e;
        }


        .status-completed {
            background: #f1f5f9;
            color: #64748b;
        }


        .status-completed .calendar-status-dot {
            background: #94a3b8;
        }


        /* =====================================================
           ACTIONS
        ===================================================== */

        .calendar-actions {
            display: flex;
            justify-content: flex-end;
            gap: 7px;
        }


        .calendar-action {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            text-decoration: none;
            transition: .2s ease;
        }


        .calendar-action:hover {
            background: #f8fafc;
            color: #2563eb;
            border-color: #bfdbfe;
        }


        .calendar-action.manage:hover {
            color: #7c3aed;
            border-color: #ddd6fe;
            background: #faf5ff;
        }


        .calendar-action.activate:hover {
            color: #16a34a;
            border-color: #bbf7d0;
            background: #f0fdf4;
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .calendar-empty {
            padding: 70px 20px;
            text-align: center;
        }


        .calendar-empty-icon {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 28px;
        }


        .calendar-empty h4 {
            margin: 0 0 7px;
            color: #0f172a;
            font-weight: 800;
        }


        .calendar-empty p {
            margin: 0 0 20px;
            color: #64748b;
            font-size: 14px;
        }


        /* =====================================================
           ADD BUTTON
        ===================================================== */

        .btn-add-calendar {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            border: none;
            transition: .2s ease;
        }


        .btn-add-calendar:hover {
            background: #1d4ed8;
            color: #ffffff;
            transform: translateY(-1px);
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .calendar-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .calendar-table {
                min-width: 950px;
            }

        }


        @media (max-width: 768px) {

            .calendar-stats {
                grid-template-columns: 1fr;
            }


            .active-year-card {
                align-items: flex-start;
                flex-direction: column;
            }


            .active-semester {
                text-align: left;
                padding-left: 70px;
            }


            .calendar-card-header {
                align-items: flex-start;
                flex-direction: column;
            }


            .calendar-search {
                width: 100%;
            }


            .page-header {
                gap: 15px;
                flex-direction: column;
                align-items: flex-start !important;
            }


            .btn-add-calendar {
                width: 100%;
                justify-content: center;
            }

        }


        @media (max-width: 576px) {

            .active-year-content {
                align-items: flex-start;
            }


            .active-year-content h3 {
                font-size: 18px;
            }


            .active-semester {
                padding-left: 0;
            }

        }

    </style>

</head>


<body>


<div class="admin-layout">


    <!-- =====================================================
         SIDEBAR
    ===================================================== -->

    <aside class="admin-sidebar">

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

        </div>


        <nav class="sidebar-nav">


            <!-- MAIN -->

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


            <!-- MANAGEMENT -->

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


            <!-- ACADEMIC -->

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
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-book-fill"></i>
                <span>Subjects</span>
            </a>


            <a
                href="#"
                class="sidebar-link"
            >
                <i class="bi bi-building-fill"></i>
                <span>Classes</span>
            </a>


            <!-- SYSTEM -->

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


        <!-- Sidebar Footer -->

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


    <!-- =====================================================
         MAIN
    ===================================================== -->

    <main class="admin-main">


        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="admin-topbar">


            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
            >
                <i class="bi bi-list"></i>
            </button>


            <div class="topbar-title">

                <h1>
                    Academic Calendar
                </h1>

                <p>
                    Manage Ethiopian academic years and semesters
                </p>

            </div>


            <div class="topbar-actions">


                <button
                    type="button"
                    class="topbar-icon-button"
                    title="Notifications"
                >
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot"></span>
                </button>


                <div class="admin-profile">

                    <div class="profile-avatar">

                        <?= strtoupper(
                            substr(
                                $_SESSION['full_name'] ?? 'A',
                                0,
                                1
                            )
                        ) ?>

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

            </div>

        </header>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <section class="admin-content">


            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div class="page-header">

                <div>

                    <div class="breadcrumb-area">

                        <a href="../dashboard.php">
                            Dashboard
                        </a>

                        <i class="bi bi-chevron-right"></i>

                        <span>
                            Academic Calendar
                        </span>

                    </div>


                    <h2>
                        Academic Calendar
                    </h2>


                    <p>
                        Manage academic years, semesters, dates, and their status.
                    </p>

                </div>


                <a
                    href="create-year.php"
                    class="btn-add-calendar"
                >
                    <i class="bi bi-calendar-plus-fill"></i>
                    <span>Add Academic Year</span>
                </a>

            </div>


            <!-- =================================================
                 ALERTS
            ================================================== -->

            <?php if ($success): ?>

                <div class="alert-message alert-success-message">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        <?= htmlspecialchars($success) ?>
                    </span>

                    <button
                        type="button"
                        class="alert-close"
                        onclick="this.parentElement.remove()"
                    >
                        <i class="bi bi-x"></i>
                    </button>

                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="alert-message alert-error-message">

                    <i class="bi bi-exclamation-circle-fill"></i>

                    <span>
                        <?= htmlspecialchars($error) ?>
                    </span>

                    <button
                        type="button"
                        class="alert-close"
                        onclick="this.parentElement.remove()"
                    >
                        <i class="bi bi-x"></i>
                    </button>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 STATISTICS
            ================================================== -->

            <div class="calendar-stats">


                <!-- Total Years -->

                <div class="calendar-stat-card">

                    <div class="calendar-stat-icon">

                        <i class="bi bi-calendar3"></i>

                    </div>

                    <div class="calendar-stat-content">

                        <span>
                            Academic Years
                        </span>

                        <strong>
                            <?= $totalYears ?>
                        </strong>

                    </div>

                </div>


                <!-- Active -->

                <div class="calendar-stat-card">

                    <div class="calendar-stat-icon active">

                        <i class="bi bi-play-circle-fill"></i>

                    </div>

                    <div class="calendar-stat-content">

                        <span>
                            Active Years
                        </span>

                        <strong>
                            <?= $activeYears ?>
                        </strong>

                    </div>

                </div>


                <!-- Completed -->

                <div class="calendar-stat-card">

                    <div class="calendar-stat-icon completed">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div class="calendar-stat-content">

                        <span>
                            Completed Years
                        </span>

                        <strong>
                            <?= $completedYears ?>
                        </strong>

                    </div>

                </div>


                <!-- Active Semester -->

                <div class="calendar-stat-card">

                    <div class="calendar-stat-icon semester">

                        <i class="bi bi-bookmark-check-fill"></i>

                    </div>

                    <div class="calendar-stat-content">

                        <span>
                            Active Semester
                        </span>

                        <strong>
                            <?= $activeSemester ? '1' : '0' ?>
                        </strong>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 ACTIVE YEAR
            ================================================== -->

            <?php

            $activeYear = null;

            foreach ($academicYears as $year) {

                if ($year['status'] === 'Active') {
                    $activeYear = $year;
                    break;
                }

            }

            ?>


            <?php if ($activeYear): ?>

                <div class="active-year-card">

                    <div class="active-year-content">

                        <div class="active-year-icon">

                            <i class="bi bi-calendar-check-fill"></i>

                        </div>


                        <div>

                            <span class="active-year-label">
                                ACTIVE ACADEMIC YEAR
                            </span>

                            <h3>
                                <?= htmlspecialchars($activeYear['name']) ?>
                            </h3>

                        </div>

                    </div>


                    <?php if ($activeSemester): ?>

                        <div class="active-semester">

                            <span class="active-semester-label">
                                Current Semester
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $activeSemester['name']
                                ) ?>
                            </strong>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 ACADEMIC YEARS CARD
            ================================================== -->

            <div class="calendar-card">


                <div class="calendar-card-header">

                    <div>

                        <h3>
                            Academic Years
                        </h3>

                        <p>
                            Manage academic year periods and their semesters.
                        </p>

                    </div>


                    <div class="calendar-search">

                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="calendarSearch"
                            placeholder="Search academic years..."
                        >

                    </div>

                </div>


                <!-- Table -->

                <div class="table-responsive">

                    <table
                        class="calendar-table"
                        id="calendarTable"
                    >

                        <thead>

                            <tr>

                                <th>
                                    Academic Year
                                </th>

                                <th>
                                    Start Date
                                </th>

                                <th>
                                    End Date
                                </th>

                                <th>
                                    Semesters
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


                        <?php if (!empty($academicYears)): ?>


                            <?php foreach ($academicYears as $year): ?>


                                <?php

                                $statusClass = match ($year['status']) {

                                    'Active' =>
                                        'status-active',

                                    'Completed' =>
                                        'status-completed',

                                    default =>
                                        'status-not-completed',

                                };

                                ?>


                                <tr>


                                    <!-- Academic Year -->

                                    <td>

                                        <div class="year-cell">

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $year['name']
                                                ) ?>
                                            </strong>

                                            <span>
                                                Ethiopian Academic Year
                                            </span>

                                        </div>

                                    </td>


                                    <!-- Start -->

                                    <td>

                                        <div class="date-range">

                                            <span>

                                                <i class="bi bi-calendar-event"></i>

                                                <?= formatEthiopianDate(
                                                    (int)$year['start_year'],
                                                    (int)$year['start_month'],
                                                    (int)$year['start_day']
                                                ) ?>

                                            </span>

                                        </div>

                                    </td>


                                    <!-- End -->

                                    <td>

                                        <div class="date-range">

                                            <span>

                                                <i class="bi bi-calendar-event"></i>

                                                <?= formatEthiopianDate(
                                                    (int)$year['end_year'],
                                                    (int)$year['end_month'],
                                                    (int)$year['end_day']
                                                ) ?>

                                            </span>

                                        </div>

                                    </td>


                                    <!-- Semesters -->

                                    <td>

                                        <span class="semester-count">

                                            <i class="bi bi-bookmark-fill"></i>

                                            <?= (int)$year['semester_count'] ?>

                                            Semester<?= (int)$year['semester_count'] === 1 ? '' : 's' ?>

                                        </span>

                                    </td>


                                    <!-- Status -->

                                    <td>

                                        <span
                                            class="calendar-status <?= $statusClass ?>"
                                        >

                                            <span class="calendar-status-dot"></span>

                                            <?= htmlspecialchars(
                                                $year['status']
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- Actions -->

                                    <td>

                                        <div class="calendar-actions">


                                            <!-- Manage Semesters -->

                                            <a
                                                href="manage-semesters.php?academic_year_id=<?= (int)$year['id'] ?>"
                                                class="calendar-action manage"
                                                title="Manage Semesters"
                                            >

                                                <i class="bi bi-journals"></i>

                                            </a>


                                            <!-- Edit -->

                                            <a
                                                href="edit-year.php?id=<?= (int)$year['id'] ?>"
                                                class="calendar-action"
                                                title="Edit Academic Year"
                                            >

                                                <i class="bi bi-pencil-square"></i>

                                            </a>


                                            <?php if ($year['status'] !== 'Active'): ?>

                                                <a
                                                    href="activate-year.php?id=<?= (int)$year['id'] ?>"
                                                    class="calendar-action activate"
                                                    title="Activate Academic Year"
                                                    onclick="return confirmActivate('<?= htmlspecialchars(addslashes($year['name'])) ?>')"
                                                >

                                                    <i class="bi bi-check-circle"></i>

                                                </a>

                                            <?php endif; ?>


                                        </div>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td colspan="6">

                                    <div class="calendar-empty">

                                        <div class="calendar-empty-icon">

                                            <i class="bi bi-calendar3"></i>

                                        </div>


                                        <h4>
                                            No academic years found
                                        </h4>


                                        <p>
                                            Create the first Ethiopian academic year to begin setting up the school calendar.
                                        </p>


                                        <a
                                            href="create-year.php"
                                            class="btn-add-calendar"
                                        >

                                            <i class="bi bi-calendar-plus"></i>

                                            Add Academic Year

                                        </a>

                                    </div>

                                </td>

                            </tr>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </div>


        </section>

    </main>

</div>


<!-- =====================================================
     MOBILE OVERLAY
===================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<script>


// =====================================================
// MOBILE SIDEBAR
// =====================================================

const mobileMenuButton =
    document.getElementById('mobileMenuButton');

const sidebar =
    document.querySelector('.admin-sidebar');

const overlay =
    document.getElementById('sidebarOverlay');


mobileMenuButton?.addEventListener(
    'click',
    () => {

        sidebar?.classList.toggle('show');

        overlay?.classList.toggle('show');

    }
);


overlay?.addEventListener(
    'click',
    () => {

        sidebar?.classList.remove('show');

        overlay?.classList.remove('show');

    }
);


// =====================================================
// SEARCH
// =====================================================

const searchInput =
    document.getElementById('calendarSearch');

const table =
    document.getElementById('calendarTable');


searchInput?.addEventListener(
    'input',
    function () {

        const searchValue =
            this.value.toLowerCase().trim();

        const rows =
            table.querySelectorAll('tbody tr');


        rows.forEach(row => {

            const text =
                row.textContent.toLowerCase();

            row.style.display =
                text.includes(searchValue)
                    ? ''
                    : 'none';

        });

    }
);


// =====================================================
// ACTIVATE CONFIRMATION
// =====================================================

function confirmActivate(name) {

    return confirm(
        'Are you sure you want to activate "' +
        name +
        '"?\n\n' +
        'The current active academic year will no longer be active.'
    );

}

</script>


</body>

</html>
