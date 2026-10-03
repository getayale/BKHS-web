<?php

session_start();


// =====================================================
// AUTHENTICATION CHECK
// =====================================================

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: ../auth/login.php');
    exit;
}


// =====================================================
// ROLE CHECK
// =====================================================

if (
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}


$fullName = $_SESSION['full_name'] ?? 'Administrator';

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
        content="Bole Kale Hiwot School Management System - Admin Dashboard"
    >

    <title>Admin Dashboard | BKHS</title>

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
            class="sidebar-link active"
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
            href="#"
            class="sidebar-link"
        >

            <i class="bi bi-megaphone"></i>

            <span>
                Announcements
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
     MAIN CONTENT
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
                Dashboard
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
                        substr($fullName, 0, 1)
                    );
                    ?>

                </div>

                <div class="profile-info">

                    <strong>
                        <?php echo htmlspecialchars($fullName); ?>
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


        <!-- Welcome -->

        <section class="welcome-section">

            <div>

               

             

            </div>

            <div class="welcome-date">

                <i class="bi bi-calendar3"></i>

                <?php echo date('F d, Y'); ?>

            </div>

        </section>



        <!-- =================================================
             STATISTICS
        ================================================== -->

        <section class="dashboard-stats">

            <div class="stat-card">

                <div class="stat-icon students">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>

                <div class="stat-content">

                    <span>
                        Total Students
                    </span>

                    <strong>
                        0
                    </strong>

                    <small>
                        <i class="bi bi-arrow-up"></i>
                        Current enrollment
                    </small>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon teachers">
                    <i class="bi bi-person-workspace"></i>
                </div>

                <div class="stat-content">

                    <span>
                        Teachers
                    </span>

                    <strong>
                        0
                    </strong>

                    <small>
                        Active teaching staff
                    </small>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon parents">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div class="stat-content">

                    <span>
                        Parents
                    </span>

                    <strong>
                        0
                    </strong>

                    <small>
                        Registered parents
                    </small>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon classes">
                    <i class="bi bi-building"></i>
                </div>

                <div class="stat-content">

                    <span>
                        Classes
                    </span>

                    <strong>
                        0
                    </strong>

                    <small>
                        Active sections
                    </small>

                </div>

            </div>

        </section>



        <!-- =================================================
             DASHBOARD GRID
        ================================================== -->

        <section class="dashboard-grid">


            <!-- Recent Activity -->

            <div class="dashboard-card activity-card">

                <div class="card-header">

                    <div>

                        <span class="card-eyebrow">
                            OVERVIEW
                        </span>

                        <h3>
                            Recent Activity
                        </h3>

                    </div>

                    <button
                        class="card-action"
                        type="button"
                    >
                        View all
                    </button>

                </div>


                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-activity"></i>
                    </div>

                    <h4>
                        No recent activity
                    </h4>

                    <p>
                        School activities will appear here.
                    </p>

                </div>

            </div>



            <!-- Quick Actions -->

            <div class="dashboard-card quick-card">

                <div class="card-header">

                    <div>

                        <span class="card-eyebrow">
                            ACTIONS
                        </span>

                        <h3>
                            Quick Actions
                        </h3>

                    </div>

                </div>


                <div class="quick-actions">

                    <a href="#" class="quick-action">

                        <span class="quick-action-icon">
                            <i class="bi bi-person-plus"></i>
                        </span>

                        <span>
                            Add User
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="#" class="quick-action">

                        <span class="quick-action-icon">
                            <i class="bi bi-mortarboard"></i>
                        </span>

                        <span>
                            Add Student
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="#" class="quick-action">

                        <span class="quick-action-icon">
                            <i class="bi bi-megaphone"></i>
                        </span>

                        <span>
                            Create Announcement
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="#" class="quick-action">

                        <span class="quick-action-icon">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                        </span>

                        <span>
                            Generate Report
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>

                </div>

            </div>

        </section>


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