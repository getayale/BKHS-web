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
    content="Bole Kale Hiwot School - Quality education, character development, creativity and a brighter future."
>

<title>Bole Kale Hiwot School</title>

<link rel="icon" type="image/webp" href="public/image/logo.webp">

<!-- =========================
     GOOGLE FONT
========================== -->

<link rel="preconnect" href="https://fonts.googleapis.com">

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    rel="stylesheet"
>


<!-- =========================
     BOOTSTRAP
========================== -->

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>


<!-- =========================
     BOOTSTRAP ICONS
========================== -->

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>


<!-- =========================
     CUSTOM CSS
========================== -->

<link
    rel="stylesheet"
    href="public/css/style.css"
>


<!-- =====================================================
     MOBILE RESPONSIVE OVERRIDES
     Desktop design remains unchanged.
====================================================== -->

<style>

    /* =====================================================
       GLOBAL MOBILE SAFETY
    ====================================================== */

    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    img,
    svg,
    video {
        max-width: 100%;
    }

    a,
    button,
    input,
    textarea {
        max-width: 100%;
    }


    /* =====================================================
       SMALL SCREENS
    ====================================================== */

    @media (max-width: 991.98px) {

        /* ---------------------------------------------
           CONTAINER
        --------------------------------------------- */

        .container {
            width: 100%;
            max-width: 100%;
            padding-left: 20px;
            padding-right: 20px;
        }


        /* ---------------------------------------------
           NAVBAR
        --------------------------------------------- */

        .site-header {
            width: 100%;
            overflow: visible;
        }

        .site-header .navbar {
            padding-top: 12px;
            padding-bottom: 12px;
        }

        .site-header .navbar > .container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
        }

        .navbar-brand.brand {
            min-width: 0;
            max-width: calc(100% - 58px);
            margin-right: 8px;
        }

        .brand-logo {
            flex: 0 0 auto;
        }

        .brand-info {
            min-width: 0;
        }

        .brand-name,
        .brand-school {
            white-space: normal;
        }

        .navbar-toggler {
            flex: 0 0 auto;
            width: 44px;
            height: 44px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            margin-left: auto;
        }

        .navbar-toggler:focus {
            box-shadow: none;
        }

        .navbar-collapse {
            width: 100%;
            flex-basis: 100%;
            margin-top: 12px;
        }

        .navbar-nav {
            width: 100%;
            align-items: stretch !important;
            gap: 4px;
            padding: 10px;
            border-radius: 16px;
        }

        .navbar-nav .nav-item {
            width: 100%;
        }

        .navbar-nav .nav-link {
            width: 100%;
            min-height: 44px;
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 10px;
        }

        .navbar-nav .login-item {
            margin-top: 6px;
        }

        .navbar-nav .btn-login {
            width: 100%;
            min-height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }


        /* ---------------------------------------------
           HERO
        --------------------------------------------- */

        .hero-section {
            overflow: hidden;
        }

        .hero-section .row {
            --bs-gutter-y: 2rem;
        }

        .hero-content {
            width: 100%;
            max-width: 100%;
        }

        .hero-badge {
            max-width: 100%;
            white-space: normal;
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            line-height: 1.4;
        }

        .hero-title {
            width: 100%;
            max-width: 100%;
            font-size: clamp(2rem, 8vw, 3rem);
            line-height: 1.12;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .hero-title span {
            display: inline;
        }

        .hero-description {
            width: 100%;
            max-width: 100%;
            font-size: 0.98rem;
            line-height: 1.75;
            overflow-wrap: break-word;
        }

        .hero-actions {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }

        .hero-actions .btn {
            width: 100%;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            white-space: normal;
            line-height: 1.35;
        }

        .hero-trust {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .trust-item {
            max-width: 100%;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
        }

        .trust-item span {
            min-width: 0;
            overflow-wrap: break-word;
        }


        /* ---------------------------------------------
           HERO VISUAL
        --------------------------------------------- */

        .hero-visual {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            position: relative;
            padding: 8px 0 70px;
        }

        .hero-image-card {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }

        .hero-image-placeholder {
            width: 100%;
            min-height: 260px;
            padding: 30px 20px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
        }

        .hero-image-placeholder span {
            max-width: 100%;
            overflow-wrap: break-word;
            line-height: 1.5;
        }

        .floating-card {
            max-width: calc(100% - 20px);
            min-width: 0;
            padding: 12px 14px;
        }

        .floating-card-one {
            left: 0;
            bottom: 12px;
        }

        .floating-card-two {
            right: 0;
            bottom: -42px;
        }

        .floating-card strong,
        .floating-card small {
            overflow-wrap: break-word;
        }


        /* ---------------------------------------------
           FEATURES
        --------------------------------------------- */

        .features-section .row {
            --bs-gutter-y: 1rem;
        }

        .feature-card {
            width: 100%;
            min-width: 0;
            height: 100%;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .feature-icon {
            flex: 0 0 auto;
        }

        .feature-card > div:last-child {
            min-width: 0;
        }

        .feature-card h3 {
            overflow-wrap: break-word;
        }

        .feature-card p {
            overflow-wrap: break-word;
            line-height: 1.65;
        }


        /* ---------------------------------------------
           ABOUT
        --------------------------------------------- */

        .about-section .row {
            --bs-gutter-y: 2.5rem;
        }

        .about-visual {
            width: 100%;
            max-width: 100%;
            padding-bottom: 55px;
        }

        .about-main-card {
            width: 100%;
            max-width: 100%;
        }

        .about-small-card {
            max-width: calc(100% - 20px);
        }

        .section-heading {
            width: 100%;
            max-width: 100%;
        }

        .section-heading h2 {
            max-width: 100%;
            overflow-wrap: break-word;
        }

        .section-heading p {
            max-width: 100%;
            overflow-wrap: break-word;
            line-height: 1.75;
        }

        .about-points {
            width: 100%;
        }

        .about-point {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
        }

        .about-point span {
            min-width: 0;
            overflow-wrap: break-word;
            line-height: 1.55;
        }

        .text-link {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            max-width: 100%;
            line-height: 1.5;
        }


        /* ---------------------------------------------
           ADMISSION
        --------------------------------------------- */

        .admission-section {
            overflow: hidden;
        }

        .admission-card {
            width: 100%;
            min-width: 0;
            position: relative;
            overflow: hidden;
        }

        .admission-content {
            width: 100%;
            max-width: 100%;
            position: relative;
            z-index: 2;
        }

        .admission-content h2 {
            max-width: 100%;
            overflow-wrap: break-word;
            line-height: 1.25;
        }

        .admission-content p {
            max-width: 100%;
            overflow-wrap: break-word;
            line-height: 1.7;
        }

        .admission-content .btn {
            max-width: 100%;
            white-space: normal;
            line-height: 1.4;
        }

        .admission-decoration {
            pointer-events: none;
        }


        /* ---------------------------------------------
           ANNOUNCEMENTS
        --------------------------------------------- */

        .announcements-section {
            overflow: hidden;
        }

        .announcement-card {
            width: 100%;
            min-width: 0;
            height: 100%;
        }

        .announcement-date {
            flex: 0 0 auto;
        }

        .announcement-content {
            min-width: 0;
        }

        .announcement-category,
        .announcement-content h3,
        .announcement-content p {
            overflow-wrap: break-word;
        }

        .announcement-content h3 {
            line-height: 1.35;
        }

        .announcement-content p {
            line-height: 1.65;
        }

        .announcement-content a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .section-button {
            width: 100%;
        }

        .section-button .btn {
            max-width: 100%;
            white-space: normal;
            line-height: 1.4;
        }


        /* ---------------------------------------------
           GALLERY
        --------------------------------------------- */

        .gallery-section {
            overflow: hidden;
        }

        .gallery-grid {
            width: 100%;
            max-width: 100%;
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .gallery-item,
        .gallery-large {
            width: 100%;
            min-width: 0;
            grid-column: auto !important;
            grid-row: auto !important;
        }

        .gallery-placeholder {
            width: 100%;
            min-height: 190px;
            padding: 25px 15px;
        }

        .gallery-placeholder span {
            max-width: 100%;
            overflow-wrap: break-word;
            text-align: center;
        }


        /* ---------------------------------------------
           CONTACT
        --------------------------------------------- */

        .contact-section .row {
            --bs-gutter-y: 2.5rem;
        }

        .contact-info {
            width: 100%;
        }

        .contact-item {
            width: 100%;
            min-width: 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .contact-icon {
            flex: 0 0 auto;
        }

        .contact-item > div:last-child {
            min-width: 0;
        }

        .contact-item strong,
        .contact-item span {
            max-width: 100%;
            overflow-wrap: break-word;
        }

        .contact-form-card {
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .contact-form-card .row {
            --bs-gutter-x: 0.8rem;
            --bs-gutter-y: 1rem;
        }

        .contact-form-card input,
        .contact-form-card textarea,
        .contact-form-card button {
            width: 100%;
            max-width: 100%;
        }

        .contact-form-card textarea {
            resize: vertical;
        }

        .contact-form-card .btn {
            min-height: 50px;
        }


        /* ---------------------------------------------
           FOOTER
        --------------------------------------------- */

        .site-footer {
            overflow: hidden;
        }

        .site-footer .row {
            --bs-gutter-y: 2.2rem;
        }

        .footer-brand {
            max-width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .footer-brand img {
            flex: 0 0 auto;
            max-width: 60px;
        }

        .footer-brand > div {
            min-width: 0;
        }

        .site-footer p {
            max-width: 100%;
            line-height: 1.7;
            overflow-wrap: break-word;
        }

        .site-footer h4 {
            margin-bottom: 12px;
        }

        .site-footer ul {
            padding-left: 0;
        }

        .site-footer ul li {
            max-width: 100%;
        }

        .site-footer ul li a {
            display: inline-block;
            max-width: 100%;
            overflow-wrap: break-word;
        }

        .social-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .social-links a {
            flex: 0 0 auto;
        }

        .footer-bottom {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            text-align: left;
        }

        .footer-bottom span {
            max-width: 100%;
            overflow-wrap: break-word;
            line-height: 1.5;
        }

    }


    /* =====================================================
       EXTRA SMALL PHONES
       576px and below
    ====================================================== */

    @media (max-width: 575.98px) {

        .container {
            padding-left: 16px;
            padding-right: 16px;
        }


        /* ---------------------------------------------
           NAVBAR
        --------------------------------------------- */

        .site-header .navbar {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .navbar-brand.brand {
            max-width: calc(100% - 52px);
        }

        .brand-logo {
            max-width: 42px;
            height: auto;
        }

        .brand-name {
            font-size: 0.92rem;
        }

        .brand-school {
            font-size: 0.75rem;
        }

        .navbar-toggler {
            width: 40px;
            height: 40px;
            border-radius: 10px;
        }

        .navbar-nav {
            padding: 8px;
            border-radius: 14px;
        }


        /* ---------------------------------------------
           HERO
        --------------------------------------------- */

        .hero-section {
            padding-top: 38px;
            padding-bottom: 48px;
        }

        .hero-title {
            font-size: clamp(1.85rem, 9vw, 2.45rem);
            line-height: 1.14;
        }

        .hero-description {
            font-size: 0.94rem;
            line-height: 1.7;
        }

        .hero-actions .btn {
            min-height: 48px;
            padding: 11px 16px;
            font-size: 0.9rem;
        }

        .hero-image-placeholder {
            min-height: 220px;
            padding: 25px 16px;
        }

        .hero-image-placeholder i {
            font-size: 2.5rem;
        }

        .hero-image-placeholder span {
            font-size: 0.85rem;
        }

        .hero-visual {
            padding-bottom: 82px;
        }

        .floating-card {
            max-width: calc(100% - 8px);
            padding: 10px 11px;
            gap: 9px;
            border-radius: 12px;
        }

        .floating-card-one {
            left: 0;
        }

        .floating-card-two {
            right: 0;
            bottom: -50px;
        }

        .floating-card strong {
            font-size: 0.78rem;
        }

        .floating-card small {
            font-size: 0.68rem;
        }

        .floating-icon {
            flex: 0 0 auto;
        }


        /* ---------------------------------------------
           FEATURES
        --------------------------------------------- */

        .feature-card {
            padding: 18px;
            gap: 12px;
        }

        .feature-card h3 {
            font-size: 1rem;
            line-height: 1.35;
        }

        .feature-card p {
            font-size: 0.88rem;
            line-height: 1.6;
        }


        /* ---------------------------------------------
           ABOUT
        --------------------------------------------- */

        .about-section {
            padding-top: 55px;
            padding-bottom: 55px;
        }

        .about-main-card {
            min-height: 230px;
        }

        .about-small-card {
            max-width: calc(100% - 12px);
        }


        /* ---------------------------------------------
           SECTION HEADINGS
        --------------------------------------------- */

        .section-heading h2 {
            font-size: clamp(1.65rem, 7.5vw, 2.1rem);
            line-height: 1.2;
        }

        .section-heading p {
            font-size: 0.92rem;
            line-height: 1.7;
        }


        /* ---------------------------------------------
           ADMISSION
        --------------------------------------------- */

        .admission-card {
            padding: 28px 20px;
        }

        .admission-content h2 {
            font-size: 1.55rem;
            line-height: 1.3;
        }

        .admission-content p {
            font-size: 0.9rem;
        }

        .admission-content .btn {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }


        /* ---------------------------------------------
           ANNOUNCEMENTS
        --------------------------------------------- */

        .announcement-card {
            padding: 18px;
            gap: 14px;
        }

        .announcement-date {
            min-width: 48px;
            width: 48px;
            padding: 8px 5px;
        }

        .announcement-date span {
            font-size: 1.1rem;
        }

        .announcement-date small {
            font-size: 0.62rem;
        }

        .announcement-content h3 {
            font-size: 1rem;
            line-height: 1.35;
        }

        .announcement-content p {
            font-size: 0.84rem;
            line-height: 1.6;
        }


        /* ---------------------------------------------
           GALLERY
        --------------------------------------------- */

        .gallery-grid {
            gap: 10px;
        }

        .gallery-placeholder {
            min-height: 170px;
        }


        /* ---------------------------------------------
           CONTACT
        --------------------------------------------- */

        .contact-item {
            gap: 10px;
        }

        .contact-item strong {
            font-size: 0.9rem;
        }

        .contact-item span {
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .contact-form-card {
            padding: 20px 16px;
        }

        .contact-form-card label {
            font-size: 0.85rem;
        }

        .contact-form-card .form-control {
            min-height: 46px;
            font-size: 0.9rem;
        }

        .contact-form-card textarea.form-control {
            min-height: 130px;
        }


        /* ---------------------------------------------
           FOOTER
        --------------------------------------------- */

        .site-footer {
            padding-top: 45px;
            padding-bottom: 25px;
        }

        .footer-brand img {
            max-width: 50px;
        }

        .site-footer p {
            font-size: 0.88rem;
        }

        .footer-bottom {
            font-size: 0.75rem;
        }

    }


    /* =====================================================
       VERY SMALL PHONES
       380px and below
    ====================================================== */

    @media (max-width: 380px) {

        .container {
            padding-left: 13px;
            padding-right: 13px;
        }

        .brand-name {
            font-size: 0.84rem;
        }

        .brand-school {
            font-size: 0.7rem;
        }

        .hero-title {
            font-size: 1.8rem;
        }

        .hero-description {
            font-size: 0.9rem;
        }

        .floating-card {
            max-width: calc(100% - 4px);
            padding: 9px;
        }

        .floating-card strong {
            font-size: 0.72rem;
        }

        .floating-card small {
            font-size: 0.62rem;
        }

        .feature-card {
            padding: 15px;
        }

        .announcement-card {
            padding: 15px;
            gap: 10px;
        }

        .announcement-date {
            min-width: 44px;
            width: 44px;
        }

        .announcement-content h3 {
            font-size: 0.92rem;
        }

        .admission-card {
            padding: 24px 16px;
        }

    }

</style>


</head>

<body>

<!-- =====================================================
     NAVBAR
===================================================== -->

<header class="site-header">


<nav class="navbar navbar-expand-lg">

    <div class="container">

        <!-- Logo -->

        <a
            href="index.php"
            class="navbar-brand brand"
        >

            <img
                src="public/image/logo.webp"
                alt="Bole Kale Hiwot School Logo"
                class="brand-logo"
            >

            <div class="brand-info">

                <span class="brand-name">
                    Bole Kale Hiwot
                </span>

                <span class="brand-school">
                    School
                </span>

            </div>

        </a>


        <!-- Mobile Menu -->

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
            aria-controls="mainNavbar"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >

            <span class="navbar-toggler-icon"></span>

        </button>


        <!-- Navigation -->

        <div
            class="collapse navbar-collapse"
            id="mainNavbar"
        >

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">

                    <a
                        class="nav-link active"
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
                        class="nav-link"
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
                        Announcement
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


                <li class="nav-item login-item">

                    <a
                        href="auth/login.php"
                        class="btn btn-login"
                    >

                        <i class="bi bi-person-circle"></i>

                        <span>Login</span>

                    </a>

                </li>

            </ul>

        </div>

    </div>

</nav>


</header>

<!-- =====================================================
     HERO
===================================================== -->

<main>

<section class="hero-section">


<div class="container">

    <div class="row align-items-center g-5">

        <!-- Hero Text -->

        <div class="col-lg-6">

            <div class="hero-content">

                <span class="hero-badge">

                    <span class="badge-dot"></span>

                    Welcome to BKHS

                </span>


                <h1 class="hero-title">

                    Building a
                    <span>Brighter Future</span>
                    Through Education

                </h1>


                <p class="hero-description">

                    Bole Kale Hiwot School is committed to creating
                    a supportive learning environment where students
                    develop knowledge, character, creativity, and
                    confidence for the future.

                </p>


                <div class="hero-actions">

                    <a
                        href="admission.php"
                        class="btn btn-primary-custom"
                    >

                        Apply for Admission

                        <i class="bi bi-arrow-right"></i>

                    </a>


                    <a
                        href="about.php"
                        class="btn btn-outline-custom"
                    >

                        Discover Our School

                    </a>

                </div>


                <!-- Small trust information -->

                <div class="hero-trust">

                    <div class="trust-item">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>Student-centered learning</span>

                    </div>


                    <div class="trust-item">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>Supportive environment</span>

                    </div>

                </div>

            </div>

        </div>


        <!-- Hero Visual -->

        <div class="col-lg-6">

            <div class="hero-visual">

                <div class="hero-image-card">

                    <div class="hero-image-placeholder">

                        <i class="bi bi-mortarboard-fill"></i>

                        <span>
                            Education • Character • Excellence
                        </span>

                    </div>

                </div>


                <!-- Floating card -->

                <div class="floating-card floating-card-one">

                    <div class="floating-icon">

                        <i class="bi bi-book"></i>

                    </div>

                    <div>

                        <strong>Quality Education</strong>

                        <small>Learning for life</small>

                    </div>

                </div>


                <div class="floating-card floating-card-two">

                    <div class="floating-icon">

                        <i class="bi bi-people"></i>

                    </div>

                    <div>

                        <strong>Student Growth</strong>

                        <small>Learn & grow together</small>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     QUICK FEATURES
===================================================== -->

<section class="features-section">


<div class="container">

    <div class="row g-4">

        <div class="col-md-4">

            <div class="feature-card">

                <div class="feature-icon">

                    <i class="bi bi-book-half"></i>

                </div>

                <div>

                    <h3>Quality Education</h3>

                    <p>
                        Creating meaningful learning experiences
                        that help students build strong foundations.
                    </p>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="feature-card">

                <div class="feature-icon">

                    <i class="bi bi-person-workspace"></i>

                </div>

                <div>

                    <h3>Dedicated Teachers</h3>

                    <p>
                        Supporting students through guidance,
                        encouragement, and effective teaching.
                    </p>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="feature-card">

                <div class="feature-icon">

                    <i class="bi bi-stars"></i>

                </div>

                <div>

                    <h3>Student Development</h3>

                    <p>
                        Encouraging academic, personal, social,
                        and creative development.
                    </p>

                </div>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     ABOUT
===================================================== -->

<section class="about-section">


<div class="container">

    <div class="row align-items-center g-5">

        <div class="col-lg-6">

            <div class="about-visual">

                <div class="about-main-card">

                    <div class="about-icon">

                        <i class="bi bi-building"></i>

                    </div>

                    <span>BKHS</span>

                </div>


                <div class="about-small-card">

                    <i class="bi bi-lightbulb"></i>

                    <div>

                        <strong>Learn</strong>

                        <small>Discover new possibilities</small>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-6">

            <div class="section-heading text-start">

                <span class="section-label">
                    ABOUT OUR SCHOOL
                </span>

                <h2>
                    Education that goes
                    <span>beyond the classroom</span>
                </h2>

                <p>
                    At Bole Kale Hiwot School, we believe education
                    is about more than academic achievement. We aim
                    to create an environment where students can learn,
                    explore, communicate, and develop the confidence
                    they need for their future.
                </p>

            </div>


            <div class="about-points">

                <div class="about-point">

                    <i class="bi bi-check-lg"></i>

                    <span>
                        A supportive and welcoming learning environment
                    </span>

                </div>


                <div class="about-point">

                    <i class="bi bi-check-lg"></i>

                    <span>
                        Focus on academic and personal development
                    </span>

                </div>


                <div class="about-point">

                    <i class="bi bi-check-lg"></i>

                    <span>
                        Encouragement of creativity and responsibility
                    </span>

                </div>

            </div>


            <a
                href="about.php"
                class="text-link"
            >

                Learn more about BKHS

                <i class="bi bi-arrow-right"></i>

            </a>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     ADMISSION CTA
===================================================== -->

<section class="admission-section">


<div class="container">

    <div class="admission-card">

        <div class="admission-content">

            <span class="section-label light">
                ADMISSIONS
            </span>

            <h2>
                Give your child a strong
                foundation for tomorrow.
            </h2>

            <p>
                Learn more about our admission process,
                requirements, and how to apply.
            </p>

            <a
                href="admission.php"
                class="btn btn-white"
            >

                View Admission Information

                <i class="bi bi-arrow-right"></i>

            </a>

        </div>


        <div class="admission-decoration">

            <i class="bi bi-mortarboard"></i>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     ANNOUNCEMENTS
===================================================== -->

<section class="announcements-section">


<div class="container">

    <div class="section-heading">

        <span class="section-label">
            STAY INFORMED
        </span>

        <h2>
            Latest <span>Announcements</span>
        </h2>

        <p>
            Stay updated with the latest school news,
            events, and important information.
        </p>

    </div>


    <div class="row g-4">

        <!-- Announcement -->

        <div class="col-md-6 col-lg-4">

            <article class="announcement-card">

                <div class="announcement-date">

                    <span>01</span>

                    <small>JAN</small>

                </div>


                <div class="announcement-content">

                    <span class="announcement-category">
                        School News
                    </span>

                    <h3>
                        Welcome to the BKHS School Community
                    </h3>

                    <p>
                        Discover the latest updates and
                        activities from our school community.
                    </p>

                    <a href="announcements.php">
                        Read more
                        <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

            </article>

        </div>


        <div class="col-md-6 col-lg-4">

            <article class="announcement-card">

                <div class="announcement-date">

                    <span>02</span>

                    <small>JAN</small>

                </div>


                <div class="announcement-content">

                    <span class="announcement-category">
                        Academic
                    </span>

                    <h3>
                        Academic Activities and Updates
                    </h3>

                    <p>
                        Important academic information for
                        students and parents.
                    </p>

                    <a href="announcements.php">
                        Read more
                        <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

            </article>

        </div>


        <div class="col-md-6 col-lg-4">

            <article class="announcement-card">

                <div class="announcement-date">

                    <span>03</span>

                    <small>JAN</small>

                </div>


                <div class="announcement-content">

                    <span class="announcement-category">
                        Event
                    </span>

                    <h3>
                        School Events and Activities
                    </h3>

                    <p>
                        Explore upcoming school activities
                        and community events.
                    </p>

                    <a href="announcements.php">
                        Read more
                        <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

            </article>

        </div>

    </div>


    <div class="section-button">

        <a
            href="announcements.php"
            class="btn btn-outline-custom"
        >

            View All Announcements

            <i class="bi bi-arrow-right"></i>

        </a>

    </div>

</div>


</section>

<!-- =====================================================
     GALLERY
===================================================== -->

<section class="gallery-section">


<div class="container">

    <div class="section-heading">

        <span class="section-label">
            SCHOOL LIFE
        </span>

        <h2>
            Explore our <span>Gallery</span>
        </h2>

        <p>
            A glimpse into learning, activities,
            events, and school life.
        </p>

    </div>


    <div class="gallery-grid">

        <a
            href="gallery.php"
            class="gallery-item gallery-large"
        >

            <div class="gallery-placeholder">

                <i class="bi bi-image"></i>

                <span>School Life</span>

            </div>

        </a>


        <a
            href="gallery.php"
            class="gallery-item"
        >

            <div class="gallery-placeholder">

                <i class="bi bi-image"></i>

                <span>Learning</span>

            </div>

        </a>


        <a
            href="gallery.php"
            class="gallery-item"
        >

            <div class="gallery-placeholder">

                <i class="bi bi-image"></i>

                <span>Activities</span>

            </div>

        </a>


        <a
            href="gallery.php"
            class="gallery-item"
        >

            <div class="gallery-placeholder">

                <i class="bi bi-image"></i>

                <span>Students</span>

            </div>

        </a>


        <a
            href="gallery.php"
            class="gallery-item"
        >

            <div class="gallery-placeholder">

                <i class="bi bi-image"></i>

                <span>Events</span>

            </div>

        </a>

    </div>


    <div class="section-button">

        <a
            href="gallery.php"
            class="btn btn-outline-custom"
        >

            View Full Gallery

            <i class="bi bi-arrow-right"></i>

        </a>

    </div>

</div>


</section>

<!-- =====================================================
     CONTACT
===================================================== -->

<section class="contact-section">


<div class="container">

    <div class="row g-5">

        <div class="col-lg-5">

            <div class="section-heading text-start">

                <span class="section-label">
                    CONTACT US
                </span>

                <h2>
                    We'd love to
                    <span>hear from you</span>
                </h2>

                <p>
                    Have a question about admissions, school
                    activities, or anything else? Get in touch
                    with us.
                </p>

            </div>


            <div class="contact-info">

                <div class="contact-item">

                    <div class="contact-icon">

                        <i class="bi bi-geo-alt"></i>

                    </div>

                    <div>

                        <strong>Visit Us</strong>

                        <span>
                            Bole, Addis Ababa, Ethiopia
                        </span>

                    </div>

                </div>


                <div class="contact-item">

                    <div class="contact-icon">

                        <i class="bi bi-telephone"></i>

                    </div>

                    <div>

                        <strong>Call Us</strong>

                        <span>
                            +251 XXX XXX XXX
                        </span>

                    </div>

                </div>


                <div class="contact-item">

                    <div class="contact-icon">

                        <i class="bi bi-envelope"></i>

                    </div>

                    <div>

                        <strong>Email Us</strong>

                        <span>
                            info@bkhs.example
                        </span>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-7">

            <div class="contact-form-card">

                <form action="contact.php" method="POST">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label for="name">
                                Your Name
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                class="form-control"
                                placeholder="Enter your name"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label for="email">
                                Email Address
                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control"
                                placeholder="Enter your email"
                                required
                            >

                        </div>


                        <div class="col-12">

                            <label for="subject">
                                Subject
                            </label>

                            <input
                                type="text"
                                id="subject"
                                name="subject"
                                class="form-control"
                                placeholder="How can we help?"
                            >

                        </div>


                        <div class="col-12">

                            <label for="message">
                                Message
                            </label>

                            <textarea
                                id="message"
                                name="message"
                                class="form-control"
                                rows="5"
                                placeholder="Write your message..."
                                required
                            ></textarea>

                        </div>


                        <div class="col-12">

                            <button
                                type="submit"
                                class="btn btn-primary-custom"
                            >

                                Send Message

                                <i class="bi bi-send"></i>

                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


</section>

</main>

<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="site-footer">


<div class="container">

    <div class="row g-5">

        <div class="col-lg-5">

            <div class="footer-brand">

                <img
                    src="public/image/logo.webp"
                    alt="Bole Kale Hiwot School Logo"
                >

                <div>

                    <strong>
                        Bole Kale Hiwot
                    </strong>

                    <span>
                        School
                    </span>

                </div>

            </div>


            <p>
                Building knowledge, character, creativity,
                and confidence for a brighter future.
            </p>

        </div>


        <div class="col-6 col-lg-2">

            <h4>Quick Links</h4>

            <ul>

                <li>
                    <a href="index.php">Home</a>
                </li>

                <li>
                    <a href="about.php">About</a>
                </li>

                <li>
                    <a href="admission.php">Admission</a>
                </li>

                <li>
                    <a href="gallery.php">Gallery</a>
                </li>

            </ul>

        </div>


        <div class="col-6 col-lg-2">

            <h4>Information</h4>

            <ul>

                <li>
                    <a href="announcements.php">
                        Announcements
                    </a>
                </li>

                <li>
                    <a href="contact.php">
                        Contact
                    </a>
                </li>

                <li>
                    <a href="auth/login.php">
                        Login
                    </a>
                </li>

            </ul>

        </div>


        <div class="col-lg-3">

            <h4>Connect With Us</h4>

            <div class="social-links">

                <a href="#" aria-label="Facebook">
                    <i class="bi bi-facebook"></i>
                </a>

                <a href="#" aria-label="Telegram">
                    <i class="bi bi-telegram"></i>
                </a>

                <a href="#" aria-label="Instagram">
                    <i class="bi bi-instagram"></i>
                </a>

                <a href="#" aria-label="YouTube">
                    <i class="bi bi-youtube"></i>
                </a>

            </div>

        </div>

    </div>


    <div class="footer-bottom">

        <span>
            © <?php echo date('Y'); ?> Bole Kale Hiwot School.
            All rights reserved.
        </span>

        <span>
            BKHS School Management System
        </span>

    </div>

</div>


</footer>

<!-- =====================================================
     BOOTSTRAP JS
===================================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<!-- =====================================================
     CUSTOM JS
===================================================== -->

<script src="public/js/app.js"></script>

</body>

</html>
