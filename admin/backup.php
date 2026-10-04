<?php

declare(strict_types=1);

session_start();

// =====================================================
// AUTHENTICATION CHECK
// =====================================================

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    strtolower((string) ($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$fullName = $_SESSION['full_name'] ?? 'Administrator';

$backupDirectory = __DIR__ . '/backups';

if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0755, true);
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

// =====================================================
// DOWNLOAD BACKUP
// =====================================================

if (isset($_GET['download'])) {

    $fileName = basename((string) $_GET['download']);

    if (
        $fileName === '' ||
        !preg_match('/^bkhs_backup_\d{8}_\d{6}\.sql$/', $fileName)
    ) {
        http_response_code(400);
        exit('Invalid backup file.');
    }

    $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (!is_file($filePath)) {
        http_response_code(404);
        exit('Backup file not found.');
    }

    header('Content-Type: application/sql');
    header(
        'Content-Disposition: attachment; filename="' .
        $fileName .
        '"'
    );
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($filePath);
    exit;
}

// =====================================================
// DELETE BACKUP
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_backup'])
) {

    $fileName = basename((string) ($_POST['file_name'] ?? ''));

    if (
        $fileName === '' ||
        !preg_match('/^bkhs_backup_\d{8}_\d{6}\.sql$/', $fileName)
    ) {
        $_SESSION['error'] = 'Invalid backup file.';
        header('Location: backup.php');
        exit;
    }

    $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (is_file($filePath)) {

        if (unlink($filePath)) {
            $_SESSION['success'] = 'Backup deleted successfully.';
        } else {
            $_SESSION['error'] = 'Unable to delete the backup file.';
        }

    } else {

        $_SESSION['error'] = 'Backup file not found.';
    }

    header('Location: backup.php');
    exit;
}

// =====================================================
// CREATE DATABASE BACKUP
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_backup'])
) {

    $handle = null;
    $filePath = null;

    try {

        $timestamp = date('Ymd_His');

        $fileName = 'bkhs_backup_' . $timestamp . '.sql';

        $filePath =
            $backupDirectory .
            DIRECTORY_SEPARATOR .
            $fileName;

        $handle = fopen($filePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create the backup file.'
            );
        }

        // =================================================
        // SQL HEADER
        // =================================================

        fwrite(
            $handle,
            "-- =====================================================\n" .
            "-- BKHS SCHOOL MANAGEMENT SYSTEM DATABASE BACKUP\n" .
            "-- =====================================================\n" .
            "-- Database: bkhs\n" .
            "-- Created: " . date('Y-m-d H:i:s') . "\n" .
            "-- Created by: " .
            $conn->real_escape_string((string) $fullName) .
            "\n" .
            "-- =====================================================\n\n"
        );

        fwrite(
            $handle,
            "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n" .
            "SET time_zone = \"+03:00\";\n" .
            "SET NAMES utf8mb4;\n" .
            "SET FOREIGN_KEY_CHECKS = 0;\n\n"
        );

        // =================================================
        // GET ALL TABLES
        // =================================================

        $tablesResult = $conn->query('SHOW FULL TABLES');

        if ($tablesResult === false) {
            throw new RuntimeException(
                'Unable to retrieve database tables: ' .
                $conn->error
            );
        }

        $tables = [];

        while (
            $row = $tablesResult->fetch_array(MYSQLI_NUM)
        ) {

            $tableName = $row[0];

            $tableType = strtoupper(
                (string) ($row[1] ?? '')
            );

            if (
                $tableType === 'BASE TABLE' ||
                $tableType === 'VIEW'
            ) {

                $tables[] = [
                    'name' => $tableName,
                    'type' => $tableType
                ];
            }
        }

        $tablesResult->free();

        // =================================================
        // EXPORT EACH TABLE
        // =================================================

        foreach ($tables as $table) {

            $tableName = $table['name'];
            $tableType = $table['type'];

            $safeTableName =
                '`' .
                str_replace('`', '``', $tableName) .
                '`';

            fwrite(
                $handle,
                "\n" .
                "-- =====================================================\n" .
                "-- TABLE: " . $tableName . "\n" .
                "-- =====================================================\n\n"
            );

            // =================================================
            // VIEW
            // =================================================

            if ($tableType === 'VIEW') {

                $viewResult = $conn->query(
                    "SHOW CREATE VIEW {$safeTableName}"
                );

                if ($viewResult === false) {
                    continue;
                }

                $viewRow = $viewResult->fetch_assoc();

                $createView =
                    $viewRow['Create View'] ?? '';

                fwrite(
                    $handle,
                    "DROP VIEW IF EXISTS {$safeTableName};\n"
                );

                if ($createView !== '') {

                    fwrite(
                        $handle,
                        $createView . ";\n"
                    );
                }

                fwrite($handle, "\n");

                $viewResult->free();

                continue;
            }

            // =================================================
            // TABLE STRUCTURE
            // =================================================

            $createResult = $conn->query(
                "SHOW CREATE TABLE {$safeTableName}"
            );

            if ($createResult === false) {

                throw new RuntimeException(
                    'Unable to export structure for table ' .
                    $tableName .
                    ': ' .
                    $conn->error
                );
            }

            $createRow = $createResult->fetch_assoc();

            $createStatement =
                $createRow['Create Table'] ?? '';

            $createResult->free();

            fwrite(
                $handle,
                "DROP TABLE IF EXISTS {$safeTableName};\n"
            );

            if ($createStatement !== '') {

                fwrite(
                    $handle,
                    $createStatement . ";\n\n"
                );
            }

            // =================================================
            // TABLE DATA
            // =================================================

            $dataResult = $conn->query(
                "SELECT * FROM {$safeTableName}"
            );

            if ($dataResult === false) {

                throw new RuntimeException(
                    'Unable to export data from table ' .
                    $tableName .
                    ': ' .
                    $conn->error
                );
            }

            $fieldCount = $dataResult->field_count;

            if ($dataResult->num_rows > 0) {

                while (
                    $row = $dataResult->fetch_row()
                ) {

                    $values = [];

                    for (
                        $i = 0;
                        $i < $fieldCount;
                        $i++
                    ) {

                        $value = $row[$i];

                        if ($value === null) {

                            $values[] = 'NULL';

                        } else {

                            $values[] =
                                "'" .
                                $conn->real_escape_string(
                                    (string) $value
                                ) .
                                "'";
                        }
                    }

                    fwrite(
                        $handle,
                        "INSERT INTO {$safeTableName} VALUES (" .
                        implode(', ', $values) .
                        ");\n"
                    );
                }

                fwrite($handle, "\n");
            }

            $dataResult->free();
        }

        // =================================================
        // SQL FOOTER
        // =================================================

        fwrite(
            $handle,
            "\n" .
            "SET FOREIGN_KEY_CHECKS = 1;\n" .
            "SET SQL_MODE = DEFAULT;\n\n" .
            "-- =====================================================\n" .
            "-- END OF BKHS DATABASE BACKUP\n" .
            "-- =====================================================\n"
        );

        fclose($handle);

        $handle = null;

        // =================================================
        // VERIFY FILE
        // =================================================

        if (
            !is_file($filePath) ||
            filesize($filePath) === 0
        ) {

            if (is_file($filePath)) {
                unlink($filePath);
            }

            throw new RuntimeException(
                'The backup file was created incorrectly.'
            );
        }

        $_SESSION['success'] =
            'Database backup created successfully: ' .
            $fileName;

    } catch (Throwable $e) {

        if (
            $handle !== null &&
            is_resource($handle)
        ) {
            fclose($handle);
        }

        if (
            $filePath !== null &&
            is_file($filePath)
        ) {
            unlink($filePath);
        }

        $_SESSION['error'] =
            'Backup failed: ' . $e->getMessage();
    }

    header('Location: backup.php');
    exit;
}

// =====================================================
// GET BACKUP HISTORY
// =====================================================

$backups = [];

if (is_dir($backupDirectory)) {

    $files = scandir($backupDirectory);

    if ($files !== false) {

        foreach ($files as $file) {

            if (
                $file === '.' ||
                $file === '..' ||
                !preg_match(
                    '/^bkhs_backup_\d{8}_\d{6}\.sql$/',
                    $file
                )
            ) {
                continue;
            }

            $filePath =
                $backupDirectory .
                DIRECTORY_SEPARATOR .
                $file;

            if (!is_file($filePath)) {
                continue;
            }

            $backups[] = [
                'name' => $file,
                'size' => filesize($filePath),
                'created' => filemtime($filePath)
            ];
        }
    }
}

// =====================================================
// SORT NEWEST FIRST
// =====================================================

usort(
    $backups,
    static function (
        array $a,
        array $b
    ): int {

        return $b['created'] <=> $a['created'];
    }
);

// =====================================================
// BACKUP STATISTICS
// =====================================================

$totalBackups = count($backups);

$totalSize = 0;

foreach ($backups as $backup) {
    $totalSize += (int) $backup['size'];
}

$lastBackup = $backups[0] ?? null;

// =====================================================
// FORMAT FILE SIZE
// =====================================================

function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format(
            $bytes / 1024,
            2
        ) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format(
            $bytes / (1024 * 1024),
            2
        ) . ' MB';
    }

    return number_format(
        $bytes / (1024 * 1024 * 1024),
        2
    ) . ' GB';
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

    <meta
        name="description"
        content="BKHS School Management System - Database Backup"
    >

    <title>Backup & Restore | BKHS Admin</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Google Fonts -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
        rel="stylesheet"
    >

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

    <!-- Admin CSS -->

    <link
        rel="stylesheet"
        href="../public/css/admin.css"
    >

    <style>

        .backup-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 24px;
            height: 100%;
        }

        .backup-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #4f46e5;
            font-size: 22px;
            flex-shrink: 0;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 22px;
        }

        .stat-value {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: #111827;
        }

        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }

        .backup-table {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            overflow: hidden;
        }

        .backup-table table {
            margin-bottom: 0;
        }

        .backup-table th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 1px solid #e5e7eb;
            padding: 15px 18px;
        }

        .backup-table td {
            padding: 16px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .backup-table tr:last-child td {
            border-bottom: 0;
        }

        .file-name {
            font-weight: 600;
            color: #111827;
            font-size: 14px;
        }

        .backup-warning {
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #92400e;
            border-radius: 14px;
            padding: 18px;
        }

        .included-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .included-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: #4b5563;
        }

        .included-list li:last-child {
            margin-bottom: 0;
        }

        .included-list i {
            color: #16a34a;
            font-size: 17px;
        }

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 12px;
        }

        @media (max-width: 767px) {

            .backup-card {
                padding: 18px;
            }

            .backup-table {
                overflow-x: auto;
            }

            .backup-table table {
                min-width: 700px;
            }

        }

    </style>

</head>

<body>

<!-- =====================================================
     MOBILE OVERLAY
===================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside
    class="admin-sidebar"
    id="adminSidebar"
>

    <!-- Brand -->

    <div class="sidebar-brand">

        <div class="brand-mark">
            <i class="bi bi-grid-1x2-fill"></i>
        </div>

        <div class="brand-text">

            <strong>
                BKHS
            </strong>

            <span>
                Administration
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

    <!-- Navigation -->

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            MAIN
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2"></i>

            <span>
                Dashboard
            </span>
        </a>

        <a
            href="users/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-people"></i>

            <span>
                Users
            </span>
        </a>

        <a
            href="#"
            class="sidebar-link"
        >
            <i class="bi bi-mortarboard"></i>

            <span>
                Students
            </span>
        </a>

        <a
            href="#"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>

            <span>
                Staff
            </span>
        </a>

        <div class="nav-section-title">
            ACADEMIC
        </div>

        <a
            href="subjectassignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-book"></i>

            <span>
                Subjects
            </span>
        </a>

        <a
            href="#"
            class="sidebar-link"
        >
            <i class="bi bi-building"></i>

            <span>
                Classes
            </span>
        </a>

        <a
            href="academic-calendar/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar3"></i>

            <span>
                Academic Calendar
            </span>
        </a>

        <div class="nav-section-title">
            MANAGEMENT
        </div>

        <a
            href="gallery.php"
            class="sidebar-link"
        >
            <i class="bi bi-wallet2"></i>

            <span>
                Gallery
            </span>
        </a>

        <a
            href="backup.php"
            class="sidebar-link active"
        >
            <i class="bi bi-cloud-arrow-down"></i>

            <span>
                Backup
            </span>
        </a>

        <a
            href="#"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart"></i>

            <span>
                Reports
            </span>
        </a>

        <div class="nav-section-title">
            SYSTEM
        </div>

        <a
            href="#"
            class="sidebar-link"
        >
            <i class="bi bi-gear"></i>

            <span>
                Settings
            </span>
        </a>

        <a
            href="audit.php"
            class="sidebar-link"
        >
            <i class="bi bi-shield-check"></i>

            <span>
                Audit Log
            </span>
        </a>

    </nav>

    <!-- Sidebar Footer -->

    <div class="sidebar-footer">

        <a
            href="../auth/logout.php"
            class="logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>

            <span>
                Sign out
            </span>
        </a>

    </div>

</aside>

<!-- =====================================================
     MAIN
===================================================== -->

<div class="admin-main">

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

            <span>
                Administration
            </span>

            <h1>
                Backup & Restore
            </h1>

        </div>

        <div class="topbar-actions">

            <!-- Notifications -->

            <button
                type="button"
                class="topbar-icon-button"
                title="Notifications"
            >
                <i class="bi bi-bell"></i>

                <span class="notification-dot"></span>
            </button>

            <!-- Profile -->

            <div class="admin-profile">

                <div class="profile-avatar">

                    <?php
                    echo strtoupper(
                        substr((string) $fullName, 0, 1)
                    );
                    ?>

                </div>

                <div class="profile-info">

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            (string) $fullName
                        );
                        ?>
                    </strong>

                    <span>
                        Administrator
                    </span>

                </div>

                <i class="bi bi-chevron-down profile-chevron"></i>

            </div>

        </div>

    </header>

    <!-- =================================================
         PAGE CONTENT
    ================================================== -->

    <main class="admin-content">

        <!-- Page Header -->

        <section class="welcome-section">

            <div>

                <h2 class="mb-1">
                    Database Backup
                </h2>

                <p class="text-muted mb-0">
                    Protect and manage your BKHS database backups.
                </p>

            </div>

            <div class="welcome-date">

                <i class="bi bi-calendar3"></i>

                <?php echo date('F d, Y'); ?>

            </div>

        </section>

        <!-- SUCCESS -->

        <?php if ($success !== ''): ?>

            <div
                class="alert alert-success alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-check-circle me-2"></i>

                <?php
                echo htmlspecialchars($success);
                ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- ERROR -->

        <?php if ($error !== ''): ?>

            <div
                class="alert alert-danger alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle me-2"></i>

                <?php
                echo htmlspecialchars($error);
                ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- =================================================
             DATABASE BACKUP
        ================================================== -->

        <div class="row g-4 mb-4">

            <div class="col-lg-8">

                <div class="backup-card">

                    <div
                        class="d-flex justify-content-between align-items-start gap-3"
                    >

                        <div class="d-flex gap-3">

                            <div class="backup-icon">

                                <i class="bi bi-database-check"></i>

                            </div>

                            <div>

                                <h4 class="mb-1">
                                    Database Backup
                                </h4>

                                <p class="text-muted mb-0">
                                    Create a complete backup of the BKHS database.
                                </p>

                            </div>

                        </div>

                        <span class="badge text-bg-success">
                            Connected
                        </span>

                    </div>

                    <hr class="my-4">

                    <div class="row g-3 mb-4">

                        <div class="col-md-6">

                            <div class="border rounded-3 p-3">

                                <small class="text-muted d-block mb-1">
                                    Database
                                </small>

                                <strong>
                                    bkhs
                                </strong>

                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="border rounded-3 p-3">

                                <small class="text-muted d-block mb-1">
                                    Last Backup
                                </small>

                                <strong>

                                    <?php if ($lastBackup): ?>

                                        <?php
                                        echo date(
                                            'M d, Y H:i',
                                            (int) $lastBackup['created']
                                        );
                                        ?>

                                    <?php else: ?>

                                        Never

                                    <?php endif; ?>

                                </strong>

                            </div>

                        </div>

                    </div>

                    <form
                        method="POST"
                        onsubmit="return confirm('Create a complete backup of the BKHS database now?');"
                    >

                        <button
                            type="submit"
                            name="create_backup"
                            value="1"
                            class="btn btn-primary"
                        >

                            <i class="bi bi-cloud-arrow-down me-2"></i>

                            Create Backup

                        </button>

                    </form>

                </div>

            </div>

            <!-- WHAT IS INCLUDED -->

            <div class="col-lg-4">

                <div class="backup-card">

                    <h5 class="mb-4">
                        What's Included
                    </h5>

                    <ul class="included-list">

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>All database tables</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Table structures</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>All table records</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Primary keys</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Indexes</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Foreign keys</span>
                        </li>

                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Views</span>
                        </li>

                    </ul>

                </div>

            </div>

        </div>

        <!-- =================================================
             STATISTICS
        ================================================== -->

        <div class="row g-4 mb-4">

            <div class="col-md-4">

                <div class="stat-card">

                    <div
                        class="d-flex justify-content-between align-items-center"
                    >

                        <div>

                            <div class="stat-value">
                                <?php echo $totalBackups; ?>
                            </div>

                            <div class="stat-label">
                                Total Backups
                            </div>

                        </div>

                        <div class="backup-icon">

                            <i class="bi bi-archive"></i>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="stat-card">

                    <div
                        class="d-flex justify-content-between align-items-center"
                    >

                        <div>

                            <div class="stat-value">
                                <?php echo formatFileSize($totalSize); ?>
                            </div>

                            <div class="stat-label">
                                Total Backup Size
                            </div>

                        </div>

                        <div class="backup-icon">

                            <i class="bi bi-hdd"></i>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="stat-card">

                    <div
                        class="d-flex justify-content-between align-items-center"
                    >

                        <div>

                            <div class="stat-value">

                                <?php if ($lastBackup): ?>

                                    <?php
                                    echo formatFileSize(
                                        (int) $lastBackup['size']
                                    );
                                    ?>

                                <?php else: ?>

                                    0 B

                                <?php endif; ?>

                            </div>

                            <div class="stat-label">
                                Latest Backup Size
                            </div>

                        </div>

                        <div class="backup-icon">

                            <i class="bi bi-file-earmark-arrow-down"></i>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =================================================
             BACKUP HISTORY
        ================================================== -->

        <div class="backup-table mb-4">

            <div class="p-4 border-bottom">

                <div
                    class="d-flex justify-content-between align-items-center"
                >

                    <div>

                        <h5 class="mb-1">
                            Backup History
                        </h5>

                        <p class="text-muted mb-0">
                            Previously created database backups.
                        </p>

                    </div>

                </div>

            </div>

            <?php if (count($backups) > 0): ?>

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    Backup File
                                </th>

                                <th>
                                    Date & Time
                                </th>

                                <th>
                                    Size
                                </th>

                                <th class="text-end">
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($backups as $backup): ?>

                                <tr>

                                    <td>

                                        <div
                                            class="d-flex align-items-center gap-2"
                                        >

                                            <i
                                                class="bi bi-filetype-sql text-primary"
                                                style="font-size: 22px;"
                                            ></i>

                                            <span class="file-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    (string) $backup['name']
                                                );
                                                ?>

                                            </span>

                                        </div>

                                    </td>

                                    <td>

                                        <?php
                                        echo date(
                                            'M d, Y H:i:s',
                                            (int) $backup['created']
                                        );
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                        echo formatFileSize(
                                            (int) $backup['size']
                                        );
                                        ?>

                                    </td>

                                    <td class="text-end">

                                        <a
                                            href="backup.php?download=<?php echo urlencode((string) $backup['name']); ?>"
                                            class="btn btn-sm btn-outline-primary me-1"
                                            title="Download"
                                        >

                                            <i class="bi bi-download"></i>

                                        </a>

                                        <form
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm('Delete this backup permanently?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="file_name"
                                                value="<?php echo htmlspecialchars((string) $backup['name']); ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="delete_backup"
                                                value="1"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Delete"
                                            >

                                                <i class="bi bi-trash"></i>

                                            </button>

                                        </form>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <i class="bi bi-cloud-slash"></i>

                    <h6>
                        No backups yet
                    </h6>

                    <p class="mb-0">
                        Create your first BKHS database backup.
                    </p>

                </div>

            <?php endif; ?>

        </div>

        <!-- =================================================
             RESTORE
        ================================================== -->

        <div class="backup-card">

            <div class="d-flex align-items-start gap-3 mb-3">

                <div class="backup-icon">

                    <i class="bi bi-arrow-counterclockwise"></i>

                </div>

                <div>

                    <h5 class="mb-1">
                        Restore Database
                    </h5>

                    <p class="text-muted mb-0">
                        Restore the BKHS database from a previous backup.
                    </p>

                </div>

            </div>

            <div class="backup-warning">

                <div class="d-flex gap-2">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                    <div>

                        <strong>
                            Restore is currently disabled.
                        </strong>

                        <div class="mt-1">

                            Restoring a database will replace existing
                            data and should only be performed after
                            confirming the correct backup file.

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<!-- Sidebar JS -->

<script>

const sidebar =
    document.getElementById('adminSidebar');

const overlay =
    document.getElementById('sidebarOverlay');

const mobileMenuButton =
    document.getElementById('mobileMenuButton');

const sidebarClose =
    document.getElementById('sidebarClose');

function openSidebar() {

    sidebar.classList.add('show');

    overlay.classList.add('show');

    document.body.classList.add('sidebar-open');

}

function closeSidebar() {

    sidebar.classList.remove('show');

    overlay.classList.remove('show');

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