<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$registrarId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$successMessage = '';
$errorMessage = '';
$search = '';

if (isset($_GET['search'])) {
    $search = trim((string) $_GET['search']);
}

$registrar = [
    'full_name' => $_SESSION['full_name'] ?? 'Registrar',
    'photo_path' => null
];

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        r.photo
    FROM users u
    LEFT JOIN registrars r ON r.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'registrar'
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('i', $registrarId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $registrar['full_name'] = $row['full_name'];
        $registrar['photo_path'] = $row['photo'];
    }

    $stmt->close();
}

$activeAcademicYear = null;

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
    WHERE LOWER(status) = 'active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $activeAcademicYear = $row;
    }

    $stmt->close();
}

if (!$activeAcademicYear) {
    $errorMessage = 'There is no active academic year.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'withdraw_student') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        if (
            !hash_equals(
                (string) $_SESSION['csrf_token'],
                $submittedToken
            )
        ) {
            $errorMessage = 'Invalid security token. Please refresh the page and try again.';
        } else {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($studentId <= 0) {
                $errorMessage = 'Invalid student selected.';
            } elseif ($reason === '') {
                $errorMessage = 'Please enter a withdrawal reason.';
            } elseif (mb_strlen($reason) > 2000) {
                $errorMessage = 'The withdrawal reason is too long.';
            } else {
                $transactionStarted = false;

                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    $stmt = $conn->prepare("
                        SELECT
                            id,
                            name
                        FROM academic_years
                        WHERE LOWER(status) = 'active'
                        ORDER BY id DESC
                        LIMIT 1
                        FOR UPDATE
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to verify the active academic year.'
                        );
                    }

                    $stmt->execute();

                    $yearResult = $stmt->get_result();
                    $currentYear = $yearResult->fetch_assoc();

                    $stmt->close();

                    if (!$currentYear) {
                        throw new RuntimeException(
                            'There is no active academic year.'
                        );
                    }

                    $academicYearId = (int) $currentYear['id'];

                    $stmt = $conn->prepare("
                        SELECT
                            sr.id AS registration_id,
                            sr.student_id,
                            sr.academic_year_id,
                            sr.grade_id,
                            sr.section_id,
                            s.student_code,
                            s.full_name,
                            s.user_id,
                            g.name AS grade_name,
                            g.grade_number,
                            sec.name AS section_name,
                            sec.code AS section_code
                        FROM student_registrations sr
                        INNER JOIN students s
                            ON s.id = sr.student_id
                        INNER JOIN grades g
                            ON g.id = sr.grade_id
                        INNER JOIN sections sec
                            ON sec.id = sr.section_id
                        WHERE sr.student_id = ?
                          AND sr.academic_year_id = ?
                          AND s.is_deleted = 0
                        LIMIT 1
                        FOR UPDATE
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to find the student registration.'
                        );
                    }

                    $stmt->bind_param(
                        'ii',
                        $studentId,
                        $academicYearId
                    );

                    $stmt->execute();

                    $studentResult = $stmt->get_result();
                    $student = $studentResult->fetch_assoc();

                    $stmt->close();

                    if (!$student) {
                        throw new RuntimeException(
                            'The student is not currently registered in the active academic year.'
                        );
                    }

                    $registrationId = (int) $student['registration_id'];
                    $studentUserId = (int) $student['user_id'];
                    $gradeId = (int) $student['grade_id'];
                    $sectionId = (int) $student['section_id'];

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM student_withdrawals
                        WHERE student_id = ?
                          AND academic_year_id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to check previous withdrawal records.'
                        );
                    }

                    $stmt->bind_param(
                        'ii',
                        $studentId,
                        $academicYearId
                    );

                    $stmt->execute();

                    $withdrawalCheck = $stmt->get_result();

                    if ($withdrawalCheck->fetch_assoc()) {
                        $stmt->close();

                        throw new RuntimeException(
                            'This student already has a withdrawal record for the active academic year.'
                        );
                    }

                    $stmt->close();

                    $stmt = $conn->prepare("
                        INSERT INTO student_withdrawals (
                            student_id,
                            academic_year_id,
                            grade_id,
                            section_id,
                            reason,
                            withdrawn_by,
                            withdrawn_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to create the withdrawal record.'
                        );
                    }

                    $stmt->bind_param(
                        'iiiisi',
                        $studentId,
                        $academicYearId,
                        $gradeId,
                        $sectionId,
                        $reason,
                        $registrarId
                    );

                    if (!$stmt->execute()) {
                        $stmt->close();

                        throw new RuntimeException(
                            'Unable to save the withdrawal record.'
                        );
                    }

                    $stmt->close();

                    $stmt = $conn->prepare("
                        DELETE FROM student_registrations
                        WHERE id = ?
                          AND student_id = ?
                          AND academic_year_id = ?
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to remove the current registration.'
                        );
                    }

                    $stmt->bind_param(
                        'iii',
                        $registrationId,
                        $studentId,
                        $academicYearId
                    );

                    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                        $stmt->close();

                        throw new RuntimeException(
                            'Unable to remove the current student registration.'
                        );
                    }

                    $stmt->close();

                    $stmt = $conn->prepare("
                        UPDATE students
                        SET is_deleted = 1
                        WHERE id = ?
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to disable the student record.'
                        );
                    }

                    $stmt->bind_param('i', $studentId);

                    if (!$stmt->execute()) {
                        $stmt->close();

                        throw new RuntimeException(
                            'Unable to disable the student record.'
                        );
                    }

                    $stmt->close();

                    if ($studentUserId > 0) {
                        $stmt = $conn->prepare("
                            UPDATE users
                            SET is_deleted = 1
                            WHERE id = ?
                              AND LOWER(role) = 'student'
                        ");

                        if (!$stmt) {
                            throw new RuntimeException(
                                'Unable to disable the student account.'
                            );
                        }

                        $stmt->bind_param('i', $studentUserId);

                        if (!$stmt->execute()) {
                            $stmt->close();

                            throw new RuntimeException(
                                'Unable to disable the student account.'
                            );
                        }

                        $stmt->close();
                    }

                    $parentIds = [];

                    $stmt = $conn->prepare("
                        SELECT DISTINCT parent_id
                        FROM student_parents
                        WHERE student_id = ?
                    ");

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to find the student parent.'
                        );
                    }

                    $stmt->bind_param('i', $studentId);
                    $stmt->execute();

                    $parentResult = $stmt->get_result();

                    while ($parentRow = $parentResult->fetch_assoc()) {
                        $parentIds[] = (int) $parentRow['parent_id'];
                    }

                    $stmt->close();

                    foreach ($parentIds as $parentId) {
                        $hasOtherActiveChild = false;

                        $stmt = $conn->prepare("
                            SELECT 1
                            FROM student_parents sp
                            INNER JOIN students s
                                ON s.id = sp.student_id
                            INNER JOIN student_registrations sr
                                ON sr.student_id = s.id
                               AND sr.academic_year_id = ?
                            WHERE sp.parent_id = ?
                              AND sp.student_id <> ?
                              AND sp.is_account_access = 1
                              AND s.is_deleted = 0
                            LIMIT 1
                        ");

                        if (!$stmt) {
                            throw new RuntimeException(
                                'Unable to check the parent account.'
                            );
                        }

                        $stmt->bind_param(
                            'iii',
                            $academicYearId,
                            $parentId,
                            $studentId
                        );

                        $stmt->execute();

                        $otherChildResult = $stmt->get_result();

                        if ($otherChildResult->fetch_assoc()) {
                            $hasOtherActiveChild = true;
                        }

                        $stmt->close();

                        if (!$hasOtherActiveChild) {
                            $stmt = $conn->prepare("
                                SELECT user_id
                                FROM parents
                                WHERE id = ?
                                LIMIT 1
                                FOR UPDATE
                            ");

                            if (!$stmt) {
                                throw new RuntimeException(
                                    'Unable to find the parent account.'
                                );
                            }

                            $stmt->bind_param('i', $parentId);
                            $stmt->execute();

                            $parentResult = $stmt->get_result();
                            $parent = $parentResult->fetch_assoc();

                            $stmt->close();

                            if (
                                $parent &&
                                !empty($parent['user_id'])
                            ) {
                                $parentUserId = (int) $parent['user_id'];

                                $stmt = $conn->prepare("
                                    UPDATE users
                                    SET is_deleted = 1
                                    WHERE id = ?
                                      AND LOWER(role) = 'parent'
                                ");

                                if (!$stmt) {
                                    throw new RuntimeException(
                                        'Unable to disable the parent account.'
                                    );
                                }

                                $stmt->bind_param(
                                    'i',
                                    $parentUserId
                                );

                                if (!$stmt->execute()) {
                                    $stmt->close();

                                    throw new RuntimeException(
                                        'Unable to disable the parent account.'
                                    );
                                }

                                $stmt->close();
                            }
                        }
                    }

                    $conn->commit();
                    $transactionStarted = false;

                    $successMessage =
                        'Student ' .
                        $student['student_code'] .
                        ' (' .
                        $student['full_name'] .
                        ') was successfully withdrawn from the active academic year.';

                    $search = '';
                } catch (Throwable $e) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }

                    $errorMessage = $e->getMessage();
                }
            }
        }
    }
}

/*
 * Search current students.
 */
$students = [];

if ($activeAcademicYear && $search !== '') {
    $searchLike = '%' . $search . '%';
    $academicYearId = (int) $activeAcademicYear['id'];

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name,
            sr.id AS registration_id,
            g.name AS grade_name,
            g.grade_number,
            sec.name AS section_name,
            sec.code AS section_code
        FROM students s
        INNER JOIN student_registrations sr
            ON sr.student_id = s.id
           AND sr.academic_year_id = ?
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        WHERE s.is_deleted = 0
          AND (
              s.student_code LIKE ?
              OR s.full_name LIKE ?
          )
        ORDER BY s.full_name ASC
        LIMIT 50
    ");

    if ($stmt) {
        $stmt->bind_param(
            'iss',
            $academicYearId,
            $searchLike,
            $searchLike
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();
    }
}

$profilePhoto = '../public/images/default-avatar.png';

if (!empty($registrar['photo_path'])) {
    $profilePhoto = '../public/images/' . ltrim(
        (string) $registrar['photo_path'],
        '/'
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

    <title>Withdraw Student | Registrar Portal</title>

    <link
        rel="icon"
        type="image/webp"
        href="/BKHS/public/image/logo.webp"
    >

    <link
        rel="shortcut icon"
        type="image/webp"
        href="/BKHS/public/image/logo.webp"
    >

    <link
        rel="apple-touch-icon"
        href="/BKHS/public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand i {
            font-size: 27px;
            color: #60a5fa;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 700;
            line-height: 1.2;
        }

        .brand-subtitle {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #9ca3af;
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 16px 12px 30px;
        }

        .nav-link {
            color: #d1d5db;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            margin-bottom: 4px;
            transition: .2s;
        }

        .nav-link:hover,
        .nav-link.active {
            color: #fff;
            background: #2563eb;
        }

        .nav-link i {
            width: 20px;
            font-size: 17px;
            text-align: center;
        }

        .nav-link .arrow {
            margin-left: auto;
            font-size: 13px;
            transition: transform .2s;
        }

        .nav-link[aria-expanded="true"] .arrow {
            transform: rotate(180deg);
        }

        .submenu {
            padding-left: 28px;
            margin-bottom: 5px;
        }

        .submenu .nav-link {
            font-size: 13px;
            padding: 9px 12px;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 78px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 13px;
            margin-top: 3px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 600;
        }

        .profile-role {
            font-size: 11px;
            color: #6b7280;
        }

        .content {
            padding: 28px;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .04);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 20px;
        }

        .card-body {
            padding: 20px;
        }

        .academic-year-card {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 20px;
        }

        .academic-year-icon {
            width: 42px;
            height: 42px;
            border-radius: 9px;
            background: #2563eb;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .academic-year-label {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 2px;
        }

        .academic-year-name {
            color: #1e3a8a;
            font-size: 15px;
            font-weight: 700;
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-box input {
            padding-left: 42px;
            height: 46px;
            border-radius: 9px;
        }

        .btn-primary {
            background: #2563eb;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .student-table th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }

        .student-table td {
            vertical-align: middle;
            font-size: 14px;
        }

        .student-code {
            font-weight: 700;
            color: #2563eb;
        }

        .grade-badge,
        .section-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .grade-badge {
            background: #eef2ff;
            color: #4338ca;
        }

        .section-badge {
            background: #f0fdf4;
            color: #15803d;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 12px;
        }

        .withdraw-modal .modal-content {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
        }

        .withdraw-modal .modal-header {
            background: #fff7ed;
            border-bottom: 1px solid #fed7aa;
        }

        .withdraw-warning {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 9px;
            padding: 13px 15px;
            color: #9a3412;
            font-size: 13px;
        }

        .student-summary {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 18px;
        }

        .student-summary-label {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 3px;
        }

        .student-summary-value {
            font-size: 14px;
            font-weight: 600;
        }

        .main-navigation {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
        }

        .withdrawn-list-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: #374151;
            background: #fff;
            border: 1px solid #d1d5db;
            transition: .2s;
        }

        .withdrawn-list-btn:hover {
            color: #2563eb;
            border-color: #93c5fd;
            background: #eff6ff;
        }

        .withdrawn-list-btn i {
            font-size: 16px;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
            color: #111827;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .5);
            z-index: 1040;
        }

        @media (max-width: 900px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-block;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px 16px;
            }

        }

        @media (max-width: 650px) {

            .profile-name,
            .profile-role {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .search-button {
                width: 100%;
                margin-top: 10px;
            }

            .main-navigation {
                justify-content: stretch;
            }

            .withdrawn-list-btn {
                width: 100%;
                justify-content: center;
            }

        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <i class="bi bi-mortarboard-fill"></i>

        <div>

            <div class="brand-title">
                BKHS
            </div>

            <span class="brand-subtitle">
                Registrar Portal
            </span>

        </div>

    </div>

    <nav class="sidebar-nav">

        <a
            href="dashboard.php"
            class="nav-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#studentsMenu"
            role="button"
            aria-expanded="true"
        >
            <i class="bi bi-people-fill"></i>
            <span>Students</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse show"
            id="studentsMenu"
        >

            <div class="submenu">

                <a
                    href="register.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Register</span>
                </a>

                <a
                    href="students.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="update-student.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update</span>
                </a>

                <a
                    href="delete-student.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-x-fill"></i>
                    <span>Delete</span>
                </a>

                <a
                    href="withdraw-student.php"
                    class="nav-link active"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

            </div>

        </div>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#teachersMenu"
            role="button"
            aria-expanded="false"
        >
            <i class="bi bi-person-video3"></i>
            <span>Teachers</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse"
            id="teachersMenu"
        >

            <div class="submenu">

                <a
                    href="teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="update-teacher.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-gear"></i>
                    <span>Update</span>
                </a>

                <a
                    href="withdraw-teacher.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

                <a
                    href="homeroom-teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-workspace"></i>
                    <span>Homeroom Teachers</span>
                </a>

                <a
                    href="subject-teachers.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-video2"></i>
                    <span>Subject Teachers</span>
                </a>

            </div>

        </div>

        <a
            class="nav-link"
            data-bs-toggle="collapse"
            href="#staffMenu"
            role="button"
            aria-expanded="false"
        >
            <i class="bi bi-person-badge-fill"></i>
            <span>Other Staff</span>
            <i class="bi bi-chevron-down arrow"></i>
        </a>

        <div
            class="collapse"
            id="staffMenu"
        >

            <div class="submenu">

                <a
                    href="add-staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Add Staff</span>
                </a>

                <a
                    href="staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-list-ul"></i>
                    <span>List</span>
                </a>

                <a
                    href="withdraw-staff.php"
                    class="nav-link"
                >
                    <i class="bi bi-person-dash-fill"></i>
                    <span>Withdraw</span>
                </a>

            </div>

        </div>

        <a
            href="certificate.php"
            class="nav-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="Roster.php"
            class="nav-link"
        >
            <i class="bi bi-clipboard2-check-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="Transcript.php"
            class="nav-link"
        >
            <i class="bi bi-file-earmark-text-fill"></i>
            <span>Transcript</span>
        </a>

        <a
            href="profile.php"
            class="nav-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="nav-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<main class="main">

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Withdraw Student
                </h1>

                <div class="page-subtitle">
                    Withdraw a student from the current academic year
                </div>

            </div>

        </div>

        <div class="profile">

            <img
                src="<?= htmlspecialchars(
                    $profilePhoto,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                alt="Registrar"
                onerror="this.src='/BKHS/public/images/default-avatar.png'"
            >

            <div>

                <div class="profile-name">

                    <?= htmlspecialchars(
                        (string) $registrar['full_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

                <div class="profile-role">
                    Registrar
                </div>

            </div>

        </div>

    </header>

    <section class="content">

        <?php if ($successMessage !== ''): ?>

            <div
                class="alert alert-success alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-check-circle-fill me-2"></i>

                <?= htmlspecialchars(
                    $successMessage,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                <a
                    href="withdrawn-students.php"
                    class="alert-link ms-2"
                >
                    View Withdrawn Students
                </a>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>

            <div
                class="alert alert-danger alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= htmlspecialchars(
                    $errorMessage,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Main Page Navigation -->

        <div class="main-navigation">

            <a
                href="withdrawn-students.php"
                class="withdrawn-list-btn"
            >
                <i class="bi bi-person-check-fill"></i>
                <span>View Withdrawn Students</span>
            </a>

        </div>

        <?php if ($activeAcademicYear): ?>

            <div class="academic-year-card">

                <div class="academic-year-icon">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div>

                    <div class="academic-year-label">
                        Active Academic Year
                    </div>

                    <div class="academic-year-name">

                        <?= htmlspecialchars(
                            (string) $activeAcademicYear['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div class="card">

            <div class="card-header">

                <div class="d-flex align-items-center gap-2">

                    <i class="bi bi-search text-primary"></i>

                    <strong>
                        Find Student
                    </strong>

                </div>

            </div>

            <div class="card-body">

                <form
                    method="GET"
                    action="withdraw-student.php"
                >

                    <div class="row g-2 align-items-center">

                        <div class="col-lg-9">

                            <div class="search-box">

                                <i class="bi bi-search"></i>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search by Student ID or full name..."
                                    value="<?= htmlspecialchars(
                                        $search,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    autocomplete="off"
                                >

                            </div>

                        </div>

                        <div class="col-lg-3">

                            <button
                                type="submit"
                                class="btn btn-primary w-100 search-button"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search Student
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <?php if ($search !== ''): ?>

            <div class="card mt-4">

                <div class="card-header">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>

                            <strong>
                                Search Results
                            </strong>

                            <div class="text-muted small mt-1">

                                Current students matching
                                "<strong><?= htmlspecialchars(
                                    $search,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></strong>"

                            </div>

                        </div>

                        <span class="badge text-bg-light">

                            <?= count($students) ?> result(s)

                        </span>

                    </div>

                </div>

                <div class="card-body p-0">

                    <?php if ($students): ?>

                        <div class="table-responsive">

                            <table class="table table-hover student-table mb-0">

                                <thead>

                                    <tr>

                                        <th class="px-4">
                                            Student ID
                                        </th>

                                        <th>
                                            Full Name
                                        </th>

                                        <th>
                                            Grade
                                        </th>

                                        <th>
                                            Section
                                        </th>

                                        <th class="text-end px-4">
                                            Action
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($students as $student): ?>

                                    <tr>

                                        <td class="px-4">

                                            <span class="student-code">

                                                <?= htmlspecialchars(
                                                    (string) $student['student_code'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?= htmlspecialchars(
                                                (string) $student['full_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </td>

                                        <td>

                                            <span class="grade-badge">

                                                <?= htmlspecialchars(
                                                    (string) $student['grade_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="section-badge">

                                                <?= htmlspecialchars(
                                                    (string) $student['section_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </span>

                                        </td>

                                        <td class="text-end px-4">

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#withdrawModal"
                                                data-student-id="<?= (int) $student['id'] ?>"
                                                data-student-code="<?= htmlspecialchars(
                                                    (string) $student['student_code'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                data-student-name="<?= htmlspecialchars(
                                                    (string) $student['full_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                data-grade="<?= htmlspecialchars(
                                                    (string) $student['grade_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                data-section="<?= htmlspecialchars(
                                                    (string) $student['section_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                                <i class="bi bi-person-dash-fill me-1"></i>
                                                Withdraw

                                            </button>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-person-x"></i>

                            <h6 class="fw-semibold">
                                No Current Student Found
                            </h6>

                            <p class="mb-0 small">
                                No student matching your search is currently
                                registered in the active academic year.
                            </p>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php else: ?>

            <div class="card mt-4">

                <div class="empty-state">

                    <i class="bi bi-person-search"></i>

                    <h6 class="fw-semibold">
                        Search for a Student
                    </h6>

                    <p class="mb-0 small">
                        Enter a Student ID or full name to find a student
                        currently registered in the active academic year.
                    </p>

                </div>

            </div>

        <?php endif; ?>

    </section>

</main>

<div
    class="modal fade withdraw-modal"
    id="withdrawModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-person-dash-fill text-danger me-2"></i>

                        Withdraw Student

                    </h5>

                    <div class="small text-muted mt-1">
                        This action affects the student's current enrollment.
                    </div>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form
                method="POST"
                action="withdraw-student.php"
            >

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="withdraw_student"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrfToken,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="student_id"
                        id="withdrawStudentId"
                        value=""
                    >

                    <div class="student-summary">

                        <div class="row g-3">

                            <div class="col-6">

                                <div class="student-summary-label">
                                    Student ID
                                </div>

                                <div
                                    class="student-summary-value"
                                    id="withdrawStudentCode"
                                >
                                    -
                                </div>

                            </div>

                            <div class="col-6">

                                <div class="student-summary-label">
                                    Full Name
                                </div>

                                <div
                                    class="student-summary-value"
                                    id="withdrawStudentName"
                                >
                                    -
                                </div>

                            </div>

                            <div class="col-6">

                                <div class="student-summary-label">
                                    Grade
                                </div>

                                <div
                                    class="student-summary-value"
                                    id="withdrawStudentGrade"
                                >
                                    -
                                </div>

                            </div>

                            <div class="col-6">

                                <div class="student-summary-label">
                                    Section
                                </div>

                                <div
                                    class="student-summary-value"
                                    id="withdrawStudentSection"
                                >
                                    -
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="withdraw-warning mb-3">

                        <i class="bi bi-exclamation-triangle-fill me-1"></i>

                        The student's current registration will be removed,
                        the student account will be disabled, and parent
                        access will be adjusted according to the parent's
                        other active children.

                    </div>

                    <div class="mb-3">

                        <label
                            for="withdrawReason"
                            class="form-label fw-semibold"
                        >

                            Withdrawal Reason

                            <span class="text-danger">*</span>

                        </label>

                        <textarea
                            name="reason"
                            id="withdrawReason"
                            class="form-control"
                            rows="5"
                            maxlength="2000"
                            placeholder="Enter the reason for withdrawal..."
                            required
                        ></textarea>

                        <div class="form-text">
                            Please provide a clear reason for this withdrawal.
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-danger"
                    >

                        <i class="bi bi-person-dash-fill me-1"></i>

                        Confirm Withdrawal

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const mobileMenuBtn =
        document.getElementById('mobileMenuBtn');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    function closeSidebar() {

        sidebar.classList.remove('show');

        sidebarOverlay.classList.remove('show');

    }

    if (mobileMenuBtn) {

        mobileMenuBtn.addEventListener(
            'click',
            function () {

                sidebar.classList.toggle('show');

                sidebarOverlay.classList.toggle('show');

            }
        );

    }

    if (sidebarOverlay) {

        sidebarOverlay.addEventListener(
            'click',
            closeSidebar
        );

    }

    document
        .querySelectorAll('.sidebar .nav-link')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    if (
                        window.innerWidth <= 900 &&
                        !link.hasAttribute('data-bs-toggle')
                    ) {
                        closeSidebar();
                    }

                }
            );

        });

    const withdrawModal =
        document.getElementById('withdrawModal');

    if (withdrawModal) {

        withdrawModal.addEventListener(
            'show.bs.modal',
            function (event) {

                const button = event.relatedTarget;

                if (!button) {
                    return;
                }

                document.getElementById(
                    'withdrawStudentId'
                ).value =
                    button.getAttribute(
                        'data-student-id'
                    ) || '';

                document.getElementById(
                    'withdrawStudentCode'
                ).textContent =
                    button.getAttribute(
                        'data-student-code'
                    ) || '-';

                document.getElementById(
                    'withdrawStudentName'
                ).textContent =
                    button.getAttribute(
                        'data-student-name'
                    ) || '-';

                document.getElementById(
                    'withdrawStudentGrade'
                ).textContent =
                    button.getAttribute(
                        'data-grade'
                    ) || '-';

                document.getElementById(
                    'withdrawStudentSection'
                ).textContent =
                    button.getAttribute(
                        'data-section'
                    ) || '-';

                document.getElementById(
                    'withdrawReason'
                ).value = '';

            }
        );

    }

</script>

</body>

</html>