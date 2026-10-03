
<?php

session_start();

require_once '../config/database.php';


// =====================================================
// UPDATE LOGIN STATUS
// =====================================================

if (isset($_SESSION['user_id'])) {

    $userId = $_SESSION['user_id'];

    $sql = "
        UPDATE users
        SET is_logged_in = 0
        WHERE id = ?
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        $stmt->bind_param('i', $userId);

        $stmt->execute();

        $stmt->close();
    }
}


// =====================================================
// CLEAR SESSION
// =====================================================

$_SESSION = [];


// =====================================================
// DELETE SESSION COOKIE
// =====================================================

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}


// =====================================================
// DESTROY SESSION
// =====================================================

session_destroy();


// =====================================================
// REDIRECT TO LOGIN
// =====================================================

header('Location: login.php');

exit;

