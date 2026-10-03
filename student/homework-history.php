<?php

declare(strict_types=1);

require_once __DIR__ . '/homework/helpers.php';
require_once __DIR__ . '/homework/data.php';

requireStudent();

$userId = (int) $_SESSION['user_id'];

try {

    /*
    |--------------------------------------------------------------------------
    | Get active student registration
    |--------------------------------------------------------------------------
    */

    $student = getStudentActiveRegistration(
        $conn,
        $userId
    );

    if (!$student) {

        setFlash(
            'danger',
            'Your active student registration could not be found.'
        );

        header('Location: dashboard.php');
        exit;
    }

    $studentId = (int) $student['student_id'];

    $academicYear = (string) $student['academic_year'];

    $grade = (int) $student['grade_number'];

    $section = (string) $student['section'];


    /*
    |--------------------------------------------------------------------------
    | Get undone homework
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | The function expects:
    |
    | $academicYear
    | $grade
    | $section
    | $studentId
    |
    */

    $homeworks = getStudentUndoneHomeworks(
        $conn,
        $academicYear,
        $grade,
        $section,
        $studentId
    );


    /*
    |--------------------------------------------------------------------------
    | Flash message
    |--------------------------------------------------------------------------
    */

    $flash = getFlash();

} catch (Throwable $e) {

    $homeworks = [];

    /*
    |--------------------------------------------------------------------------
    | Show actual error while debugging
    |--------------------------------------------------------------------------
    */

    $flash = [
        'type' => 'danger',
        'message' => 'Unable to load your undone homework: ' . $e->getMessage(),
    ];
}


/*
|--------------------------------------------------------------------------
| Current Gregorian date
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');


/*
|--------------------------------------------------------------------------
| Current page
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Undone Homework | BKHS</title>

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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <style>

        :root {

            --primary: #2563eb;

            --primary-dark: #1d4ed8;

            --bg: #f5f7fb;

            --text: #1f2937;

            --muted: #6c757d;

            --border: #e9ecef;

            --bottom-nav-height: 76px;

        }


        * {
            box-sizing: border-box;
        }


        html {
            min-height: 100%;
        }


        body {

            margin: 0;

            background: var(--bg);

            color: var(--text);

            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

        }


        /*
        |--------------------------------------------------------------------------
        | Navbar
        |--------------------------------------------------------------------------
        */

        .navbar {

            background: #ffffff;

            border-bottom: 1px solid var(--border);

            z-index: 1000;

        }


        .navbar-brand-text {

            color: var(--primary);

            font-weight: 700;

            text-decoration: none;

        }


        .page-title {

            font-weight: 700;

        }


        .student-info {

            color: var(--muted);

            font-size: 0.88rem;

        }


        /*
        |--------------------------------------------------------------------------
        | Homework item
        |--------------------------------------------------------------------------
        */

        .homework-item {

            background: #ffffff;

            border: 1px solid var(--border);

            border-left: 4px solid #dc3545;

            border-radius: 12px;

            padding: 14px 16px;

            box-shadow:
                0 2px 10px rgba(0, 0, 0, 0.04);

            transition:
                box-shadow 0.15s ease,
                transform 0.15s ease;

        }


        .homework-item:hover {

            transform: translateY(-1px);

            box-shadow:
                0 5px 16px rgba(0, 0, 0, 0.07);

        }


        /*
        |--------------------------------------------------------------------------
        | Subject
        |--------------------------------------------------------------------------
        */

        .subject-badge {

            font-size: 0.72rem;

            font-weight: 600;

        }


        /*
        |--------------------------------------------------------------------------
        | Title
        |--------------------------------------------------------------------------
        */

        .homework-title {

            font-size: 1rem;

            font-weight: 650;

            margin: 0;

            line-height: 1.35;

        }


        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        .homework-description {

            color: var(--muted);

            font-size: 0.82rem;

            margin-top: 4px;

            display: -webkit-box;

            -webkit-line-clamp: 1;

            -webkit-box-orient: vertical;

            overflow: hidden;

        }


        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        .homework-meta {

            display: flex;

            flex-wrap: wrap;

            align-items: center;

            gap: 8px 14px;

            margin-top: 8px;

            font-size: 0.76rem;

            color: var(--muted);

        }


        .homework-meta-item {

            display: inline-flex;

            align-items: center;

            gap: 4px;

        }


        /*
        |--------------------------------------------------------------------------
        | Overdue badge
        |--------------------------------------------------------------------------
        */

        .overdue-badge {

            font-size: 0.68rem;

            font-weight: 600;

        }


        /*
        |--------------------------------------------------------------------------
        | Action
        |--------------------------------------------------------------------------
        */

        .homework-action {

            min-width: 120px;

        }


        /*
        |--------------------------------------------------------------------------
        | Empty state
        |--------------------------------------------------------------------------
        */

        .empty-state {

            background: #ffffff;

            border: 2px dashed #dee2e6;

            border-radius: 14px;

            padding: 50px 20px;

            text-align: center;

        }


        .empty-icon {

            font-size: 45px;

            color: #198754;

            margin-bottom: 10px;

        }


        /*
        |--------------------------------------------------------------------------
        | Info box
        |--------------------------------------------------------------------------
        */

        .info-box {

            background: #fff3cd;

            border: 1px solid #ffe69c;

            border-radius: 10px;

            color: #664d03;

            font-size: 0.82rem;

            padding: 10px 13px;

        }


        /*
        |--------------------------------------------------------------------------
        | Count
        |--------------------------------------------------------------------------
        */

        .homework-count {

            font-size: 0.78rem;

            color: var(--muted);

        }


        /*
        |--------------------------------------------------------------------------
        | Mobile Bottom Navigation
        |--------------------------------------------------------------------------
        */

        .mobile-bottom-nav {

            display: none;

        }


        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media screen and (max-width: 767.98px) {

            body {

                padding-bottom:
                    calc(
                        var(--bottom-nav-height)
                        + env(safe-area-inset-bottom)
                    ) !important;

            }


            .container {

                padding-left: 12px;

                padding-right: 12px;

            }


            .homework-item {

                padding: 13px;

            }


            .homework-action {

                width: 100%;

                min-width: 0;

                margin-top: 10px;

            }


            /*
            |--------------------------------------------------------------------------
            | Mobile Bottom Navigation
            |--------------------------------------------------------------------------
            */

            .mobile-bottom-nav {

                position: fixed !important;

                left: 0 !important;

                right: 0 !important;

                bottom: 0 !important;

                width: 100% !important;

                height:
                    calc(
                        var(--bottom-nav-height)
                        + env(safe-area-inset-bottom)
                    ) !important;

                min-height: var(--bottom-nav-height) !important;

                display: flex !important;

                align-items: stretch !important;

                justify-content: space-around !important;

                background: #ffffff !important;

                border-top: 1px solid #e5e7eb !important;

                box-shadow:
                    0 -4px 20px rgba(0, 0, 0, 0.10) !important;

                z-index: 99999 !important;

                padding:
                    5px
                    4px
                    env(safe-area-inset-bottom)
                    4px !important;

                margin: 0 !important;

                visibility: visible !important;

                opacity: 1 !important;

            }


            .mobile-nav-item {

                display: flex !important;

                flex: 1 1 0 !important;

                min-width: 0 !important;

                height: 100% !important;

                flex-direction: column !important;

                align-items: center !important;

                justify-content: center !important;

                gap: 4px !important;

                margin: 0 2px !important;

                padding: 5px 2px !important;

                border-radius: 10px !important;

                text-decoration: none !important;

                color: #6b7280 !important;

                background: transparent !important;

                font-size: 10px !important;

                font-weight: 600 !important;

                visibility: visible !important;

                opacity: 1 !important;

                -webkit-tap-highlight-color: transparent;

            }


            .mobile-nav-item i {

                display: block !important;

                width: auto !important;

                font-size: 21px !important;

                line-height: 1 !important;

                visibility: visible !important;

            }


            .mobile-nav-item span {

                display: block !important;

                max-width: 100% !important;

                white-space: nowrap !important;

                overflow: hidden !important;

                text-overflow: ellipsis !important;

                line-height: 1.1 !important;

                visibility: visible !important;

            }


            .mobile-nav-item.active {

                color: var(--primary) !important;

                background: #eff6ff !important;

            }


            .mobile-nav-item:active {

                transform: scale(.96);

            }

        }


        /*
        |--------------------------------------------------------------------------
        | Very small phones
        |--------------------------------------------------------------------------
        */

        @media screen and (max-width: 380px) {

            :root {

                --bottom-nav-height: 72px;

            }


            .mobile-bottom-nav {

                padding-left: 2px !important;

                padding-right: 2px !important;

            }


            .mobile-nav-item {

                margin: 0 1px !important;

                padding-left: 1px !important;

                padding-right: 1px !important;

                font-size: 9px !important;

                gap: 3px !important;

            }


            .mobile-nav-item i {

                font-size: 19px !important;

            }

        }

    </style>

</head>


<body>


<!--
|--------------------------------------------------------------------------
| Navbar
|--------------------------------------------------------------------------
-->

<nav class="navbar sticky-top">

    <div class="container-fluid px-3 px-md-4">

        <a
            href="dashboard.php"
            class="navbar-brand-text"
        >

            BKHS Student Portal

        </a>


        <div class="d-flex align-items-center gap-2">

            <span class="student-info d-none d-sm-inline">

                <?= e((string) $student['full_name']) ?>

            </span>


            <a
                href="profile.php"
                class="btn btn-sm btn-outline-secondary"
                title="Profile"
            >

                <i class="bi bi-person"></i>

            </a>

        </div>

    </div>

</nav>


<!--
|--------------------------------------------------------------------------
| Main content
|--------------------------------------------------------------------------
-->

<main class="container py-4">


    <!--
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    -->

    <div
        class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3"
    >

        <div>

            <h2 class="page-title mb-1">

                <i class="bi bi-clock-history me-2 text-danger"></i>

                Undone Homework

            </h2>


            <div class="student-info">

                <?= e((string) $student['academic_year']) ?>

                &nbsp;·&nbsp;

                Grade <?= (int) $student['grade_number'] ?>

                &nbsp;·&nbsp;

                Section <?= e((string) $student['section']) ?>

            </div>

        </div>


        <div class="d-flex gap-2 page-actions">

            <a
                href="homework.php"
                class="btn btn-primary btn-sm"
            >

                <i class="bi bi-journal-check me-1"></i>

                Current Homework

            </a>


            <a
                href="dashboard.php"
                class="btn btn-outline-secondary btn-sm"
            >

                <i class="bi bi-arrow-left me-1"></i>

                Dashboard

            </a>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Flash / Error
    |--------------------------------------------------------------------------
    -->

    <?php if ($flash): ?>

        <div
            class="alert alert-<?= e((string) $flash['type']) ?> alert-dismissible fade show py-2"
            role="alert"
        >

            <?= e((string) $flash['message']) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | Information
    |--------------------------------------------------------------------------
    -->

    <div class="info-box mb-3">

        <i class="bi bi-info-circle me-1"></i>

        This page shows homework that is

        <strong>past due</strong>,

        has

        <strong>not been submitted</strong>,

        and is from the

        <strong>last one month</strong>.

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Count
    |--------------------------------------------------------------------------
    -->

    <?php if (!empty($homeworks)): ?>

        <div class="homework-count mb-2">

            <?= count($homeworks) ?>

            undone homework<?= count($homeworks) === 1 ? '' : 's' ?>

        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | Homework list
    |--------------------------------------------------------------------------
    -->

    <?php if (empty($homeworks)): ?>


        <div class="empty-state">

            <div class="empty-icon">

                <i class="bi bi-check-circle"></i>

            </div>


            <h5 class="mb-1">

                No Undone Homework

            </h5>


            <p class="text-muted mb-3">

                You do not have any past-due unsubmitted homework
                from the last one month.

            </p>


            <a
                href="homework.php"
                class="btn btn-primary btn-sm"
            >

                <i class="bi bi-journal-check me-1"></i>

                View Current Homework

            </a>

        </div>


    <?php else: ?>


        <div class="d-flex flex-column gap-2">


            <?php foreach ($homeworks as $homework): ?>

                <?php

                $homeworkId = (int) $homework['id'];

                $dueDate = (string) $homework['due_date'];

                $assignedDate = (string) $homework['assigned_date'];

                ?>


                <div class="homework-item">


                    <div class="row align-items-center g-2">


                        <!--
                        |--------------------------------------------------------------------------
                        | Homework information
                        |--------------------------------------------------------------------------
                        -->

                        <div class="col-12 col-md homework-main">


                            <div
                                class="d-flex flex-wrap align-items-center gap-2 mb-1"
                            >

                                <span class="badge text-bg-primary subject-badge">

                                    <?= e(
                                        (string) $homework['subject_name']
                                    ) ?>

                                </span>


                                <span
                                    class="badge text-bg-danger overdue-badge"
                                >

                                    <i class="bi bi-exclamation-circle me-1"></i>

                                    Not Submitted

                                </span>

                            </div>


                            <h5 class="homework-title">

                                <?= e(
                                    (string) $homework['title']
                                ) ?>

                            </h5>


                            <?php if (
                                trim(
                                    (string) $homework['description']
                                ) !== ''
                            ): ?>

                                <div class="homework-description">

                                    <?= e(
                                        (string) $homework['description']
                                    ) ?>

                                </div>

                            <?php endif; ?>


                            <div class="homework-meta">


                                <span class="homework-meta-item">

                                    <i class="bi bi-person"></i>

                                    <?= e(
                                        (string) $homework['teacher_name']
                                    ) ?>

                                </span>


                                <span class="homework-meta-item">

                                    <i class="bi bi-calendar-plus"></i>

                                    Assigned:

                                    <?= e(
                                        formatEthiopianDate(
                                            $assignedDate
                                        )
                                    ) ?>

                                </span>


                                <span class="homework-meta-item">

                                    <i class="bi bi-calendar-x text-danger"></i>

                                    Due:

                                    <strong class="text-danger">

                                        <?= e(
                                            formatEthiopianDate(
                                                $dueDate
                                            )
                                        ) ?>

                                    </strong>

                                </span>

                            </div>

                        </div>


                        <!--
                        |--------------------------------------------------------------------------
                        | Action
                        |--------------------------------------------------------------------------
                        -->

                        <div class="col-12 col-md-auto">


                            <a
                                href="/BKHS/student/homework/upload.php?homework_id=<?= $homeworkId ?>"
                                class="btn btn-outline-danger btn-sm homework-action"
                            >

                                <i class="bi bi-eye me-1"></i>

                                View Homework

                            </a>


                        </div>


                    </div>


                </div>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>


</main>


<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav
    class="mobile-bottom-nav"
    aria-label="Student mobile navigation"
>

    <a
        href="dashboard.php"
        class="mobile-nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        aria-label="Dashboard"
    >

        <i class="bi bi-grid-1x2-fill"></i>

        <span>Home</span>

    </a>


    <a
        href="subjects.php"
        class="mobile-nav-item <?= $currentPage === 'subjects.php' ? 'active' : '' ?>"
        aria-label="My Subjects"
    >

        <i class="bi bi-book-fill"></i>

        <span>Subjects</span>

    </a>


    <a
        href="materials.php"
        class="mobile-nav-item <?= $currentPage === 'materials.php' ? 'active' : '' ?>"
        aria-label="Materials"
    >

        <i class="bi bi-folder-fill"></i>

        <span>Materials</span>

    </a>


    <a
        href="homework.php"
        class="mobile-nav-item <?= $currentPage === 'homework.php' || $currentPage === 'homework-history.php' ? 'active' : '' ?>"
        aria-label="Homework"
    >

        <i class="bi bi-journal-text"></i>

        <span>Homework</span>

    </a>


    <a
        href="result.php"
        class="mobile-nav-item <?= $currentPage === 'result.php' ? 'active' : '' ?>"
        aria-label="Results"
    >

        <i class="bi bi-bar-chart-fill"></i>

        <span>Results</span>

    </a>

</nav>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>

</html>