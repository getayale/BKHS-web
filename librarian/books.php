<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Librarian Authentication
|--------------------------------------------------------------------------
*/

$sessionRole = $_SESSION['role'] ?? '';
$sessionUserId = $_SESSION['user_id'] ?? 0;

if (is_array($sessionRole) || is_object($sessionRole)) {
    $sessionRole = '';
}

if (is_array($sessionUserId) || is_object($sessionUserId)) {
    $sessionUserId = 0;
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    strtolower((string) $sessionRole) !== 'librarian'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function scalarString(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

function bindDynamicParams(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if ($types === '' || empty($params)) {
        return;
    }

    $references = [];

    foreach ($params as $key => &$value) {
        $references[$key] =& $value;
    }

    $stmt->bind_param($types, ...$references);
}

function redirectWithMessage(
    string $type,
    string $message
): never {
    header(
        'Location: books.php?' .
        http_build_query([
            'msg_type' => $type,
            'msg' => $message
        ])
    );

    exit;
}

function pageUrl(
    int $pageNumber,
    string $search,
    int $category
): string {
    return '?' . http_build_query([
        'page' => $pageNumber,
        'search' => $search,
        'category' => $category
    ]);
}

function publicAssetUrl(
    mixed $path,
    string $default
): string {
    if (!is_string($path)) {
        return $default;
    }

    $path = trim($path);

    if ($path === '') {
        return $default;
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if (
        str_starts_with($path, 'public/') &&
        !str_contains($path, '..')
    ) {
        return '../' . $path;
    }

    return $default;
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Librarian Information
|--------------------------------------------------------------------------
*/

$librarianId = (int) $sessionUserId;

$librarianName = 'Librarian';
$librarianEmail = '';
$librarianPhoto = null;

$stmt = $conn->prepare("
    SELECT
        full_name,
        email,
        photo_path
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'librarian'
      AND is_deleted = 0
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $librarianId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $librarianName = scalarString(
            $row['full_name'] ?? 'Librarian'
        );

        if ($librarianName === '') {
            $librarianName = 'Librarian';
        }

        $librarianEmail = scalarString(
            $row['email'] ?? ''
        );

        $librarianPhoto = $row['photo_path'] ?? null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Librarian Photo
|--------------------------------------------------------------------------
*/

$photoUrl = publicAssetUrl(
    $librarianPhoto,
    '../public/images/default-avatar.png'
);

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = scalarString(
    $_GET['msg'] ?? ''
);

$messageType = scalarString(
    $_GET['msg_type'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = scalarString(
        $_POST['action'] ?? ''
    );

    /*
    |--------------------------------------------------------------------------
    | ADD BOOK
    |--------------------------------------------------------------------------
    |
    | Only:
    | - Book Title
    | - Category
    | - Quantity
    |
    */

    if ($action === 'add_book') {

        $title = scalarString(
            $_POST['title'] ?? ''
        );

        $categoryRaw = $_POST['category_id'] ?? 0;

        $categoryId = is_scalar($categoryRaw)
            ? (int) $categoryRaw
            : 0;

        $quantityRaw = $_POST['quantity'] ?? 0;

        $quantity = is_scalar($quantityRaw)
            ? (int) $quantityRaw
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($title === '') {

            redirectWithMessage(
                'danger',
                'Book title is required.'
            );
        }

        if ($categoryId < 0) {
            $categoryId = 0;
        }

        if ($quantity < 1) {

            redirectWithMessage(
                'danger',
                'Quantity must be at least 1.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Insert Book
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO library_books (
                title,
                category_id,
                total_quantity,
                available_quantity,
                is_deleted
            )
            VALUES (
                ?,
                NULLIF(?, 0),
                ?,
                ?,
                0
            )
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare the book query: ' .
                $conn->error
            );
        }

        $stmt->bind_param(
            'siii',
            $title,
            $categoryId,
            $quantity,
            $quantity
        );

        if ($stmt->execute()) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Book added successfully.'
            );
        }

        $error = $stmt->error;

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Unable to add book: ' . $error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE BOOK
    |--------------------------------------------------------------------------
    |
    | Only:
    | - Book Title
    | - Category
    | - Quantity
    |
    */

    if ($action === 'update_book') {

        $bookRaw = $_POST['book_id'] ?? 0;

        $bookId = is_scalar($bookRaw)
            ? (int) $bookRaw
            : 0;

        $title = scalarString(
            $_POST['title'] ?? ''
        );

        $categoryRaw = $_POST['category_id'] ?? 0;

        $categoryId = is_scalar($categoryRaw)
            ? (int) $categoryRaw
            : 0;

        $quantityRaw = $_POST['quantity'] ?? 0;

        $quantity = is_scalar($quantityRaw)
            ? (int) $quantityRaw
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($bookId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid book.'
            );
        }

        if ($title === '') {

            redirectWithMessage(
                'danger',
                'Book title is required.'
            );
        }

        if ($categoryId < 0) {
            $categoryId = 0;
        }

        if ($quantity < 1) {

            redirectWithMessage(
                'danger',
                'Quantity must be at least 1.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Book
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE library_books
            SET
                title = ?,
                category_id = NULLIF(?, 0),
                total_quantity = ?,
                available_quantity = LEAST(
                    available_quantity,
                    ?
                )
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare the update query: ' .
                $conn->error
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Keep Available Quantity Valid
        |--------------------------------------------------------------------------
        |
        | If quantity is reduced below the current available quantity,
        | available quantity becomes the new quantity.
        |
        */

        $stmt->bind_param(
            'siiii',
            $title,
            $categoryId,
            $quantity,
            $quantity,
            $bookId
        );

        if ($stmt->execute()) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Book updated successfully.'
            );
        }

        $error = $stmt->error;

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Unable to update book: ' . $error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE BOOK
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_book') {

        $bookRaw = $_POST['book_id'] ?? 0;

        $bookId = is_scalar($bookRaw)
            ? (int) $bookRaw
            : 0;

        if ($bookId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid book.'
            );
        }

        $stmt = $conn->prepare("
            UPDATE library_books
            SET is_deleted = 1
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare delete query: ' .
                $conn->error
            );
        }

        $stmt->bind_param(
            'i',
            $bookId
        );

        if (
            $stmt->execute() &&
            $stmt->affected_rows > 0
        ) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Book deleted successfully.'
            );
        }

        $error = $stmt->error;

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Book could not be deleted.' .
            ($error !== '' ? ' ' . $error : '')
        );
    }
}

/*
|--------------------------------------------------------------------------
| Search / Filters
|--------------------------------------------------------------------------
*/

$search = scalarString(
    $_GET['search'] ?? ''
);

$categoryRaw = $_GET['category'] ?? 0;

$categoryFilter = is_scalar($categoryRaw)
    ? (int) $categoryRaw
    : 0;

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 10;

$pageRaw = $_GET['page'] ?? 1;

$page = is_scalar($pageRaw)
    ? (int) $pageRaw
    : 1;

$page = max(1, $page);

/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

$where = [
    'b.is_deleted = 0'
];

$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            b.title LIKE ?
            OR b.author LIKE ?
            OR b.isbn LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'sss';
}

if ($categoryFilter > 0) {

    $where[] = 'b.category_id = ?';

    $params[] = $categoryFilter;

    $types .= 'i';
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Total Books Count
|--------------------------------------------------------------------------
*/

$totalBooks = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM library_books b
    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if ($stmt) {

    if (!empty($params)) {

        $countParams = $params;

        bindDynamicParams(
            $stmt,
            $types,
            $countParams
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $totalBooks = (int) (
            $row['total'] ?? 0
        );
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination Calculation
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalBooks / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = (
    $page - 1
) * $perPage;

/*
|--------------------------------------------------------------------------
| Books Query
|--------------------------------------------------------------------------
*/

$books = [];

$booksSql = "
    SELECT
        b.id,
        b.title,
        b.category_id,
        b.total_quantity,
        b.available_quantity,
        b.created_at,
        c.name AS category_name
    FROM library_books b
    LEFT JOIN library_categories c
        ON c.id = b.category_id
       AND c.is_deleted = 0
    WHERE {$whereSql}
    ORDER BY b.id DESC
    LIMIT ? OFFSET ?
";

$booksParams = $params;

$booksParams[] = $perPage;
$booksParams[] = $offset;

$booksTypes = $types . 'ii';

$stmt = $conn->prepare($booksSql);

if ($stmt) {

    bindDynamicParams(
        $stmt,
        $booksTypes,
        $booksParams
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $books[] = [
            'id' => (int) (
                $row['id'] ?? 0
            ),

            'title' => scalarString(
                $row['title'] ?? ''
            ),

            'category_id' => (int) (
                $row['category_id'] ?? 0
            ),

            'total_quantity' => (int) (
                $row['total_quantity'] ?? 0
            ),

            'available_quantity' => (int) (
                $row['available_quantity'] ?? 0
            ),

            'created_at' => scalarString(
                $row['created_at'] ?? ''
            ),

            'category_name' => scalarString(
                $row['category_name'] ?? ''
            )
        ];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categories = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        description
    FROM library_categories
    WHERE is_deleted = 0
    ORDER BY name ASC
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $categories[] = [
            'id' => (int) (
                $row['id'] ?? 0
            ),

            'name' => scalarString(
                $row['name'] ?? ''
            ),

            'description' => scalarString(
                $row['description'] ?? ''
            )
        ];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Page Statistics
|--------------------------------------------------------------------------
*/

$totalTitles = 0;
$totalCopies = 0;
$totalAvailable = 0;
$totalBorrowed = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_titles,
        COALESCE(SUM(total_quantity), 0) AS total_copies,
        COALESCE(SUM(available_quantity), 0) AS total_available
    FROM library_books
    WHERE is_deleted = 0
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $totalTitles = (int) (
            $row['total_titles'] ?? 0
        );

        $totalCopies = (int) (
            $row['total_copies'] ?? 0
        );

        $totalAvailable = (int) (
            $row['total_available'] ?? 0
        );

        $totalBorrowed = max(
            0,
            $totalCopies - $totalAvailable
        );
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = '';

try {

    if (
        class_exists('EthiopianCalendar') &&
        method_exists(
            'EthiopianCalendar',
            'fromGregorian'
        )
    ) {

        $convertedDate =
            EthiopianCalendar::fromGregorian(
                date('Y-m-d')
            );

        if (is_scalar($convertedDate)) {

            $todayEthiopian =
                (string) $convertedDate;
        }
    }

} catch (Throwable $exception) {

    $todayEthiopian = '';
}

if ($todayEthiopian === '') {
    $todayEthiopian = date('Y-m-d');
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

    <title>Books | BKHS Library</title>

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
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: var(--sidebar);
            color: #fff;
            z-index: 1100;
            overflow-y: auto;
            transition: transform 0.25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 4px;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 20px 14px 7px;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
        }

        .sidebar-nav {
            padding: 0 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d1d5db;
            padding: 11px 13px;
            border-radius: 99px;
            margin-bottom: 3px;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-nav a i {
            width: 20px;
            font-size: 16px;
        }

        .sidebar-nav a:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-nav a.active {
            background: var(--primary);
            color: #fff;
        }

        .logout-link {
            color: #fca5a5 !important;
        }

        .logout-link:hover {
            background: rgba(239,68,68,.12) !important;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.5);
            z-index: 1050;
        }

        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;
            background: rgba(255,255,255,.96);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            backdrop-filter: blur(10px);
        }

        .page-heading h1 {
            font-size: 21px;
            font-weight: 800;
            margin: 0;
        }

        .page-heading p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ethiopian-date {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .profile-mini img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
        }

        .profile-role {
            color: var(--muted);
            font-size: 10px;
        }

        .content {
            padding: 28px;
        }

        .page-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 22px;
        }

        .page-title h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
        }

        .page-title p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 19px;
            height: 100%;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(15,23,42,.06);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            font-size: 19px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-label {
            margin-top: 7px;
            color: var(--muted);
            font-size: 12px;
        }

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .search-area {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            border-color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
            min-height: 42px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.1);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            margin: 0;
            min-width: 800px;
        }

        .table thead th {
            background: #f8fafc;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .04em;
            font-weight: 800;
            border-bottom: 1px solid var(--border);
            padding: 13px 14px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px;
            vertical-align: middle;
            border-color: #eef0f3;
            font-size: 12px;
        }

        .book-title {
            font-weight: 700;
            color: #111827;
        }

        .book-date {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .category-badge {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 700;
        }

        .quantity-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .quantity-available {
            background: #ecfdf5;
            color: #047857;
        }

        .quantity-empty {
            background: #fef2f2;
            color: #b91c1c;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #4b5563;
            transition: all .2s ease;
        }

        .action-btn:hover {
            background: #f9fafb;
            color: var(--primary);
            border-color: #bfdbfe;
        }

        .action-btn.delete:hover {
            color: #dc2626;
            border-color: #fecaca;
            background: #fef2f2;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state h4 {
            color: #374151;
            font-size: 16px;
        }

        .modal-content {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
        }

        .modal-footer {
            border-top: 1px solid var(--border);
        }

        .pagination .page-link {
            color: var(--primary);
        }

        .pagination .active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar.show + .sidebar-overlay {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 20px;
            }

            .ethiopian-date {
                display: none;
            }
        }

        @media (max-width: 767px) {

            .page-title-row {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-title-row .btn {
                width: 100%;
                justify-content: center;
            }

            .profile-mini > div {
                display: none;
            }

            .topbar {
                height: 70px;
            }

            .content {
                padding: 16px;
            }

            .pagination {
                overflow-x: auto;
                white-space: nowrap;
                max-width: 100%;
            }

            .search-area {
                padding: 13px;
            }
        }

    </style>

</head>

<body>

<!-- ================================================================
     SIDEBAR
     ================================================================ -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS Logo"
        >

        <div>

            <div class="brand-title">
                BKHS Library
            </div>

            <div class="brand-subtitle">
                Librarian Portal
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main Menu
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2"></i>
            Dashboard
        </a>

        <a
            href="books.php"
            class="active"
        >
            <i class="bi bi-book"></i>
            Book Catalog
        </a>

        <a href="categories.php">
            <i class="bi bi-tags"></i>
            Categories
        </a>

        <a href="borrow.php">
            <i class="bi bi-journal-arrow-up"></i>
            Issue Book
        </a>

        <a href="return.php">
            <i class="bi bi-journal-arrow-down"></i>
            Return Book
        </a>

        <a href="borrowed_books.php">
            <i class="bi bi-clock-history"></i>
            Borrow Records
        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="profile.php">
            <i class="bi bi-person"></i>
            Profile
        </a>

        <a
            href="../auth/logout.php"
            class="logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            Logout
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- ================================================================
     MAIN
     ================================================================ -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                class="btn btn-light d-lg-none"
                id="toggleSidebar"
                type="button"
                aria-label="Toggle navigation"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-heading">

                <h1>
                    Book Catalog
                </h1>

                <p>
                    Manage books and inventory
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">

                <i class="bi bi-calendar3"></i>

                <?= e($todayEthiopian); ?>

            </div>

            <div class="profile-mini">

                <img
                    src="<?= e($photoUrl); ?>"
                    alt="Librarian Avatar"
                >

                <div>

                    <div class="profile-name">
                        <?= e($librarianName); ?>
                    </div>

                    <div class="profile-role">
                        Librarian
                    </div>

                </div>

            </div>

        </div>

    </header>

    <!-- CONTENT -->

    <div class="content">

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= e(
                    $messageType === 'success'
                        ? 'success'
                        : 'danger'
                ); ?> alert-dismissible fade show"
                role="alert"
            >

                <?= e($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"
                ></button>

            </div>

        <?php endif; ?>

        <!-- PAGE TITLE -->

        <div class="page-title-row">

            <div class="page-title">

                <h2>
                    Library Books
                </h2>

                <p>
                    Manage your library book collection
                </p>

            </div>

            <!-- ADD BUTTON -->

            <button
                class="btn btn-primary d-inline-flex align-items-center gap-2"
                data-bs-toggle="modal"
                data-bs-target="#addBookModal"
                type="button"
            >

                <i class="bi bi-plus-lg"></i>

                Add New Book

            </button>

        </div>

        <!-- ============================================================
             STATISTICS
             ============================================================ -->

        <div class="row g-3 mb-4">

            <div class="col-sm-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-journal-bookmark"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalTitles); ?>
                    </div>

                    <div class="stat-label">
                        Total Titles
                    </div>

                </div>

            </div>

            <div class="col-sm-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-stack"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalCopies); ?>
                    </div>

                    <div class="stat-label">
                        Total Copies
                    </div>

                </div>

            </div>

            <div class="col-sm-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalAvailable); ?>
                    </div>

                    <div class="stat-label">
                        Available Copies
                    </div>

                </div>

            </div>

            <div class="col-sm-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-arrow-up-right-circle"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalBorrowed); ?>
                    </div>

                    <div class="stat-label">
                        Borrowed Copies
                    </div>

                </div>

            </div>

        </div>

        <!-- ============================================================
             SEARCH
             ============================================================ -->

        <div class="search-area">

            <form
                method="GET"
                action="books.php"
                class="row g-3"
            >

                <div class="col-md-7">

                    <label class="form-label">
                        Search Book
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search by title, author, ISBN..."
                        value="<?= e($search); ?>"
                    >

                </div>

                <div class="col-md-3">

                    <label class="form-label">
                        Category
                    </label>

                    <select
                        name="category"
                        class="form-select"
                    >

                        <option value="0">
                            All Categories
                        </option>

                        <?php foreach ($categories as $cat): ?>

                            <option
                                value="<?= (int) $cat['id']; ?>"
                                <?= $categoryFilter === (int) $cat['id']
                                    ? 'selected'
                                    : ''; ?>
                            >
                                <?= e($cat['name']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-2 d-flex align-items-end gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    >
                        Filter
                    </button>

                    <a
                        href="books.php"
                        class="btn btn-light border"
                        title="Clear Filters"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>

                </div>

            </form>

        </div>

        <!-- ============================================================
             BOOK RECORDS
             ============================================================ -->

        <div class="card-box">

            <div class="p-3 border-bottom">

                <h3 class="fs-6 fw-bold mb-0">

                    Book Records
                    (<?= number_format($totalBooks); ?>)

                </h3>

            </div>

            <?php if (empty($books)): ?>

                <div class="empty-state">

                    <i class="bi bi-book"></i>

                    <h4>
                        No books found
                    </h4>

                    <p>
                        Try clearing your filters or add a new book.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    Book Title
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Quantity
                                </th>

                                <th>
                                    Available
                                </th>

                                <th class="text-end">
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($books as $b): ?>

                                <?php

                                $bookId = (int) $b['id'];

                                $bookTitle = scalarString(
                                    $b['title'] ?? ''
                                );

                                $bookCategory = scalarString(
                                    $b['category_name'] ?? ''
                                );

                                $categoryId = (int) (
                                    $b['category_id'] ?? 0
                                );

                                $totalQuantity = (int) (
                                    $b['total_quantity'] ?? 0
                                );

                                $availableQuantity = (int) (
                                    $b['available_quantity'] ?? 0
                                );

                                ?>

                                <tr>

                                    <!-- BOOK TITLE -->

                                    <td>

                                        <div class="book-title">

                                            <?= e($bookTitle); ?>

                                        </div>

                                    </td>

                                    <!-- CATEGORY -->

                                    <td>

                                        <span class="category-badge">

                                            <?= e(
                                                $bookCategory !== ''
                                                    ? $bookCategory
                                                    : 'Uncategorized'
                                            ); ?>

                                        </span>

                                    </td>

                                    <!-- TOTAL QUANTITY -->

                                    <td>

                                        <strong>
                                            <?= number_format(
                                                $totalQuantity
                                            ); ?>
                                        </strong>

                                    </td>

                                    <!-- AVAILABLE -->

                                    <td>

                                        <span
                                            class="quantity-badge <?= $availableQuantity > 0
                                                ? 'quantity-available'
                                                : 'quantity-empty'; ?>"
                                        >

                                            <i class="bi bi-circle-fill"></i>

                                            <?= number_format(
                                                $availableQuantity
                                            ); ?>

                                            Available

                                        </span>

                                    </td>

                                    <!-- ACTIONS -->

                                    <td class="text-end">

                                        <button
                                            class="action-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editBookModal<?= $bookId; ?>"
                                            title="Edit"
                                            type="button"
                                        >

                                            <i class="bi bi-pencil"></i>

                                        </button>

                                        <button
                                            class="action-btn delete"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteBookModal<?= $bookId; ?>"
                                            title="Delete"
                                            type="button"
                                        >

                                            <i class="bi bi-trash"></i>

                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- PAGINATION -->

                <?php if ($totalPages > 1): ?>

                    <div class="p-3 border-top d-flex align-items-center justify-content-between gap-3 flex-wrap">

                        <span class="text-muted small text-nowrap">

                            Showing page
                            <?= $page; ?>
                            of
                            <?= $totalPages; ?>

                        </span>

                        <ul class="pagination pagination-sm m-0">

                            <li
                                class="page-item <?= $page <= 1
                                    ? 'disabled'
                                    : ''; ?>"
                            >

                                <?php if ($page > 1): ?>

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                $page - 1,
                                                $search,
                                                $categoryFilter
                                            )
                                        ); ?>"
                                    >
                                        Previous
                                    </a>

                                <?php else: ?>

                                    <span class="page-link">
                                        Previous
                                    </span>

                                <?php endif; ?>

                            </li>

                            <?php

                            $startPage = max(
                                1,
                                $page - 2
                            );

                            $endPage = min(
                                $totalPages,
                                $page + 2
                            );

                            ?>

                            <?php if ($startPage > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                1,
                                                $search,
                                                $categoryFilter
                                            )
                                        ); ?>"
                                    >
                                        1
                                    </a>

                                </li>

                                <?php if ($startPage > 2): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                            <?php endif; ?>

                            <?php for (
                                $i = $startPage;
                                $i <= $endPage;
                                $i++
                            ): ?>

                                <li
                                    class="page-item <?= $i === $page
                                        ? 'active'
                                        : ''; ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                $i,
                                                $search,
                                                $categoryFilter
                                            )
                                        ); ?>"
                                    >
                                        <?= $i; ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>

                                <?php if ($endPage < $totalPages - 1): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                $totalPages,
                                                $search,
                                                $categoryFilter
                                            )
                                        ); ?>"
                                    >
                                        <?= $totalPages; ?>
                                    </a>

                                </li>

                            <?php endif; ?>

                            <li
                                class="page-item <?= $page >= $totalPages
                                    ? 'disabled'
                                    : ''; ?>"
                            >

                                <?php if ($page < $totalPages): ?>

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                $page + 1,
                                                $search,
                                                $categoryFilter
                                            )
                                        ); ?>"
                                    >
                                        Next
                                    </a>

                                <?php else: ?>

                                    <span class="page-link">
                                        Next
                                    </span>

                                <?php endif; ?>

                            </li>

                        </ul>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</main>

<!-- ================================================================
     EDIT + DELETE MODALS
     ================================================================ -->

<?php foreach ($books as $b): ?>

    <?php

    $bookId = (int) (
        $b['id'] ?? 0
    );

    $bookTitle = scalarString(
        $b['title'] ?? ''
    );

    $bookCategoryId = (int) (
        $b['category_id'] ?? 0
    );

    $bookTotalQuantity = (int) (
        $b['total_quantity'] ?? 0
    );

    ?>

    <!-- EDIT BOOK MODAL -->

    <div
        class="modal fade"
        id="editBookModal<?= $bookId; ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form
                    method="POST"
                    action="books.php"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="update_book"
                    >

                    <input
                        type="hidden"
                        name="book_id"
                        value="<?= $bookId; ?>"
                    >

                    <div class="modal-header">

                        <h5 class="modal-title fw-bold">
                            Edit Book
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                        ></button>

                    </div>

                    <div class="modal-body">

                        <!-- TITLE -->

                        <div class="mb-3">

                            <label class="form-label">
                                Book Title *
                            </label>

                            <input
                                type="text"
                                name="title"
                                class="form-control"
                                required
                                value="<?= e($bookTitle); ?>"
                                placeholder="Enter book title"
                            >

                        </div>

                        <!-- CATEGORY -->

                        <div class="mb-3">

                            <label class="form-label">
                                Category
                            </label>

                            <select
                                name="category_id"
                                class="form-select"
                            >

                                <option value="0">
                                    Uncategorized
                                </option>

                                <?php foreach ($categories as $cat): ?>

                                    <?php

                                    $catId = (int) (
                                        $cat['id'] ?? 0
                                    );

                                    ?>

                                    <option
                                        value="<?= $catId; ?>"
                                        <?= $bookCategoryId === $catId
                                            ? 'selected'
                                            : ''; ?>
                                    >

                                        <?= e(
                                            $cat['name'] ?? ''
                                        ); ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- QUANTITY -->

                        <div class="mb-1">

                            <label class="form-label">
                                Quantity *
                            </label>

                            <input
                                type="number"
                                name="quantity"
                                class="form-control"
                                min="1"
                                required
                                value="<?= $bookTotalQuantity; ?>"
                            >

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                            <i class="bi bi-check-lg me-1"></i>

                            Save Changes

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- DELETE BOOK MODAL -->

    <div
        class="modal fade"
        id="deleteBookModal<?= $bookId; ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog">

            <div class="modal-content">

                <form
                    method="POST"
                    action="books.php"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="delete_book"
                    >

                    <input
                        type="hidden"
                        name="book_id"
                        value="<?= $bookId; ?>"
                    >

                    <div class="modal-header">

                        <h5 class="modal-title text-danger fw-bold">

                            <i class="bi bi-exclamation-triangle me-2"></i>

                            Confirm Deletion

                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                        ></button>

                    </div>

                    <div class="modal-body">

                        <p class="mb-2">
                            Are you sure you want to delete:
                        </p>

                        <div class="alert alert-light border mb-0">

                            <strong>
                                <?= e($bookTitle); ?>
                            </strong>

                        </div>

                        <p class="text-muted small mt-3 mb-0">

                            The book will be removed from the active
                            catalog. Existing database history will
                            remain available.

                        </p>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="btn btn-danger"
                        >

                            <i class="bi bi-trash me-1"></i>

                            Delete Book

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

<?php endforeach; ?>

<!-- ================================================================
     ADD BOOK MODAL
     ================================================================ -->

<div
    class="modal fade"
    id="addBookModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form
                method="POST"
                action="books.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="add_book"
                >

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-plus-circle me-2"></i>

                        Add New Book

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>

                </div>

                <div class="modal-body">

                    <!-- BOOK TITLE -->

                    <div class="mb-3">

                        <label class="form-label">
                            Book Title *
                        </label>

                        <input
                            type="text"
                            name="title"
                            class="form-control"
                            required
                            placeholder="Enter book title"
                            autofocus
                        >

                    </div>

                    <!-- CATEGORY -->

                    <div class="mb-3">

                        <label class="form-label">
                            Category
                        </label>

                        <select
                            name="category_id"
                            class="form-select"
                        >

                            <option value="0">
                                Uncategorized
                            </option>

                            <?php foreach ($categories as $cat): ?>

                                <option
                                    value="<?= (int) $cat['id']; ?>"
                                >

                                    <?= e(
                                        $cat['name'] ?? ''
                                    ); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- QUANTITY -->

                    <div class="mb-1">

                        <label class="form-label">
                            Quantity *
                        </label>

                        <input
                            type="number"
                            name="quantity"
                            class="form-control"
                            min="1"
                            value="1"
                            required
                        >

                        <div class="form-text">
                            Enter the total number of copies available.
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-plus-lg me-1"></i>

                        Add Book

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ================================================================
     BOOTSTRAP JS
     ================================================================ -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const toggleSidebar =
        document.getElementById('toggleSidebar');

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    function closeSidebar() {

        if (sidebar) {
            sidebar.classList.remove('show');
        }

    }

    toggleSidebar?.addEventListener(
        'click',
        () => {

            sidebar?.classList.toggle('show');

        }
    );

    sidebarOverlay?.addEventListener(
        'click',
        closeSidebar
    );

    document
        .querySelectorAll('.sidebar-nav a')
        .forEach(link => {

            link.addEventListener(
                'click',
                () => {

                    if (window.innerWidth <= 991) {
                        closeSidebar();
                    }

                }
            );

        });

    window.addEventListener(
        'resize',
        () => {

            if (window.innerWidth > 991) {
                closeSidebar();
            }

        }
    );

</script>

</body>

</html>