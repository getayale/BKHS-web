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

$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';

unset($_SESSION['success_message'], $_SESSION['error_message']);

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

function formatEthiopianDate(
    int $year,
    int $month,
    int $day,
    array $months
): string {
    $monthName = $months[$month] ?? 'Unknown';

    return sprintf(
        '%d %s %d',
        $day,
        $monthName,
        $year
    );
}

$academicYearId = filter_input(
    INPUT_GET,
    'academic_year_id',
    FILTER_VALIDATE_INT
);

if (!$academicYearId) {
    $_SESSION['error_message'] = 'Invalid academic year.';
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

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$academicYear = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    $_SESSION['error_message'] = 'Academic year not found.';
    header('Location: index.php');
    exit;
}

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

/*
|--------------------------------------------------------------------------
| Load Semesters
|--------------------------------------------------------------------------
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
    ORDER BY order_number ASC
");

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();

$semesters = [];

while ($row = $result->fetch_assoc()) {
    $semesters[(int)$row['order_number']] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Determine Next Semester
|--------------------------------------------------------------------------
*/
$nextSemesterOrder = null;

foreach ($semesterRules as $order => $rule) {
    if (!isset($semesters[$order])) {
        $nextSemesterOrder = $order;
        break;
    }

    if ($semesters[$order]['status'] === 'Not Completed') {
        $nextSemesterOrder = $order;
        break;
    }
}

function statusClass(string $status): string
{
    return match ($status) {
        'Active' => 'active',
        'Completed' => 'completed',
        default => 'pending'
    };
}

function statusIcon(string $status): string
{
    return match ($status) {
        'Active' => 'bi-play-circle-fill',
        'Completed' => 'bi-check-circle-fill',
        default => 'bi-clock-fill'
    };
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

    <title>Manage Semesters | BKHS</title>

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
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
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
            padding-bottom: 40px;
        }

        .page-heading {
            margin-bottom: 26px;
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
            font-size: 0.94rem;
        }

        .year-summary {
            background: #fff;
            border: 1px solid var(--calendar-border);
            border-radius: 20px;
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.05);
        }

        .year-summary-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .year-label {
            color: var(--calendar-muted);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .year-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 5px;
        }

        .year-title h2 {
            margin: 0;
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--calendar-text);
        }

        .year-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(79, 70, 229, 0.10);
            color: var(--calendar-primary);
            font-size: 1.3rem;
        }

        .year-date {
            color: var(--calendar-muted);
            font-size: 0.88rem;
        }

        .year-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .year-status.active {
            background: rgba(22, 163, 74, 0.10);
            color: var(--calendar-success);
        }

        .year-status.completed {
            background: rgba(100, 116, 139, 0.12);
            color: #475569;
        }

        .year-status.pending {
            background: rgba(217, 119, 6, 0.10);
            color: var(--calendar-warning);
        }

        .progress-wrap {
            margin-top: 22px;
        }

        .progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 9px;
        }

        .progress-header span {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--calendar-muted);
        }

        .progress-header strong {
            color: var(--calendar-text);
            font-size: 0.82rem;
        }

        .calendar-progress {
            height: 8px;
            background: #edf0f5;
            border-radius: 999px;
            overflow: hidden;
        }

        .calendar-progress-bar {
            height: 100%;
            background: linear-gradient(
                90deg,
                var(--calendar-primary),
                #7c3aed
            );
            border-radius: inherit;
            transition: width 0.3s ease;
        }

        .section-title {
            margin-bottom: 14px;
        }

        .section-title h3 {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--calendar-text);
        }

        .section-title p {
            margin: 4px 0 0;
            color: var(--calendar-muted);
            font-size: 0.84rem;
        }

        .semester-list {
            display: grid;
            gap: 15px;
        }

        .semester-card {
            background: #fff;
            border: 1px solid var(--calendar-border);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 6px 22px rgba(15, 23, 42, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .semester-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
        }

        .semester-card.next {
            border-color: rgba(79, 70, 229, 0.35);
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.08);
        }

        .semester-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
        }

        .semester-info {
            display: flex;
            gap: 14px;
            min-width: 0;
        }

        .semester-number {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f2ff;
            color: var(--calendar-primary);
            font-weight: 800;
            font-size: 0.95rem;
        }

        .semester-card.completed .semester-number {
            background: rgba(22, 163, 74, 0.10);
            color: var(--calendar-success);
        }

        .semester-card.active .semester-number {
            background: rgba(79, 70, 229, 0.12);
            color: var(--calendar-primary);
        }

        .semester-name {
            margin: 0 0 4px;
            font-size: 1rem;
            font-weight: 800;
            color: var(--calendar-text);
        }

        .semester-mark {
            color: var(--calendar-muted);
            font-size: 0.82rem;
        }

        .semester-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .semester-status.active {
            background: rgba(79, 70, 229, 0.10);
            color: var(--calendar-primary);
        }

        .semester-status.completed {
            background: rgba(22, 163, 74, 0.10);
            color: var(--calendar-success);
        }

        .semester-status.pending {
            background: rgba(217, 119, 6, 0.10);
            color: var(--calendar-warning);
        }

        .semester-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #eef0f4;
        }

        .detail-box {
            min-width: 0;
        }

        .detail-label {
            display: block;
            color: #8a94a6;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }

        .detail-value {
            color: var(--calendar-text);
            font-size: 0.82rem;
            font-weight: 600;
            line-height: 1.4;
        }

        .semester-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 17px;
        }

        .edit-semester-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 40px;
            padding: 0 15px;
            border-radius: 10px;
            border: 1px solid #dfe3eb;
            background: #fff;
            color: var(--calendar-text);
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .edit-semester-btn:hover {
            color: var(--calendar-primary);
            border-color: rgba(79, 70, 229, 0.35);
            background: rgba(79, 70, 229, 0.05);
        }

        .next-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 8px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.09);
            color: var(--calendar-primary);
            font-size: 0.67rem;
            font-weight: 800;
            vertical-align: middle;
        }

        .alert {
            border: 0;
            border-radius: 13px;
            margin-bottom: 20px;
        }

        .empty-state {
            background: #fff;
            border: 1px dashed #d9dee8;
            border-radius: 18px;
            padding: 35px 20px;
            text-align: center;
            color: var(--calendar-muted);
        }

        .empty-state i {
            display: block;
            font-size: 2rem;
            margin-bottom: 10px;
            color: #9aa4b2;
        }

        @media (max-width: 575.98px) {
            .calendar-main {
                padding-left: 14px;
                padding-right: 14px;
            }

            .year-summary {
                padding: 18px;
                border-radius: 16px;
            }

            .year-summary-top {
                flex-direction: column;
            }

            .semester-card {
                padding: 17px;
            }

            .semester-top {
                flex-direction: column;
            }

            .semester-status {
                align-self: flex-start;
            }

            .semester-details {
                grid-template-columns: 1fr;
            }

            .semester-actions {
                justify-content: stretch;
            }

            .edit-semester-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="adminSidebar">

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
                <span>Academic Calendar</span>
            </div>

            <div class="topbar-profile">
                <div class="profile-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div class="profile-info">
                    <strong>
                        <?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator') ?>
                    </strong>
                    <span>Administrator</span>
                </div>
            </div>

        </header>

        <div class="calendar-main container-fluid">

            <!-- Page heading -->
            <div class="page-heading">

                <h1>
                    Manage Semesters
                </h1>

                <p>
                    Configure the four semesters for academic year
                    <strong><?= htmlspecialchars($academicYear['name']) ?></strong>.
                </p>

            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div
                    class="alert alert-success alert-dismissible fade show"
                    role="alert"
                >
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($success) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div
                    class="alert alert-danger alert-dismissible fade show"
                    role="alert"
                >
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?= htmlspecialchars($error) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>
            <?php endif; ?>

            <!-- Academic year summary -->
            <section class="year-summary">

                <div class="year-summary-top">

                    <div>

                        <div class="year-label">
                            Academic Year
                        </div>

                        <div class="year-title">

                            <div class="year-icon">
                                <i class="bi bi-calendar2-week-fill"></i>
                            </div>

                            <h2>
                                <?= htmlspecialchars($academicYear['name']) ?>
                            </h2>

                        </div>

                        <div class="year-date">

                            <?= formatEthiopianDate(
                                (int)$academicYear['start_year'],
                                (int)$academicYear['start_month'],
                                (int)$academicYear['start_day'],
                                $ethiopianMonths
                            ) ?>

                            <span class="mx-1">—</span>

                            <?= formatEthiopianDate(
                                (int)$academicYear['end_year'],
                                (int)$academicYear['end_month'],
                                (int)$academicYear['end_day'],
                                $ethiopianMonths
                            ) ?>

                        </div>

                    </div>

                    <span
                        class="year-status <?= statusClass($academicYear['status']) ?>"
                    >
                        <i class="bi bi-circle-fill"></i>

                        <?= htmlspecialchars($academicYear['status']) ?>
                    </span>

                </div>

                <?php
                $completedCount = 0;

                foreach ($semesters as $semester) {
                    if ($semester['status'] === 'Completed') {
                        $completedCount++;
                    }
                }

                $progress = ($completedCount / 4) * 100;
                ?>

                <div class="progress-wrap">

                    <div class="progress-header">
                        <span>Semester Progress</span>
                        <strong>
                            <?= $completedCount ?> of 4 completed
                        </strong>
                    </div>

                    <div class="calendar-progress">
                        <div
                            class="calendar-progress-bar"
                            style="width: <?= $progress ?>%;"
                        ></div>
                    </div>

                </div>

            </section>

            <!-- Semester section -->
            <section>

                <div class="section-title">

                    <h3>
                        Semesters
                    </h3>

                    <p>
                        Configure each semester individually. Maximum marks
                        are fixed by the school academic structure.
                    </p>

                </div>

                <div class="semester-list">

                    <?php foreach ($semesterRules as $order => $rule): ?>

                        <?php
                        $semester = $semesters[$order] ?? null;

                        $status = $semester['status'] ?? 'Not Completed';

                        $isNext = $nextSemesterOrder === $order;

                        $cardClass = '';

                        if ($status === 'Completed') {
                            $cardClass = 'completed';
                        } elseif ($status === 'Active') {
                            $cardClass = 'active';
                        }
                        ?>

                        <article
                            class="semester-card <?= $cardClass ?> <?= $isNext ? 'next' : '' ?>"
                        >

                            <div class="semester-top">

                                <div class="semester-info">

                                    <div class="semester-number">
                                        <?= $order ?>
                                    </div>

                                    <div>

                                        <h4 class="semester-name">

                                            <?= htmlspecialchars($rule['name']) ?>

                                            <?php if ($isNext): ?>
                                                <span class="next-badge">
                                                    <i class="bi bi-arrow-right"></i>
                                                    Next
                                                </span>
                                            <?php endif; ?>

                                        </h4>

                                        <div class="semester-mark">
                                            Maximum mark:
                                            <strong>
                                                <?= $rule['max_mark'] ?>
                                            </strong>
                                        </div>

                                    </div>

                                </div>

                                <span
                                    class="semester-status <?= statusClass($status) ?>"
                                >
                                    <i class="bi <?= statusIcon($status) ?>"></i>

                                    <?= htmlspecialchars($status) ?>
                                </span>

                            </div>

                            <?php if ($semester): ?>

                                <div class="semester-details">

                                    <div class="detail-box">

                                        <span class="detail-label">
                                            Start Date
                                        </span>

                                        <span class="detail-value">
                                            <?= formatEthiopianDate(
                                                (int)$semester['start_year'],
                                                (int)$semester['start_month'],
                                                (int)$semester['start_day'],
                                                $ethiopianMonths
                                            ) ?>
                                        </span>

                                    </div>

                                    <div class="detail-box">

                                        <span class="detail-label">
                                            End Date
                                        </span>

                                        <span class="detail-value">
                                            <?= formatEthiopianDate(
                                                (int)$semester['end_year'],
                                                (int)$semester['end_month'],
                                                (int)$semester['end_day'],
                                                $ethiopianMonths
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                                <div class="semester-actions">

                                    <a
                                        href="edit-semester.php?academic_year_id=<?= (int)$academicYearId ?>&semester_id=<?= (int)$semester['id'] ?>"
                                        class="edit-semester-btn"
                                    >
                                        <i class="bi bi-pencil-square"></i>

                                        <?= $status === 'Not Completed'
                                            ? 'Configure Semester'
                                            : 'Edit Semester'
                                        ?>
                                    </a>

                                </div>

                            <?php else: ?>

                                <div class="semester-details">

                                    <div class="detail-box">

                                        <span class="detail-label">
                                            Status
                                        </span>

                                        <span class="detail-value">
                                            Not configured
                                        </span>

                                    </div>

                                    <div class="detail-box">

                                        <span class="detail-label">
                                            Maximum Mark
                                        </span>

                                        <span class="detail-value">
                                            <?= $rule['max_mark'] ?>
                                        </span>

                                    </div>

                                </div>

                                <div class="semester-actions">

                                    <a
                                        href="edit-semester.php?academic_year_id=<?= (int)$academicYearId ?>&order_number=<?= $order ?>"
                                        class="edit-semester-btn"
                                    >
                                        <i class="bi bi-plus-circle"></i>
                                        Configure Semester
                                    </a>

                                </div>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

        </div>

    </main>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const sidebarClose = document.getElementById('sidebarClose');

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
</script>

</body>
</html>