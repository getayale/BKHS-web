<?php

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

$userId = (int) $_SESSION['user_id'];

$registrar = null;
$students = [];
$search = trim((string) ($_GET['search'] ?? ''));
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Load Registrar
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            r.photo
        FROM users u
        LEFT JOIN registrars r
            ON r.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'registrar'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare registrar query: ' . $conn->error
        );
    }

    $stmt->bind_param('i', $userId);

    if (!$stmt->execute()) {
        throw new Exception(
            'Failed to load registrar information: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    $registrar = $result->fetch_assoc();

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Validate Registrar
    |--------------------------------------------------------------------------
    */

    if (!$registrar) {

        session_destroy();

        header('Location: ../auth/login.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Search Students
    |--------------------------------------------------------------------------
    |
    | Transcript search intentionally uses ONLY:
    |
    | 1. Student Code
    | 2. Full Name
    |
    | Academic Year and Semester are NOT search criteria.
    |
    */

    if ($search !== '') {

        $searchTerm = '%' . $search . '%';

        $stmt = $conn->prepare("
            SELECT
                id,
                student_code,
                full_name
            FROM students
            WHERE is_deleted = 0
              AND (
                    student_code LIKE ?
                    OR full_name LIKE ?
              )
            ORDER BY full_name ASC
            LIMIT 50
        ");

        if (!$stmt) {
            throw new Exception(
                'Failed to prepare student search: ' . $conn->error
            );
        }

        $stmt->bind_param(
            'ss',
            $searchTerm,
            $searchTerm
        );

        if (!$stmt->execute()) {
            throw new Exception(
                'Failed to search students: ' . $stmt->error
            );
        }

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();
    }

} catch (Throwable $e) {

    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Registrar Display Information
|--------------------------------------------------------------------------
*/

$registrarName = e(
    $registrar['full_name'] ?? 'Registrar'
);

/*
|--------------------------------------------------------------------------
| Registrar Profile Photo
|--------------------------------------------------------------------------
*/

$photoPath = '../public/images/default-avatar.png';

if (!empty($registrar['photo'])) {

    $candidate = '../' . ltrim(
        (string) $registrar['photo'],
        '/'
    );

    if (file_exists($candidate)) {
        $photoPath = $candidate;
    }
}

$photoPath = e($photoPath);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Transcript | Registrar Portal</title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Bootstrap 5.3.3 -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Inter -->

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Transcript Styles -->

    <link
        rel="stylesheet"
        href="transcript-style.php"
    >

</head>

<body>

<div class="app">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <!-- Brand -->

        <div class="brand">

            <div class="brand-icon">

                <i class="bi bi-mortarboard-fill"></i>

            </div>

            <div>

                <div class="brand-title">
                    School Management
                </div>

                <div class="brand-subtitle">
                    Registrar Portal
                </div>

            </div>

        </div>

        <!-- Navigation -->

        <nav class="sidebar-menu">

            <div class="menu-label">
                Main Menu
            </div>

            <a
                href="dashboard.php"
                class="nav-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="register.php"
                class="nav-link"
            >
                <i class="bi bi-person-plus-fill"></i>
                <span>Register</span>
            </a>

            <a
                href="delete-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-x-fill"></i>
                <span>Delete Student</span>
            </a>

            <a
                href="student-record.php"
                class="nav-link"
            >
                <i class="bi bi-people-fill"></i>
                <span>Student Record</span>
            </a>

            <a
                href="update-student.php"
                class="nav-link"
            >
                <i class="bi bi-person-gear"></i>
                <span>Update Student</span>
            </a>

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
                <i class="bi bi-list-check"></i>
                <span>Roster</span>
            </a>

            <!-- Transcript -->

            <a
                href="Transcript.php"
                class="nav-link active"
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

            <div style="height: 15px;"></div>

            <a
                href="../auth/logout.php"
                class="nav-link logout-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </nav>

    </aside>

    <!-- Sidebar Overlay -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>

    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="main">

        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu"
                    id="mobileMenu"
                    aria-label="Open menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>

                    <h1 class="page-title">
                        Student Transcript
                    </h1>

                    <div class="page-subtitle">
                        Search and generate student transcripts
                    </div>

                </div>

            </div>

            <!-- Registrar Profile -->

            <div class="top-profile">

                <img
                    src="<?= $photoPath ?>"
                    alt="Registrar"
                >

                <div>

                    <div class="top-profile-name">
                        <?= $registrarName ?>
                    </div>

                    <div class="top-profile-role">
                        Registrar
                    </div>

                </div>

            </div>

        </header>

        <!-- =================================================
             CONTENT
        ================================================== -->

        <div class="content">

            <!-- Error -->

            <?php if ($errorMessage): ?>

                <div
                    class="alert alert-danger error-alert"
                    role="alert"
                >

                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                    <?= e($errorMessage) ?>

                </div>

            <?php endif; ?>

            <!-- =================================================
                 INTRO
            ================================================== -->

            <section class="transcript-intro">

                <div class="intro-icon">

                    <i class="bi bi-file-earmark-text-fill"></i>

                </div>

                <div>

                    <h2>
                        Student Transcript
                    </h2>

                    <p>
                        Search for a student by Student Code or
                        Full Name to view and print their complete
                        academic transcript.
                    </p>

                </div>

            </section>

            <!-- =================================================
                 SEARCH
            ================================================== -->

            <section class="search-card">

                <div class="section-heading">

                    <div class="section-heading-icon">

                        <i class="bi bi-person-search"></i>

                    </div>

                    <div>

                        <h2>
                            Search Student
                        </h2>

                        <p>
                            Enter the student's Student Code or Full Name.
                        </p>

                    </div>

                </div>

                <!--
                ==================================================
                IMPORTANT:
                Transcript search contains ONLY ONE FIELD.

                There is NO:
                - Academic Year
                - Semester
                - Academic Year dropdown
                - Semester dropdown
                ==================================================
                -->

                <form
                    method="GET"
                    action="Transcript.php"
                    class="search-form"
                >

                    <div class="search-input-wrapper">

                        <i class="bi bi-search"></i>

                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Student Code or Full Name"
                            autocomplete="off"
                            autofocus
                        >

                    </div>

                    <button
                        type="submit"
                        class="search-button"
                    >

                        <i class="bi bi-search"></i>

                        Search

                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="Transcript.php"
                            class="clear-button"
                        >

                            <i class="bi bi-x-lg"></i>

                            Clear

                        </a>

                    <?php endif; ?>

                </form>

            </section>

            <!-- =================================================
                 SEARCH RESULTS
            ================================================== -->

            <?php if ($search !== ''): ?>

                <section class="results-card">

                    <!-- Results Header -->

                    <div class="results-header">

                        <div>

                            <h2>
                                Search Results
                            </h2>

                            <p>
                                Students matching your search
                            </p>

                        </div>

                        <div class="result-count">

                            <?= count($students) ?>

                            <span>
                                Found
                            </span>

                        </div>

                    </div>

                    <!-- No Results -->

                    <?php if (empty($students)): ?>

                        <div class="empty-state">

                            <div class="empty-icon">

                                <i class="bi bi-person-x"></i>

                            </div>

                            <h3>
                                No Student Found
                            </h3>

                            <p>

                                No student was found matching

                                <strong>
                                    <?= e($search) ?>
                                </strong>.

                            </p>

                            <p class="empty-hint">
                                Try searching with a different
                                Student Code or Full Name.
                            </p>

                        </div>

                    <?php else: ?>

                        <!-- Results Table -->

                        <div class="table-responsive">

                            <table class="students-table">

                                <thead>

                                    <tr>

                                        <th>
                                            #
                                        </th>

                                        <th>
                                            Student Code
                                        </th>

                                        <th>
                                            Full Name
                                        </th>

                                        <th class="text-end">
                                            Action
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach (
                                    $students as $index => $student
                                ): ?>

                                    <tr>

                                        <!-- Number -->

                                        <td>

                                            <span class="row-number">
                                                <?= $index + 1 ?>
                                            </span>

                                        </td>

                                        <!-- Student Code -->

                                        <td>

                                            <span class="student-code">

                                                <?= e(
                                                    $student['student_code']
                                                ) ?>

                                            </span>

                                        </td>

                                        <!-- Student Name -->

                                        <td>

                                            <div class="student-name">

                                                <div class="student-avatar">

                                                    <i class="bi bi-person-fill"></i>

                                                </div>

                                                <strong>

                                                    <?= e(
                                                        $student['full_name']
                                                    ) ?>

                                                </strong>

                                            </div>

                                        </td>

                                        <!-- Action -->

                                        <td class="text-end">

                                            <a
                                                href="transcript-view.php?student_id=<?= (int) $student['id'] ?>"
                                                class="view-button"
                                            >

                                                <i class="bi bi-file-earmark-text"></i>

                                                View Full Transcript

                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </section>

            <?php else: ?>

                <!-- =================================================
                     INITIAL STATE
                ================================================== -->

                <section class="results-card">

                    <div class="empty-state">

                        <div class="empty-icon">

                            <i class="bi bi-search"></i>

                        </div>

                        <h3>
                            Search for a Student
                        </h3>

                        <p>
                            Enter a Student Code or Full Name above
                            to view the student's complete transcript.
                        </p>

                    </div>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

<!-- =========================================================
     MOBILE SIDEBAR SCRIPT
========================================================== -->

<script>

const mobileMenu =
    document.getElementById('mobileMenu');

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');


mobileMenu.addEventListener(
    'click',
    function () {

        sidebar.classList.toggle('show');

        sidebarOverlay.classList.toggle('show');

    }
);


sidebarOverlay.addEventListener(
    'click',
    function () {

        sidebar.classList.remove('show');

        sidebarOverlay.classList.remove('show');

    }
);

</script>

</body>

</html>