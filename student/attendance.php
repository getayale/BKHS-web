<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'student'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$userId = (int) $_SESSION['user_id'];

$perPage = 10;

try {
    /*
     * ---------------------------------------------------------
     * Get the logged-in student
     * ---------------------------------------------------------
     */
    $studentStmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name
        FROM students s
        INNER JOIN users u
            ON u.id = s.user_id
        WHERE s.user_id = ?
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'
        LIMIT 1
    ");

    if (!$studentStmt) {
        throw new RuntimeException('Failed to prepare student query.');
    }

    $studentStmt->bind_param('i', $userId);
    $studentStmt->execute();

    $studentResult = $studentStmt->get_result();
    $student = $studentResult->fetch_assoc();

    $studentStmt->close();

    if (!$student) {
        session_unset();
        session_destroy();

        header('Location: ../auth/login.php');
        exit;
    }

    $studentId = (int) $student['id'];

    /*
     * ---------------------------------------------------------
     * Pagination
     * ---------------------------------------------------------
     */
    $currentPage = isset($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    if ($currentPage < 1) {
        $currentPage = 1;
    }

    /*
     * ---------------------------------------------------------
     * Count Absent + Late records
     * ---------------------------------------------------------
     */
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM student_attendance
        WHERE student_id = ?
          AND status IN ('Absent', 'Late')
    ");

    if (!$countStmt) {
        throw new RuntimeException('Failed to prepare count query.');
    }

    $countStmt->bind_param('i', $studentId);
    $countStmt->execute();

    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();

    $totalRecords = (int) ($countRow['total'] ?? 0);

    $countStmt->close();

    /*
     * ---------------------------------------------------------
     * Calculate pagination
     * ---------------------------------------------------------
     */
    $totalPages = max(
        1,
        (int) ceil($totalRecords / $perPage)
    );

    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $perPage;

    /*
     * ---------------------------------------------------------
     * Get attendance records
     *
     * Latest records first.
     * Only Absent and Late.
     * ---------------------------------------------------------
     */
    $attendanceStmt = $conn->prepare("
        SELECT
            id,
            attendance_date,
            ethiopian_date,
            status
        FROM student_attendance
        WHERE student_id = ?
          AND status IN ('Absent', 'Late')
        ORDER BY attendance_date DESC, id DESC
        LIMIT ? OFFSET ?
    ");

    if (!$attendanceStmt) {
        throw new RuntimeException('Failed to prepare attendance query.');
    }

    $attendanceStmt->bind_param(
        'iii',
        $studentId,
        $perPage,
        $offset
    );

    $attendanceStmt->execute();

    $attendanceResult = $attendanceStmt->get_result();

    $attendanceRecords = [];

    while ($row = $attendanceResult->fetch_assoc()) {
        $attendanceRecords[] = $row;
    }

    $attendanceStmt->close();

} catch (Throwable $e) {
    error_log('Student attendance error: ' . $e->getMessage());

    $student = $student ?? null;
    $attendanceRecords = [];
    $totalRecords = 0;
    $totalPages = 1;
    $currentPage = 1;
}

/*
 * ---------------------------------------------------------
 * Helper functions
 * ---------------------------------------------------------
 */

function h(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function getStatusClass(string $status): string
{
    return match ($status) {
        'Absent' => 'status-absent',
        'Late' => 'status-late',
        default => 'status-default',
    };
}

function getDayName(?string $date): string
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '';
    }

    return date('l', $timestamp);
}

function buildPageUrl(int $page): string
{
    return '?page=' . max(1, $page);
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

    <title>Attendance - BKHS</title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f7fb;
            color: #1f2937;
            min-height: 100vh;
        }

        .page {
            width: 100%;
            min-height: 100vh;
        }

        /*
         * ------------------------------------------------------
         * Header
         * ------------------------------------------------------
         */

        .topbar {
            width: 100%;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 16px 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 10px 16px;

            background: #2563eb;
            color: #ffffff;

            text-decoration: none;

            border-radius: 8px;

            font-size: 14px;
            font-weight: 600;

            transition: 0.2s ease;
        }

        .back-button:hover {
            background: #1d4ed8;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
        }

        /*
         * ------------------------------------------------------
         * Main
         * ------------------------------------------------------
         */

        .container {
            width: min(100% - 32px, 900px);
            margin: 30px auto;
        }

        .intro {
            margin-bottom: 20px;
        }

        .intro h1 {
            font-size: 25px;
            margin-bottom: 7px;
            color: #111827;
        }

        .intro p {
            font-size: 14px;
            color: #6b7280;
        }

        /*
         * ------------------------------------------------------
         * Attendance card
         * ------------------------------------------------------
         */

        .attendance-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .card-header h2 {
            font-size: 17px;
            color: #111827;
        }

        .record-count {
            font-size: 13px;
            color: #6b7280;
        }

        /*
         * ------------------------------------------------------
         * Empty state
         * ------------------------------------------------------
         */

        .empty-state {
            padding: 55px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 60px;
            height: 60px;

            margin: 0 auto 15px;

            border-radius: 50%;

            background: #ecfdf5;
            color: #059669;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 28px;
        }

        .empty-state h3 {
            font-size: 18px;
            color: #111827;
            margin-bottom: 7px;
        }

        .empty-state p {
            color: #6b7280;
            font-size: 14px;
        }

        /*
         * ------------------------------------------------------
         * Attendance list
         * ------------------------------------------------------
         */

        .attendance-list {
            width: 100%;
        }

        .attendance-row {
            display: grid;
            grid-template-columns: 1fr 160px 120px;

            align-items: center;

            gap: 20px;

            padding: 18px 20px;

            border-bottom: 1px solid #eef0f3;
        }

        .attendance-row:last-child {
            border-bottom: none;
        }

        .date-section {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .ethiopian-date {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
        }

        .day-name {
            font-size: 13px;
            color: #6b7280;
        }

        .gregorian-date {
            font-size: 12px;
            color: #9ca3af;
        }

        .status-wrapper {
            display: flex;
            align-items: center;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            min-width: 85px;

            padding: 7px 12px;

            border-radius: 20px;

            font-size: 13px;
            font-weight: 700;
        }

        .status-absent {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-late {
            background: #fff7ed;
            color: #ea580c;
        }

        .status-default {
            background: #f3f4f6;
            color: #4b5563;
        }

        /*
         * ------------------------------------------------------
         * Table header
         * ------------------------------------------------------
         */

        .attendance-heading {
            display: grid;
            grid-template-columns: 1fr 160px 120px;

            gap: 20px;

            padding: 12px 20px;

            background: #f9fafb;

            border-bottom: 1px solid #e5e7eb;

            font-size: 12px;
            font-weight: 700;

            color: #6b7280;

            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /*
         * ------------------------------------------------------
         * Pagination
         * ------------------------------------------------------
         */

        .pagination {
            padding: 18px 20px;

            border-top: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .pagination-info {
            font-size: 13px;
            color: #6b7280;
        }

        .pagination-buttons {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .page-button {
            min-width: 38px;
            height: 38px;

            padding: 0 11px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border: 1px solid #d1d5db;
            border-radius: 7px;

            background: #ffffff;
            color: #374151;

            text-decoration: none;

            font-size: 13px;
            font-weight: 600;

            transition: 0.2s ease;
        }

        .page-button:hover {
            background: #f3f4f6;
        }

        .page-button.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        .page-button.disabled {
            opacity: 0.45;
            pointer-events: none;
        }

        /*
         * ------------------------------------------------------
         * Mobile
         * ------------------------------------------------------
         */

        @media (max-width: 700px) {

            .topbar {
                padding: 14px 16px;
            }

            .page-title {
                font-size: 19px;
            }

            .container {
                width: min(100% - 20px, 900px);
                margin: 20px auto;
            }

            .intro h1 {
                font-size: 22px;
            }

            .attendance-heading {
                display: none;
            }

            .attendance-row {
                grid-template-columns: 1fr auto;
                gap: 12px;

                padding: 16px;
            }

            .status-wrapper {
                grid-column: 2;
                grid-row: 1;
            }

            .date-section {
                grid-column: 1;
                grid-row: 1;
            }

            .pagination {
                flex-direction: column;
                align-items: stretch;
            }

            .pagination-info {
                text-align: center;
            }

            .pagination-buttons {
                justify-content: center;
                flex-wrap: wrap;
            }

            .page-button {
                min-width: 36px;
            }
        }

        @media (max-width: 420px) {

            .back-button {
                padding: 9px 12px;
                font-size: 13px;
            }

            .page-title {
                font-size: 17px;
            }

            .card-header {
                padding: 15px;
            }

            .card-header h2 {
                font-size: 15px;
            }

            .record-count {
                font-size: 12px;
            }

            .ethiopian-date {
                font-size: 15px;
            }

            .status-badge {
                min-width: 75px;
                padding: 6px 9px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

<div class="page">

    <!-- Header -->
    <header class="topbar">

        <div class="topbar-left">

            <a
                href="dashboard.php"
                class="back-button"
            >
                ← Dashboard
            </a>

            <span class="page-title">
                Attendance
            </span>

        </div>

    </header>


    <!-- Main -->
    <main class="container">

        <div class="intro">

            <h1>
                Attendance
            </h1>

            <p>
                Your absent and late attendance records.
            </p>

        </div>


        <section class="attendance-card">

            <div class="card-header">

                <h2>
                    Attendance Records
                </h2>

                <span class="record-count">
                    <?= $totalRecords ?>
                    <?= $totalRecords === 1 ? 'record' : 'records' ?>
                </span>

            </div>


            <?php if (empty($attendanceRecords)): ?>

                <div class="empty-state">

                    <div class="empty-icon">
                        ✓
                    </div>

                    <h3>
                        No attendance issues
                    </h3>

                    <p>
                        You have no absent or late attendance records.
                    </p>

                </div>

            <?php else: ?>

                <!-- Column headings -->
                <div class="attendance-heading">

                    <div>
                        Ethiopian Date
                    </div>

                    <div>
                        Day
                    </div>

                    <div>
                        Status
                    </div>

                </div>


                <!-- Attendance records -->
                <div class="attendance-list">

                    <?php foreach ($attendanceRecords as $record): ?>

                        <div class="attendance-row">

                            <div class="date-section">

                                <div class="ethiopian-date">

                                    <?= h(
                                        (string) $record['ethiopian_date']
                                    ) ?>

                                </div>

                                <div class="day-name">

                                    <?= h(
                                        getDayName(
                                            (string) $record['attendance_date']
                                        )
                                    ) ?>

                                </div>

                            </div>


                            <div class="gregorian-date">

                                <?= h(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            (string) $record['attendance_date']
                                        )
                                    )
                                ) ?>

                            </div>


                            <div class="status-wrapper">

                                <span
                                    class="status-badge
                                    <?= h(
                                        getStatusClass(
                                            (string) $record['status']
                                        )
                                    ) ?>"
                                >

                                    <?= h(
                                        (string) $record['status']
                                    ) ?>

                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>

                    <div class="pagination">

                        <div class="pagination-info">

                            Page
                            <?= $currentPage ?>
                            of
                            <?= $totalPages ?>

                        </div>


                        <div class="pagination-buttons">

                            <!-- Previous -->
                            <?php if ($currentPage > 1): ?>

                                <a
                                    href="<?= h(
                                        buildPageUrl(
                                            $currentPage - 1
                                        )
                                    ) ?>"
                                    class="page-button"
                                >
                                    ←
                                </a>

                            <?php else: ?>

                                <span
                                    class="page-button disabled"
                                >
                                    ←
                                </span>

                            <?php endif; ?>


                            <?php

                            /*
                             * Show a small range of page numbers.
                             */
                            $startPage = max(
                                1,
                                $currentPage - 2
                            );

                            $endPage = min(
                                $totalPages,
                                $currentPage + 2
                            );

                            ?>


                            <?php if ($startPage > 1): ?>

                                <a
                                    href="<?= h(
                                        buildPageUrl(1)
                                    ) ?>"
                                    class="page-button"
                                >
                                    1
                                </a>

                                <?php if ($startPage > 2): ?>

                                    <span class="page-button disabled">
                                        ...
                                    </span>

                                <?php endif; ?>

                            <?php endif; ?>


                            <?php for (
                                $page = $startPage;
                                $page <= $endPage;
                                $page++
                            ): ?>

                                <?php if ($page === $currentPage): ?>

                                    <span
                                        class="page-button active"
                                    >
                                        <?= $page ?>
                                    </span>

                                <?php else: ?>

                                    <a
                                        href="<?= h(
                                            buildPageUrl($page)
                                        ) ?>"
                                        class="page-button"
                                    >
                                        <?= $page ?>
                                    </a>

                                <?php endif; ?>

                            <?php endfor; ?>


                            <?php if ($endPage < $totalPages): ?>

                                <?php if ($endPage < $totalPages - 1): ?>

                                    <span class="page-button disabled">
                                        ...
                                    </span>

                                <?php endif; ?>

                                <a
                                    href="<?= h(
                                        buildPageUrl($totalPages)
                                    ) ?>"
                                    class="page-button"
                                >
                                    <?= $totalPages ?>
                                </a>

                            <?php endif; ?>


                            <!-- Next -->
                            <?php if ($currentPage < $totalPages): ?>

                                <a
                                    href="<?= h(
                                        buildPageUrl(
                                            $currentPage + 1
                                        )
                                    ) ?>"
                                    class="page-button"
                                >
                                    →
                                </a>

                            <?php else: ?>

                                <span
                                    class="page-button disabled"
                                >
                                    →
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>