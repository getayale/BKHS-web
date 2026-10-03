<?php

declare(strict_types=1);

session_start();

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
        content="Login to the Bole Kale Hiwot School Management System."
    >

    <title>Login | Bole Kale Hiwot School</title>

    <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp?v=1"
    >

    <!-- =========================================================
         Google Fonts
    ========================================================== -->

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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- =========================================================
         Bootstrap
    ========================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- =========================================================
         Bootstrap Icons
    ========================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- =========================================================
         Login CSS
    ========================================================== -->

    <link
        rel="stylesheet"
        href="../public/css/login.css"
    >

</head>


<body>


<main class="login-page">


    <!-- =========================================================
         LEFT PANEL
    ========================================================== -->

    <section class="login-intro">

        <div class="intro-content">


            <!-- =====================================================
                 School Brand
            ====================================================== -->

            <a
                href="../index.php"
                class="school-brand"
            >

                <img
                    src="../public/image/logo.webp"
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

            </a>


            <!-- =====================================================
                 Introduction
            ====================================================== -->

            <div class="intro-message">

                <span class="intro-label">

                    <i class="bi bi-shield-check"></i>

                    School Management System

                </span>


                <h1>

                    Welcome back to

                    <span>BKHS.</span>

                </h1>


                <p>

                    Access your school account and stay connected
                    with your learning, teachers, homework, results,
                    and school activities.

                </p>

            </div>


            <!-- =====================================================
                 Features
            ====================================================== -->

            <div class="intro-features">


                <div class="intro-feature">

                    <div class="feature-icon">

                        <i class="bi bi-mortarboard"></i>

                    </div>

                    <div>

                        <strong>
                            Student learning
                        </strong>

                        <span>
                            Access subjects, materials, homework and results.
                        </span>

                    </div>

                </div>


                <div class="intro-feature">

                    <div class="feature-icon">

                        <i class="bi bi-people"></i>

                    </div>

                    <div>

                        <strong>
                            Parent access
                        </strong>

                        <span>
                            Stay connected with your child's progress.
                        </span>

                    </div>

                </div>


                <div class="intro-feature">

                    <div class="feature-icon">

                        <i class="bi bi-shield-lock"></i>

                    </div>

                    <div>

                        <strong>
                            Secure access
                        </strong>

                        <span>
                            Your school information stays protected.
                        </span>

                    </div>

                </div>


            </div>

        </div>


        <!-- Decorative Shapes -->

        <div class="intro-decoration decoration-one"></div>

        <div class="intro-decoration decoration-two"></div>

    </section>


    <!-- =========================================================
         LOGIN PANEL
    ========================================================== -->

    <section class="login-panel">

        <div class="login-container">


            <!-- =====================================================
                 Mobile Brand
            ====================================================== -->

            <div class="mobile-brand">

                <a
                    href="../index.php"
                    class="school-brand"
                >

                    <img
                        src="../public/image/logo.webp"
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

                </a>

            </div>


            <!-- =====================================================
                 Login Heading
            ====================================================== -->

            <div class="login-heading">

                <span class="login-icon">

                    <i class="bi bi-person"></i>

                </span>


                <h2>
                    Sign in
                </h2>


                <p>
                    Enter your account details to continue.
                </p>

            </div>


            <!-- =====================================================
                 ERROR MESSAGE
            ====================================================== -->

            <?php if (isset($_SESSION['login_error'])): ?>

                <div
                    class="login-alert"
                    role="alert"
                >

                    <i class="bi bi-exclamation-circle"></i>

                    <span>

                        <?= htmlspecialchars(
                            (string) $_SESSION['login_error'],
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>

                    </span>

                </div>

                <?php unset($_SESSION['login_error']); ?>

            <?php endif; ?>


            <!-- =====================================================
                 LOGIN FORM
            ====================================================== -->

            <form
                action="authenticate.php"
                method="POST"
                class="login-form"
                id="loginForm"
            >


                <!-- =================================================
                     IDENTIFIER
                ================================================== -->

                <div class="form-group">

                    <label for="identifier">
                        Username or Phone Number
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-person-badge input-icon"
                        ></i>


                        <input
                            type="text"
                            id="identifier"
                            name="identifier"
                            class="form-control"
                            placeholder="Enter username or phone number"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                        >

                    </div>


                    <small class="form-help">

                        Students can use their student code.
                        Parents can use their registered phone number.

                    </small>

                </div>


                <!-- =================================================
                     PASSWORD
                ================================================== -->

                <div class="form-group">

                    <div class="password-label-row">

                        <label for="password">
                            Password
                        </label>


                        <a
                            href="#"
                            class="forgot-link"
                        >
                            Forgot password?
                        </a>

                    </div>


                    <div class="input-wrapper">

                        <i
                            class="bi bi-lock input-icon"
                        ></i>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control password-input"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            id="passwordToggle"
                            aria-label="Show password"
                        >

                            <i class="bi bi-eye"></i>

                        </button>

                    </div>

                </div>


                <!-- =================================================
                     Remember Me
                ================================================== -->

                <div class="login-options">

                    <label class="remember-me">

                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                        >

                        <span class="custom-checkbox"></span>

                        <span>
                            Remember me
                        </span>

                    </label>

                </div>


                <!-- =================================================
                     Submit
                ================================================== -->

                <button
                    type="submit"
                    class="login-button"
                >

                    <span>
                        Sign in
                    </span>

                    <i class="bi bi-arrow-right"></i>

                </button>

            </form>


            <!-- =====================================================
                 Divider
            ====================================================== -->

            <div class="login-divider">

                <span>
                    Need help?
                </span>

            </div>


            <!-- =====================================================
                 Help
            ====================================================== -->

            <div class="login-help">

                <i class="bi bi-info-circle"></i>

                <span>

                    Use the login information provided by the school.
                    Students use their student code, while parents
                    use their registered phone number.

                </span>

            </div>


            <!-- =====================================================
                 Back
            ====================================================== -->

            <a
                href="../index.php"
                class="back-home"
            >

                <i class="bi bi-arrow-left"></i>

                <span>
                    Back to school website
                </span>

            </a>


            <!-- =====================================================
                 Footer
            ====================================================== -->

            <div class="login-footer">

                © <?= date('Y'); ?>

                Bole Kale Hiwot School

            </div>

        </div>

    </section>

</main>


<!-- =============================================================
     LOGIN PAGE JAVASCRIPT
============================================================== -->

<script>

const passwordInput =
    document.getElementById('password');

const passwordToggle =
    document.getElementById('passwordToggle');


/*
|--------------------------------------------------------------------------
| Password toggle
|--------------------------------------------------------------------------
*/

if (passwordInput && passwordToggle) {

    passwordToggle.addEventListener(
        'click',
        function () {

            const icon =
                passwordToggle.querySelector('i');


            if (passwordInput.type === 'password') {

                passwordInput.type = 'text';

                icon.classList.remove(
                    'bi-eye'
                );

                icon.classList.add(
                    'bi-eye-slash'
                );

                passwordToggle.setAttribute(
                    'aria-label',
                    'Hide password'
                );

            } else {

                passwordInput.type = 'password';

                icon.classList.remove(
                    'bi-eye-slash'
                );

                icon.classList.add(
                    'bi-eye'
                );

                passwordToggle.setAttribute(
                    'aria-label',
                    'Show password'
                );

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Prevent accidental submit while toggling password
|--------------------------------------------------------------------------
*/

if (passwordToggle) {

    passwordToggle.addEventListener(
        'mousedown',
        function (event) {

            event.preventDefault();

        }
    );

}

</script>


<style>

/*
|--------------------------------------------------------------------------
| Help text
|--------------------------------------------------------------------------
*/

.form-help {

    display: block;

    margin-top: 6px;

    color: #737b88;

    font-size: 12px;

    line-height: 1.5;

}


/*
|--------------------------------------------------------------------------
| Identifier input
|--------------------------------------------------------------------------
*/

#identifier {

    width: 100%;

}


/*
|--------------------------------------------------------------------------
| ================================================================
| SMALL SCREEN ONLY
| Large screen layout remains unchanged.
| ================================================================
|--------------------------------------------------------------------------
*/

@media (max-width: 767.98px) {


    /*
    |--------------------------------------------------------------------------
    | Prevent horizontal scrolling
    |--------------------------------------------------------------------------
    */

    html,
    body {

        width: 100%;

        max-width: 100%;

        overflow-x: hidden;

    }


    body {

        min-height: 100vh;

        background: #f5f7fb;

    }


    /*
    |--------------------------------------------------------------------------
    | Main Login Page
    |--------------------------------------------------------------------------
    */

    .login-page {

        width: 100%;

        min-height: 100vh;

        display: block;

    }


    /*
    |--------------------------------------------------------------------------
    | Hide Desktop Introduction
    |--------------------------------------------------------------------------
    */

    .login-intro {

        display: none;

    }


    /*
    |--------------------------------------------------------------------------
    | Login Panel
    |--------------------------------------------------------------------------
    */

    .login-panel {

        width: 100%;

        min-height: 100vh;

        padding: 16px 12px 22px;

        background:
            radial-gradient(
                circle at top right,
                rgba(79, 70, 229, .09),
                transparent 35%
            ),
            #f5f7fb;

        display: flex;

        align-items: flex-start;

        justify-content: center;

    }


    /*
    |--------------------------------------------------------------------------
    | Login Container
    |--------------------------------------------------------------------------
    */

    .login-container {

        width: 100%;

        max-width: 430px;

        margin: 0 auto;

        padding: 0;

    }


    /*
    |--------------------------------------------------------------------------
    | Mobile Brand
    |--------------------------------------------------------------------------
    */

    .mobile-brand {

        width: 100%;

        display: flex;

        justify-content: center;

        margin-bottom: 14px;

    }


    .mobile-brand .school-brand {

        display: inline-flex;

        align-items: center;

        justify-content: center;

        gap: 9px;

        padding: 8px 13px;

        background: rgba(255, 255, 255, .96);

        border: 1px solid #e5e7eb;

        border-radius: 13px;

        box-shadow:
            0 5px 18px rgba(15, 23, 42, .055);

        text-decoration: none;

        max-width: 100%;

    }


    .mobile-brand .school-brand img {

        width: 40px;

        height: 40px;

        flex: 0 0 40px;

        object-fit: contain;

    }


    .mobile-brand .school-brand div {

        min-width: 0;

        display: flex;

        flex-direction: column;

        justify-content: center;

    }


    .mobile-brand .school-brand strong {

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            Inter,
            sans-serif;

        font-size: 13.5px;

        line-height: 1.2;

        font-weight: 800;

        white-space: nowrap;

    }


    .mobile-brand .school-brand span {

        color: #64748b;

        font-size: 10.5px;

        line-height: 1.3;

        margin-top: 2px;

    }


    /*
    |--------------------------------------------------------------------------
    | Login Heading
    |--------------------------------------------------------------------------
    */

    .login-heading {

        width: 100%;

        text-align: center;

        margin-bottom: 17px;

        padding: 0 5px;

    }


    .login-heading .login-icon {

        width: 44px;

        height: 44px;

        margin: 0 auto 8px;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 12px;

        background: #eef2ff;

        color: #4f46e5;

        font-size: 19px;

        box-shadow:
            0 4px 13px rgba(79, 70, 229, .07);

    }


    .login-heading h2 {

        margin: 0 0 4px;

        font-size: 23px;

        line-height: 1.2;

        font-weight: 800;

        color: #111827;

        font-family:
            "Plus Jakarta Sans",
            Inter,
            sans-serif;

    }


    .login-heading p {

        margin: 0 auto;

        max-width: 290px;

        color: #64748b;

        font-size: 11.5px;

        line-height: 1.5;

    }


    /*
    |--------------------------------------------------------------------------
    | Error Alert
    |--------------------------------------------------------------------------
    */

    .login-alert {

        width: 100%;

        display: flex;

        align-items: center;

        gap: 8px;

        margin-bottom: 13px;

        padding: 10px 11px;

        border-radius: 10px;

        font-size: 11.5px;

        line-height: 1.45;

        overflow-wrap: anywhere;

    }


    .login-alert i {

        flex: 0 0 auto;

    }


    .login-alert span {

        min-width: 0;

    }


    /*
    |--------------------------------------------------------------------------
    | Login Form Card
    |--------------------------------------------------------------------------
    */

    .login-form {

        width: 100%;

        padding: 18px 15px;

        background: #fff;

        border: 1px solid #e5e7eb;

        border-radius: 17px;

        box-shadow:
            0 9px 30px rgba(15, 23, 42, .065);

    }


    /*
    |--------------------------------------------------------------------------
    | Form Groups
    |--------------------------------------------------------------------------
    */

    .form-group {

        width: 100%;

        margin-bottom: 15px;

    }


    .form-group:last-of-type {

        margin-bottom: 12px;

    }


    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    .form-group label {

        display: block;

        margin-bottom: 6px;

        color: #1e293b;

        font-size: 11.5px;

        line-height: 1.35;

        font-weight: 700;

    }


    /*
    |--------------------------------------------------------------------------
    | Password Label Row
    |--------------------------------------------------------------------------
    */

    .password-label-row {

        width: 100%;

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 8px;

        flex-wrap: nowrap;

    }


    .password-label-row label {

        margin-bottom: 6px;

        min-width: 0;

    }


    /*
    |--------------------------------------------------------------------------
    | Forgot Password
    |--------------------------------------------------------------------------
    */

    .forgot-link {

        flex: 0 0 auto;

        font-size: 10.5px;

        font-weight: 600;

        color: #4f46e5;

        text-decoration: none;

        margin-bottom: 6px;

        white-space: nowrap;

    }


    .forgot-link:hover {

        text-decoration: underline;

    }


    /*
    |--------------------------------------------------------------------------
    | Input Wrapper
    |--------------------------------------------------------------------------
    */

    .input-wrapper {

        width: 100%;

        position: relative;

    }


    /*
    |--------------------------------------------------------------------------
    | Input Icon
    |--------------------------------------------------------------------------
    */

    .input-icon {

        position: absolute;

        left: 12px;

        top: 50%;

        transform: translateY(-50%);

        z-index: 2;

        color: #94a3b8;

        font-size: 15px;

        pointer-events: none;

    }


    /*
    |--------------------------------------------------------------------------
    | Inputs
    |--------------------------------------------------------------------------
    */

    .input-wrapper .form-control {

        width: 100%;

        min-height: 46px;

        padding:
            10px
            40px
            10px
            37px;

        border: 1px solid #dbe1ea;

        border-radius: 10px;

        background: #fff;

        color: #0f172a;

        font-size: 12.5px;

        line-height: 1.4;

        box-shadow: none;

        transition:
            border-color .2s ease,
            box-shadow .2s ease,
            background .2s ease;

    }


    .input-wrapper .form-control::placeholder {

        color: #a0a8b5;

        opacity: 1;

    }


    .input-wrapper .form-control:focus {

        border-color: #6366f1;

        background: #fff;

        box-shadow:
            0 0 0 3px rgba(99, 102, 241, .10);

        outline: none;

    }


    /*
    |--------------------------------------------------------------------------
    | Password Input
    |--------------------------------------------------------------------------
    */

    .password-input {

        padding-right: 43px !important;

    }


    /*
    |--------------------------------------------------------------------------
    | Password Toggle
    |--------------------------------------------------------------------------
    */

    .password-toggle {

        position: absolute;

        top: 50%;

        right: 4px;

        transform: translateY(-50%);

        width: 36px;

        height: 36px;

        display: flex;

        align-items: center;

        justify-content: center;

        border: 0;

        border-radius: 8px;

        background: transparent;

        color: #64748b;

        font-size: 15px;

        cursor: pointer;

        transition:
            background .2s ease,
            color .2s ease;

    }


    .password-toggle:hover {

        background: #f1f5f9;

        color: #4f46e5;

    }


    .password-toggle:focus {

        outline: none;

        box-shadow:
            0 0 0 3px rgba(99, 102, 241, .10);

    }


    /*
    |--------------------------------------------------------------------------
    | Help Text
    |--------------------------------------------------------------------------
    */

    .form-help {

        display: block;

        width: 100%;

        margin-top: 6px;

        color: #7b8491;

        font-size: 10px;

        line-height: 1.45;

        overflow-wrap: anywhere;

    }


    /*
    |--------------------------------------------------------------------------
    | Remember Me
    |--------------------------------------------------------------------------
    */

    .login-options {

        width: 100%;

        margin-bottom: 14px;

    }


    .remember-me {

        display: inline-flex;

        align-items: center;

        gap: 7px;

        cursor: pointer;

        color: #64748b;

        font-size: 11px;

        line-height: 1.35;

        user-select: none;

    }


    /*
    |--------------------------------------------------------------------------
    | Custom Checkbox
    |--------------------------------------------------------------------------
    */

    .remember-me input {

        position: absolute;

        opacity: 0;

        pointer-events: none;

    }


    .remember-me .custom-checkbox {

        width: 16px;

        height: 16px;

        flex: 0 0 16px;

        display: inline-flex;

        align-items: center;

        justify-content: center;

        border: 1px solid #cbd5e1;

        border-radius: 4px;

        background: #fff;

        transition:
            background .2s ease,
            border-color .2s ease;

    }


    .remember-me input:checked
    + .custom-checkbox {

        background: #4f46e5;

        border-color: #4f46e5;

    }


    .remember-me input:checked
    + .custom-checkbox::after {

        content: "\F26E";

        font-family: "bootstrap-icons";

        color: #fff;

        font-size: 10px;

        font-weight: 700;

    }


    /*
    |--------------------------------------------------------------------------
    | Login Button
    |--------------------------------------------------------------------------
    */

    .login-button {

        width: 100%;

        min-height: 47px;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 8px;

        border: 0;

        border-radius: 10px;

        background: #4f46e5;

        color: #fff;

        font-size: 12.5px;

        font-weight: 700;

        box-shadow:
            0 6px 15px rgba(79, 70, 229, .17);

        transition:
            transform .2s ease,
            box-shadow .2s ease,
            background .2s ease;

    }


    .login-button:hover {

        background: #4338ca;

        transform: translateY(-1px);

        box-shadow:
            0 8px 18px rgba(79, 70, 229, .22);

    }


    .login-button:active {

        transform: translateY(0);

    }


    .login-button i {

        font-size: 14px;

    }


    /*
    |--------------------------------------------------------------------------
    | Divider
    |--------------------------------------------------------------------------
    */

    .login-divider {

        width: 100%;

        display: flex;

        align-items: center;

        gap: 9px;

        margin: 17px 0 11px;

        color: #94a3b8;

        font-size: 9.5px;

        font-weight: 600;

    }


    .login-divider::before,
    .login-divider::after {

        content: "";

        height: 1px;

        flex: 1;

        background: #e2e8f0;

    }


    .login-divider span {

        white-space: nowrap;

    }


    /*
    |--------------------------------------------------------------------------
    | Help Box
    |--------------------------------------------------------------------------
    */

    .login-help {

        width: 100%;

        display: flex;

        align-items: flex-start;

        gap: 8px;

        padding: 10px 11px;

        border: 1px solid #e2e8f0;

        border-radius: 10px;

        background: #f8fafc;

        color: #64748b;

        font-size: 10px;

        line-height: 1.5;

        overflow-wrap: anywhere;

    }


    .login-help i {

        flex: 0 0 auto;

        margin-top: 1px;

        color: #4f46e5;

        font-size: 13px;

    }


    .login-help span {

        min-width: 0;

    }


    /*
    |--------------------------------------------------------------------------
    | Back Home
    |--------------------------------------------------------------------------
    */

    .back-home {

        width: 100%;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 6px;

        margin-top: 13px;

        padding: 8px;

        color: #4f46e5;

        font-size: 11px;

        font-weight: 600;

        text-decoration: none;

        text-align: center;

        border-radius: 8px;

        transition:
            background .2s ease,
            color .2s ease;

    }


    .back-home:hover {

        background: #eef2ff;

        color: #4338ca;

    }


    /*
    |--------------------------------------------------------------------------
    | Footer
    |--------------------------------------------------------------------------
    */

    .login-footer {

        width: 100%;

        margin-top: 10px;

        padding: 0 5px;

        color: #94a3b8;

        font-size: 9px;

        line-height: 1.4;

        text-align: center;

        overflow-wrap: anywhere;

    }

}


/*
|--------------------------------------------------------------------------
| VERY SMALL PHONES
| 380px and below
|--------------------------------------------------------------------------
*/

@media (max-width: 380px) {


    .login-panel {

        padding:
            11px
            9px
            18px;

    }


    .mobile-brand {

        margin-bottom: 11px;

    }


    .mobile-brand .school-brand {

        padding:
            7px
            10px;

        border-radius: 11px;

        gap: 7px;

    }


    .mobile-brand .school-brand img {

        width: 36px;

        height: 36px;

        flex-basis: 36px;

    }


    .mobile-brand .school-brand strong {

        font-size: 12px;

    }


    .mobile-brand .school-brand span {

        font-size: 9.5px;

    }


    .login-heading {

        margin-bottom: 14px;

    }


    .login-heading .login-icon {

        width: 40px;

        height: 40px;

        margin-bottom: 7px;

        border-radius: 11px;

        font-size: 17px;

    }


    .login-heading h2 {

        font-size: 21px;

    }


    .login-heading p {

        font-size: 10.5px;

        max-width: 255px;

    }


    .login-form {

        padding:
            16px
            12px;

        border-radius: 15px;

    }


    .form-group {

        margin-bottom: 13px;

    }


    .form-group label {

        font-size: 11px;

    }


    .password-label-row {

        gap: 5px;

    }


    .forgot-link {

        font-size: 10px;

    }


    .input-wrapper .form-control {

        min-height: 44px;

        font-size: 11.5px;

        padding:
            9px
            38px
            9px
            35px;

    }


    .input-icon {

        left: 11px;

        font-size: 14px;

    }


    .password-input {

        padding-right: 41px !important;

    }


    .password-toggle {

        width: 34px;

        height: 34px;

        right: 3px;

    }


    .form-help {

        font-size: 9.5px;

        line-height: 1.4;

    }


    .login-options {

        margin-bottom: 12px;

    }


    .remember-me {

        font-size: 10.5px;

    }


    .login-button {

        min-height: 45px;

        font-size: 12px;

    }


    .login-help {

        padding: 9px 10px;

        font-size: 9.5px;

        line-height: 1.45;

    }


    .back-home {

        margin-top: 11px;

        font-size: 10.5px;

    }


    .login-footer {

        margin-top: 8px;

        font-size: 8.5px;

    }

}


/*
|--------------------------------------------------------------------------
| LANDSCAPE SMALL PHONES
|--------------------------------------------------------------------------
*/

@media (max-width: 767.98px) and (orientation: landscape) {


    .login-panel {

        padding-top: 10px;

    }


    .mobile-brand {

        margin-bottom: 8px;

    }


    .mobile-brand .school-brand {

        padding:
            5px
            9px;

    }


    .mobile-brand .school-brand img {

        width: 31px;

        height: 31px;

        flex-basis: 31px;

    }


    .mobile-brand .school-brand strong {

        font-size: 11.5px;

    }


    .mobile-brand .school-brand span {

        font-size: 9px;

    }


    .login-heading {

        margin-bottom: 9px;

    }


    .login-heading .login-icon {

        display: none;

    }


    .login-heading h2 {

        font-size: 20px;

    }


    .login-heading p {

        font-size: 10.5px;

    }


    .login-form {

        padding:
            12px
            15px;

    }


    .form-group {

        margin-bottom: 10px;

    }


    .form-group label {

        margin-bottom: 4px;

    }


    .input-wrapper .form-control {

        min-height: 42px;

    }


    .form-help {

        margin-top: 4px;

    }


    .login-options {

        margin-bottom: 9px;

    }


    .login-button {

        min-height: 43px;

    }


    .login-divider {

        margin-top: 11px;

        margin-bottom: 8px;

    }


    .login-help {

        padding: 8px 10px;

    }


    .back-home {

        margin-top: 8px;

    }


    .login-footer {

        margin-top: 6px;

    }

}

</style>


</body>

</html>
