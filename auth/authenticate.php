<?php

declare(strict_types=1);

session_start();

require_once '../config/database.php';


// =====================================================
// CHECK DATABASE CONNECTION
// =====================================================

if (!isset($conn) || !($conn instanceof mysqli)) {

    $_SESSION['login_error'] =
        'Database connection is not available.';

    header('Location: login.php');
    exit;

}

$conn->set_charset('utf8mb4');


// =====================================================
// CHECK REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: login.php');
    exit;

}


// =====================================================
// GET FORM DATA
// =====================================================

$identifier = trim(
    (string) ($_POST['identifier'] ?? '')
);

$password = (string) ($_POST['password'] ?? '');


// =====================================================
// VALIDATE INPUT
// =====================================================

if ($identifier === '' || $password === '') {

    $_SESSION['login_error'] =
        'Please enter your login details and password.';

    header('Location: login.php');
    exit;

}


// =====================================================
// FIND USER
// Role is determined automatically from the database.
// No role is accepted from the login form.
// =====================================================

$user = null;


// =====================================================
// 1. TRY STUDENT LOGIN
// Student Code + Password
// =====================================================

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.password,
        u.role,
        u.is_logged_in,
        u.is_deleted,

        s.id AS student_id,
        s.student_code

    FROM users AS u

    INNER JOIN students AS s
        ON s.user_id = u.id

    WHERE s.student_code = ?
      AND LOWER(u.role) = 'student'
      AND u.is_deleted = 0
      AND s.is_deleted = 0

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    $_SESSION['login_error'] =
        'Unable to process your login. Please try again.';

    header('Location: login.php');
    exit;

}

$stmt->bind_param(
    's',
    $identifier
);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();


// =====================================================
// 2. TRY PARENT LOGIN
// Parent Phone Number + Password
// =====================================================

if (!$user) {

    $sql = "
        SELECT
            id,
            full_name,
            email,
            phone,
            password,
            role,
            is_logged_in,
            is_deleted

        FROM users

        WHERE phone = ?
          AND LOWER(role) = 'parent'
          AND is_deleted = 0

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        $_SESSION['login_error'] =
            'Unable to process your login. Please try again.';

        header('Location: login.php');
        exit;

    }

    $stmt->bind_param(
        's',
        $identifier
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();

}


// =====================================================
// 3. TRY STAFF / ADMIN LOGIN
// Email OR Phone + Password
//
// This covers:
// Admin
// Principal
// Teacher
// Registrar
// Librarian
// =====================================================

if (!$user) {

    $sql = "
        SELECT
            id,
            full_name,
            email,
            phone,
            password,
            role,
            is_logged_in,
            is_deleted

        FROM users

        WHERE (
            email = ?
            OR phone = ?
        )
        AND is_deleted = 0
        AND LOWER(role) IN (
            'admin',
            'principal',
            'teacher',
            'registrar',
            'librarian'
        )

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        $_SESSION['login_error'] =
            'Unable to process your login. Please try again.';

        header('Location: login.php');
        exit;

    }

    $stmt->bind_param(
        'ss',
        $identifier,
        $identifier
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();

}


// =====================================================
// USER NOT FOUND
// =====================================================

if (!$user) {

    $_SESSION['login_error'] =
        'Invalid login details or password.';

    header('Location: login.php');
    exit;

}


// =====================================================
// VERIFY PASSWORD
// =====================================================

if (!password_verify(
    $password,
    (string) $user['password']
)) {

    $_SESSION['login_error'] =
        'Invalid login details or password.';

    header('Location: login.php');
    exit;

}


// =====================================================
// GET ACTUAL ROLE FROM DATABASE
// =====================================================

$userRole = strtolower(
    trim((string) $user['role'])
);


// =====================================================
// STUDENT EXTRA VALIDATION
// Make sure student has an active registration
// =====================================================

if ($userRole === 'student') {

    $studentId = (int) $user['student_id'];

    $sql = "
        SELECT
            sr.id

        FROM student_registrations AS sr

        INNER JOIN academic_years AS ay
            ON ay.id = sr.academic_year_id

        WHERE sr.student_id = ?
          AND ay.status = 'Active'

        ORDER BY sr.id DESC

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        $_SESSION['login_error'] =
            'Unable to verify your student registration.';

        header('Location: login.php');
        exit;

    }

    $stmt->bind_param(
        'i',
        $studentId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $registration = $result->fetch_assoc();

    $stmt->close();


    if (!$registration) {

        $_SESSION['login_error'] =
            'You do not have an active student registration for the current academic year. Please contact the school registrar.';

        header('Location: login.php');
        exit;

    }

}


// =====================================================
// REGENERATE SESSION ID
// =====================================================

session_regenerate_id(true);


// =====================================================
// STORE USER SESSION
// =====================================================

$_SESSION['user_id'] =
    (int) $user['id'];

$_SESSION['full_name'] =
    (string) $user['full_name'];

$_SESSION['email'] =
    (string) ($user['email'] ?? '');

$_SESSION['phone'] =
    (string) ($user['phone'] ?? '');

$_SESSION['role'] =
    (string) $user['role'];

$_SESSION['logged_in'] =
    true;


// =====================================================
// STORE STUDENT INFORMATION
// =====================================================

if ($userRole === 'student') {

    $_SESSION['student_id'] =
        (int) $user['student_id'];

    $_SESSION['student_code'] =
        (string) $user['student_code'];

}


// =====================================================
// UPDATE LOGIN INFORMATION
// =====================================================

$updateSql = "
    UPDATE users

    SET
        is_logged_in = 1,
        last_login_at = NOW()

    WHERE id = ?
";

$updateStmt = $conn->prepare($updateSql);

if ($updateStmt) {

    $userId = (int) $user['id'];

    $updateStmt->bind_param(
        'i',
        $userId
    );

    $updateStmt->execute();

    $updateStmt->close();

}


// =====================================================
// ROLE-BASED REDIRECT
// =====================================================

switch ($userRole) {

    case 'admin':

        header(
            'Location: ../admin/dashboard.php'
        );

        break;


    case 'principal':

        header(
            'Location: ../principal/dashboard.php'
        );

        break;


    case 'teacher':

        header(
            'Location: ../teacher/dashboard.php'
        );

        break;


    case 'registrar':

        header(
            'Location: ../registrar/dashboard.php'
        );

        break;


    case 'librarian':

        header(
            'Location: ../librarian/dashboard.php'
        );

        break;


    case 'student':

        header(
            'Location: ../student/dashboard.php'
        );

        break;


    case 'parent':

        header(
            'Location: ../parent/dashboard.php'
        );

        break;


    default:

        session_unset();

        session_destroy();

        session_start();

        $_SESSION['login_error'] =
            'Your account does not have a valid role. Please contact the school administrator.';

        header('Location: login.php');

        break;

}


exit;