<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'parent'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$parentUserId = (int) $_SESSION['user_id'];

$parentName = 'Parent';
$parentPhoto = '';
$parentPhotoUrl = '';

$children = [];
$errorMessage = '';
$academicYearName = '';

try {

    /*
     * ---------------------------------------------------------
     * Get parent information
     * ---------------------------------------------------------
     */

    $parentStmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.phone,
            u.email,
            p.photo
        FROM users AS u
        INNER JOIN parents AS p
            ON p.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'parent'
          AND u.is_deleted = 0
        LIMIT 1
    ");

    if (!$parentStmt) {
        throw new RuntimeException(
            'Failed to prepare parent query.'
        );
    }

    $parentStmt->bind_param(
        'i',
        $parentUserId
    );

    $parentStmt->execute();

    $parentResult = $parentStmt->get_result();

    $parent = $parentResult->fetch_assoc();

    $parentStmt->close();

    if (!$parent) {
        session_unset();
        session_destroy();

        header('Location: ../auth/login.php');
        exit;
    }

    $parentName = trim(
        (string) ($parent['full_name'] ?? 'Parent')
    );

    if ($parentName === '') {
        $parentName = 'Parent';
    }

    /*
     * ---------------------------------------------------------
     * Parent photo
     * ---------------------------------------------------------
     */

    $parentPhoto = trim(
        (string) ($parent['photo'] ?? '')
    );

    if ($parentPhoto !== '') {
        $parentPhotoUrl = '../' . ltrim(
            $parentPhoto,
            '/'
        );
    }


    /*
     * ---------------------------------------------------------
     * Get active academic year
     * ---------------------------------------------------------
     */

    $academicYearStmt = $conn->prepare("
        SELECT
            id,
            name
        FROM academic_years
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$academicYearStmt) {
        throw new RuntimeException(
            'Failed to prepare academic year query.'
        );
    }

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    $activeAcademicYear =
        $academicYearResult->fetch_assoc();

    $academicYearStmt->close();

    if (!$activeAcademicYear) {
        throw new RuntimeException(
            'No active academic year was found.'
        );
    }

    $academicYearId =
        (int) $activeAcademicYear['id'];

    $academicYearName =
        (string) $activeAcademicYear['name'];


    /*
     * ---------------------------------------------------------
     * Get parent's children
     *
     * users.id
     *     ↓
     * parents.user_id
     *     ↓
     * parents.id
     *     ↓
     * student_parents.parent_id
     *     ↓
     * students.id
     * ---------------------------------------------------------
     */

    $childrenStmt = $conn->prepare("
        SELECT
            s.id AS student_id,
            s.student_code,
            s.full_name,
            sp.relationship,

            sr.id AS registration_id,

            g.grade_number,
            sec.code AS section

        FROM parents p

        INNER JOIN student_parents sp
            ON sp.parent_id = p.id

        INNER JOIN students s
            ON s.id = sp.student_id

        INNER JOIN student_registrations sr
            ON sr.student_id = s.id
            AND sr.academic_year_id = ?

        INNER JOIN grades g
            ON g.id = sr.grade_id

        INNER JOIN sections sec
            ON sec.id = sr.section_id

        INNER JOIN users u
            ON u.id = s.user_id

        WHERE p.user_id = ?
          AND sp.is_account_access = 1
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'

        ORDER BY
            g.grade_number ASC,
            sec.code ASC,
            s.full_name ASC
    ");

    if (!$childrenStmt) {
        throw new RuntimeException(
            'Failed to prepare children query.'
        );
    }

    $childrenStmt->bind_param(
        'ii',
        $academicYearId,
        $parentUserId
    );

    $childrenStmt->execute();

    $childrenResult =
        $childrenStmt->get_result();

    while ($row = $childrenResult->fetch_assoc()) {
        $children[] = $row;
    }

    $childrenStmt->close();

} catch (Throwable $e) {

    error_log(
        'Parent dashboard error: ' .
        $e->getMessage()
    );

    $errorMessage =
        'Unable to load the dashboard right now.';
}


/*
 * -------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------
 */

function h(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function gradeLabel(int $grade): string
{
    return 'Grade ' . $grade;
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
        Parent Dashboard - BKHS
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;

            --background: #f5f7fb;
            --white: #ffffff;

            --text: #111827;
            --text-secondary: #6b7280;

            --border: #e5e7eb;

            --sidebar-width: 250px;
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: var(--background);
            color: var(--text);

            min-height: 100vh;
        }

        .app {
            min-height: 100vh;
        }

        /*
         * ------------------------------------------------------
         * Sidebar
         * ------------------------------------------------------
         */

        .sidebar {
            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            width: var(--sidebar-width);

            background: var(--white);

            border-right: 1px solid var(--border);

            z-index: 1000;

            transition:
                transform 0.25s ease;
        }

        .main {
            margin-left: var(--sidebar-width);

            min-height: 100vh;
        }

        .sidebar-header {
            height: 72px;

            padding: 0 20px;

            display: flex;
            align-items: center;

            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;

            text-decoration: none;
            color: var(--text);
        }

        .brand-logo {
            width: 38px;
            height: 38px;

            border-radius: 9px;

            background: var(--primary);

            color: #ffffff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 15px;
            font-weight: 800;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-size: 16px;
            font-weight: 800;
        }

        .brand-role {
            font-size: 11px;
            color: var(--text-secondary);

            margin-top: 2px;
        }

        .nav {
            padding: 18px 12px;
        }

        .nav-label {
            padding: 0 10px 9px;

            font-size: 11px;
            font-weight: 700;

            color: #9ca3af;

            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;

            width: 100%;

            padding: 11px 12px;

            margin-bottom: 4px;

            border-radius: 8px;

            color: #4b5563;

            text-decoration: none;

            font-size: 14px;
            font-weight: 600;

            transition: 0.2s ease;
        }

        .nav-item:hover {
            background: #f3f4f6;
            color: var(--text);
        }

        .nav-item.active {
            background: #eff6ff;
            color: var(--primary);
        }

        .nav-icon {
            width: 20px;

            text-align: center;

            font-size: 17px;
        }

        .logout {
            position: absolute;

            left: 12px;
            right: 12px;
            bottom: 18px;

            color: #dc2626;
        }

        .logout:hover {
            background: #fef2f2;
            color: #b91c1c;
        }

        /*
         * ------------------------------------------------------
         * Topbar
         * ------------------------------------------------------
         */

        .topbar {
            height: 72px;

            padding: 0 28px;

            background: var(--white);

            border-bottom: 1px solid var(--border);

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .menu-button {
            display: none;

            border: none;

            background: transparent;

            font-size: 24px;

            cursor: pointer;

            color: var(--text);
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
        }

        .parent-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .parent-avatar {
            width: 38px;
            height: 38px;

            border-radius: 50%;

            background: #eff6ff;
            color: var(--primary);

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 14px;
            font-weight: 800;

            overflow: hidden;
        }

        .parent-avatar img {
            width: 100%;
            height: 100%;

            object-fit: cover;

            display: block;
        }

        .parent-name {
            font-size: 14px;
            font-weight: 600;
        }

        /*
         * ------------------------------------------------------
         * Content
         * ------------------------------------------------------
         */

        .content {
            width: min(
                100% - 48px,
                1100px
            );

            margin: 0 auto;

            padding: 32px 0 50px;
        }

        .welcome {
            margin-bottom: 28px;
        }

        .welcome h1 {
            font-size: 27px;
            line-height: 1.3;

            margin-bottom: 7px;
        }

        .welcome p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /*
         * ------------------------------------------------------
         * Section header
         * ------------------------------------------------------
         */

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            margin-bottom: 15px;
        }

        .section-title {
            font-size: 19px;
            font-weight: 700;
        }

        .academic-year {
            font-size: 13px;
            color: var(--text-secondary);

            background: #ffffff;

            border: 1px solid var(--border);

            border-radius: 7px;

            padding: 7px 11px;
        }

        /*
         * ------------------------------------------------------
         * Children grid
         * ------------------------------------------------------
         */

        .children-grid {
            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(300px, 1fr)
                );

            gap: 18px;
        }

        .child-card {
            background: var(--white);

            border: 1px solid var(--border);

            border-radius: 12px;

            padding: 20px;

            box-shadow:
                0 2px 8px
                rgba(0, 0, 0, 0.03);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .child-card:hover {
            transform: translateY(-2px);

            box-shadow:
                0 5px 18px
                rgba(0, 0, 0, 0.07);
        }

        .child-top {
            display: flex;
            align-items: center;

            gap: 14px;

            margin-bottom: 18px;
        }

        .child-avatar {
            width: 52px;
            height: 52px;

            flex-shrink: 0;

            border-radius: 50%;

            background: #eff6ff;

            color: var(--primary);

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 19px;
            font-weight: 800;
        }

        .child-name {
            font-size: 17px;
            font-weight: 700;

            margin-bottom: 4px;
        }

        .child-code {
            font-size: 12px;

            color: var(--text-secondary);
        }

        .child-details {
            border-top: 1px solid #eef0f3;

            padding-top: 16px;

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 14px;
        }

        .detail-label {
            display: block;

            font-size: 11px;

            color: #9ca3af;

            margin-bottom: 4px;

            text-transform: uppercase;

            letter-spacing: 0.03em;
        }

        .detail-value {
            font-size: 14px;

            font-weight: 600;

            color: #374151;
        }

        .relationship {
            margin-top: 15px;

            font-size: 12px;

            color: var(--text-secondary);
        }

        /*
         * ------------------------------------------------------
         * Child button
         * ------------------------------------------------------
         */

        .child-actions {
            margin-top: 18px;

            padding-top: 16px;

            border-top: 1px solid #eef0f3;
        }

        .view-child {
            display: flex;

            align-items: center;
            justify-content: center;

            gap: 7px;

            width: 100%;

            padding: 10px 14px;

            border-radius: 8px;

            background: var(--primary);

            color: #ffffff;

            text-decoration: none;

            font-size: 13px;

            font-weight: 700;

            transition: 0.2s ease;
        }

        .view-child:hover {
            background: var(--primary-dark);
        }

        /*
         * ------------------------------------------------------
         * Empty state
         * ------------------------------------------------------
         */

        .empty-card {
            background: var(--white);

            border: 1px solid var(--border);

            border-radius: 12px;

            padding: 55px 20px;

            text-align: center;
        }

        .empty-icon {
            width: 58px;
            height: 58px;

            margin: 0 auto 15px;

            border-radius: 50%;

            background: #f3f4f6;

            color: #6b7280;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 25px;
        }

        .empty-card h3 {
            font-size: 17px;

            margin-bottom: 7px;
        }

        .empty-card p {
            font-size: 13px;

            color: var(--text-secondary);

            margin-bottom: 0;
        }

        /*
         * ------------------------------------------------------
         * Error
         * ------------------------------------------------------
         */

        .error-card {
            background: #fef2f2;

            border: 1px solid #fecaca;

            color: #b91c1c;

            padding: 14px 16px;

            border-radius: 8px;

            font-size: 14px;
        }

        /*
         * ------------------------------------------------------
         * Overlay
         * ------------------------------------------------------
         */

        .sidebar-overlay {
            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(0, 0, 0, 0.35);

            z-index: 999;
        }

        /*
         * ------------------------------------------------------
         * Mobile
         * ------------------------------------------------------
         */

        @media (max-width: 800px) {

            .sidebar {
                transform:
                    translateX(-100%);
            }

            .sidebar.open {
                transform:
                    translateX(0);
            }

            .sidebar-overlay.active {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .menu-button {
                display: block;
            }

            .topbar {
                padding: 0 16px;
            }

            .content {
                width: min(
                    100% - 28px,
                    1100px
                );

                padding-top: 25px;
            }

            .parent-name {
                display: none;
            }
        }

        @media (max-width: 550px) {

            .welcome h1 {
                font-size: 23px;
            }

            .section-header {
                align-items: flex-start;

                flex-direction: column;

                gap: 10px;
            }

            .children-grid {
                grid-template-columns: 1fr;
            }

            .child-card {
                padding: 17px;
            }

            .child-details {
                grid-template-columns: 1fr 1fr;
            }
        }

    </style>

</head>

<body>

<div class="app">

    <!-- =====================================================
         Sidebar
         ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="sidebar-header">

            <a
                href="dashboard.php"
                class="brand"
            >

                <div class="brand-logo">
                    BK
                </div>

                <div class="brand-text">

                    <span class="brand-name">
                        BKHS
                    </span>

                    <span class="brand-role">
                        Parent Portal
                    </span>

                </div>

            </a>

        </div>

        <nav class="nav">

            <div class="nav-label">
                Menu
            </div>

            <a
                href="dashboard.php"
                class="nav-item active"
            >

                <span class="nav-icon">
                    🏠
                </span>

                <span>
                    Dashboard
                </span>

            </a>

            <a
                href="children.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    👨‍👩‍👧
                </span>

                <span>
                    My Children
                </span>

            </a>

            <a
                href="homework.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    📝
                </span>

                <span>
                    Homework
                </span>

            </a>

            <a
                href="announcements.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    📢
                </span>

                <span>
                    Announcements
                </span>

            </a>

            <a
                href="profile.php"
                class="nav-item"
            >

                <span class="nav-icon">
                    👤
                </span>

                <span>
                    Profile
                </span>

            </a>

            <a
                href="../auth/logout.php"
                class="nav-item logout"
            >

                <span class="nav-icon">
                    🚪
                </span>

                <span>
                    Logout
                </span>

            </a>

        </nav>

    </aside>


    <!-- Sidebar overlay -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- =====================================================
         Main
         ====================================================== -->

    <main class="main">

        <!-- Topbar -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="menu-button"
                    id="menuButton"
                    aria-label="Open menu"
                >
                    ☰
                </button>

                <span class="topbar-title">
                    Parent Dashboard
                </span>

            </div>


            <div class="parent-info">

                <div class="parent-avatar">

                    <?php if ($parentPhotoUrl !== ''): ?>

                        <img
                            src="<?= h($parentPhotoUrl) ?>"
                            alt="Parent photo"
                        >

                    <?php else: ?>

                        <?= h(
                            strtoupper(
                                substr(
                                    $parentName,
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    <?php endif; ?>

                </div>

                <span class="parent-name">
                    <?= h($parentName) ?>
                </span>

            </div>

        </header>


        <!-- Content -->

        <div class="content">

            <!-- Welcome -->

            <section class="welcome">

                <h1>
                    Welcome, <?= h($parentName) ?>
                </h1>

                <p>
                    Here's an overview of your children's
                    school information.
                </p>

            </section>


            <?php if ($errorMessage !== ''): ?>

                <div class="error-card">
                    <?= h($errorMessage) ?>
                </div>

            <?php else: ?>

                <!-- Children -->

                <section>

                    <div class="section-header">

                        <h2 class="section-title">
                            My Children
                        </h2>

                        <span class="academic-year">

                            Academic Year:
                            <?= h($academicYearName) ?>

                        </span>

                    </div>


                    <?php if (empty($children)): ?>

                        <div class="empty-card">

                            <div class="empty-icon">
                                👨‍👩‍👧
                            </div>

                            <h3>
                                No children found
                            </h3>

                            <p>
                                No children with active account
                                access were found for the
                                current academic year.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="children-grid">

                            <?php foreach (
                                $children
                                as $child
                            ): ?>

                                <?php

                                $childName =
                                    trim(
                                        (string)
                                        $child['full_name']
                                    );

                                $firstLetter =
                                    strtoupper(
                                        substr(
                                            $childName,
                                            0,
                                            1
                                        )
                                    );

                                $grade =
                                    (int)
                                    $child['grade_number'];

                                $section =
                                    (string)
                                    $child['section'];

                                $relationship =
                                    (string)
                                    $child['relationship'];

                                ?>

                                <article
                                    class="child-card"
                                >

                                    <div class="child-top">

                                        <div
                                            class="child-avatar"
                                        >
                                            <?= h(
                                                $firstLetter
                                            ) ?>
                                        </div>


                                        <div>

                                            <div
                                                class="child-name"
                                            >
                                                <?= h(
                                                    $childName
                                                ) ?>
                                            </div>

                                            <div
                                                class="child-code"
                                            >
                                                <?= h(
                                                    (string)
                                                    $child['student_code']
                                                ) ?>
                                            </div>

                                        </div>

                                    </div>


                                    <div class="child-details">

                                        <div>

                                            <span
                                                class="detail-label"
                                            >
                                                Grade
                                            </span>

                                            <span
                                                class="detail-value"
                                            >
                                                <?= h(
                                                    gradeLabel(
                                                        $grade
                                                    )
                                                ) ?>
                                            </span>

                                        </div>


                                        <div>

                                            <span
                                                class="detail-label"
                                            >
                                                Section
                                            </span>

                                            <span
                                                class="detail-value"
                                            >
                                                <?= h(
                                                    $section
                                                ) ?>
                                            </span>

                                        </div>


                                        <div>

                                            <span
                                                class="detail-label"
                                            >
                                                Academic Year
                                            </span>

                                            <span
                                                class="detail-value"
                                            >
                                                <?= h(
                                                    $academicYearName
                                                ) ?>
                                            </span>

                                        </div>


                                        <div>

                                            <span
                                                class="detail-label"
                                            >
                                                Relationship
                                            </span>

                                            <span
                                                class="detail-value"
                                            >
                                                <?= h(
                                                    $relationship
                                                ) ?>
                                            </span>

                                        </div>

                                    </div>


                                    <div class="child-actions">

                                        <a
                                            href="children.php?student_id=<?= (int) $child['student_id'] ?>"
                                            class="view-child"
                                        >

                                            View Child

                                            <span>
                                                →
                                            </span>

                                        </a>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>


<script>

    const menuButton =
        document.getElementById('menuButton');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');


    function openSidebar() {

        sidebar.classList.add('open');

        sidebarOverlay.classList.add('active');

    }


    function closeSidebar() {

        sidebar.classList.remove('open');

        sidebarOverlay.classList.remove('active');

    }


    if (menuButton) {

        menuButton.addEventListener(
            'click',
            openSidebar
        );

    }


    if (sidebarOverlay) {

        sidebarOverlay.addEventListener(
            'click',
            closeSidebar
        );

    }


    document
        .querySelectorAll('.sidebar .nav-item')
        .forEach(function (item) {

            item.addEventListener(
                'click',
                closeSidebar
            );

        });

</script>

</body>

</html>