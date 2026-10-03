<?php
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
    content="Learn about Bole Kale Hiwot School, our mission, values, learning environment, and commitment to student development."
>

<title>About Us | Bole Kale Hiwot School</title>

<link rel="icon" type="image/webp" href="public/image/logo.webp">


<!-- Google Fonts -->

<link rel="preconnect" href="https://fonts.googleapis.com">

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
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


<!-- Main CSS -->

<link
    rel="stylesheet"
    href="public/css/style.css"
>


<!-- =====================================================
     ABOUT PAGE CSS
====================================================== -->

<style>

    /* =========================================
       ABOUT HERO
    ========================================= */

    .about-page-hero {

        position: relative;

        padding: 90px 0 80px;

        overflow: hidden;

        background:
            radial-gradient(
                circle at 85% 20%,
                #eff6ff 0,
                transparent 32%
            ),
            radial-gradient(
                circle at 10% 90%,
                #f5f3ff 0,
                transparent 30%
            ),
            #ffffff;
    }


    .about-page-hero-content {

        max-width: 780px;

        margin: auto;

        text-align: center;

        position: relative;

        z-index: 2;
    }


    .about-page-badge {

        display: inline-flex;

        align-items: center;

        gap: 8px;

        padding: 7px 14px;

        margin-bottom: 20px;

        border: 1px solid #dbeafe;

        border-radius: 50px;

        background: #eff6ff;

        color: #2563eb;

        font-size: 0.72rem;

        font-weight: 800;

        letter-spacing: 0.08em;

        text-transform: uppercase;
    }


    .about-page-hero h1 {

        margin: 0;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size:
            clamp(2.4rem, 6vw, 4rem);

        font-weight: 800;

        line-height: 1.1;

        letter-spacing: -0.045em;
    }


    .about-page-hero h1 span {

        color: #2563eb;
    }


    .about-page-hero p {

        max-width: 680px;

        margin: 22px auto 0;

        color: #6b7280;

        font-size: 1rem;

        line-height: 1.8;
    }


    /* =========================================
       STORY SECTION
    ========================================= */

    .about-story {

        padding: 100px 0;

        background: #ffffff;
    }


    .about-story-visual {

        position: relative;

        min-height: 430px;

        display: flex;

        align-items: center;

        justify-content: center;
    }


    .story-main-card {

        width: min(100%, 460px);

        height: 380px;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 28px;

        background:
            linear-gradient(
                145deg,
                #eff6ff,
                #eef2ff
            );

        border: 1px solid #dbeafe;

        box-shadow:
            0 20px 45px rgba(15, 23, 42, 0.08);
    }


    .story-logo-box {

        width: 145px;

        height: 145px;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 30px;

        background: #ffffff;

        box-shadow:
            0 15px 35px rgba(15, 23, 42, 0.08);
    }


    .story-logo-box img {

        width: 105px;

        height: 105px;

        object-fit: contain;
    }


    .story-floating-card {

        position: absolute;

        right: 0;

        bottom: 20px;

        display: flex;

        align-items: center;

        gap: 12px;

        padding: 15px 18px;

        border-radius: 14px;

        background: #ffffff;

        box-shadow:
            0 15px 35px rgba(15, 23, 42, 0.1);
    }


    .story-floating-icon {

        width: 43px;

        height: 43px;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 11px;

        background: #eff6ff;

        color: #2563eb;
    }


    .story-floating-card strong {

        display: block;

        color: #111827;

        font-size: 0.8rem;
    }


    .story-floating-card span {

        display: block;

        color: #6b7280;

        font-size: 0.7rem;
    }


    .about-story-content {

        max-width: 600px;
    }


    .about-story-content h2 {

        margin: 0 0 18px;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size:
            clamp(2rem, 4vw, 2.8rem);

        font-weight: 800;

        line-height: 1.2;

        letter-spacing: -0.035em;
    }


    .about-story-content h2 span {

        color: #2563eb;
    }


    .about-story-content p {

        color: #6b7280;

        font-size: 0.94rem;

        line-height: 1.85;
    }


    .story-points {

        display: grid;

        grid-template-columns: 1fr 1fr;

        gap: 12px;

        margin-top: 25px;
    }


    .story-point {

        display: flex;

        align-items: flex-start;

        gap: 9px;

        color: #374151;

        font-size: 0.82rem;

        line-height: 1.5;
    }


    .story-point i {

        flex: 0 0 auto;

        margin-top: 2px;

        color: #16a34a;
    }


    /* =========================================
       VALUES
    ========================================= */

    .values-section {

        padding: 100px 0;

        background: #f8fafc;
    }


    .value-card {

        height: 100%;

        padding: 30px;

        border: 1px solid #e5e7eb;

        border-radius: 18px;

        background: #ffffff;

        box-shadow:
            0 5px 18px rgba(15, 23, 42, 0.04);

        transition:
            transform 0.25s ease,
            box-shadow 0.25s ease;
    }


    .value-card:hover {

        transform: translateY(-5px);

        box-shadow:
            0 15px 35px rgba(15, 23, 42, 0.08);
    }


    .value-icon {

        width: 54px;

        height: 54px;

        display: flex;

        align-items: center;

        justify-content: center;

        margin-bottom: 20px;

        border-radius: 14px;

        background: #eff6ff;

        color: #2563eb;

        font-size: 1.35rem;
    }


    .value-card h3 {

        margin: 0 0 9px;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size: 1rem;

        font-weight: 800;
    }


    .value-card p {

        margin: 0;

        color: #6b7280;

        font-size: 0.82rem;

        line-height: 1.7;
    }


    /* =========================================
       MISSION / VISION
    ========================================= */

    .mission-section {

        padding: 100px 0;

        background: #ffffff;
    }


    .mission-card {

        height: 100%;

        padding: 35px;

        border-radius: 22px;

        border: 1px solid #e5e7eb;

        background: #ffffff;

        box-shadow:
            0 8px 25px rgba(15, 23, 42, 0.05);
    }


    .mission-card.highlight {

        border-color: transparent;

        background:
            linear-gradient(
                145deg,
                #2563eb,
                #1d4ed8
            );

        box-shadow:
            0 20px 40px rgba(37, 99, 235, 0.18);
    }


    .mission-icon {

        width: 55px;

        height: 55px;

        display: flex;

        align-items: center;

        justify-content: center;

        margin-bottom: 22px;

        border-radius: 14px;

        background: #eff6ff;

        color: #2563eb;

        font-size: 1.35rem;
    }


    .mission-card.highlight .mission-icon {

        background: rgba(255, 255, 255, 0.15);

        color: #ffffff;
    }


    .mission-card h3 {

        margin: 0 0 12px;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size: 1.3rem;

        font-weight: 800;
    }


    .mission-card.highlight h3 {

        color: #ffffff;
    }


    .mission-card p {

        margin: 0;

        color: #6b7280;

        font-size: 0.88rem;

        line-height: 1.8;
    }


    .mission-card.highlight p {

        color: #dbeafe;
    }


    /* =========================================
       COMMUNITY
    ========================================= */

    .community-section {

        padding: 100px 0;

        background: #f8fafc;
    }


    .community-content {

        max-width: 650px;
    }


    .community-content h2 {

        margin: 0 0 18px;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size:
            clamp(2rem, 4vw, 2.7rem);

        font-weight: 800;

        line-height: 1.2;
    }


    .community-content h2 span {

        color: #2563eb;
    }


    .community-content p {

        color: #6b7280;

        font-size: 0.92rem;

        line-height: 1.85;
    }


    .community-list {

        display: grid;

        grid-template-columns: 1fr 1fr;

        gap: 14px;

        margin-top: 28px;
    }


    .community-item {

        display: flex;

        align-items: center;

        gap: 10px;

        padding: 15px;

        border-radius: 12px;

        background: #ffffff;

        border: 1px solid #e5e7eb;

        color: #374151;

        font-size: 0.8rem;

        font-weight: 600;
    }


    .community-item i {

        color: #2563eb;

        font-size: 1rem;
    }


    .community-visual {

        min-height: 400px;

        display: flex;

        align-items: center;

        justify-content: center;
    }


    .community-card {

        width: min(100%, 450px);

        min-height: 350px;

        display: flex;

        flex-direction: column;

        align-items: center;

        justify-content: center;

        gap: 15px;

        border-radius: 28px;

        background:
            linear-gradient(
                145deg,
                #eef2ff,
                #eff6ff
            );

        border: 1px solid #dbeafe;

        color: #2563eb;
    }


    .community-card i {

        font-size: 5rem;
    }


    .community-card strong {

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size: 1.1rem;
    }


    .community-card span {

        color: #6b7280;

        font-size: 0.8rem;
    }


    /* =========================================
       CTA
    ========================================= */

    .about-cta {

        padding: 90px 0;
    }


    .about-cta-card {

        position: relative;

        overflow: hidden;

        padding: 55px;

        border-radius: 26px;

        background:
            linear-gradient(
                120deg,
                #111827,
                #1f2937
            );

        text-align: center;
    }


    .about-cta-card > * {

        position: relative;

        z-index: 2;
    }


    .about-cta-card h2 {

        margin: 0 0 13px;

        color: #ffffff;

        font-family:
            "Plus Jakarta Sans",
            sans-serif;

        font-size:
            clamp(1.8rem, 4vw, 2.7rem);

        font-weight: 800;
    }


    .about-cta-card p {

        max-width: 620px;

        margin: 0 auto 25px;

        color: #9ca3af;

        font-size: 0.9rem;
    }


    .cta-decoration {

        position: absolute;

        width: 280px;

        height: 280px;

        border-radius: 50%;

        background:
            rgba(37, 99, 235, 0.16);
    }


    .cta-decoration-one {

        left: -130px;

        bottom: -160px;
    }


    .cta-decoration-two {

        right: -120px;

        top: -150px;
    }


    /* =====================================================
       RESPONSIVE MOBILE OVERRIDES
       Desktop remains unchanged.
    ====================================================== */

    @media (max-width: 991.98px) {

        /* Global overflow protection */

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


        .container {

            width: 100%;

            max-width: 100%;

            padding-left: 20px;

            padding-right: 20px;
        }


        /* =========================================
           NAVBAR
        ========================================= */

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

            max-width:
                calc(100% - 58px);

            margin-right: 8px;
        }


        .brand-info {

            min-width: 0;
        }


        .brand-name,
        .brand-school {

            white-space: normal;

            overflow-wrap: break-word;
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


        /* =========================================
           ABOUT HERO
        ========================================= */

        .about-page-hero {

            padding:
                70px 0 65px;
        }


        .about-page-hero-content {

            width: 100%;

            max-width: 100%;

            padding: 0;
        }


        .about-page-badge {

            max-width: 100%;

            flex-wrap: wrap;

            justify-content: center;

            line-height: 1.4;

            text-align: center;
        }


        .about-page-hero h1 {

            width: 100%;

            max-width: 100%;

            font-size:
                clamp(2rem, 8vw, 3rem);

            line-height: 1.14;

            overflow-wrap: break-word;

            word-break: normal;
        }


        .about-page-hero p {

            width: 100%;

            max-width: 680px;

            padding: 0 4px;

            font-size: 0.95rem;

            line-height: 1.75;

            overflow-wrap: break-word;
        }


        /* =========================================
           STORY
        ========================================= */

        .about-story {

            padding:
                75px 0;
        }


        .about-story .row {

            --bs-gutter-y: 2.5rem;
        }


        .about-story-visual {

            width: 100%;

            max-width: 100%;

            min-height: 370px;

            padding:
                5px 0 55px;

            margin: 0;
        }


        .story-main-card {

            width: 100%;

            max-width: 460px;

            height: 330px;

            margin: 0 auto;
        }


        .story-logo-box {

            flex: 0 0 auto;
        }


        .story-floating-card {

            max-width:
                calc(100% - 16px);

            right: 0;

            bottom: 0;

            min-width: 0;

            padding:
                13px 15px;
        }


        .story-floating-card strong,
        .story-floating-card span {

            max-width: 100%;

            overflow-wrap: break-word;
        }


        .about-story-content {

            width: 100%;

            max-width: 100%;
        }


        .about-story-content h2 {

            width: 100%;

            max-width: 100%;

            font-size:
                clamp(1.85rem, 7vw, 2.5rem);

            line-height: 1.2;

            overflow-wrap: break-word;
        }


        .about-story-content p {

            font-size: 0.92rem;

            line-height: 1.75;

            overflow-wrap: break-word;
        }


        .story-points {

            width: 100%;

            grid-template-columns:
                1fr 1fr;

            gap: 12px;
        }


        .story-point {

            min-width: 0;

            gap: 8px;
        }


        .story-point span {

            min-width: 0;

            overflow-wrap: break-word;
        }


        /* =========================================
           VALUES
        ========================================= */

        .values-section {

            padding:
                75px 0;
        }


        .values-section .section-heading {

            width: 100%;

            max-width: 100%;
        }


        .value-card {

            width: 100%;

            min-width: 0;

            height: 100%;

            padding: 26px;
        }


        .value-card h3,
        .value-card p {

            overflow-wrap: break-word;
        }


        .value-card p {

            line-height: 1.7;
        }


        /* =========================================
           MISSION / VISION
        ========================================= */

        .mission-section {

            padding:
                75px 0;
        }


        .mission-card {

            width: 100%;

            min-width: 0;

            height: 100%;

            padding: 30px;
        }


        .mission-card h3,
        .mission-card p {

            overflow-wrap: break-word;
        }


        .mission-card p {

            line-height: 1.75;
        }


        /* =========================================
           COMMUNITY
        ========================================= */

        .community-section {

            padding:
                75px 0;
        }


        .community-section .row {

            --bs-gutter-y: 2.5rem;
        }


        .community-content {

            width: 100%;

            max-width: 100%;
        }


        .community-content h2 {

            width: 100%;

            max-width: 100%;

            font-size:
                clamp(1.85rem, 7vw, 2.5rem);

            line-height: 1.2;

            overflow-wrap: break-word;
        }


        .community-content p {

            font-size: 0.92rem;

            line-height: 1.75;

            overflow-wrap: break-word;
        }


        .community-list {

            width: 100%;

            grid-template-columns:
                1fr 1fr;

            gap: 12px;
        }


        .community-item {

            min-width: 0;

            padding: 13px;

            align-items: flex-start;
        }


        .community-item span {

            min-width: 0;

            overflow-wrap: break-word;

            line-height: 1.45;
        }


        .community-visual {

            width: 100%;

            min-height: 330px;

            margin-top: 10px;
        }


        .community-card {

            width: 100%;

            max-width: 450px;

            min-height: 310px;

            padding:
                30px 20px;

            text-align: center;
        }


        .community-card strong,
        .community-card span {

            max-width: 100%;

            overflow-wrap: break-word;
        }


        .community-card span {

            line-height: 1.5;
        }


        /* =========================================
           CTA
        ========================================= */

        .about-cta {

            padding:
                70px 0;
        }


        .about-cta-card {

            width: 100%;

            max-width: 100%;

            padding:
                45px 25px;
        }


        .about-cta-card h2 {

            max-width: 100%;

            font-size:
                clamp(1.7rem, 7vw, 2.4rem);

            line-height: 1.25;

            overflow-wrap: break-word;
        }


        .about-cta-card p {

            width: 100%;

            max-width: 100%;

            line-height: 1.7;

            overflow-wrap: break-word;
        }


        .about-cta-card .btn {

            max-width: 100%;

            min-height: 48px;

            white-space: normal;

            line-height: 1.4;
        }


        /* =========================================
           FOOTER
        ========================================= */

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

            height: auto;
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
    ====================================================== */

    @media (max-width: 575.98px) {

        .container {

            padding-left: 16px;

            padding-right: 16px;
        }


        /* Navbar */

        .site-header .navbar {

            padding-top: 10px;

            padding-bottom: 10px;
        }


        .navbar-brand.brand {

            max-width:
                calc(100% - 52px);
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


        /* About hero */

        .about-page-hero {

            padding:
                48px 0 52px;
        }


        .about-page-badge {

            font-size: 0.66rem;

            padding:
                7px 12px;
        }


        .about-page-hero h1 {

            font-size:
                clamp(1.85rem, 9vw, 2.35rem);

            line-height: 1.15;
        }


        .about-page-hero p {

            font-size: 0.9rem;

            line-height: 1.7;
        }


        /* Story */

        .about-story {

            padding:
                58px 0;
        }


        .about-story-visual {

            min-height: 285px;

            padding-bottom: 52px;
        }


        .story-main-card {

            height: 250px;

            border-radius: 22px;
        }


        .story-logo-box {

            width: 120px;

            height: 120px;

            border-radius: 24px;
        }


        .story-logo-box img {

            width: 85px;

            height: 85px;
        }


        .story-floating-card {

            max-width:
                calc(100% - 6px);

            padding:
                10px 12px;

            gap: 9px;

            border-radius: 12px;
        }


        .story-floating-icon {

            width: 38px;

            height: 38px;

            border-radius: 10px;
        }


        .story-floating-card strong {

            font-size: 0.73rem;
        }


        .story-floating-card span {

            font-size: 0.64rem;
        }


        .about-story-content h2 {

            font-size:
                clamp(1.7rem, 8vw, 2.1rem);
        }


        .about-story-content p {

            font-size: 0.88rem;

            line-height: 1.7;
        }


        .story-points {

            grid-template-columns: 1fr;

            gap: 10px;
        }


        .story-point {

            font-size: 0.78rem;
        }


        /* Values */

        .values-section {

            padding:
                58px 0;
        }


        .value-card {

            padding: 22px;
        }


        .value-icon {

            width: 50px;

            height: 50px;

            margin-bottom: 16px;
        }


        .value-card h3 {

            font-size: 0.98rem;
        }


        .value-card p {

            font-size: 0.8rem;

            line-height: 1.65;
        }


        /* Mission */

        .mission-section {

            padding:
                58px 0;
        }


        .mission-card {

            padding: 24px;

            border-radius: 18px;
        }


        .mission-icon {

            width: 50px;

            height: 50px;

            margin-bottom: 18px;
        }


        .mission-card h3 {

            font-size: 1.15rem;
        }


        .mission-card p {

            font-size: 0.84rem;

            line-height: 1.7;
        }


        /* Community */

        .community-section {

            padding:
                58px 0;
        }


        .community-list {

            grid-template-columns: 1fr;
        }


        .community-item {

            padding:
                12px 13px;
        }


        .community-visual {

            min-height: 270px;
        }


        .community-card {

            min-height: 255px;

            padding:
                25px 18px;

            border-radius: 22px;
        }


        .community-card i {

            font-size: 4rem;
        }


        .community-card strong {

            font-size: 1rem;
        }


        .community-card span {

            font-size: 0.75rem;
        }


        /* CTA */

        .about-cta {

            padding:
                58px 0;
        }


        .about-cta-card {

            padding:
                35px 18px;

            border-radius: 20px;
        }


        .about-cta-card h2 {

            font-size:
                clamp(1.55rem, 7.5vw, 2rem);

            line-height: 1.25;
        }


        .about-cta-card p {

            font-size: 0.84rem;

            line-height: 1.65;
        }


        .about-cta-card .d-flex {

            width: 100%;

            flex-direction: column;

            align-items: stretch;
        }


        .about-cta-card .btn {

            width: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 7px;
        }


        /* Footer */

        .site-footer {

            padding-top: 45px;

            padding-bottom: 25px;
        }


        .footer-brand img {

            max-width: 50px;
        }


        .site-footer p {

            font-size: 0.86rem;
        }


        .footer-bottom {

            font-size: 0.74rem;
        }

    }


    /* =====================================================
       VERY SMALL PHONES
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


        .about-page-hero h1 {

            font-size: 1.78rem;
        }


        .about-page-hero p {

            font-size: 0.86rem;
        }


        .story-main-card {

            height: 230px;
        }


        .story-logo-box {

            width: 105px;

            height: 105px;
        }


        .story-logo-box img {

            width: 75px;

            height: 75px;
        }


        .story-floating-card {

            padding:
                9px 10px;
        }


        .story-floating-icon {

            width: 34px;

            height: 34px;
        }


        .story-floating-card strong {

            font-size: 0.68rem;
        }


        .story-floating-card span {

            font-size: 0.59rem;
        }


        .value-card {

            padding: 20px;
        }


        .mission-card {

            padding: 21px;
        }


        .community-card {

            min-height: 235px;
        }


        .about-cta-card {

            padding:
                30px 15px;
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


        <!-- Brand -->

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


        <!-- Mobile Toggle -->

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
                        class="nav-link"
                        href="index.php"
                    >
                        Home
                    </a>

                </li>


                <li class="nav-item">

                    <a
                        class="nav-link active"
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

                        <span>
                            Login
                        </span>

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

<section class="about-page-hero">


<div class="container">

    <div class="about-page-hero-content">


        <span class="about-page-badge">

            <i class="bi bi-building"></i>

            About BKHS

        </span>


        <h1>

            Growing learners.
            <span>Building futures.</span>

        </h1>


        <p>

            Bole Kale Hiwot School is committed to creating
            a supportive educational environment where students
            can learn, grow, discover their potential, and
            prepare for the future.

        </p>

    </div>

</div>


</section>

<!-- =====================================================
     OUR STORY
===================================================== -->

<section class="about-story">


<div class="container">

    <div class="row align-items-center g-5">


        <!-- Visual -->

        <div class="col-lg-6">

            <div class="about-story-visual">


                <div class="story-main-card">

                    <div class="story-logo-box">

                        <img
                            src="public/image/logo.webp"
                            alt="Bole Kale Hiwot School"
                        >

                    </div>

                </div>


                <div class="story-floating-card">

                    <div class="story-floating-icon">

                        <i class="bi bi-lightbulb"></i>

                    </div>

                    <div>

                        <strong>
                            Learning & Growth
                        </strong>

                        <span>
                            Developing potential
                        </span>

                    </div>

                </div>

            </div>

        </div>


        <!-- Content -->

        <div class="col-lg-6">

            <div class="about-story-content">


                <span class="section-label">
                    OUR STORY
                </span>


                <h2>

                    More than a school,
                    <span>a learning community.</span>

                </h2>


                <p>

                    Education plays an important role in shaping
                    the future of every student. At Bole Kale Hiwot
                    School, we strive to provide students with an
                    environment where they can develop academically,
                    socially, creatively, and personally.

                </p>


                <p>

                    We believe students learn best when they feel
                    supported, respected, encouraged, and motivated
                    to explore new ideas. Our school community brings
                    together students, teachers, parents, and staff
                    with a shared commitment to learning and growth.

                </p>


                <div class="story-points">

                    <div class="story-point">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>
                            Student-centered learning
                        </span>

                    </div>


                    <div class="story-point">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>
                            Character development
                        </span>

                    </div>


                    <div class="story-point">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>
                            Creativity and curiosity
                        </span>

                    </div>


                    <div class="story-point">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>
                            Strong school community
                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     VALUES
===================================================== -->

<section class="values-section">


<div class="container">


    <div class="section-heading">

        <span class="section-label">
            WHAT WE VALUE
        </span>

        <h2>

            Principles that
            <span>guide us</span>

        </h2>

        <p>

            Our approach to education is built around values
            that help students become responsible, confident,
            and lifelong learners.

        </p>

    </div>


    <div class="row g-4">


        <!-- Value 1 -->

        <div class="col-md-6 col-lg-3">

            <div class="value-card">

                <div class="value-icon">

                    <i class="bi bi-book"></i>

                </div>

                <h3>
                    Learning
                </h3>

                <p>

                    Encouraging students to develop strong
                    knowledge, skills, curiosity, and a love
                    for learning.

                </p>

            </div>

        </div>


        <!-- Value 2 -->

        <div class="col-md-6 col-lg-3">

            <div class="value-card">

                <div class="value-icon">

                    <i class="bi bi-heart"></i>

                </div>

                <h3>
                    Character
                </h3>

                <p>

                    Helping students develop responsibility,
                    respect, integrity, and positive relationships.

                </p>

            </div>

        </div>


        <!-- Value 3 -->

        <div class="col-md-6 col-lg-3">

            <div class="value-card">

                <div class="value-icon">

                    <i class="bi bi-stars"></i>

                </div>

                <h3>
                    Creativity
                </h3>

                <p>

                    Creating opportunities for students to
                    explore ideas, solve problems, and express
                    themselves.

                </p>

            </div>

        </div>


        <!-- Value 4 -->

        <div class="col-md-6 col-lg-3">

            <div class="value-card">

                <div class="value-icon">

                    <i class="bi bi-people"></i>

                </div>

                <h3>
                    Community
                </h3>

                <p>

                    Building positive relationships between
                    students, teachers, parents, and the wider
                    school community.

                </p>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     MISSION & VISION
===================================================== -->

<section class="mission-section">


<div class="container">


    <div class="section-heading">

        <span class="section-label">
            OUR DIRECTION
        </span>

        <h2>

            Where we are
            <span>going</span>

        </h2>

    </div>


    <div class="row g-4">


        <!-- Mission -->

        <div class="col-lg-6">

            <div class="mission-card highlight">

                <div class="mission-icon">

                    <i class="bi bi-bullseye"></i>

                </div>

                <h3>
                    Our Mission
                </h3>

                <p>

                    To provide a supportive and engaging learning
                    environment that helps students build knowledge,
                    character, creativity, confidence, and the skills
                    needed to participate positively in their
                    communities.

                </p>

            </div>

        </div>


        <!-- Vision -->

        <div class="col-lg-6">

            <div class="mission-card">

                <div class="mission-icon">

                    <i class="bi bi-eye"></i>

                </div>

                <h3>
                    Our Vision
                </h3>

                <p>

                    To nurture capable, responsible, creative, and
                    confident learners who are prepared to continue
                    learning and contribute positively to the future.

                </p>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     SCHOOL COMMUNITY
===================================================== -->

<section class="community-section">


<div class="container">

    <div class="row align-items-center g-5">


        <div class="col-lg-6">

            <div class="community-content">


                <span class="section-label">
                    OUR COMMUNITY
                </span>


                <h2>

                    Everyone has a role
                    in <span>student success.</span>

                </h2>


                <p>

                    A successful school community depends on
                    collaboration. Teachers guide and support
                    learning, parents encourage students at home,
                    school leaders provide direction, and students
                    take an active role in their own development.

                </p>


                <div class="community-list">

                    <div class="community-item">

                        <i class="bi bi-person-workspace"></i>

                        <span>
                            Dedicated teachers
                        </span>

                    </div>


                    <div class="community-item">

                        <i class="bi bi-people"></i>

                        <span>
                            Engaged parents
                        </span>

                    </div>


                    <div class="community-item">

                        <i class="bi bi-mortarboard"></i>

                        <span>
                            Motivated students
                        </span>

                    </div>


                    <div class="community-item">

                        <i class="bi bi-building"></i>

                        <span>
                            Strong leadership
                        </span>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-6">

            <div class="community-visual">

                <div class="community-card">

                    <i class="bi bi-people-fill"></i>

                    <strong>
                        One School Community
                    </strong>

                    <span>
                        Learning, supporting, and growing together
                    </span>

                </div>

            </div>

        </div>

    </div>

</div>


</section>

<!-- =====================================================
     CTA
===================================================== -->

<section class="about-cta">


<div class="container">

    <div class="about-cta-card">


        <div class="cta-decoration cta-decoration-one"></div>

        <div class="cta-decoration cta-decoration-two"></div>


        <span class="section-label light">
            JOIN OUR COMMUNITY
        </span>


        <h2>
            Ready to learn more about BKHS?
        </h2>


        <p>

            Explore our admission information or get in touch
            with the school for more information.

        </p>


        <div
            class="d-flex flex-wrap justify-content-center gap-3"
        >

            <a
                href="admission.php"
                class="btn btn-white"
            >

                Admission Information

                <i class="bi bi-arrow-right"></i>

            </a>


            <a
                href="contact.php"
                class="btn btn-outline-light"
            >

                Contact Us

            </a>

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

            <h4>
                Quick Links
            </h4>

            <ul>

                <li>
                    <a href="index.php">
                        Home
                    </a>
                </li>

                <li>
                    <a href="about.php">
                        About
                    </a>
                </li>

                <li>
                    <a href="admission.php">
                        Admission
                    </a>
                </li>

                <li>
                    <a href="gallery.php">
                        Gallery
                    </a>
                </li>

            </ul>

        </div>


        <div class="col-6 col-lg-2">

            <h4>
                Information
            </h4>

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

            <h4>
                Connect With Us
            </h4>

            <div class="social-links">

                <a
                    href="#"
                    aria-label="Facebook"
                >
                    <i class="bi bi-facebook"></i>
                </a>

                <a
                    href="#"
                    aria-label="Telegram"
                >
                    <i class="bi bi-telegram"></i>
                </a>

                <a
                    href="#"
                    aria-label="Instagram"
                >
                    <i class="bi bi-instagram"></i>
                </a>

                <a
                    href="#"
                    aria-label="YouTube"
                >
                    <i class="bi bi-youtube"></i>
                </a>

            </div>

        </div>

    </div>


    <div class="footer-bottom">

        <span>

            © <?php echo date('Y'); ?>
            Bole Kale Hiwot School.
            All rights reserved.

        </span>


        <span>

            BKHS School Management System

        </span>

    </div>

</div>


</footer>

<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>
