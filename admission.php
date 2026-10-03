<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

$schoolName = 'Bole Kale Hiwot School';
$pageTitle  = 'Admission | ' . $schoolName;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars($pageTitle) ?></title>


    <!-- =====================================================
         FAVICON
    ====================================================== -->

    <link
        rel="icon"
        type="image/webp"
        href="public/image/logo.webp"
    >

    <link
        rel="shortcut icon"
        type="image/webp"
        href="public/image/logo.webp"
    >


    <!-- =====================================================
         GOOGLE FONTS
    ====================================================== -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- =====================================================
         BOOTSTRAP 5.3.3
    ====================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =====================================================
         BOOTSTRAP ICONS
    ====================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <!-- =====================================================
         MAIN WEBSITE CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="public/css/style.css"
    >


    <style>

        :root {
            --bkhs-primary: #2563eb;
            --bkhs-primary-dark: #1d4ed8;
            --bkhs-text: #172033;
            --bkhs-muted: #64748b;
            --bkhs-border: #e5e7eb;
            --bkhs-light: #f8fafc;
        }


        /* =====================================================
           GENERAL
        ====================================================== */

        body {
            font-family: 'Inter', sans-serif;
            color: var(--bkhs-text);
            background: #fff;
        }


        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }


        /* =====================================================
           NAVBAR
        ====================================================== */

        .navbar {
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }


        .navbar-brand img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }


        .navbar-brand span {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800;
            color: var(--bkhs-text);
        }


        .nav-link {
            font-weight: 500;
            color: #475569 !important;
            transition: all .2s ease;
        }


        .nav-link:hover,
        .nav-link.active {
            color: var(--bkhs-primary) !important;
        }


        .btn-primary {
            background: var(--bkhs-primary);
            border-color: var(--bkhs-primary);
        }


        .btn-primary:hover {
            background: var(--bkhs-primary-dark);
            border-color: var(--bkhs-primary-dark);
        }


        /* =====================================================
           HERO
        ====================================================== */

        .admission-hero {
            position: relative;
            overflow: hidden;

            padding: 90px 0 85px;

            background:
                radial-gradient(
                    circle at 90% 10%,
                    rgba(37, 99, 235, .12),
                    transparent 35%
                ),
                radial-gradient(
                    circle at 5% 90%,
                    rgba(59, 130, 246, .08),
                    transparent 30%
                ),
                #f8fafc;
        }


        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            padding: 8px 15px;

            border-radius: 50px;

            background: #eff6ff;
            color: var(--bkhs-primary);

            font-size: .85rem;
            font-weight: 700;

            margin-bottom: 20px;
        }


        .admission-hero h1 {
            font-size: clamp(2.2rem, 5vw, 4rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -.04em;
        }


        .admission-hero h1 span {
            color: var(--bkhs-primary);
        }


        .hero-description {
            max-width: 780px;

            color: var(--bkhs-muted);

            font-size: 1.08rem;
            line-height: 1.8;
        }


        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;

            margin-top: 30px;
        }


        /* =====================================================
           SECTIONS
        ====================================================== */

        .section-padding {
            padding: 85px 0;
        }


        .section-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 7px 14px;

            border-radius: 50px;

            background: #eff6ff;
            color: var(--bkhs-primary);

            font-size: .8rem;
            font-weight: 700;

            margin-bottom: 14px;
        }


        .section-title {
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -.03em;
        }


        .section-description {
            color: var(--bkhs-muted);
            line-height: 1.8;
            max-width: 760px;
        }


        /* =====================================================
           ADMISSION CARDS
        ====================================================== */

        .admission-card {
            height: 100%;

            border: 1px solid var(--bkhs-border);
            border-radius: 22px;

            background: #fff;

            box-shadow:
                0 10px 30px rgba(15, 23, 42, .05);

            transition:
                transform .25s ease,
                box-shadow .25s ease;
        }


        .admission-card:hover {
            transform: translateY(-5px);

            box-shadow:
                0 18px 40px rgba(15, 23, 42, .09);
        }


        .admission-card-body {
            padding: 32px;
        }


        .card-icon {
            width: 58px;
            height: 58px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 16px;

            font-size: 1.5rem;

            margin-bottom: 20px;
        }


        .icon-blue {
            background: #eff6ff;
            color: var(--bkhs-primary);
        }


        .icon-green {
            background: #ecfdf5;
            color: #059669;
        }


        .icon-orange {
            background: #fff7ed;
            color: #ea580c;
        }


        .icon-purple {
            background: #f5f3ff;
            color: #7c3aed;
        }


        .admission-card h4 {
            font-weight: 800;
            margin-bottom: 12px;
        }


        .admission-card p {
            color: var(--bkhs-muted);
            line-height: 1.75;
        }


        /* =====================================================
           ADMISSION TYPE BADGE
        ====================================================== */

        .admission-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            padding: 6px 11px;

            margin-bottom: 16px;

            border-radius: 50px;

            background: #eff6ff;
            color: #1d4ed8;

            font-size: .72rem;
            font-weight: 800;

            text-transform: uppercase;
            letter-spacing: .03em;
        }


        .admission-type-badge.orange {
            background: #fff7ed;
            color: #c2410c;
        }


        /* =====================================================
           REQUIREMENT LIST
        ====================================================== */

        .requirement-list {
            list-style: none;

            padding: 0;
            margin: 0;
        }


        .requirement-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;

            margin-bottom: 16px;

            color: #475569;

            line-height: 1.65;
        }


        .requirement-list li:last-child {
            margin-bottom: 0;
        }


        .requirement-list i {
            color: var(--bkhs-primary);

            font-size: 1.05rem;

            margin-top: 3px;

            flex-shrink: 0;
        }


        /* =====================================================
           CURRENT MARKS BOX
        ====================================================== */

        .current-marks-box {
            display: flex;
            align-items: flex-start;
            gap: 14px;

            margin-top: 24px;
            padding: 18px;

            border: 1px solid #fed7aa;
            border-radius: 16px;

            background:
                linear-gradient(
                    135deg,
                    #fff7ed,
                    #fffdf9
                );
        }


        .current-marks-icon {
            width: 44px;
            height: 44px;

            display: flex;
            align-items: center;
            justify-content: center;

            flex-shrink: 0;

            border-radius: 12px;

            background: #ffedd5;
            color: #ea580c;

            font-size: 1.2rem;
        }


        .current-marks-box h6 {
            font-weight: 800;
            color: #9a3412;

            margin-bottom: 5px;
        }


        .current-marks-box p {
            margin: 0;

            color: #475569;

            font-size: .94rem;
            line-height: 1.65;
        }


        /* =====================================================
           ENTRANCE EXAM BOX
        ====================================================== */

        .entrance-exam-box {
            display: flex;
            align-items: flex-start;
            gap: 14px;

            margin-top: 24px;
            padding: 18px;

            border: 1px solid #bfdbfe;
            border-radius: 16px;

            background:
                linear-gradient(
                    135deg,
                    #eff6ff,
                    #f8fbff
                );
        }


        .entrance-exam-icon {
            width: 44px;
            height: 44px;

            display: flex;
            align-items: center;
            justify-content: center;

            flex-shrink: 0;

            border-radius: 12px;

            background: #dbeafe;
            color: var(--bkhs-primary);

            font-size: 1.2rem;
        }


        .entrance-exam-box h6 {
            font-weight: 800;
            color: #1e3a8a;

            margin-bottom: 5px;
        }


        .entrance-exam-box p {
            margin: 0;

            color: #475569;

            font-size: .94rem;
            line-height: 1.65;
        }


        /* =====================================================
           PROCESS
        ====================================================== */

        .process-card {
            position: relative;

            height: 100%;

            padding: 30px;

            border: 1px solid var(--bkhs-border);
            border-radius: 20px;

            background: #fff;

            transition:
                transform .25s ease,
                box-shadow .25s ease;
        }


        .process-card:hover {
            transform: translateY(-4px);

            box-shadow:
                0 15px 35px rgba(15, 23, 42, .07);
        }


        .process-number {
            width: 44px;
            height: 44px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background: var(--bkhs-primary);
            color: #fff;

            font-weight: 800;

            margin-bottom: 20px;
        }


        .process-card h5 {
            font-weight: 800;
        }


        .process-card p {
            color: var(--bkhs-muted);

            line-height: 1.7;

            margin-bottom: 0;
        }


        /* =====================================================
           EXAM PROCESS CARD
        ====================================================== */

        .process-card.exam-process {
            border-color: #bfdbfe;

            background:
                linear-gradient(
                    135deg,
                    #eff6ff,
                    #ffffff
                );
        }


        .process-card.exam-process .process-number {
            background: #1d4ed8;
        }


        .process-card.exam-process h5 {
            color: #1e3a8a;
        }


        .exam-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;

            padding: 5px 10px;

            margin-bottom: 12px;

            border-radius: 50px;

            background: #dbeafe;
            color: #1d4ed8;

            font-size: .72rem;
            font-weight: 800;
        }


        /* =====================================================
           IMPORTANT NOTICE
        ====================================================== */

        .notice-box {
            border: 0;

            border-radius: 22px;

            background:
                linear-gradient(
                    135deg,
                    #eff6ff,
                    #f8fafc
                );

            padding: 30px;
        }


        .notice-icon {
            width: 52px;
            height: 52px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 14px;

            background: #dbeafe;
            color: var(--bkhs-primary);

            font-size: 1.4rem;

            flex-shrink: 0;
        }


        .notice-list {
            padding-left: 0;

            list-style: none;

            margin-bottom: 0;
        }


        .notice-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;

            margin-bottom: 15px;

            color: #475569;

            line-height: 1.7;
        }


        .notice-list li:last-child {
            margin-bottom: 0;
        }


        .notice-list i {
            color: var(--bkhs-primary);

            margin-top: 4px;

            flex-shrink: 0;
        }


        /* =====================================================
           CTA
        ====================================================== */

        .admission-cta {
            padding: 75px 0;

            background:
                linear-gradient(
                    135deg,
                    #1d4ed8,
                    #2563eb
                );

            color: #fff;
        }


        .admission-cta p {
            color: rgba(255, 255, 255, .82);

            line-height: 1.8;
        }


        .btn-light {
            color: var(--bkhs-primary);
            font-weight: 700;
        }


        /* =====================================================
           FOOTER
        ====================================================== */

        footer {
            background: #0f172a;
            color: #cbd5e1;
        }


        footer a {
            color: #cbd5e1;

            text-decoration: none;

            transition: color .2s ease;
        }


        footer a:hover {
            color: #fff;
        }


        .footer-brand img {
            width: 55px;
            height: 55px;

            object-fit: contain;

            background: #fff;

            border-radius: 12px;

            padding: 5px;
        }


        .social-link {
            width: 38px;
            height: 38px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 10px;

            background: rgba(255, 255, 255, .08);

            color: #fff;
        }


        .social-link:hover {
            background: rgba(255, 255, 255, .15);
            color: #fff;
        }


        /* =====================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 991.98px) {

            .admission-hero {
                padding: 70px 0;
            }


            .section-padding {
                padding: 65px 0;
            }

        }


        @media (max-width: 575.98px) {

            .admission-hero {
                padding: 55px 0;
            }


            .section-padding {
                padding: 55px 0;
            }


            .admission-card-body,
            .process-card,
            .notice-box {
                padding: 24px;
            }


            .hero-actions .btn {
                width: 100%;
            }


            .entrance-exam-box,
            .current-marks-box {
                padding: 16px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg bg-white sticky-top">

    <div class="container py-2">


        <a
            class="navbar-brand d-flex align-items-center gap-2"
            href="index.php"
        >

            <img
                src="public/image/logo.webp"
                alt="<?= htmlspecialchars($schoolName) ?> Logo"
            >

            <span>
                <?= htmlspecialchars($schoolName) ?>
            </span>

        </a>


        <button
            class="navbar-toggler border-0 shadow-none"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
            aria-controls="mainNavbar"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >

            <i class="bi bi-list fs-2"></i>

        </button>


        <div
            class="collapse navbar-collapse"
            id="mainNavbar"
        >

            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="index.php"
                    >
                        Home
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="about.php"
                    >
                        About
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link active"
                        aria-current="page"
                        href="admission.php"
                    >
                        Admission
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="announcements.php"
                    >
                        Announcements
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="gallery.php"
                    >
                        Gallery
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link"
                        href="contact.php"
                    >
                        Contact
                    </a>

                </li>


                <li class="nav-item ms-lg-2 mt-3 mt-lg-0">

                    <a
                        class="btn btn-primary rounded-pill px-4"
                        href="auth/login.php"
                    >

                        <i class="bi bi-box-arrow-in-right me-1"></i>

                        Login

                    </a>

                </li>

            </ul>

        </div>

    </div>

</nav>


<!-- =========================================================
     HERO
========================================================= -->

<section class="admission-hero">

    <div class="container">

        <div class="row align-items-center">

            <div class="col-lg-10">


                <div class="hero-badge">

                    <i class="bi bi-mortarboard-fill"></i>

                    New Student Admission

                </div>


                <h1 class="mb-4">

                    Start Your Journey at
                    <span>Bole Kale Hiwot School</span>

                </h1>


                <p class="hero-description mb-0">

                    Bole Kale Hiwot School welcomes new students throughout
                    the academic year. Admission may take place before the
                    beginning of a new academic year or during an ongoing
                    academic year. In both cases, previous school clearance
                    is required and the student must meet the school's
                    admission requirements and pass the entrance examination.

                </p>


                <div class="hero-actions">


                    <a
                        href="#admission-types"
                        class="btn btn-primary btn-lg rounded-pill px-4"
                    >

                        <i class="bi bi-arrow-down-circle me-2"></i>

                        Admission Types

                    </a>


                    <a
                        href="contact.php"
                        class="btn btn-outline-dark btn-lg rounded-pill px-4"
                    >

                        <i class="bi bi-telephone me-2"></i>

                        Contact School

                    </a>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     ADMISSION TYPES
========================================================= -->

<section
    class="section-padding"
    id="admission-types"
>

    <div class="container">


        <div class="text-center mb-5">


            <span class="section-label">

                <i class="bi bi-people-fill"></i>

                Admission Types

            </span>


            <h2 class="section-title mb-3">

                Two Admission Periods for New Students

            </h2>


            <p class="section-description mx-auto mb-0">

                New students may apply either before the beginning of a new
                academic year or while the academic year is already in
                progress. The requirements differ slightly depending on when
                the student joins the school.

            </p>

        </div>


        <div class="row g-4">


            <!-- =================================================
                 TYPE 1
            ================================================== -->

            <div class="col-lg-6">

                <div class="admission-card">

                    <div class="admission-card-body">


                        <div class="card-icon icon-blue">

                            <i class="bi bi-calendar2-plus-fill"></i>

                        </div>


                        <span class="admission-type-badge">

                            <i class="bi bi-calendar-check"></i>

                            Before Academic Year Begins

                        </span>


                        <h4>

                            Admission Before the Academic Year

                        </h4>


                        <p>

                            This type of admission is for new students who
                            want to join Bole Kale Hiwot School before the
                            beginning of a new academic year.

                        </p>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Previous school clearance is required
                                    before admission.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must have successfully
                                    completed the previous grade or academic
                                    year.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Previous school academic records should
                                    be provided where required.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Promotion or completion information may
                                    be required for grade placement.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must take the school's
                                    entrance examination.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must pass the entrance
                                    examination before admission is
                                    finalized.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Final placement is subject to available
                                    space and school requirements.

                                </span>

                            </li>

                        </ul>


                        <div class="entrance-exam-box">


                            <div class="entrance-exam-icon">

                                <i class="bi bi-pencil-square"></i>

                            </div>


                            <div>


                                <h6>
                                    Entrance Examination Required
                                </h6>


                                <p>

                                    New students applying before the
                                    beginning of the academic year must take
                                    and pass the entrance examination.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 TYPE 2
            ================================================== -->

            <div class="col-lg-6">

                <div class="admission-card">

                    <div class="admission-card-body">


                        <div class="card-icon icon-orange">

                            <i class="bi bi-calendar-range-fill"></i>

                        </div>


                        <span class="admission-type-badge orange">

                            <i class="bi bi-calendar-event"></i>

                            During the Academic Year

                        </span>


                        <h4>

                            Admission During the Academic Year

                        </h4>


                        <p>

                            This type of admission is for new students who
                            want to join Bole Kale Hiwot School after the
                            academic year has already started.

                        </p>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Previous school clearance is required
                                    before admission.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must provide the academic
                                    record available from the previous
                                    school.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student's grades and marks from the
                                    current academic year are required.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Current academic-year results are
                                    reviewed to help determine the
                                    appropriate grade and academic placement.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must take the school's
                                    entrance examination.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    The student must pass the entrance
                                    examination before admission is
                                    finalized.

                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>

                                    Final placement is subject to available
                                    space and school requirements.

                                </span>

                            </li>

                        </ul>


                        <div class="current-marks-box">


                            <div class="current-marks-icon">

                                <i class="bi bi-bar-chart-fill"></i>

                            </div>


                            <div>


                                <h6>
                                    Current Academic-Year Marks Required
                                </h6>


                                <p>
Students joining during the academic year must provide their grades and marks from their previous school.
 These records help the school determine the appropriate academic placement.


                                </p>

                            </div>

                        </div>


                        <div class="entrance-exam-box">


                            <div class="entrance-exam-icon">

                                <i class="bi bi-pencil-square"></i>

                            </div>


                            <div>


                                <h6>
                                    Entrance Examination Required
                                </h6>


                                <p>

                                    Students joining during the academic year
                                    are also required to take and pass the
                                    school's entrance examination.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     COMMON REQUIREMENTS
========================================================= -->

<section class="section-padding bg-light">

    <div class="container">


        <div class="text-center mb-5">


            <span class="section-label">

                <i class="bi bi-file-earmark-check-fill"></i>

                Common Requirements

            </span>


            <h2 class="section-title mb-3">

                Requirements for All New Students

            </h2>


            <p class="section-description mx-auto mb-0">

                Regardless of when a student applies, the following
                requirements apply to new students seeking admission to
                Bole Kale Hiwot School.

            </p>

        </div>


        <div class="row g-4">


            <!-- =================================================
                 PREVIOUS SCHOOL CLEARANCE
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <div class="admission-card">

                    <div class="admission-card-body">


                        <div class="card-icon icon-blue">

                            <i class="bi bi-file-earmark-check-fill"></i>

                        </div>


                        <h4>
                            Previous School Clearance
                        </h4>


                        <p>

                            All new students must provide appropriate
                            clearance from their previous school before
                            admission can be completed.

                        </p>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Previous school clearance document.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Relevant academic records.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Other school documents when required.
                                </span>

                            </li>

                        </ul>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 ACADEMIC RECORD
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <div class="admission-card">

                    <div class="admission-card-body">


                        <div class="card-icon icon-green">

                            <i class="bi bi-journal-text"></i>

                        </div>


                        <h4>
                            Academic Records
                        </h4>


                        <p>

                            Academic records are reviewed to understand the
                            student's previous academic progress and determine
                            appropriate placement.

                        </p>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Previous academic records.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Previous grade completion information.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Current academic-year grades and marks
                                    for mid-year admission.
                                </span>

                            </li>

                        </ul>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 PARENT / GUARDIAN
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <div class="admission-card">

                    <div class="admission-card-body">


                        <div class="card-icon icon-purple">

                            <i class="bi bi-people-fill"></i>

                        </div>


                        <h4>
                            Parent or Guardian Information
                        </h4>


                        <p>

                            A parent or guardian should provide accurate
                            information so the school can communicate
                            important student and academic information.

                        </p>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Parent or guardian full name.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Valid phone number and contact
                                    information.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-check-circle-fill"></i>

                                <span>
                                    Accurate student-parent relationship
                                    information.
                                </span>

                            </li>

                        </ul>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     ENTRANCE EXAMINATION
========================================================= -->

<section class="section-padding">

    <div class="container">


        <div class="row align-items-center g-5">


            <div class="col-lg-6">


                <span class="section-label">

                    <i class="bi bi-pencil-square"></i>

                    Entrance Examination

                </span>


                <h2 class="section-title mb-3">

                    Entrance Examination for New Students

                </h2>


                <p class="section-description mb-4">

                    The entrance examination is an important part of the
                    admission process for new students. It helps the school
                    assess the student's academic level before admission and
                    placement.

                </p>


                <div class="entrance-exam-box">


                    <div class="entrance-exam-icon">

                        <i class="bi bi-check2-circle"></i>

                    </div>


                    <div>


                        <h6>
                            Pass Required
                        </h6>


                        <p>

                            The student must take and pass the entrance
                            examination before admission can be finalized.

                        </p>

                    </div>

                </div>

            </div>


            <div class="col-lg-6">


                <div class="admission-card">


                    <div class="admission-card-body">


                        <div class="card-icon icon-orange">

                            <i class="bi bi-clipboard2-check-fill"></i>

                        </div>


                        <h4>
                            Examination Process
                        </h4>


                        <ul class="requirement-list">


                            <li>

                                <i class="bi bi-1-circle-fill"></i>

                                <span>
                                    Student completes the admission
                                    application.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-2-circle-fill"></i>

                                <span>
                                    Required previous school documents are
                                    submitted.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-3-circle-fill"></i>

                                <span>
                                    Student takes the entrance examination.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-4-circle-fill"></i>

                                <span>
                                    Examination result is reviewed by the
                                    school.
                                </span>

                            </li>


                            <li>

                                <i class="bi bi-5-circle-fill"></i>

                                <span>
                                    Successful students proceed to admission
                                    and registration.
                                </span>

                            </li>

                        </ul>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     ADMISSION PROCESS
========================================================= -->

<section class="section-padding bg-light">

    <div class="container">


        <div class="text-center mb-5">


            <span class="section-label">

                <i class="bi bi-list-check"></i>

                Admission Process

            </span>


            <h2 class="section-title mb-3">

                How New Student Admission Works

            </h2>


            <p class="section-description mx-auto mb-0">

                The school follows a clear process to verify previous school
                information, assess the student's academic level and complete
                admission and registration.

            </p>

        </div>


        <div class="row g-4">


            <!-- STEP 1 -->

            <div class="col-md-6 col-lg-3">

                <div class="process-card">


                    <div class="process-number">
                        1
                    </div>


                    <h5>
                        Submit Documents
                    </h5>


                    <p>

                        Submit previous school clearance and the required
                        academic and student documents.

                    </p>

                </div>

            </div>


            <!-- STEP 2 -->

            <div class="col-md-6 col-lg-3">

                <div class="process-card">


                    <div class="process-number">
                        2
                    </div>


                    <h5>
                        Academic Review
                    </h5>


                    <p>

                        The school reviews the student's academic information
                        for appropriate grade and academic placement.

                    </p>

                </div>

            </div>


            <!-- STEP 3 -->

            <div class="col-md-6 col-lg-3">

                <div class="process-card exam-process">


                    <div class="process-number">
                        3
                    </div>


                    <span class="exam-badge">

                        <i class="bi bi-pencil-square"></i>

                        REQUIRED

                    </span>


                    <h5>
                        Entrance Examination
                    </h5>


                    <p>

                        The new student takes the entrance examination and
                        must pass it to qualify for admission.

                    </p>

                </div>

            </div>


            <!-- STEP 4 -->

            <div class="col-md-6 col-lg-3">

                <div class="process-card">


                    <div class="process-number">
                        4
                    </div>


                    <h5>
                        Admission & Registration
                    </h5>


                    <p>

                        After completing the requirements, the student is
                        admitted and registered in the appropriate grade and
                        section.

                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     IMPORTANT NOTICE
========================================================= -->

<section class="section-padding">

    <div class="container">


        <div class="notice-box">


            <div class="d-flex align-items-start gap-3">


                <div class="notice-icon">

                    <i class="bi bi-exclamation-circle"></i>

                </div>


                <div class="flex-grow-1">


                    <h4 class="fw-bold mb-3">

                        Important Admission Information

                    </h4>


                    <ul class="notice-list">


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>Two admission periods:</strong>
                                New students may apply before the beginning
                                of a new academic year or during an ongoing
                                academic year.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>Previous school clearance:</strong>
                                Previous school clearance is required for
                                students applying during either admission
                                period.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>Beginning of academic year:</strong>
                                Students applying before the academic year
                                begins must provide evidence of successful
                                completion of the previous grade or academic
                                year.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>During the academic year:</strong>
                                Students joining after the academic year has
                                started must provide their current
                                academic-year grades and marks from their
                                previous school.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>Entrance examination:</strong>
                                New students are required to take and pass
                                the school's entrance examination.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                <strong>Placement:</strong>
                                Academic records and examination results are
                                reviewed to determine the appropriate grade
                                and section.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                All submitted documents are subject to
                                verification by the school administration.

                            </span>

                        </li>


                        <li>

                            <i class="bi bi-check-circle-fill"></i>

                            <span>

                                Admission and placement are subject to
                                available space and the school's applicable
                                requirements.

                            </span>

                        </li>

                    </ul>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     CTA
========================================================= -->

<section class="admission-cta">

    <div class="container">


        <div class="row align-items-center g-4">


            <div class="col-lg-8">


                <h2 class="fw-bold mb-3">

                    Ready to Apply for Admission?

                </h2>


                <p class="mb-0">

                    If you need more information about new student admission,
                    required documents, previous school clearance, current
                    academic-year marks or the entrance examination, please
                    contact Bole Kale Hiwot School.

                </p>

            </div>


            <div class="col-lg-4 text-lg-end">


                <a
                    href="contact.php"
                    class="btn btn-light btn-lg rounded-pill px-4"
                >

                    <i class="bi bi-telephone me-2"></i>

                    Contact Us

                </a>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="pt-5 pb-4">

    <div class="container">


        <div class="row g-4">


            <!-- SCHOOL -->

            <div class="col-lg-4">


                <div class="footer-brand d-flex align-items-center gap-3 mb-3">


                    <img
                        src="public/image/logo.webp"
                        alt="<?= htmlspecialchars($schoolName) ?> Logo"
                    >


                    <div>


                        <h5 class="text-white fw-bold mb-1">

                            <?= htmlspecialchars($schoolName) ?>

                        </h5>


                        <small class="text-secondary">

                            Learning • Character • Excellence

                        </small>

                    </div>

                </div>


                <p class="mb-4">

                    Providing quality education and helping students develop
                    the knowledge, skills and character they need for the
                    future.

                </p>


                <div class="d-flex gap-2">


                    <a
                        href="#"
                        class="social-link"
                        aria-label="Facebook"
                    >

                        <i class="bi bi-facebook"></i>

                    </a>


                    <a
                        href="#"
                        class="social-link"
                        aria-label="Telegram"
                    >

                        <i class="bi bi-telegram"></i>

                    </a>


                    <a
                        href="#"
                        class="social-link"
                        aria-label="YouTube"
                    >

                        <i class="bi bi-youtube"></i>

                    </a>

                </div>

            </div>


            <!-- QUICK LINKS -->

            <div class="col-6 col-lg-2">


                <h6 class="text-white fw-bold mb-3">
                    Quick Links
                </h6>


                <ul class="list-unstyled mb-0">


                    <li class="mb-2">

                        <a href="index.php">
                            Home
                        </a>

                    </li>


                    <li class="mb-2">

                        <a href="about.php">
                            About
                        </a>

                    </li>


                    <li class="mb-2">

                        <a href="admission.php">
                            Admission
                        </a>

                    </li>


                    <li class="mb-2">

                        <a href="gallery.php">
                            Gallery
                        </a>

                    </li>

                </ul>

            </div>


            <!-- INFORMATION -->

            <div class="col-6 col-lg-3">


                <h6 class="text-white fw-bold mb-3">
                    Information
                </h6>


                <ul class="list-unstyled mb-0">


                    <li class="mb-2">

                        <a href="announcements.php">
                            Announcements
                        </a>

                    </li>


                    <li class="mb-2">

                        <a href="contact.php">
                            Contact Us
                        </a>

                    </li>


                    <li class="mb-2">

                        <a href="auth/login.php">
                            Portal Login
                        </a>

                    </li>

                </ul>

            </div>


            <!-- CONTACT -->

            <div class="col-lg-3">


                <h6 class="text-white fw-bold mb-3">
                    Contact
                </h6>


                <ul class="list-unstyled mb-0">


                    <li class="d-flex gap-2 mb-3">

                        <i class="bi bi-geo-alt text-white"></i>

                        <span>
                            Addis Ababa, Ethiopia
                        </span>

                    </li>


                    <li class="d-flex gap-2 mb-3">

                        <i class="bi bi-telephone text-white"></i>

                        <span>
                            Contact the school office
                        </span>

                    </li>


                    <li class="d-flex gap-2">

                        <i class="bi bi-envelope text-white"></i>

                        <span>
                            School Administration
                        </span>

                    </li>

                </ul>

            </div>

        </div>


        <hr class="border-secondary my-4">


        <div
            class="d-flex flex-column flex-md-row justify-content-between gap-2"
        >


            <small>

                &copy; <?= date('Y') ?>

                <?= htmlspecialchars($schoolName) ?>.

                All rights reserved.

            </small>


            <small>

                School Management System

            </small>

        </div>

    </div>

</footer>


<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>
</html>