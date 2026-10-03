
<?php

session_start();

/*
|--------------------------------------------------------------------------
| Admin Authorization
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';

/*
|--------------------------------------------------------------------------
| Only POST Requests Allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate User ID
|--------------------------------------------------------------------------
*/

$id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);

if (!$id || $id <= 0) {
    $_SESSION['error'] = 'Invalid user ID.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Prevent Admin From Deleting Their Own Account
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] === (int) $id
) {
    $_SESSION['error'] = 'You cannot delete your own account.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Check Whether User Exists
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id, full_name
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Delete User
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "DELETE FROM users
     WHERE id = ?"
);

$stmt->bind_param('i', $id);

if ($stmt->execute()) {

    if ($stmt->affected_rows > 0) {

        $_SESSION['success'] =
            'User "' . $user['full_name'] . '" deleted successfully.';

    } else {

        $_SESSION['error'] =
            'The user could not be deleted.';
    }

} else {

    $_SESSION['error'] =
        'Unable to delete the user. Please try again.';
}

$stmt->close();

header('Location: index.php');
exit;

