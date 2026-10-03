<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Librarian Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'librarian'
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

/**
 * Safely escape a value for HTML output.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Safely retrieve a scalar string from request/database values.
 */
function inputString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

/**
 * Redirect with a message.
 */
function redirectWithMessage(
    string $type,
    string $message
): never {
    header(
        'Location: categories.php?' .
        http_build_query([
            'msg_type' => $type,
            'msg' => $message
        ])
    );

    exit;
}

/**
 * Bind dynamic MySQLi parameters safely.
 */
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

    $stmt->bind_param(
        $types,
        ...$references
    );
}

/**
 * Safely convert a scalar value to integer.
 */
function safeInt(mixed $value): int
{
    if (!is_scalar($value)) {
        return 0;
    }

    return (int) $value;
}

/*
|--------------------------------------------------------------------------
| Database Connection Check
|--------------------------------------------------------------------------
*/

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Librarian Information
|--------------------------------------------------------------------------
*/

$librarianId = safeInt(
    $_SESSION['user_id'] ?? 0
);

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

        $librarianName = inputString(
            $row['full_name'] ?? ''
        );

        if ($librarianName === '') {
            $librarianName = 'Librarian';
        }

        $librarianEmail = inputString(
            $row['email'] ?? ''
        );

        $librarianPhoto = $row['photo_path'] ?? null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Librarian Photo URL
|--------------------------------------------------------------------------
*/

$photoUrl = '../public/images/default-avatar.png';

if (
    is_string($librarianPhoto) &&
    trim($librarianPhoto) !== ''
) {

    $photoPath = str_replace(
        '\\',
        '/',
        trim($librarianPhoto)
    );

    $photoPath = ltrim(
        $photoPath,
        '/'
    );

    if (
        str_starts_with(
            $photoPath,
            'public/'
        ) &&
        !str_contains(
            $photoPath,
            '..'
        )
    ) {
        $photoUrl = '../' . $photoPath;
    }
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = inputString(
    $_GET['msg'] ?? ''
);

$messageType = inputString(
    $_GET['msg_type'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = inputString(
        $_POST['action'] ?? ''
    );

    /*
    |--------------------------------------------------------------------------
    | Add Category
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_category') {

        $name = inputString(
            $_POST['name'] ?? ''
        );

        $description = inputString(
            $_POST['description'] ?? ''
        );

        if ($name === '') {

            redirectWithMessage(
                'danger',
                'Category name is required.'
            );
        }

        if (mb_strlen($name) > 100) {

            redirectWithMessage(
                'danger',
                'Category name cannot exceed 100 characters.'
            );
        }

        if (mb_strlen($description) > 255) {

            redirectWithMessage(
                'danger',
                'Description cannot exceed 255 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM library_categories
            WHERE LOWER(name) = LOWER(?)
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare category check.'
            );
        }

        $stmt->bind_param(
            's',
            $name
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $existingCategory = $result->fetch_assoc();

        $stmt->close();

        if ($existingCategory) {

            redirectWithMessage(
                'danger',
                'A category with this name already exists.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Insert Category
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO library_categories (
                name,
                description,
                is_deleted
            )
            VALUES (?, ?, 0)
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare category query.'
            );
        }

        $stmt->bind_param(
            'ss',
            $name,
            $description
        );

        if ($stmt->execute()) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Category added successfully.'
            );
        }

        $error = $stmt->error;

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Unable to add category: ' . $error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Category
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_category') {

        $categoryId = safeInt(
            $_POST['category_id'] ?? 0
        );

        $name = inputString(
            $_POST['name'] ?? ''
        );

        $description = inputString(
            $_POST['description'] ?? ''
        );

        if ($categoryId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid category.'
            );
        }

        if ($name === '') {

            redirectWithMessage(
                'danger',
                'Category name is required.'
            );
        }

        if (mb_strlen($name) > 100) {

            redirectWithMessage(
                'danger',
                'Category name cannot exceed 100 characters.'
            );
        }

        if (mb_strlen($description) > 255) {

            redirectWithMessage(
                'danger',
                'Description cannot exceed 255 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Category
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM library_categories
            WHERE LOWER(name) = LOWER(?)
              AND id <> ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare duplicate category check.'
            );
        }

        $stmt->bind_param(
            'si',
            $name,
            $categoryId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $existingCategory = $result->fetch_assoc();

        $stmt->close();

        if ($existingCategory) {

            redirectWithMessage(
                'danger',
                'Another category with this name already exists.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Category
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE library_categories
            SET
                name = ?,
                description = ?
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare category update query.'
            );
        }

        $stmt->bind_param(
            'ssi',
            $name,
            $description,
            $categoryId
        );

        if ($stmt->execute()) {

            $affectedRows = $stmt->affected_rows;

            $stmt->close();

            if ($affectedRows > 0) {

                redirectWithMessage(
                    'success',
                    'Category updated successfully.'
                );
            }

            redirectWithMessage(
                'success',
                'Category information is already up to date.'
            );
        }

        $error = $stmt->error;

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Unable to update category: ' . $error
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Category
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_category') {

        $categoryId = safeInt(
            $_POST['category_id'] ?? 0
        );

        if ($categoryId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid category.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Books Using Category
        |--------------------------------------------------------------------------
        */

        $bookCount = 0;

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM library_books
            WHERE category_id = ?
              AND is_deleted = 0
        ");

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $categoryId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {

                $bookCount = safeInt(
                    $row['total'] ?? 0
                );
            }

            $stmt->close();
        }

        if ($bookCount > 0) {

            redirectWithMessage(
                'danger',
                'This category cannot be deleted because it is assigned to ' .
                $bookCount .
                ' active book' .
                ($bookCount === 1 ? '' : 's') .
                '. Please update the books first.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Soft Delete
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE library_categories
            SET is_deleted = 1
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            redirectWithMessage(
                'danger',
                'Unable to prepare delete query.'
            );
        }

        $stmt->bind_param(
            'i',
            $categoryId
        );

        if (
            $stmt->execute() &&
            $stmt->affected_rows > 0
        ) {

            $stmt->close();

            redirectWithMessage(
                'success',
                'Category deleted successfully.'
            );
        }

        $stmt->close();

        redirectWithMessage(
            'danger',
            'Category could not be deleted.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = inputString(
    $_GET['search'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 10;

$pageValue = $_GET['page'] ?? 1;

$page = safeInt($pageValue);

if ($page < 1) {
    $page = 1;
}

/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

$where = [
    'c.is_deleted = 0'
];

$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            c.name LIKE ?
            OR c.description LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'ss';
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Total Categories
|--------------------------------------------------------------------------
*/

$totalCategories = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM library_categories c
    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if ($stmt) {

    if (!empty($params)) {

        bindDynamicParams(
            $stmt,
            $types,
            $params
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $totalCategories = safeInt(
            $row['total'] ?? 0
        );
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalCategories / $perPage
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
| Category List
|--------------------------------------------------------------------------
*/

$categories = [];

$categoriesSql = "
    SELECT
        c.id,
        c.name,
        c.description,
        c.created_at,

        COUNT(
            CASE
                WHEN b.is_deleted = 0
                THEN b.id
            END
        ) AS book_count,

        COALESCE(
            SUM(
                CASE
                    WHEN b.is_deleted = 0
                    THEN b.total_quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_copies,

        COALESCE(
            SUM(
                CASE
                    WHEN b.is_deleted = 0
                    THEN b.available_quantity
                    ELSE 0
                END
            ),
            0
        ) AS available_copies

    FROM library_categories c

    LEFT JOIN library_books b
        ON b.category_id = c.id

    WHERE {$whereSql}

    GROUP BY
        c.id,
        c.name,
        c.description,
        c.created_at

    ORDER BY c.name ASC

    LIMIT ? OFFSET ?
";

$categoryParams = $params;

$categoryParams[] = $perPage;
$categoryParams[] = $offset;

$categoryTypes = $types . 'ii';

$stmt = $conn->prepare(
    $categoriesSql
);

if ($stmt) {

    bindDynamicParams(
        $stmt,
        $categoryTypes,
        $categoryParams
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        if (is_array($row)) {
            $categories[] = $row;
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Page Statistics
|--------------------------------------------------------------------------
*/

$allCategoriesCount = 0;
$categoriesWithBooks = 0;
$totalCategoryCopies = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_categories,

        COALESCE(
            SUM(
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM library_books b
                        WHERE b.category_id = library_categories.id
                          AND b.is_deleted = 0
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS categories_with_books

    FROM library_categories

    WHERE is_deleted = 0
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $allCategoriesCount = safeInt(
            $row['total_categories'] ?? 0
        );

        $categoriesWithBooks = safeInt(
            $row['categories_with_books'] ?? 0
        );
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(b.total_quantity),
            0
        ) AS total_copies

    FROM library_books b

    INNER JOIN library_categories c
        ON c.id = b.category_id

    WHERE b.is_deleted = 0
      AND c.is_deleted = 0
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $totalCategoryCopies = safeInt(
            $row['total_copies'] ?? 0
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

        $ethiopianResult =
            EthiopianCalendar::fromGregorian(
                date('Y-m-d')
            );

        /*
        |----------------------------------------------------------------------
        | Important:
        | fromGregorian() may return a scalar or an array depending on the
        | implementation. Never directly cast an array to string.
        |----------------------------------------------------------------------
        */

        if (is_scalar($ethiopianResult)) {

            $todayEthiopian =
                trim((string) $ethiopianResult);

        } elseif (is_array($ethiopianResult)) {

            /*
            |------------------------------------------------------------------
            | Support common EthiopianCalendar return formats.
            |------------------------------------------------------------------
            */

            if (
                isset(
                    $ethiopianResult['formatted']
                ) &&
                is_scalar(
                    $ethiopianResult['formatted']
                )
            ) {

                $todayEthiopian =
                    trim(
                        (string)
                        $ethiopianResult['formatted']
                    );

            } elseif (
                isset(
                    $ethiopianResult['date']
                ) &&
                is_scalar(
                    $ethiopianResult['date']
                )
            ) {

                $todayEthiopian =
                    trim(
                        (string)
                        $ethiopianResult['date']
                    );

            } elseif (
                isset($ethiopianResult['year']) &&
                isset($ethiopianResult['month']) &&
                isset($ethiopianResult['day']) &&
                is_scalar($ethiopianResult['year']) &&
                is_scalar($ethiopianResult['month']) &&
                is_scalar($ethiopianResult['day'])
            ) {

                $todayEthiopian = sprintf(
                    '%04d-%02d-%02d',
                    (int) $ethiopianResult['year'],
                    (int) $ethiopianResult['month'],
                    (int) $ethiopianResult['day']
                );
            }
        }

    }

} catch (Throwable $calendarException) {

    $todayEthiopian = '';
}

if ($todayEthiopian === '') {

    $todayEthiopian = date(
        'Y-m-d'
    );
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function pageUrl(
    int $pageNumber,
    string $search
): string {

    $query = [
        'page' => max(
            1,
            $pageNumber
        )
    ];

    if ($search !== '') {

        $query['search'] = $search;
    }

    return '?' . http_build_query(
        $query
    );
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

    <title>Categories | BKHS Library</title>

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

        /* Sidebar */

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
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
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
            border-radius: 9px;
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

        /* Main */

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

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
            color: #111827;
        }

        /* Content */

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

        /* Statistics */

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

        /* Cards */

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .card-box-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .card-box-title {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
        }

        /* Search */

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

        .form-control {
            border-color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
            min-height: 42px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.1);
        }

        /* Table */

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            margin: 0;
            min-width: 900px;
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

        .category-name {
            font-weight: 700;
            color: #111827;
        }

        .category-description {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
            max-width: 350px;
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 700;
        }

        .copy-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-size: 10px;
            font-weight: 700;
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

        /* Empty State */

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
            font-weight: 700;
        }

        .empty-state p {
            font-size: 12px;
        }

        /* Pagination */

        .pagination {
            margin: 0;
        }

        .page-link {
            font-size: 12px;
            color: #374151;
            border-color: var(--border);
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        /* Modal */

        .modal-content {
            border: 0;
            border-radius: 15px;
            overflow: hidden;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .modal-title {
            font-size: 17px;
            font-weight: 800;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border);
        }

        /* Overlay */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
            z-index: 1050;
        }

        /* Mobile Bottom Navigation */

        .mobile-bottom-nav {
            display: none;
        }

        /* Responsive */

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
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

        @media (max-width: 767.98px) {

            .topbar {
                height: 68px;
            }

            .page-heading h1 {
                font-size: 17px;
            }

            .page-heading p {
                display: none;
            }

            .profile-name,
            .profile-role {
                display: none;
            }

            .profile-mini img {
                width: 37px;
                height: 37px;
            }

            .content {
                padding: 16px;
                padding-bottom: 85px;
            }

            .page-title-row {
                align-items: flex-start;
            }

            .page-title h2 {
                font-size: 20px;
            }

            .page-title p {
                font-size: 11px;
            }

            .page-title-row .btn {
                white-space: nowrap;
            }

            .stat-value {
                font-size: 21px;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: #fff;
                border-top: 1px solid var(--border);
                display: flex;
                align-items: center;
                justify-content: space-around;
                z-index: 1200;
            }

            .mobile-bottom-nav a {
                color: #6b7280;
                font-size: 10px;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 3px;
                min-width: 55px;
            }

            .mobile-bottom-nav a i {
                font-size: 18px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }
        }

    </style>

</head>

<body>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS"
        >

        <div>

            <div class="brand-title">
                BKHS Library
            </div>

            <div class="brand-subtitle">
                Library Management
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">

            <i class="bi bi-speedometer2"></i>

            <span>
                Dashboard
            </span>

        </a>

        <a href="books.php">

            <i class="bi bi-book"></i>

            <span>
                Books
            </span>

        </a>

        <a
            href="categories.php"
            class="active"
        >

            <i class="bi bi-tags"></i>

            <span>
                Categories
            </span>

        </a>

        <a href="study-attendance.php">

            <i class="bi bi-calendar-check"></i>

            <span>
                Study Attendance
            </span>

        </a>

    </nav>

    <div class="sidebar-section">
        Circulation
    </div>

    <nav class="sidebar-nav">

        <a href="borrow-book.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Borrow Book</span>
        </a>

        <a href="return-book.php">
            <i class="bi bi-box-arrow-in-left"></i>
            <span>Return Book</span>
        </a>

        <a href="borrowing-control.php">
            <i class="bi bi-arrow-left-right"></i>
            <span>Borrowing Control</span>
        </a>

        <a href="overdue-books.php">
            <i class="bi bi-exclamation-circle"></i>
            <span>Overdue Books</span>
        </a>

       
    </nav>

    <div class="sidebar-section">
        Reports
    </div>

    <nav class="sidebar-nav">

        <a href="reports.php">
            <i class="bi bi-bar-chart"></i>
            <span>Reports</span>
        </a>

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="logout-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Main -->

<main class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                class="mobile-menu-btn"
                type="button"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >

                <i class="bi bi-list"></i>

            </button>

            <div class="page-heading">

                <h1>
                    Categories
                </h1>

                <p>
                    Organize and manage library book categories
                </p>

            </div>

        </div>

        <div class="topbar-right">

            <div class="ethiopian-date">
                <?= e($todayEthiopian) ?>
            </div>

            <div class="profile-mini">

                <div class="text-end">

                    <div class="profile-name">
                        <?= e($librarianName) ?>
                    </div>

                    <div class="profile-role">
                        Librarian
                    </div>

                </div>

                <img
                    src="<?= e($photoUrl) ?>"
                    alt="Librarian"
                >

            </div>

        </div>

    </header>

    <!-- Content -->

    <section class="content">

        <!-- Page Heading -->

        <div class="page-title-row">

            <div class="page-title">

                <h2>
                    Book Categories
                </h2>

                <p>
                    Create, update and manage categories used for library books.
                </p>

            </div>

            <button
                type="button"
                class="btn btn-primary btn-sm px-3"
                data-bs-toggle="modal"
                data-bs-target="#addCategoryModal"
            >

                <i class="bi bi-plus-lg me-1"></i>

                Add Category

            </button>

        </div>

        <!-- Messages -->

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= e(
                    $messageType === 'success'
                        ? 'success'
                        : 'danger'
                ) ?> alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-info-circle me-2"></i>

                <?= e($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-tags"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($allCategoriesCount) ?>
                    </div>

                    <div class="stat-label">
                        Total Categories
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-book"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($categoriesWithBooks) ?>
                    </div>

                    <div class="stat-label">
                        Categories With Books
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-collection"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalCategoryCopies) ?>
                    </div>

                    <div class="stat-label">
                        Categorized Copies
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-folder-check"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalCategories) ?>
                    </div>

                    <div class="stat-label">
                        Matching Categories
                    </div>

                </div>

            </div>

        </div>

        <!-- Search -->

        <div class="search-area">

            <form
                method="get"
                action="categories.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-lg-10">

                        <label class="form-label">
                            Search Categories
                        </label>

                        <div class="input-group">

                            <span class="input-group-text bg-white">

                                <i class="bi bi-search"></i>

                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                value="<?= e($search) ?>"
                                placeholder="Search by category name or description..."
                            >

                        </div>

                    </div>

                    <div class="col-lg-2 d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary flex-grow-1"
                        >

                            <i class="bi bi-search me-1"></i>

                            Search

                        </button>

                        <a
                            href="categories.php"
                            class="btn btn-light border"
                            title="Clear search"
                        >

                            <i class="bi bi-x-lg"></i>

                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- Category Table -->

        <div class="card-box">

            <div class="card-box-header">

                <div>

                    <h3 class="card-box-title">
                        Category List
                    </h3>

                    <div
                        class="text-muted"
                        style="font-size:11px;"
                    >

                        <?= number_format($totalCategories) ?>

                        matching

                        categor<?= $totalCategories === 1 ? 'y' : 'ies' ?>

                    </div>

                </div>

            </div>

            <?php if (empty($categories)): ?>

                <div class="empty-state">

                    <i class="bi bi-tags"></i>

                    <h4>
                        No categories found
                    </h4>

                    <p>
                        Try changing your search or add a new category.
                    </p>

                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#addCategoryModal"
                    >

                        <i class="bi bi-plus-lg me-1"></i>

                        Add Category

                    </button>

                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Description
                                </th>

                                <th>
                                    Books
                                </th>

                                <th>
                                    Copies
                                </th>

                                <th>
                                    Available
                                </th>

                                <th>
                                    Created
                                </th>

                                <th class="text-end">
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($categories as $category): ?>

                                <?php

                                if (!is_array($category)) {
                                    continue;
                                }

                                $categoryId = safeInt(
                                    $category['id'] ?? 0
                                );

                                $categoryName = inputString(
                                    $category['name'] ?? ''
                                );

                                $categoryDescription = inputString(
                                    $category['description'] ?? ''
                                );

                                $bookCount = safeInt(
                                    $category['book_count'] ?? 0
                                );

                                $totalCopies = safeInt(
                                    $category['total_copies'] ?? 0
                                );

                                $availableCopies = safeInt(
                                    $category['available_copies'] ?? 0
                                );

                                $createdDate = '';

                                $rawCreatedAt =
                                    $category['created_at'] ?? '';

                                if (is_scalar($rawCreatedAt)) {

                                    $createdAtString =
                                        trim((string) $rawCreatedAt);

                                    if ($createdAtString !== '') {

                                        $timestamp =
                                            strtotime($createdAtString);

                                        if ($timestamp !== false) {

                                            $createdDate =
                                                date(
                                                    'Y-m-d',
                                                    $timestamp
                                                );
                                        }
                                    }
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | Safe JavaScript JSON
                                |--------------------------------------------------------------------------
                                */

                                $categoryNameJson = json_encode(
                                    $categoryName,
                                    JSON_HEX_TAG |
                                    JSON_HEX_AMP |
                                    JSON_HEX_APOS |
                                    JSON_HEX_QUOT
                                );

                                if ($categoryNameJson === false) {
                                    $categoryNameJson = '""';
                                }

                                ?>

                                <tr>

                                    <td>

                                        <div class="category-name">

                                            <i class="bi bi-tag me-1 text-primary"></i>

                                            <?= e($categoryName) ?>

                                        </div>

                                    </td>

                                    <td>

                                        <?php if ($categoryDescription !== ''): ?>

                                            <div class="category-description">

                                                <?= e(
                                                    $categoryDescription
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <span class="count-badge">

                                            <i class="bi bi-book me-1"></i>

                                            <?= number_format(
                                                $bookCount
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="copy-badge">

                                            <i class="bi bi-collection me-1"></i>

                                            <?= number_format(
                                                $totalCopies
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?php if ($availableCopies > 0): ?>

                                            <span class="copy-badge">

                                                <i class="bi bi-check-circle me-1"></i>

                                                <?= number_format(
                                                    $availableCopies
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge text-bg-light border"
                                                style="font-size:10px;"
                                            >
                                                No copies
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <span
                                            class="text-muted"
                                            style="font-size:11px;"
                                        >

                                            <?= e(
                                                $createdDate !== ''
                                                    ? $createdDate
                                                    : '—'
                                            ) ?>

                                        </span>

                                    </td>

                                    <td class="text-end">

                                        <div
                                            class="d-flex justify-content-end gap-1"
                                        >

                                            <!-- Edit -->

                                            <button
                                                type="button"
                                                class="action-btn"
                                                title="Edit Category"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editCategoryModal"
                                                data-id="<?= e($categoryId) ?>"
                                                data-name="<?= e($categoryName) ?>"
                                                data-description="<?= e($categoryDescription) ?>"
                                            >

                                                <i class="bi bi-pencil"></i>

                                            </button>

                                            <!-- Delete -->

                                            <button
                                                type="button"
                                                class="action-btn delete"
                                                title="Delete Category"
                                                onclick='confirmDelete(
                                                    <?= (int) $categoryId ?>,
                                                    <?= $categoryNameJson ?>
                                                )'
                                            >

                                                <i class="bi bi-trash3"></i>

                                            </button>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- Pagination -->

                <?php if ($totalPages > 1): ?>

                    <div
                        class="d-flex justify-content-between align-items-center flex-wrap gap-3 p-3 border-top"
                    >

                        <div
                            class="text-muted"
                            style="font-size:11px;"
                        >

                            Showing

                            <?= $totalCategories > 0
                                ? number_format($offset + 1)
                                : 0 ?>

                            -

                            <?= number_format(
                                min(
                                    $offset + $perPage,
                                    $totalCategories
                                )
                            ) ?>

                            of

                            <?= number_format(
                                $totalCategories
                            ) ?>

                        </div>

                        <nav>

                            <ul class="pagination pagination-sm mb-0">

                                <!-- Previous -->

                                <li
                                    class="page-item <?= $page <= 1
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                max(
                                                    1,
                                                    $page - 1
                                                ),
                                                $search
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi bi-chevron-left"></i>

                                    </a>

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

                                <?php for (
                                    $p = $startPage;
                                    $p <= $endPage;
                                    $p++
                                ): ?>

                                    <li
                                        class="page-item <?= $p === $page
                                            ? 'active'
                                            : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                pageUrl(
                                                    $p,
                                                    $search
                                                )
                                            ) ?>"
                                        >

                                            <?= $p ?>

                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <!-- Next -->

                                <li
                                    class="page-item <?= $page >= $totalPages
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                min(
                                                    $totalPages,
                                                    $page + 1
                                                ),
                                                $search
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi bi-chevron-right"></i>

                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </section>

</main>

<!-- Mobile Bottom Navigation -->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-speedometer2"></i>

        <span>
            Home
        </span>

    </a>

    <a href="books.php">

        <i class="bi bi-book"></i>

        <span>
            Books
        </span>

    </a>

    <a
        href="categories.php"
        class="active"
    >

        <i class="bi bi-tags"></i>

        <span>
            Categories
        </span>

    </a>

    <a href="borrow-book.php">

        <i class="bi bi-box-arrow-right"></i>

        <span>
            Borrow
        </span>

    </a>

    <a href="profile.php">

        <i class="bi bi-person"></i>

        <span>
            Profile
        </span>

    </a>

</nav>

<!-- Add Category Modal -->

<div
    class="modal fade"
    id="addCategoryModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form
                method="post"
                action="categories.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="add_category"
                >

                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-plus-circle me-2 text-primary"></i>

                        Add New Category

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label">

                            Category Name

                            <span class="text-danger">*</span>

                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="100"
                            required
                            placeholder="e.g. Computer Science"
                        >

                    </div>

                    <div>

                        <label class="form-label">
                            Description
                        </label>

                        <input
                            type="text"
                            name="description"
                            class="form-control"
                            maxlength="255"
                            placeholder="Optional description"
                        >

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Save Category

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- Edit Category Modal -->

<div
    class="modal fade"
    id="editCategoryModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form
                method="post"
                action="categories.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="update_category"
                >

                <input
                    type="hidden"
                    name="category_id"
                    id="edit_category_id"
                >

                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-pencil-square me-2 text-primary"></i>

                        Edit Category

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label">

                            Category Name

                            <span class="text-danger">*</span>

                        </label>

                        <input
                            type="text"
                            name="name"
                            id="edit_category_name"
                            class="form-control"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div>

                        <label class="form-label">
                            Description
                        </label>

                        <input
                            type="text"
                            name="description"
                            id="edit_category_description"
                            class="form-control"
                            maxlength="255"
                        >

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Update Category

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- Delete Form -->

<form
    method="post"
    action="categories.php"
    id="deleteCategoryForm"
    class="d-none"
>

    <input
        type="hidden"
        name="action"
        value="delete_category"
    >

    <input
        type="hidden"
        name="category_id"
        id="delete_category_id"
    >

</form>

<!-- Bootstrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

function openSidebar() {

    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('show');
    }
}

function closeSidebar() {

    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('show');
    }
}

if (mobileMenuBtn) {

    mobileMenuBtn.addEventListener(
        'click',
        function () {

            if (
                sidebar &&
                sidebar.classList.contains('show')
            ) {
                closeSidebar();
            } else {
                openSidebar();
            }

        }
    );
}

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );
}

/*
|--------------------------------------------------------------------------
| Close Mobile Sidebar When Navigation Link Is Clicked
|--------------------------------------------------------------------------
*/

if (sidebar) {

    const sidebarLinks =
        sidebar.querySelectorAll('a');

    sidebarLinks.forEach(
        function (link) {

            link.addEventListener(
                'click',
                closeSidebar
            );
        }
    );
}

/*
|--------------------------------------------------------------------------
| Edit Category Modal
|--------------------------------------------------------------------------
*/

const editCategoryModal =
    document.getElementById(
        'editCategoryModal'
    );

if (editCategoryModal) {

    editCategoryModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button =
                event.relatedTarget;

            if (!button) {
                return;
            }

            const categoryId =
                button.getAttribute(
                    'data-id'
                ) || '';

            const categoryName =
                button.getAttribute(
                    'data-name'
                ) || '';

            const categoryDescription =
                button.getAttribute(
                    'data-description'
                ) || '';

            const idInput =
                document.getElementById(
                    'edit_category_id'
                );

            const nameInput =
                document.getElementById(
                    'edit_category_name'
                );

            const descriptionInput =
                document.getElementById(
                    'edit_category_description'
                );

            if (idInput) {
                idInput.value = categoryId;
            }

            if (nameInput) {
                nameInput.value = categoryName;
            }

            if (descriptionInput) {
                descriptionInput.value =
                    categoryDescription;
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| Delete Confirmation
|--------------------------------------------------------------------------
*/

function confirmDelete(
    categoryId,
    categoryName
) {

    const confirmed =
        window.confirm(
            'Are you sure you want to delete "' +
            String(categoryName) +
            '"?\n\n' +
            'The category will be removed from the active category list.'
        );

    if (!confirmed) {
        return;
    }

    const idInput =
        document.getElementById(
            'delete_category_id'
        );

    const deleteForm =
        document.getElementById(
            'deleteCategoryForm'
        );

    if (!idInput || !deleteForm) {
        return;
    }

    idInput.value =
        String(categoryId);

    deleteForm.submit();
}

</script>

</body>
</html>