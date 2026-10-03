<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get Registrar Information
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        email,
        phone,
        photo_path
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'registrar'
      AND is_deleted = 0
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$registrar = $result->fetch_assoc();

$stmt->close();

if (!$registrar) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Registrar Information
|--------------------------------------------------------------------------
*/

$fullName = $registrar['full_name'] ?? 'Registrar';
$email = $registrar['email'] ?? '';
$photo = $registrar['photo_path'] ?? null;

$photoUrl = '../public/images/default-avatar.png';

if (!empty($photo)) {
    $photoUrl = '../' . ltrim($photo, '/');
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

    <title>Student Registration | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

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

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            color: #172033;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: transform .28s ease;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Brand
        |--------------------------------------------------------------------------
        */

        .sidebar-brand {
            height: 76px;
            min-height: 76px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 12px;
            background: #2563eb;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 20px;
            box-shadow: 0 6px 16px rgba(37, 99, 235, .25);
        }

        .brand-title {
            font-size: 17px;
            line-height: 1.2;
            font-weight: 800;
            color: #fff;
            margin: 0;
        }

        .brand-subtitle {
            font-size: 10px;
            line-height: 1.3;
            color: #9ca3af;
            margin-top: 4px;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Menu
        |--------------------------------------------------------------------------
        */

        .sidebar-menu {
            flex: 1;
            padding: 20px 12px 15px;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-menu::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-menu::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, .12);
            border-radius: 20px;
        }

        .sidebar-menu::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, .2);
        }

        .menu-label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .09em;
            padding: 0 12px;
            margin: 3px 0 9px;
        }

        /*
        |--------------------------------------------------------------------------
        | Navigation Item
        |--------------------------------------------------------------------------
        */

        .menu-item {
            position: relative;
            width: 100%;
            min-height: 44px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin: 3px 0;
            border-radius: 9px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition:
                background .2s ease,
                color .2s ease,
                transform .2s ease;
        }

        .menu-item i {
            width: 22px;
            min-width: 22px;
            text-align: center;
            font-size: 17px;
            color: #94a3b8;
            transition: color .2s ease;
        }

        .menu-item span {
            white-space: nowrap;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, .065);
            color: #fff;
        }

        .menu-item:hover i {
            color: #fff;
        }

        .menu-item.active {
            background: #2563eb;
            color: #fff;
            box-shadow: 0 5px 14px rgba(37, 99, 235, .22);
        }

        .menu-item.active i {
            color: #fff;
        }

        .menu-item.active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 9px;
            bottom: 9px;
            width: 3px;
            background: #fff;
            border-radius: 0 4px 4px 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Dropdown Parent
        |--------------------------------------------------------------------------
        */

        .dropdown-parent {
            cursor: pointer;
            border: 0;
            background: transparent;
            text-align: left;
        }

        .dropdown-parent .dropdown-arrow {
            width: auto;
            min-width: auto;
            margin-left: auto;
            font-size: 12px;
            transition: transform .2s ease;
        }

        .dropdown-parent.expanded .dropdown-arrow {
            transform: rotate(180deg);
        }

        /*
        |--------------------------------------------------------------------------
        | Submenu
        |--------------------------------------------------------------------------
        */

        .submenu {
            display: block;
            margin: 2px 0 7px 17px;
            padding-left: 12px;
            border-left: 1px solid rgba(255, 255, 255, .10);
        }

        .submenu-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 38px;
            padding: 8px 10px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition:
                background .2s ease,
                color .2s ease;
        }

        .submenu-item i {
            width: 18px;
            min-width: 18px;
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }

        .submenu-item:hover {
            background: rgba(255, 255, 255, .055);
            color: #fff;
        }

        .submenu-item:hover i {
            color: #fff;
        }

        .submenu-item.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .submenu-item.active i {
            color: #60a5fa;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar Footer
        |--------------------------------------------------------------------------
        */

        .sidebar-footer {
            flex-shrink: 0;
            padding: 14px;
            border-top: 1px solid rgba(255, 255, 255, .08);
            background: #0f172a;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            padding: 3px;
        }

        .profile-mini img {
            width: 40px;
            height: 40px;
            min-width: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, .15);
            background: #1f2937;
        }

        .profile-mini-info {
            min-width: 0;
            flex: 1;
        }

        .profile-mini-name {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-mini-role {
            color: #94a3b8;
            font-size: 10px;
            margin-top: 3px;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0;
            line-height: 1.2;
        }

        .page-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #64748b;
        }

        .topbar-right > i {
            font-size: 20px;
        }

        .topbar-user {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .mobile-menu {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            background: #fff;
            color: #111827;
            font-size: 21px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s ease;
        }

        .mobile-menu:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 30px;
            max-width: 1500px;
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Introduction
        |--------------------------------------------------------------------------
        */

        .registration-intro {
            background: linear-gradient(
                135deg,
                #1d4ed8,
                #2563eb
            );
            color: #fff;
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(37, 99, 235, .15);
        }

        .intro-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .intro-icon {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 16px;
            background: rgba(255, 255, 255, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 27px;
        }

        .intro-title {
            margin: 0 0 6px;
            font-size: 24px;
            font-weight: 800;
        }

        .intro-description {
            margin: 0;
            color: rgba(255, 255, 255, .82);
            font-size: 13px;
            line-height: 1.7;
            max-width: 850px;
        }

        /*
        |--------------------------------------------------------------------------
        | Registration Cards
        |--------------------------------------------------------------------------
        */

        .registration-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
        }

        .registration-card {
            position: relative;
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 28px;
            text-decoration: none;
            color: inherit;
            overflow: hidden;
            transition:
                transform .25s ease,
                box-shadow .25s ease,
                border-color .25s ease;
        }

        .registration-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .25s ease;
        }

        .new-card::before {
            background: #2563eb;
        }

        .returning-card::before {
            background: #16a34a;
        }

        .registration-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 18px 40px rgba(15, 23, 42, .08);
        }

        .new-card:hover {
            border-color: #bfdbfe;
        }

        .returning-card:hover {
            border-color: #bbf7d0;
        }

        .registration-card:hover::before {
            transform: scaleX(1);
        }

        /*
        |--------------------------------------------------------------------------
        | Card Icon
        |--------------------------------------------------------------------------
        */

        .registration-icon {
            width: 62px;
            height: 62px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 22px;
        }

        .new-card .registration-icon {
            background: #eff6ff;
            color: #2563eb;
        }

        .returning-card .registration-icon {
            background: #f0fdf4;
            color: #16a34a;
        }

        /*
        |--------------------------------------------------------------------------
        | Card Content
        |--------------------------------------------------------------------------
        */

        .registration-card h2 {
            margin: 0 0 9px;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .registration-card-description {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 22px;
        }

        /*
        |--------------------------------------------------------------------------
        | Features
        |--------------------------------------------------------------------------
        */

        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0 0 26px;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #4b5563;
            font-size: 12px;
            margin-bottom: 12px;
        }

        .feature-list li:last-child {
            margin-bottom: 0;
        }

        .feature-list i {
            font-size: 13px;
        }

        .new-card .feature-list i {
            color: #2563eb;
        }

        .returning-card .feature-list i {
            color: #16a34a;
        }

        /*
        |--------------------------------------------------------------------------
        | Card Footer
        |--------------------------------------------------------------------------
        */

        .registration-card-footer {
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .registration-action {
            font-size: 12px;
            font-weight: 700;
        }

        .new-card .registration-action {
            color: #2563eb;
        }

        .returning-card .registration-action {
            color: #16a34a;
        }

        .arrow-button {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .2s ease;
        }

        .new-card .arrow-button {
            background: #eff6ff;
            color: #2563eb;
        }

        .returning-card .arrow-button {
            background: #f0fdf4;
            color: #16a34a;
        }

        .registration-card:hover .arrow-button {
            transform: translateX(4px);
        }

        /*
        |--------------------------------------------------------------------------
        | Information
        |--------------------------------------------------------------------------
        */

        .registration-info {
            margin-top: 24px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 17px 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .registration-info-icon {
            color: #2563eb;
            font-size: 17px;
            margin-top: 1px;
        }

        .registration-info p {
            margin: 0;
            color: #64748b;
            font-size: 11px;
            line-height: 1.7;
        }

        .registration-info strong {
            color: #334155;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile Overlay
        |--------------------------------------------------------------------------
        */

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .48);
            z-index: 1040;
        }

        /*
        |--------------------------------------------------------------------------
        | Tablet
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
                box-shadow: 10px 0 35px rgba(0, 0, 0, .15);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: inline-flex;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 22px;
            }

            .topbar-user {
                display: none;
            }

            .registration-grid {
                grid-template-columns: 1fr;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 576px) {

            .sidebar {
                width: 280px;
            }

            .content {
                padding: 14px;
            }

            .topbar {
                height: 70px;
                padding: 0 14px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .registration-intro {
                padding: 20px;
                border-radius: 14px;
            }

            .intro-content {
                align-items: flex-start;
                gap: 14px;
            }

            .intro-icon {
                width: 50px;
                height: 50px;
                border-radius: 13px;
                font-size: 21px;
            }

            .intro-title {
                font-size: 18px;
            }

            .intro-description {
                font-size: 12px;
                line-height: 1.6;
            }

            .registration-card {
                padding: 22px;
                border-radius: 15px;
            }

            .registration-card h2 {
                font-size: 18px;
            }

            .registration-info {
                padding: 15px;
            }
        }

    </style>

</head>

<body>

<!-- ============================================================
     MOBILE OVERLAY
============================================================ -->

<div
    class="mobile-overlay"
    id="mobileOverlay"
></div>


<!-- ============================================================
     SIDEBAR
============================================================ -->

<aside
    class="sidebar"
    id="sidebar"
>

    <!-- BRAND -->

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>

            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                Registrar Portal
            </div>

        </div>

    </div>


    <!-- MENU -->

    <div class="sidebar-menu">

        <div class="menu-label">
            Main Menu
        </div>


        <!-- Dashboard -->

        <a
            href="dashboard.php"
            class="menu-item"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <!-- =====================================================
             STUDENTS
        ====================================================== -->

        <button
            type="button"
            class="menu-item dropdown-parent expanded"
            id="studentsToggle"
        >

            <i class="bi bi-people-fill"></i>

            <span>
                Students
            </span>

            <i class="bi bi-chevron-down dropdown-arrow"></i>

        </button>


        <div
            class="submenu"
            id="studentsSubmenu"
        >

            <!-- Register -->

            <a
                href="register.php"
                class="submenu-item active"
            >

                <i class="bi bi-person-plus-fill"></i>

                <span>
                    Register
                </span>

            </a>


            <!-- List -->

            <a
                href="student-list.php"
                class="submenu-item"
            >

                <i class="bi bi-list-ul"></i>

                <span>
                    List
                </span>

            </a>


            <!-- Update -->

            <a
                href="update-student.php"
                class="submenu-item"
            >

                <i class="bi bi-person-gear"></i>

                <span>
                    Update
                </span>

            </a>


            <!-- Delete -->

            <a
                href="delete-student.php"
                class="submenu-item"
            >

                <i class="bi bi-person-x-fill"></i>

                <span>
                    Delete
                </span>

            </a>


            <!-- Withdraw -->

            <a
                href="withdraw-student.php"
                class="submenu-item"
            >

                <i class="bi bi-person-dash-fill"></i>

                <span>
                    Withdraw
                </span>

            </a>

        </div>


        <!-- =====================================================
             TEACHERS
        ====================================================== -->

        <button
            type="button"
            class="menu-item dropdown-parent"
            id="teachersToggle"
        >

            <i class="bi bi-person-video3"></i>

            <span>
                Teachers
            </span>

            <i class="bi bi-chevron-down dropdown-arrow"></i>

        </button>


        <div
            class="submenu"
            id="teachersSubmenu"
            style="display: none;"
        >

            <!-- List -->

            <a
                href="teacher-list.php"
                class="submenu-item"
            >

                <i class="bi bi-list-ul"></i>

                <span>
                    List
                </span>

            </a>


            <!-- Update -->

            <a
                href="update-teacher.php"
                class="submenu-item"
            >

                <i class="bi bi-person-gear"></i>

                <span>
                    Update
                </span>

            </a>


            <!-- Withdraw -->

            <a
                href="withdraw-teacher.php"
                class="submenu-item"
            >

                <i class="bi bi-person-dash-fill"></i>

                <span>
                    Withdraw
                </span>

            </a>


            <!-- Homeroom -->

            <a
                href="homeroom.php"
                class="submenu-item"
            >

                <i class="bi bi-house-door-fill"></i>

                <span>
                    Homeroom
                </span>

            </a>


            <!-- Subject -->

            <a
                href="teacher-subject.php"
                class="submenu-item"
            >

                <i class="bi bi-book-fill"></i>

                <span>
                    Subject
                </span>

            </a>

        </div>


        <!-- =====================================================
             OTHER STAFF
        ====================================================== -->

        <button
            type="button"
            class="menu-item dropdown-parent"
            id="staffToggle"
        >

            <i class="bi bi-person-badge"></i>

            <span>
                Other Staff
            </span>

            <i class="bi bi-chevron-down dropdown-arrow"></i>

        </button>


        <div
            class="submenu"
            id="staffSubmenu"
            style="display: none;"
        >

            <!-- Add -->

            <a
                href="add-staff.php"
                class="submenu-item"
            >

                <i class="bi bi-person-plus-fill"></i>

                <span>
                    Add
                </span>

            </a>


            <!-- List -->

            <a
                href="staff-list.php"
                class="submenu-item"
            >

                <i class="bi bi-list-ul"></i>

                <span>
                    List
                </span>

            </a>


            <!-- Withdraw -->

            <a
                href="withdraw-staff.php"
                class="submenu-item"
            >

                <i class="bi bi-person-dash-fill"></i>

                <span>
                    Withdraw
                </span>

            </a>

        </div>


        <!-- =====================================================
             ACADEMIC RECORDS
        ====================================================== -->

        <div class="menu-label mt-3">
            Academic Records
        </div>


        <!-- Certificate -->

        <a
            href="certificates.php"
            class="menu-item"
        >

            <i class="bi bi-award-fill"></i>

            <span>
                Certificate
            </span>

        </a>


        <!-- Roster -->

        <a
            href="Roster.php"
            class="menu-item"
        >

            <i class="bi bi-clipboard2-check"></i>

            <span>
                Roster
            </span>

        </a>


        <!-- Transcript -->

        <a
            href="Transcript.php"
            class="menu-item"
        >

            <i class="bi bi-file-earmark-text-fill"></i>

            <span>
                Transcript
            </span>

        </a>


        <!-- =====================================================
             ACCOUNT
        ====================================================== -->

        <div class="menu-label mt-3">
            Account
        </div>


        <!-- Profile -->

        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile
            </span>

        </a>


        <!-- Logout -->

        <a
            href="../auth/logout.php"
            class="menu-item"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


    <!-- ========================================================
         SIDEBAR PROFILE
    ========================================================= -->

    <div class="sidebar-footer">

        <div class="profile-mini">

            <img
                src="<?= e($photoUrl) ?>"
                alt="Registrar"
                onerror="this.src='../public/images/default-avatar.png'"
            >

            <div class="profile-mini-info">

                <div class="profile-mini-name">
                    <?= e($fullName) ?>
                </div>

                <div class="profile-mini-role">
                    Registrar
                </div>

            </div>

        </div>

    </div>

</aside>


<!-- ============================================================
     MAIN
============================================================ -->

<main class="main">


    <!-- ========================================================
         TOPBAR
    ========================================================= -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
                aria-label="Open navigation"
            >

                <i class="bi bi-list"></i>

            </button>


            <div>

                <h1 class="page-title">
                    Student Registration
                </h1>

                <div class="page-subtitle">
                    Register new and returning students
                </div>

            </div>

        </div>


        <div class="topbar-right">

            <i class="bi bi-person-circle"></i>

            <span class="topbar-user">
                <?= e($fullName) ?>
            </span>

        </div>

    </header>


    <!-- ========================================================
         CONTENT
    ========================================================= -->

    <div class="content">


        <!-- ====================================================
             INTRO
        ==================================================== -->

        <section class="registration-intro">

            <div class="intro-content">

                <div class="intro-icon">

                    <i class="bi bi-person-vcard-fill"></i>

                </div>


                <div>

                    <h2 class="intro-title">
                        Student Registration
                    </h2>

                    <p class="intro-description">

                        Choose the appropriate registration type to begin.
                        New students receive a permanent BKHS Student ID,
                        while returning students continue their academic
                        journey using their existing Student ID.

                    </p>

                </div>

            </div>

        </section>


        <!-- ====================================================
             REGISTRATION OPTIONS
        ==================================================== -->

        <section class="registration-grid">


            <!-- NEW STUDENT -->

            <a
                href="newStudentRegiter.php"
                class="registration-card new-card"
            >

                <div class="registration-icon">

                    <i class="bi bi-person-plus-fill"></i>

                </div>


                <h2>
                    New Student
                </h2>


                <p class="registration-card-description">

                    Register a student who is joining BKHS
                    for the first time. The complete student
                    admission and academic registration process
                    will be completed here.

                </p>


                <ul class="feature-list">

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Create permanent BKHS Student ID

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Register student personal information

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Register parent or guardian information

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Record previous school information

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Register academic year, grade and section

                    </li>

                </ul>


                <div class="registration-card-footer">

                    <span class="registration-action">
                        Start New Registration
                    </span>

                    <span class="arrow-button">

                        <i class="bi bi-arrow-right"></i>

                    </span>

                </div>

            </a>


            <!-- RETURNING STUDENT -->

            <a
                href="returning.php"
                class="registration-card returning-card"
            >

                <div class="registration-icon">

                    <i class="bi bi-person-check-fill"></i>

                </div>


                <h2>
                    Returning Student
                </h2>


                <p class="registration-card-description">

                    Register a student who already has a BKHS
                    Student ID. Existing student and parent
                    information will remain unchanged.

                </p>


                <ul class="feature-list">

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Search using existing Student ID

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Keep the student's permanent ID

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Select the new academic year

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Assign grade and section

                    </li>

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Preserve previous academic history

                    </li>

                </ul>


                <div class="registration-card-footer">

                    <span class="registration-action">
                        Continue Returning Registration
                    </span>

                    <span class="arrow-button">

                        <i class="bi bi-arrow-right"></i>

                    </span>

                </div>

            </a>

        </section>


        <!-- ====================================================
             INFORMATION
        ==================================================== -->

        <div class="registration-info">

            <i
                class="bi bi-info-circle-fill registration-info-icon"
            ></i>


            <p>

                <strong>Permanent Student ID:</strong>

                Every BKHS student receives one permanent
                Student ID in the format
                <strong>BKHS-STU-000000</strong>.

                The same ID is used throughout the student's
                entire academic journey, including when the
                student moves to another grade or repeats a grade.

            </p>

        </div>

    </div>

</main>


<!-- ============================================================
     BOOTSTRAP
============================================================ -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const mobileMenu =
    document.getElementById('mobileMenu');

const sidebar =
    document.getElementById('sidebar');

const mobileOverlay =
    document.getElementById('mobileOverlay');


function openSidebar() {

    sidebar.classList.add('open');

    mobileOverlay.classList.add('show');

    document.body.style.overflow = 'hidden';

}


function closeSidebar() {

    sidebar.classList.remove('open');

    mobileOverlay.classList.remove('show');

    document.body.style.overflow = '';

}


if (mobileMenu) {

    mobileMenu.addEventListener(
        'click',
        openSidebar
    );

}


if (mobileOverlay) {

    mobileOverlay.addEventListener(
        'click',
        closeSidebar
    );

}


/*
|--------------------------------------------------------------------------
| Dropdown Helper
|--------------------------------------------------------------------------
*/

function setupDropdown(toggleId, submenuId) {

    const toggle =
        document.getElementById(toggleId);

    const submenu =
        document.getElementById(submenuId);


    if (!toggle || !submenu) {
        return;
    }


    toggle.addEventListener(
        'click',
        function () {

            const isExpanded =
                toggle.classList.contains('expanded');


            if (isExpanded) {

                toggle.classList.remove('expanded');

                submenu.style.display = 'none';

            } else {

                toggle.classList.add('expanded');

                submenu.style.display = 'block';

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Students Dropdown
|--------------------------------------------------------------------------
*/

setupDropdown(
    'studentsToggle',
    'studentsSubmenu'
);


/*
|--------------------------------------------------------------------------
| Teachers Dropdown
|--------------------------------------------------------------------------
*/

setupDropdown(
    'teachersToggle',
    'teachersSubmenu'
);


/*
|--------------------------------------------------------------------------
| Other Staff Dropdown
|--------------------------------------------------------------------------
*/

setupDropdown(
    'staffToggle',
    'staffSubmenu'
);


/*
|--------------------------------------------------------------------------
| Close Sidebar After Selecting Link on Mobile
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.menu-item, .submenu-item')
    .forEach(function (item) {

        item.addEventListener(
            'click',
            function () {

                if (window.innerWidth <= 991) {
                    closeSidebar();
                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Close Sidebar When Resizing to Desktop
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'resize',
    function () {

        if (window.innerWidth > 991) {
            closeSidebar();
        }

    }
);

</script>

</body>

</html>