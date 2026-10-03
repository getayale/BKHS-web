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
        content="Contact Bole Kale Hiwot School. Get in touch with our school for admissions, general inquiries, and support."
    >

    <title>Contact Us | Bole Kale Hiwot School</title>

    <link
        rel="icon"
        type="image/webp"
        href="public/image/logo.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Main CSS -->
    <link
        rel="stylesheet"
        href="public/css/style.css"
    >

    <style>

        :root {
            --contact-primary: #2563eb;
            --contact-primary-dark: #1d4ed8;
            --contact-primary-soft: #eff6ff;
            --contact-dark: #111827;
            --contact-text: #4b5563;
            --contact-muted: #6b7280;
            --contact-border: #e5e7eb;
            --contact-bg: #f8fafc;
            --contact-white: #ffffff;
        }

        body {
            font-family: "Inter", sans-serif;
            color: var(--contact-text);
            background: #ffffff;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: "Plus Jakarta Sans", sans-serif;
            color: var(--contact-dark);
        }

        /* ========================================
           NAVBAR
        ======================================== */

        .contact-navbar {
            min-height: 78px;
            background: #ffffff;
            border-bottom: 1px solid var(--contact-border);
        }

        .contact-brand {
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .contact-logo {
            width: 58px;
            height: 58px;
            object-fit: contain;
        }

        .contact-brand-info {
            margin-left: 10px;
            line-height: 1.1;
        }

        .contact-brand-name {
            display: block;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 1.08rem;
            font-weight: 800;
            color: #111827;
        }

        .contact-brand-school {
            display: block;
            margin-top: 4px;
            font-size: 0.76rem;
            color: #6b7280;
        }

        .contact-navbar .navbar-nav {
            gap: 4px;
        }

        .contact-navbar .nav-link {
            color: #374151 !important;
            font-size: 0.93rem;
            font-weight: 500;
            padding: 10px 13px !important;
            transition: color 0.2s ease;
        }

        .contact-navbar .nav-link:hover,
        .contact-navbar .nav-link.active {
            color: var(--contact-primary) !important;
        }

        .contact-login {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 19px;
            border: 1px solid var(--contact-primary);
            border-radius: 8px;
            color: var(--contact-primary);
            background: transparent;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .contact-login:hover {
            color: #ffffff;
            background: var(--contact-primary);
        }

        .contact-navbar .navbar-toggler {
            border: 1px solid #d1d5db;
            padding: 7px 9px;
            box-shadow: none;
        }

        /* ========================================
           HERO
        ======================================== */

        .contact-hero {
            position: relative;
            padding: 90px 0 85px;
            background:
                radial-gradient(
                    circle at 85% 20%,
                    rgba(37, 99, 235, 0.09),
                    transparent 30%
                ),
                linear-gradient(
                    180deg,
                    #f8fafc 0%,
                    #ffffff 100%
                );
            overflow: hidden;
        }

        .contact-hero::before {
            content: "";
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.04);
            top: -150px;
            left: -100px;
        }

        .contact-hero-content {
            position: relative;
            z-index: 1;
            max-width: 780px;
            margin: 0 auto;
            text-align: center;
        }

        .contact-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 13px;
            margin-bottom: 20px;
            border-radius: 999px;
            background: var(--contact-primary-soft);
            color: var(--contact-primary);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .contact-hero h1 {
            margin-bottom: 20px;
            font-size: clamp(2.25rem, 5vw, 4rem);
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .contact-hero h1 span {
            color: var(--contact-primary);
        }

        .contact-hero p {
            max-width: 650px;
            margin: 0 auto;
            color: var(--contact-muted);
            font-size: 1.05rem;
            line-height: 1.8;
        }

        /* ========================================
           CONTACT INFORMATION
        ======================================== */

        .contact-info-section {
            padding: 80px 0;
        }

        .contact-info-card {
            height: 100%;
            padding: 28px 24px;
            background: #ffffff;
            border: 1px solid var(--contact-border);
            border-radius: 14px;
            transition:
                transform 0.25s ease,
                box-shadow 0.25s ease,
                border-color 0.25s ease;
        }

        .contact-info-card:hover {
            transform: translateY(-5px);
            border-color: #bfdbfe;
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08);
        }

        .contact-info-icon {
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            border-radius: 12px;
            background: var(--contact-primary-soft);
            color: var(--contact-primary);
            font-size: 1.35rem;
        }

        .contact-info-card h3 {
            margin-bottom: 10px;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .contact-info-card p {
            margin-bottom: 0;
            color: var(--contact-muted);
            font-size: 0.92rem;
            line-height: 1.7;
        }

        .contact-info-card a {
            color: var(--contact-text);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .contact-info-card a:hover {
            color: var(--contact-primary);
        }

        /* ========================================
           CONTACT FORM SECTION
        ======================================== */

        .contact-form-section {
            padding: 90px 0;
            background: var(--contact-bg);
        }

        .contact-form-wrapper {
            max-width: 1150px;
            margin: 0 auto;
        }

        .contact-form-intro {
            padding: 15px 35px 15px 0;
        }

        .section-label {
            display: inline-block;
            margin-bottom: 15px;
            color: var(--contact-primary);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .contact-form-intro h2 {
            margin-bottom: 18px;
            font-size: clamp(1.8rem, 3vw, 2.7rem);
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.03em;
        }

        .contact-form-intro > p {
            margin-bottom: 28px;
            color: var(--contact-muted);
            line-height: 1.8;
        }

        .contact-note {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            padding: 17px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid var(--contact-border);
        }

        .contact-note i {
            color: var(--contact-primary);
            font-size: 1.1rem;
            margin-top: 2px;
        }

        .contact-note p {
            margin: 0;
            color: var(--contact-muted);
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .contact-form-card {
            padding: 34px;
            background: #ffffff;
            border: 1px solid var(--contact-border);
            border-radius: 16px;
            box-shadow: 0 15px 45px rgba(15, 23, 42, 0.07);
        }

        .form-label {
            margin-bottom: 8px;
            color: #374151;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .form-control,
        .form-select {
            min-height: 48px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            color: #111827;
            font-size: 0.92rem;
            box-shadow: none;
            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        textarea.form-control {
            min-height: 145px;
            resize: vertical;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--contact-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .btn-send-message {
            min-height: 48px;
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            background: var(--contact-primary);
            color: #ffffff;
            font-size: 0.92rem;
            font-weight: 600;
            transition:
                background 0.2s ease,
                transform 0.2s ease;
        }

        .btn-send-message:hover {
            background: var(--contact-primary-dark);
            color: #ffffff;
            transform: translateY(-1px);
        }

        /* ========================================
           OFFICE SECTION
        ======================================== */

        .office-section {
            padding: 90px 0;
        }

        .office-heading {
            max-width: 700px;
            margin: 0 auto 50px;
            text-align: center;
        }

        .office-heading h2 {
            margin-bottom: 14px;
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .office-heading p {
            margin: 0;
            color: var(--contact-muted);
            line-height: 1.7;
        }

        .office-card {
            overflow: hidden;
            height: 100%;
            border: 1px solid var(--contact-border);
            border-radius: 14px;
            background: #ffffff;
        }

        /* ========================================
           REAL GOOGLE MAP
        ======================================== */

        .office-map {
            position: relative;
            min-height: 420px;
            width: 100%;
            background: #e5e7eb;
            overflow: hidden;
        }

        .office-map iframe {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }

        .map-overlay-button {
            position: absolute;
            left: 18px;
            bottom: 18px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border-radius: 9px;
            background: #ffffff;
            color: var(--contact-primary);
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .map-overlay-button:hover {
            color: var(--contact-primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22);
        }

        .office-details {
            height: 100%;
            padding: 28px;
        }

        .office-details h3 {
            margin-bottom: 18px;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .office-detail {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 17px;
        }

        .office-detail:last-child {
            margin-bottom: 0;
        }

        .office-detail i {
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--contact-primary-soft);
            color: var(--contact-primary);
            font-size: 0.95rem;
        }

        .office-detail div {
            color: var(--contact-muted);
            font-size: 0.9rem;
            line-height: 1.6;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .office-detail strong {
            display: block;
            margin-bottom: 2px;
            color: var(--contact-dark);
            font-size: 0.88rem;
        }

        .office-detail a {
            color: var(--contact-text);
            text-decoration: none;
        }

        .office-detail a:hover {
            color: var(--contact-primary);
        }

        /* ========================================
           FAQ / HELP
        ======================================== */

        .help-section {
            padding: 80px 0;
            background: #f8fafc;
        }

        .help-heading {
            max-width: 680px;
            margin: 0 auto 45px;
            text-align: center;
        }

        .help-heading h2 {
            margin-bottom: 14px;
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            font-weight: 800;
        }

        .help-heading p {
            margin: 0;
            color: var(--contact-muted);
            line-height: 1.7;
        }

        .accordion-item {
            margin-bottom: 12px;
            overflow: hidden;
            border: 1px solid var(--contact-border) !important;
            border-radius: 10px !important;
            background: #ffffff;
        }

        .accordion-button {
            padding: 18px 20px;
            color: var(--contact-dark);
            background: #ffffff;
            font-size: 0.94rem;
            font-weight: 600;
            box-shadow: none !important;
        }

        .accordion-button:not(.collapsed) {
            color: var(--contact-primary);
            background: var(--contact-primary-soft);
        }

        .accordion-body {
            padding: 0 20px 20px;
            color: var(--contact-muted);
            font-size: 0.9rem;
            line-height: 1.7;
        }

        /* ========================================
           CTA
        ======================================== */

        .contact-cta {
            padding: 80px 0;
        }

        .contact-cta-box {
            position: relative;
            overflow: hidden;
            padding: 55px 40px;
            border-radius: 18px;
            background: linear-gradient(
                135deg,
                #2563eb,
                #1d4ed8
            );
            text-align: center;
            color: #ffffff;
        }

        .contact-cta-box::before,
        .contact-cta-box::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .contact-cta-box::before {
            width: 260px;
            height: 260px;
            top: -150px;
            right: -70px;
        }

        .contact-cta-box::after {
            width: 180px;
            height: 180px;
            bottom: -100px;
            left: -50px;
        }

        .contact-cta-content {
            position: relative;
            z-index: 1;
            max-width: 680px;
            margin: 0 auto;
        }

        .contact-cta-box h2 {
            margin-bottom: 15px;
            color: #ffffff;
            font-size: clamp(1.7rem, 3vw, 2.4rem);
            font-weight: 800;
        }

        .contact-cta-box p {
            margin-bottom: 28px;
            color: rgba(255, 255, 255, 0.86);
            line-height: 1.7;
        }

        .btn-cta-white {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 23px;
            border-radius: 8px;
            background: #ffffff;
            color: var(--contact-primary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 700;
            transition: transform 0.2s ease;
        }

        .btn-cta-white:hover {
            color: var(--contact-primary-dark);
            transform: translateY(-2px);
        }

        /* ========================================
           FOOTER
        ======================================== */

        .contact-footer {
            padding: 60px 0 25px;
            background: #111827;
            color: #9ca3af;
        }

        .footer-logo {
            width: 55px;
            height: 55px;
            object-fit: contain;
            margin-bottom: 15px;
        }

        .footer-brand-name {
            margin-bottom: 8px;
            color: #ffffff;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .footer-description {
            max-width: 330px;
            margin-bottom: 20px;
            color: #9ca3af;
            font-size: 0.88rem;
            line-height: 1.7;
        }

        .footer-title {
            margin-bottom: 17px;
            color: #ffffff;
            font-size: 0.92rem;
            font-weight: 700;
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            font-size: 0.86rem;
            transition: color 0.2s ease;
        }

        .footer-links a:hover {
            color: #ffffff;
        }

        .footer-socials {
            display: flex;
            gap: 9px;
            margin-top: 18px;
        }

        .footer-social {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #374151;
            border-radius: 8px;
            color: #d1d5db;
            text-decoration: none;
            transition:
                background 0.2s ease,
                color 0.2s ease;
        }

        .footer-social:hover {
            background: #ffffff;
            color: #111827;
        }

        .footer-bottom {
            margin-top: 45px;
            padding-top: 20px;
            border-top: 1px solid #374151;
            color: #9ca3af;
            font-size: 0.82rem;
        }

        /* ========================================
           RESPONSIVE
        ======================================== */

        @media (max-width: 991.98px) {

            .contact-navbar .navbar-collapse {
                padding: 15px 0;
            }

            .contact-navbar .navbar-nav {
                gap: 0;
            }

            .contact-navbar .nav-link {
                padding: 10px 5px !important;
            }

            .contact-login {
                margin-top: 8px;
            }

            .contact-hero {
                padding: 70px 0;
            }

            .contact-form-intro {
                padding: 0;
                margin-bottom: 35px;
            }

            .contact-form-section {
                padding: 70px 0;
            }

            .office-section {
                padding: 70px 0;
            }

            .office-map {
                min-height: 360px;
            }
        }

        /* ========================================
           TABLET / MOBILE
        ======================================== */

        @media (max-width: 767.98px) {

            html,
            body {
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }

            .contact-navbar {
                min-height: 68px;
            }

            .contact-navbar .navbar {
                padding: 8px 0;
            }

            .contact-navbar .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .contact-brand {
                min-width: 0;
                max-width: calc(100% - 58px);
            }

            .contact-logo {
                width: 50px;
                height: 50px;
                flex-shrink: 0;
            }

            .contact-brand-info {
                min-width: 0;
                max-width: calc(100% - 60px);
            }

            .contact-brand-name {
                font-size: 1rem;
                line-height: 1.15;
                white-space: normal;
            }

            .contact-navbar .navbar-toggler {
                width: 43px;
                height: 43px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                padding: 0;
                border-radius: 10px;
            }

            .contact-navbar .navbar-collapse {
                margin-top: 9px;
                padding: 8px;
                border: 1px solid var(--contact-border);
                border-radius: 14px;
                background: #ffffff;
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            }

            .contact-navbar .navbar-nav {
                width: 100%;
                gap: 3px;
            }

            .contact-navbar .nav-item {
                width: 100%;
            }

            .contact-navbar .nav-link {
                width: 100%;
                padding: 10px 12px !important;
                border-radius: 9px;
            }

            .contact-navbar .nav-link:hover,
            .contact-navbar .nav-link.active {
                background: var(--contact-primary-soft);
            }

            .contact-login {
                width: 100%;
                justify-content: center;
                margin-top: 5px;
                padding: 10px 15px;
            }

            .contact-hero {
                padding: 58px 0 60px;
            }

            .contact-hero-content {
                width: 100%;
                max-width: 100%;
                padding: 0 8px;
            }

            .contact-label {
                margin-bottom: 16px;
                padding: 7px 11px;
                font-size: 0.72rem;
            }

            .contact-hero h1 {
                margin-bottom: 17px;
                font-size: 2.25rem;
                line-height: 1.12;
                letter-spacing: -0.035em;
            }

            .contact-hero p {
                max-width: 100%;
                font-size: 0.93rem;
                line-height: 1.7;
            }

            .contact-info-section {
                padding: 55px 0;
            }

            .contact-info-section .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .contact-info-section .row {
                --bs-gutter-x: 10px;
                --bs-gutter-y: 10px;
            }

            .contact-info-card {
                min-width: 0;
                padding: 20px 16px;
                border-radius: 12px;
            }

            .contact-info-icon {
                width: 44px;
                height: 44px;
                margin-bottom: 13px;
                border-radius: 10px;
                font-size: 1.1rem;
            }

            .contact-info-card h3 {
                margin-bottom: 7px;
                font-size: 0.94rem;
            }

            .contact-info-card p {
                font-size: 0.82rem;
                line-height: 1.5;
                overflow-wrap: anywhere;
            }

            .contact-form-section {
                padding: 55px 0;
            }

            .contact-form-section .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .contact-form-intro {
                margin-bottom: 28px;
            }

            .contact-form-intro h2 {
                font-size: 1.8rem;
                line-height: 1.2;
            }

            .contact-form-intro > p {
                margin-bottom: 20px;
                font-size: 0.9rem;
                line-height: 1.7;
            }

            .contact-note {
                gap: 10px;
                padding: 14px;
            }

            .contact-note p {
                font-size: 0.82rem;
                line-height: 1.55;
            }

            .contact-form-card {
                padding: 24px 18px;
                border-radius: 14px;
            }

            .contact-form-card .row {
                --bs-gutter-x: 12px;
                --bs-gutter-y: 14px;
            }

            .form-label {
                font-size: 0.84rem;
            }

            .form-control,
            .form-select {
                min-height: 47px;
                font-size: 0.88rem;
            }

            textarea.form-control {
                min-height: 130px;
            }

            .btn-send-message {
                width: 100%;
                min-height: 47px;
            }

            .office-section {
                padding: 55px 0;
            }

            .office-section .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .office-heading {
                margin-bottom: 30px;
            }

            .office-heading h2 {
                font-size: 1.8rem;
            }

            .office-heading p {
                font-size: 0.9rem;
            }

            .office-card {
                border-radius: 12px;
            }

            .office-map {
                min-height: 300px;
            }

            .map-overlay-button {
                left: 12px;
                bottom: 12px;
                padding: 9px 12px;
                font-size: 0.78rem;
            }

            .office-details {
                padding: 22px 18px;
            }

            .office-details h3 {
                margin-bottom: 17px;
                font-size: 1.08rem;
            }

            .office-detail {
                gap: 10px;
                margin-bottom: 14px;
            }

            .office-detail i {
                width: 32px;
                height: 32px;
                font-size: 0.88rem;
            }

            .office-detail div {
                font-size: 0.84rem;
                line-height: 1.55;
                overflow-wrap: anywhere;
            }

            .help-section {
                padding: 55px 0;
            }

            .help-section .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .help-heading {
                margin-bottom: 30px;
            }

            .help-heading h2 {
                font-size: 1.8rem;
                line-height: 1.2;
            }

            .help-heading p {
                font-size: 0.9rem;
            }

            .accordion-item {
                margin-bottom: 9px;
                border-radius: 10px !important;
            }

            .accordion-button {
                padding: 15px 16px;
                padding-right: 42px;
                font-size: 0.86rem;
                line-height: 1.45;
            }

            .accordion-body {
                padding: 0 16px 16px;
                font-size: 0.84rem;
                line-height: 1.65;
            }

            .contact-cta {
                padding: 55px 0;
            }

            .contact-cta .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .contact-cta-box {
                padding: 40px 20px;
                border-radius: 14px;
            }

            .contact-cta-content {
                max-width: 100%;
            }

            .contact-cta-box h2 {
                font-size: 1.7rem;
                line-height: 1.25;
            }

            .contact-cta-box p {
                margin-bottom: 23px;
                font-size: 0.88rem;
                line-height: 1.65;
            }

            .btn-cta-white {
                width: 100%;
                max-width: 300px;
                justify-content: center;
                padding: 12px 16px;
                font-size: 0.86rem;
            }

            .contact-footer {
                padding: 48px 0 22px;
            }

            .contact-footer .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .contact-footer .row {
                --bs-gutter-x: 18px;
                --bs-gutter-y: 28px;
            }

            .footer-description {
                max-width: 100%;
                font-size: 0.84rem;
            }

            .footer-socials {
                flex-wrap: wrap;
            }

            .footer-bottom {
                margin-top: 32px;
                padding-top: 17px;
                line-height: 1.6;
            }

            .footer-bottom .row {
                row-gap: 5px;
            }
        }

        /* ========================================
           SMALL PHONES
        ======================================== */

        @media (max-width: 575.98px) {

            .contact-brand-school {
                display: none;
            }

            .contact-brand-name {
                font-size: 0.96rem;
            }

            .contact-hero {
                padding: 50px 0 52px;
            }

            .contact-hero h1 {
                font-size: 2rem;
            }

            .contact-hero p {
                font-size: 0.88rem;
            }

            /*
             * FOUR CONTACT CARDS REMAIN COMPACT
             * PHONE + EMAIL + LOCATION + HOURS
             * STAY IN TWO COLUMNS ON SMALL SCREENS.
             */

            .contact-info-section .row > .col-md-6,
            .contact-info-section .row > .col-lg-3 {
                width: 50%;
                flex: 0 0 50%;
            }

            .contact-info-card {
                min-height: 100%;
                padding: 16px 12px;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
            }

            .contact-info-icon {
                width: 40px;
                height: 40px;
                margin-bottom: 9px;
                border-radius: 9px;
                font-size: 1rem;
            }

            .contact-info-card h3 {
                font-size: 0.86rem;
                margin-bottom: 5px;
            }

            .contact-info-card p {
                width: 100%;
                font-size: 0.74rem;
                line-height: 1.4;
            }

            .contact-info-card a {
                display: block;
                max-width: 100%;
                overflow-wrap: anywhere;
                word-break: break-word;
            }

            .contact-form-card {
                padding: 21px 15px;
            }

            .office-map {
                min-height: 260px;
            }

            .office-details {
                padding: 21px 16px;
            }

            .contact-cta-box h2 {
                font-size: 1.6rem;
            }

            .contact-cta-box {
                padding: 36px 17px;
            }

            .footer-description {
                font-size: 0.82rem;
            }
        }

        /* ========================================
           VERY SMALL PHONES
        ======================================== */

        @media (max-width: 420px) {

            .contact-navbar .container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .contact-logo {
                width: 45px;
                height: 45px;
            }

            .contact-brand-info {
                margin-left: 8px;
            }

            .contact-brand-name {
                font-size: 0.91rem;
            }

            .contact-navbar .navbar-toggler {
                width: 41px;
                height: 41px;
            }

            .contact-hero {
                padding: 45px 0 48px;
            }

            .contact-hero-content {
                padding: 0 3px;
            }

            .contact-hero h1 {
                font-size: 1.85rem;
            }

            .contact-hero p {
                font-size: 0.84rem;
            }

            .contact-info-section {
                padding: 48px 0;
            }

            .contact-info-section .container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .contact-info-section .row {
                --bs-gutter-x: 8px;
                --bs-gutter-y: 8px;
            }

            .contact-info-card {
                padding: 14px 10px;
                border-radius: 11px;
            }

            .contact-info-icon {
                width: 36px;
                height: 36px;
                margin-bottom: 8px;
                border-radius: 8px;
                font-size: 0.92rem;
            }

            .contact-info-card h3 {
                font-size: 0.79rem;
                margin-bottom: 4px;
            }

            .contact-info-card p {
                font-size: 0.68rem;
                line-height: 1.35;
            }

            .contact-form-section,
            .office-section,
            .help-section,
            .contact-cta {
                padding-top: 48px;
                padding-bottom: 48px;
            }

            .contact-form-section .container,
            .office-section .container,
            .help-section .container,
            .contact-cta .container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .contact-form-intro h2,
            .office-heading h2,
            .help-heading h2 {
                font-size: 1.65rem;
            }

            .contact-form-card {
                padding: 19px 13px;
            }

            .contact-note {
                padding: 13px;
            }

            .contact-note p {
                font-size: 0.78rem;
            }

            .office-map {
                min-height: 230px;
            }

            .map-overlay-button {
                left: 9px;
                bottom: 9px;
                padding: 8px 10px;
                font-size: 0.72rem;
            }

            .office-details {
                padding: 19px 14px;
            }

            .office-details h3 {
                font-size: 1rem;
            }

            .office-detail {
                gap: 8px;
                margin-bottom: 12px;
            }

            .office-detail i {
                width: 30px;
                height: 30px;
                font-size: 0.82rem;
            }

            .office-detail div {
                font-size: 0.79rem;
            }

            .accordion-button {
                padding: 14px 14px;
                padding-right: 40px;
                font-size: 0.81rem;
            }

            .accordion-body {
                padding: 0 14px 14px;
                font-size: 0.79rem;
            }

            .contact-cta-box {
                padding: 32px 15px;
            }

            .contact-cta-box h2 {
                font-size: 1.48rem;
            }

            .contact-cta-box p {
                font-size: 0.81rem;
            }

            .btn-cta-white {
                max-width: 100%;
                font-size: 0.82rem;
            }

            .contact-footer {
                padding-top: 42px;
            }

            .footer-bottom {
                font-size: 0.76rem;
            }
        }

    </style>

</head>

<body>

<!-- ==========================================
     NAVBAR
========================================== -->

<header class="contact-navbar sticky-top">

    <nav class="navbar navbar-expand-lg">

        <div class="container">

            <a
                href="index.php"
                class="navbar-brand contact-brand"
            >

                <img
                    src="public/image/logo.webp"
                    alt="Bole Kale Hiwot School Logo"
                    class="contact-logo"
                >

                <div class="contact-brand-info">

                    <span class="contact-brand-name">
                        Bole Kale Hiwot
                    </span>

                    <span class="contact-brand-school">
                        School
                    </span>

                </div>

            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#contactNavbar"
                aria-controls="contactNavbar"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >

                <span class="navbar-toggler-icon"></span>

            </button>

            <div
                class="collapse navbar-collapse"
                id="contactNavbar"
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
                            class="nav-link active"
                            href="contact.php"
                            aria-current="page"
                        >
                            Contact
                        </a>
                    </li>

                    <li class="nav-item ms-lg-3">

                        <a
                            href="auth/login.php"
                            class="contact-login"
                        >

                            <i class="bi bi-box-arrow-in-right"></i>

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


<!-- ==========================================
     HERO
========================================== -->

<section class="contact-hero">

    <div class="container">

        <div class="contact-hero-content">

            <div class="contact-label">

                <i class="bi bi-chat-dots"></i>

                Contact BKHS

            </div>

            <h1>

                We'd love to

                <span>
                    hear from you.
                </span>

            </h1>

            <p>
                Whether you have a question about admissions, school
                programs, student services, or anything else, our team
                is here to help.
            </p>

        </div>

    </div>

</section>


<!-- ==========================================
     CONTACT INFORMATION
========================================== -->

<section class="contact-info-section">

    <div class="container">

        <div class="row g-4">

            <!-- Phone -->

            <div class="col-md-6 col-lg-3">

                <div class="contact-info-card">

                    <div class="contact-info-icon">
                        <i class="bi bi-telephone"></i>
                    </div>

                    <h3>
                        Phone
                    </h3>

                    <p>
                        <a href="tel:+251000000000">
                            +251 00 000 0000
                        </a>
                    </p>

                </div>

            </div>


            <!-- Email -->

            <div class="col-md-6 col-lg-3">

                <div class="contact-info-card">

                    <div class="contact-info-icon">
                        <i class="bi bi-envelope"></i>
                    </div>

                    <h3>
                        Email
                    </h3>

                    <p>
                        <a href="mailto:info@bkhschool.com">
                            info@bkhschool.com
                        </a>
                    </p>

                </div>

            </div>


            <!-- Location -->

            <div class="col-md-6 col-lg-3">

                <div class="contact-info-card">

                    <div class="contact-info-icon">
                        <i class="bi bi-geo-alt"></i>
                    </div>

                    <h3>
                        Location
                    </h3>

                    <p>
                        Addis Ababa, Ethiopia
                    </p>

                </div>

            </div>


            <!-- Office Hours -->

            <div class="col-md-6 col-lg-3">

                <div class="contact-info-card">

                    <div class="contact-info-icon">
                        <i class="bi bi-clock"></i>
                    </div>

                    <h3>
                        Office Hours
                    </h3>

                    <p>
                        Monday – Friday<br>
                        8:00 AM – 5:00 PM
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ==========================================
     CONTACT FORM
========================================== -->

<section class="contact-form-section">

    <div class="container">

        <div class="contact-form-wrapper">

            <div class="row align-items-center g-lg-5">

                <!-- Intro -->

                <div class="col-lg-5">

                    <div class="contact-form-intro">

                        <span class="section-label">
                            SEND US A MESSAGE
                        </span>

                        <h2>
                            Have a question?
                            Let's talk.
                        </h2>

                        <p>
                            Send us your message using the form.
                            Please provide enough information so our
                            team can understand how we can help you.
                        </p>

                        <div class="contact-note">

                            <i class="bi bi-info-circle"></i>

                            <p>
                                For admission-related questions,
                                please visit our Admission page for
                                application information and requirements.
                            </p>

                        </div>

                    </div>

                </div>


                <!-- Form -->

                <div class="col-lg-7">

                    <div class="contact-form-card">

                        <form
                            action=""
                            method="POST"
                            novalidate
                        >

                            <div class="row g-3">

                                <!-- Name -->

                                <div class="col-md-6">

                                    <label
                                        for="name"
                                        class="form-label"
                                    >
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        id="name"
                                        name="name"
                                        placeholder="Enter your name"
                                        required
                                    >

                                </div>


                                <!-- Email -->

                                <div class="col-md-6">

                                    <label
                                        for="email"
                                        class="form-label"
                                    >
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control"
                                        id="email"
                                        name="email"
                                        placeholder="you@example.com"
                                        required
                                    >

                                </div>


                                <!-- Phone -->

                                <div class="col-md-6">

                                    <label
                                        for="phone"
                                        class="form-label"
                                    >
                                        Phone Number
                                    </label>

                                    <input
                                        type="tel"
                                        class="form-control"
                                        id="phone"
                                        name="phone"
                                        placeholder="+251..."
                                    >

                                </div>


                                <!-- Subject -->

                                <div class="col-md-6">

                                    <label
                                        for="subject"
                                        class="form-label"
                                    >
                                        Subject
                                    </label>

                                    <select
                                        class="form-select"
                                        id="subject"
                                        name="subject"
                                        required
                                    >

                                        <option
                                            value=""
                                            selected
                                            disabled
                                        >
                                            Select a subject
                                        </option>

                                        <option value="admission">
                                            Admission
                                        </option>

                                        <option value="general">
                                            General Inquiry
                                        </option>

                                        <option value="academic">
                                            Academic Information
                                        </option>

                                        <option value="student">
                                            Student Services
                                        </option>

                                        <option value="other">
                                            Other
                                        </option>

                                    </select>

                                </div>


                                <!-- Message -->

                                <div class="col-12">

                                    <label
                                        for="message"
                                        class="form-label"
                                    >
                                        Message
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="message"
                                        name="message"
                                        placeholder="Write your message here..."
                                        required
                                    ></textarea>

                                </div>


                                <!-- Submit -->

                                <div class="col-12">

                                    <button
                                        type="submit"
                                        class="btn btn-send-message"
                                    >

                                        <i class="bi bi-send me-2"></i>

                                        Send Message

                                    </button>

                                </div>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ==========================================
     OFFICE / LOCATION
========================================== -->

<section class="office-section">

    <div class="container">

        <div class="office-heading">

            <span class="section-label">
                FIND US
            </span>

            <h2>
                Visit our school
            </h2>

            <p>
                We welcome parents, students, and visitors to
                connect with our school community.
            </p>

        </div>


        <div class="row g-0 office-card">

            <!-- Real Google Map -->

            <div class="col-lg-7">

                <div
                    class="office-map"
                    aria-label="Bole Kale Hiwot School Google Maps location"
                >

                    <iframe
                        src="https://www.google.com/maps?q=8.9797202,38.7759628&z=17&output=embed"
                        title="Bole Kale Hiwot School Location"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen
                    ></iframe>

                    <a
                        href="https://maps.app.goo.gl/xMMesrijnUAzaEYdA"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="map-overlay-button"
                    >

                        <i class="bi bi-google"></i>

                        Open in Google Maps

                    </a>

                </div>

            </div>


            <!-- Details -->

            <div class="col-lg-5">

                <div class="office-details">

                    <h3>
                        Bole Kale Hiwot School
                    </h3>


                    <div class="office-detail">

                        <i class="bi bi-geo-alt"></i>

                        <div>

                            <strong>
                                Address
                            </strong>

                            Addis Ababa, Ethiopia

                        </div>

                    </div>


                    <div class="office-detail">

                        <i class="bi bi-telephone"></i>

                        <div>

                            <strong>
                                Phone
                            </strong>

                            <a href="tel:+251000000000">
                                +251 00 000 0000
                            </a>

                        </div>

                    </div>


                    <div class="office-detail">

                        <i class="bi bi-envelope"></i>

                        <div>

                            <strong>
                                Email
                            </strong>

                            <a href="mailto:info@bkhschool.com">
                                info@bkhschool.com
                            </a>

                        </div>

                    </div>


                    <div class="office-detail">

                        <i class="bi bi-clock"></i>

                        <div>

                            <strong>
                                Office Hours
                            </strong>

                            Monday – Friday<br>

                            8:00 AM – 5:00 PM

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ==========================================
     HELP / FAQ
========================================== -->

<section class="help-section">

    <div class="container">

        <div class="help-heading">

            <span class="section-label">
                QUICK HELP
            </span>

            <h2>
                Frequently asked questions
            </h2>

            <p>
                Here are some common questions visitors may have
                about contacting and connecting with BKHS.
            </p>

        </div>


        <div
            class="accordion mx-auto"
            id="contactFaq"
            style="max-width: 850px;"
        >

            <!-- FAQ 1 -->

            <div class="accordion-item">

                <h3 class="accordion-header">

                    <button
                        class="accordion-button"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#faqOne"
                        aria-expanded="true"
                        aria-controls="faqOne"
                    >
                        How can I ask about admission?
                    </button>

                </h3>

                <div
                    id="faqOne"
                    class="accordion-collapse collapse show"
                    data-bs-parent="#contactFaq"
                >

                    <div class="accordion-body">

                        You can visit our Admission page for
                        application information, requirements,
                        and other admission-related details.
                        You can also contact the school directly
                        using the information above.

                    </div>

                </div>

            </div>


            <!-- FAQ 2 -->

            <div class="accordion-item">

                <h3 class="accordion-header">

                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#faqTwo"
                        aria-expanded="false"
                        aria-controls="faqTwo"
                    >
                        Can I visit the school?
                    </button>

                </h3>

                <div
                    id="faqTwo"
                    class="accordion-collapse collapse"
                    data-bs-parent="#contactFaq"
                >

                    <div class="accordion-body">

                        Yes. Visitors can contact the school
                        office to confirm suitable visiting
                        times before coming to the school.

                    </div>

                </div>

            </div>


            <!-- FAQ 3 -->

            <div class="accordion-item">

                <h3 class="accordion-header">

                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#faqThree"
                        aria-expanded="false"
                        aria-controls="faqThree"
                    >
                        How can I contact the school about a student?
                    </button>

                </h3>

                <div
                    id="faqThree"
                    class="accordion-collapse collapse"
                    data-bs-parent="#contactFaq"
                >

                    <div class="accordion-body">

                        For student-related questions, please
                        contact the school office and provide
                        the relevant information so the appropriate
                        department can assist you.

                    </div>

                </div>

            </div>


            <!-- FAQ 4 -->

            <div class="accordion-item">

                <h3 class="accordion-header">

                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#faqFour"
                        aria-expanded="false"
                        aria-controls="faqFour"
                    >
                        Can I send a message online?
                    </button>

                </h3>

                <div
                    id="faqFour"
                    class="accordion-collapse collapse"
                    data-bs-parent="#contactFaq"
                >

                    <div class="accordion-body">

                        Yes. Use the contact form on this page
                        to send your inquiry. The form can later
                        be connected to the BKHS database so that
                        submitted messages can be managed by the
                        appropriate school staff.

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ==========================================
     CTA
========================================== -->

<section class="contact-cta">

    <div class="container">

        <div class="contact-cta-box">

            <div class="contact-cta-content">

                <h2>
                    Interested in joining BKHS?
                </h2>

                <p>
                    Learn more about our admission process and
                    take the next step toward becoming part of
                    the Bole Kale Hiwot School community.
                </p>

                <a
                    href="admission.php"
                    class="btn-cta-white"
                >

                    <i class="bi bi-arrow-right"></i>

                    Admission Information

                </a>

            </div>

        </div>

    </div>

</section>


<!-- ==========================================
     FOOTER
========================================== -->

<footer class="contact-footer">

    <div class="container">

        <div class="row g-4">

            <!-- Brand -->

            <div class="col-lg-5">

                <img
                    src="public/image/logo.webp"
                    alt="Bole Kale Hiwot School Logo"
                    class="footer-logo"
                >

                <div class="footer-brand-name">
                    Bole Kale Hiwot School
                </div>

                <p class="footer-description">
                    Building knowledge, character, creativity,
                    and a brighter future for every student.
                </p>

                <div class="footer-socials">

                    <a
                        href="#"
                        class="footer-social"
                        aria-label="Facebook"
                    >
                        <i class="bi bi-facebook"></i>
                    </a>

                    <a
                        href="#"
                        class="footer-social"
                        aria-label="Telegram"
                    >
                        <i class="bi bi-telegram"></i>
                    </a>

                    <a
                        href="#"
                        class="footer-social"
                        aria-label="YouTube"
                    >
                        <i class="bi bi-youtube"></i>
                    </a>

                    <a
                        href="mailto:info@bkhschool.com"
                        class="footer-social"
                        aria-label="Email"
                    >
                        <i class="bi bi-envelope"></i>
                    </a>

                </div>

            </div>


            <!-- Quick Links -->

            <div class="col-6 col-lg-3">

                <h3 class="footer-title">
                    Quick Links
                </h3>

                <ul class="footer-links">

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
                        <a href="announcements.php">
                            Announcements
                        </a>
                    </li>

                    <li>
                        <a href="gallery.php">
                            Gallery
                        </a>
                    </li>

                </ul>

            </div>


            <!-- Information -->

            <div class="col-6 col-lg-4">

                <h3 class="footer-title">
                    Information
                </h3>

                <ul class="footer-links">

                    <li>
                        <a href="contact.php">
                            Contact Us
                        </a>
                    </li>

                    <li>
                        <a href="auth/login.php">
                            Login
                        </a>
                    </li>

                    <li>
                        <a href="admission.php">
                            Apply for Admission
                        </a>
                    </li>

                    <li>
                        <a href="mailto:info@bkhschool.com">
                            info@bkhschool.com
                        </a>
                    </li>

                </ul>

            </div>

        </div>


        <div class="footer-bottom">

            <div class="row align-items-center">

                <div class="col-md-6 text-center text-md-start">

                    ©
                    <?= date('Y'); ?>

                    Bole Kale Hiwot School.

                    All rights reserved.

                </div>

                <div class="col-md-6 text-center text-md-end mt-2 mt-md-0">

                    School Management System

                </div>

            </div>

        </div>

    </div>

</footer>


<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>