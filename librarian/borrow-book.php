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

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function safeString(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function redirectWithMessage(
    string $type,
    string $message
): never {
    $_SESSION['borrow_book_message'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: borrow-book.php');
    exit;
}

function jsonResponse(array $data): never
{
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Database
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

$librarianId = (int) $_SESSION['user_id'];

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

        $librarianName = safeString(
            $row['full_name'] ?? 'Librarian'
        );

        $librarianEmail = safeString(
            $row['email'] ?? ''
        );

        $librarianPhoto =
            $row['photo_path'] ?? null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Librarian Photo
|--------------------------------------------------------------------------
*/

$photoUrl =
    '../public/images/default-avatar.png';

if (!empty($librarianPhoto)) {

    $photoPath = str_replace(
        '\\',
        '/',
        trim((string) $librarianPhoto)
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
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE LOWER(status) = 'active'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $activeAcademicYear = $row;
    }

    $stmt->close();
}

if (!$activeAcademicYear) {

    redirectWithMessage(
        'danger',
        'No active academic year is available.'
    );
}

$academicYearId =
    (int) $activeAcademicYear['id'];

$academicYearName =
    safeString(
        $activeAcademicYear['name'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$todayEthiopianFormatted =
    EthiopianCalendar::format(
        $todayEthiopian['year'],
        $todayEthiopian['month'],
        $todayEthiopian['day'],
        'en'
    );

/*
|--------------------------------------------------------------------------
| AJAX - Search Books
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Book search uses TITLE ONLY.
| ISBN is intentionally NOT used.
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'book_search'
) {

    $query = safeString(
        $_GET['q'] ?? ''
    );

    if (mb_strlen($query) < 2) {

        jsonResponse([
            'success' => true,
            'books' => []
        ]);
    }

    $search =
        '%' . $query . '%';

    $books = [];

    $stmt = $conn->prepare("
        SELECT
            id,
            title,
            total_quantity,
            available_quantity
        FROM library_books
        WHERE is_deleted = 0
          AND available_quantity > 0
          AND title LIKE ?
        ORDER BY title ASC
        LIMIT 20
    ");

    if (!$stmt) {

        jsonResponse([
            'success' => false,
            'message' =>
                'Unable to search books.'
        ]);
    }

    $stmt->bind_param(
        's',
        $search
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $books[] = [
            'id' =>
                (int) $row['id'],

            'title' =>
                safeString(
                    $row['title']
                ),

            'total_quantity' =>
                (int) $row['total_quantity'],

            'available_quantity' =>
                (int) $row['available_quantity']
        ];
    }

    $stmt->close();

    jsonResponse([
        'success' => true,
        'books' => $books
    ]);
}

/*
|--------------------------------------------------------------------------
| AJAX - Search Students
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'student_search'
) {

    $query = safeString(
        $_GET['q'] ?? ''
    );

    if (mb_strlen($query) < 2) {

        jsonResponse([
            'success' => true,
            'students' => []
        ]);
    }

    $search =
        '%' . $query . '%';

    $students = [];

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name,
            sr.id AS registration_id
        FROM students s
        INNER JOIN student_registrations sr
            ON sr.student_id = s.id
        WHERE sr.academic_year_id = ?
          AND (
                s.full_name LIKE ?
                OR s.student_code LIKE ?
              )
        ORDER BY s.full_name ASC
        LIMIT 20
    ");

    if (!$stmt) {

        jsonResponse([
            'success' => false,
            'message' =>
                'Unable to search students.'
        ]);
    }

    $stmt->bind_param(
        'iss',
        $academicYearId,
        $search,
        $search
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $students[] = [
            'id' =>
                (int) $row['id'],

            'student_code' =>
                safeString(
                    $row['student_code'] ?? ''
                ),

            'full_name' =>
                safeString(
                    $row['full_name'] ?? ''
                ),

            'registration_id' =>
                (int) $row['registration_id']
        ];
    }

    $stmt->close();

    jsonResponse([
        'success' => true,
        'students' => $students
    ]);
}

/*
|--------------------------------------------------------------------------
| Ethiopian Due Date Options
|--------------------------------------------------------------------------
*/

$ethiopianYears = [];

$currentEthYear =
    (int) $todayEthiopian['year'];

for (
    $year = $currentEthYear;
    $year <= $currentEthYear + 2;
    $year++
) {
    $ethiopianYears[] = $year;
}

$ethiopianMonths =
    EthiopianCalendar::months('en');

/*
|--------------------------------------------------------------------------
| Handle Borrow Book
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'borrow_book'
) {

    $studentId =
        (int) ($_POST['student_id'] ?? 0);

    $registrationId =
        (int) ($_POST['registration_id'] ?? 0);

    $bookId =
        (int) ($_POST['book_id'] ?? 0);

    $dueYear =
        (int) ($_POST['due_year'] ?? 0);

    $dueMonth =
        (int) ($_POST['due_month'] ?? 0);

    $dueDay =
        (int) ($_POST['due_day'] ?? 0);

    $note =
        safeString(
            $_POST['note'] ?? ''
        );

    /*
    |--------------------------------------------------------------------------
    | Validate Student
    |--------------------------------------------------------------------------
    */

    if ($studentId <= 0) {

        redirectWithMessage(
            'danger',
            'Please select a student.'
        );
    }

    if ($registrationId <= 0) {

        redirectWithMessage(
            'danger',
            'The selected student registration is invalid.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Book
    |--------------------------------------------------------------------------
    */

    if ($bookId <= 0) {

        redirectWithMessage(
            'danger',
            'Please select a book.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Ethiopian Due Date
    |--------------------------------------------------------------------------
    */

    $dueDateGregorian = null;

    if (
        $dueYear > 0 ||
        $dueMonth > 0 ||
        $dueDay > 0
    ) {

        if (
            $dueYear <= 0 ||
            $dueMonth <= 0 ||
            $dueDay <= 0
        ) {

            redirectWithMessage(
                'danger',
                'Please select a complete Ethiopian due date.'
            );
        }

        try {

            $dueDateGregorian =
                EthiopianCalendar::toGregorian(
                    $dueYear,
                    $dueMonth,
                    $dueDay
                );

        } catch (Throwable $exception) {

            redirectWithMessage(
                'danger',
                'The selected Ethiopian due date is invalid.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Due Date Cannot Be Before Today
        |--------------------------------------------------------------------------
        */

        if (
            $dueDateGregorian <
            date('Y-m-d')
        ) {

            redirectWithMessage(
                'danger',
                'Due date cannot be before today.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Database Transaction
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Validate Student Registration
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                sr.id,
                sr.student_id
            FROM student_registrations sr
            WHERE sr.id = ?
              AND sr.student_id = ?
              AND sr.academic_year_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to validate student registration.'
            );
        }

        $stmt->bind_param(
            'iii',
            $registrationId,
            $studentId,
            $academicYearId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $registration =
            $result->fetch_assoc();

        $stmt->close();

        if (!$registration) {

            throw new RuntimeException(
                'The selected student is not registered for the active academic year.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Lock Book Row
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                title,
                total_quantity,
                available_quantity
            FROM library_books
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to check the selected book.'
            );
        }

        $stmt->bind_param(
            'i',
            $bookId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $book =
            $result->fetch_assoc();

        $stmt->close();

        if (!$book) {

            throw new RuntimeException(
                'The selected book was not found.'
            );
        }

        $bookTitle =
            safeString(
                $book['title']
            );

        $availableQuantity =
            (int) $book['available_quantity'];

        if ($availableQuantity <= 0) {

            throw new RuntimeException(
                'This book is currently not available.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Active Borrow
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id
            FROM library_borrowings
            WHERE student_registration_id = ?
              AND book_id = ?
              AND status IN (
                  'Borrowed',
                  'Overdue'
              )
            LIMIT 1
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to check existing borrowing records.'
            );
        }

        $stmt->bind_param(
            'ii',
            $registrationId,
            $bookId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $existing =
            $result->fetch_assoc();

        $stmt->close();

        if ($existing) {

            throw new RuntimeException(
                'This student already has this book and has not returned it yet.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Insert Borrowing Record
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO library_borrowings (
                student_id,
                student_registration_id,
                academic_year_id,
                book_id,
                borrow_date,
                borrow_time,
                due_date,
                status,
                note,
                recorded_by
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                CURDATE(),
                NOW(),
                ?,
                'Borrowed',
                ?,
                ?
            )
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to create the borrowing record.'
            );
        }

        $stmt->bind_param(
            'iiiissi',
            $studentId,
            $registrationId,
            $academicYearId,
            $bookId,
            $dueDateGregorian,
            $note,
            $librarianId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new RuntimeException(
                'Unable to save the borrowing record.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Decrease Available Quantity
        |--------------------------------------------------------------------------
        */

        $newAvailableQuantity =
            $availableQuantity - 1;

        $stmt = $conn->prepare("
            UPDATE library_books
            SET available_quantity = ?
            WHERE id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

        if (!$stmt) {

            throw new RuntimeException(
                'Unable to update book quantity.'
            );
        }

        $stmt->bind_param(
            'ii',
            $newAvailableQuantity,
            $bookId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new RuntimeException(
                'Unable to update available book quantity.'
            );
        }

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        redirectWithMessage(
            'success',
            'Book "' .
            $bookTitle .
            '" was successfully borrowed.'
        );

    } catch (Throwable $exception) {

        $conn->rollback();

        redirectWithMessage(
            'danger',
            $exception->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

$flashMessage =
    $_SESSION['borrow_book_message']
    ?? null;

unset(
    $_SESSION['borrow_book_message']
);

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    safeString(
        $_GET['search'] ?? ''
    );

$statusFilter =
    safeString(
        $_GET['status'] ?? ''
    );

$page =
    max(
        1,
        (int) ($_GET['page'] ?? 1)
    );

$perPage = 20;

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Search Conditions
|--------------------------------------------------------------------------
*/

$where = [
    "b.academic_year_id = ?",
    "s.id IS NOT NULL",
    "lb.is_deleted = 0"
];

$params = [
    $academicYearId
];

$types = 'i';

if ($search !== '') {

    $where[] = "
        (
            s.full_name LIKE ?
            OR s.student_code LIKE ?
            OR lb.title LIKE ?
        )
    ";

    $searchValue =
        '%' . $search . '%';

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $types .= 'sss';
}

if (
    in_array(
        $statusFilter,
        [
            'Borrowed',
            'Returned',
            'Overdue'
        ],
        true
    )
) {

    $where[] =
        "b.status = ?";

    $params[] =
        $statusFilter;

    $types .= 's';
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Count Records
|--------------------------------------------------------------------------
*/

$totalRecords = 0;

$countSql = "
    SELECT
        COUNT(*) AS total
    FROM library_borrowings b

    INNER JOIN students s
        ON s.id = b.student_id

    INNER JOIN library_books lb
        ON lb.id = b.book_id

    WHERE {$whereSql}
";

$stmt =
    $conn->prepare(
        $countSql
    );

if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $totalRecords =
            (int) $row['total'];
    }

    $stmt->close();
}

$totalPages =
    max(
        1,
        (int) ceil(
            $totalRecords /
            $perPage
        )
    );

if ($page > $totalPages) {

    $page =
        $totalPages;

    $offset =
        ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Fetch Borrowing Records
|--------------------------------------------------------------------------
*/

$borrowings = [];

$listSql = "
    SELECT
        b.id,
        b.student_id,
        b.student_registration_id,
        b.book_id,
        b.borrow_date,
        b.borrow_time,
        b.due_date,
        b.return_date,
        b.return_time,
        b.status,
        b.note,

        s.full_name AS student_name,
        s.student_code,

        lb.title AS book_title

    FROM library_borrowings b

    INNER JOIN students s
        ON s.id = b.student_id

    INNER JOIN library_books lb
        ON lb.id = b.book_id

    WHERE {$whereSql}

    ORDER BY
        b.borrow_time DESC,
        b.id DESC

    LIMIT ? OFFSET ?
";

$listParams =
    $params;

$listParams[] =
    $perPage;

$listParams[] =
    $offset;

$listTypes =
    $types . 'ii';

$stmt =
    $conn->prepare(
        $listSql
    );

if ($stmt) {

    $stmt->bind_param(
        $listTypes,
        ...$listParams
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row =
        $result->fetch_assoc()
    ) {
        $borrowings[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalBorrowed = 0;
$totalReturned = 0;
$totalOverdue = 0;
$totalAvailableBooks = 0;

/*
|--------------------------------------------------------------------------
| Currently Borrowed
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Borrowed'
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $totalBorrowed =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Returned
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Returned'
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $totalReturned =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Overdue
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Overdue'
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $totalOverdue =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Available Copies
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(available_quantity),
            0
        ) AS total
    FROM library_books
    WHERE is_deleted = 0
");

if ($stmt) {

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row =
        $result->fetch_assoc()
    ) {
        $totalAvailableBooks =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Ethiopian Display Helper
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(
    ?string $gregorianDate
): string {

    if (
        empty($gregorianDate)
    ) {
        return '—';
    }

    try {

        return EthiopianCalendar::fromGregorian(
            substr(
                $gregorianDate,
                0,
                10
            )
        )['formatted'];

    } catch (Throwable $exception) {

        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function paginationUrl(
    int $page,
    string $search,
    string $status
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['search'] =
            $search;
    }

    if ($status !== '') {
        $query['status'] =
            $status;
    }

    return '?' .
        http_build_query(
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

    <title>
        Borrow Book | BKHS Library
    </title>

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
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --sidebar-text: #cbd5e1;
            --border: #e5e7eb;
            --muted: #64748b;
            --bg: #f8fafc;
            --white: #ffffff;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        a {
            text-decoration: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 260px;
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
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 10px;
            background: #fff;
        }

        .sidebar-brand-text {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
        }

        .sidebar-brand small {
            display: block;
            color: #94a3b8;
            font-size: 10px;
            font-weight: 500;
            margin-top: 1px;
        }

        .sidebar-section {
            padding: 20px 14px 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 10px;
            font-weight: 700;
        }

        .sidebar-nav {
            padding: 8px 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            margin-bottom: 4px;
            color: var(--sidebar-text);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-nav a i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-nav a:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-nav a.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .sidebar-nav a.active i {
            color: #60a5fa;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        /*
        |--------------------------------------------------------------------------
        | Topbar
        |--------------------------------------------------------------------------
        */

        .topbar {
            height: 76px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .profile-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-text {
            text-align: right;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .profile-role {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .profile-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }

        /*
        |--------------------------------------------------------------------------
        | Content
        |--------------------------------------------------------------------------
        */

        .content {
            padding: 28px;
        }

        .academic-year-bar {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .academic-year-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .academic-year-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            border-radius: 9px;
            font-size: 17px;
        }

        .academic-year-label {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 2px;
        }

        .academic-year-value {
            font-weight: 700;
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow:
                0 8px 24px
                rgba(15,23,42,.06);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            margin-bottom: 14px;
        }

        .stat-blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .stat-green {
            background: #dcfce7;
            color: #16a34a;
        }

        .stat-orange {
            background: #ffedd5;
            color: #ea580c;
        }

        .stat-red {
            background: #fee2e2;
            color: #dc2626;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 25px;
            line-height: 1;
            font-weight: 800;
        }

        /*
        |--------------------------------------------------------------------------
        | Card
        |--------------------------------------------------------------------------
        */

        .card-custom {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }

        .card-header-custom {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .card-header-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 9px;
            padding: 9px 14px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-box input {
            padding-left: 38px;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #dbe1e8;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #93c5fd;
            box-shadow:
                0 0 0 .2rem
                rgba(37,99,235,.10);
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .table-responsive {
            border-radius:
                0 0 14px 14px;
        }

        .table {
            margin: 0;
            vertical-align: middle;
        }

        .table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .student-name {
            font-weight: 700;
            color: #0f172a;
        }

        .student-code {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        .book-title {
            font-weight: 600;
            color: #334155;
        }

        .date-text {
            font-weight: 500;
            color: #334155;
            white-space: nowrap;
        }

        .time-text {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-borrowed {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .status-returned {
            color: #15803d;
            background: #dcfce7;
        }

        .status-overdue {
            color: #b91c1c;
            background: #fee2e2;
        }

        /*
        |--------------------------------------------------------------------------
        | Autocomplete
        |--------------------------------------------------------------------------
        */

        .autocomplete {
            position: relative;
        }

        .autocomplete-results {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #dbe1e8;
            border-radius: 10px;
            box-shadow:
                0 12px 30px
                rgba(15,23,42,.12);
            max-height: 260px;
            overflow-y: auto;
            z-index: 1200;
            display: none;
        }

        .autocomplete-results.show {
            display: block;
        }

        .autocomplete-item {
            padding: 11px 13px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: background .15s ease;
        }

        .autocomplete-item:last-child {
            border-bottom: 0;
        }

        .autocomplete-item:hover {
            background: #f8fafc;
        }

        .autocomplete-title {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }

        .autocomplete-meta {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }

        .autocomplete-loading,
        .autocomplete-empty {
            padding: 14px;
            color: #64748b;
            text-align: center;
            font-size: 12px;
        }

        .selected-box {
            margin-top: 8px;
            padding: 9px 11px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            border-radius: 9px;
            font-size: 12px;
            color: #1e40af;
            display: none;
        }

        .selected-box.show {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Date Selector
        |--------------------------------------------------------------------------
        */

        .ethiopian-date-box {
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }

        .ethiopian-date-label {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .ethiopian-date-preview {
            margin-top: 9px;
            color: #1e40af;
            font-size: 12px;
            font-weight: 600;
            display: none;
        }

        .ethiopian-date-preview.show {
            display: block;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #64748b;
        }

        .empty-state-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 14px;
            border-radius: 15px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            color: #94a3b8;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1050;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 22px;
            margin-right: 10px;
            color: #334155;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform:
                    translateX(-100%);
            }

            .sidebar.show {
                transform:
                    translateX(0);
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

            .mobile-menu-btn {
                display: inline-flex;
            }
        }

        @media (max-width: 767.98px) {

            body {
                padding-bottom: 66px;
            }

            .topbar {
                height: 68px;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .profile-text {
                display: none;
            }

            .profile-avatar {
                width: 38px;
                height: 38px;
            }

            .content {
                padding: 16px;
            }

            .academic-year-bar {
                align-items: flex-start;
            }

            .academic-year-right {
                display: none;
            }

            .card-header-custom {
                align-items: flex-start;
                flex-direction: column;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1150;
                justify-content: space-around;
                align-items: center;
            }

            .mobile-bottom-nav a {
                color: #64748b;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3px;
                font-size: 9px;
                font-weight: 600;
                min-width: 58px;
            }

            .mobile-bottom-nav a i {
                font-size: 18px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }

            .mobile-bottom-nav .bottom-add {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--primary);
                color: #fff;
                margin-top: -19px;
                box-shadow:
                    0 5px 15px
                    rgba(37,99,235,.25);
            }

            .mobile-bottom-nav .bottom-add i {
                font-size: 20px;
            }

            .table {
                min-width: 900px;
            }
        }

    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
-->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/image/logo.webp"
            alt="BKHS"
            class="sidebar-logo"
        >

        <div>

            <div class="sidebar-brand-text">
                BKHS
            </div>

            <small>
                Library Management
            </small>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="books.php">
            <i class="bi bi-book-fill"></i>
            <span>Books</span>
        </a>

        <a href="categories.php">
            <i class="bi bi-tags-fill"></i>
            <span>Categories</span>
        </a>

        <a href="study-attendance.php">
            <i class="bi bi-journal-check"></i>
            <span>Study Attendance</span>
        </a>

        <a
            href="borrow-book.php"
            class="active"
        >
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Borrow Book</span>
        </a>

        <a href="return-book.php">
            <i class="bi bi-box-arrow-in-down-left"></i>
            <span>Return Book</span>
        </a>

        <a href="borrowing-control.php">
            <i class="bi bi-sliders"></i>
            <span>Borrowing Control</span>
        </a>

        <a href="overdue-books.php">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Overdue Books</span>
        </a>

     

        <a href="reports.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

    </nav>

    <div class="sidebar-section">
        Account
    </div>

    <nav class="sidebar-nav">

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!--
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
-->

<main class="main">

    <header class="topbar">

        <div class="d-flex align-items-center">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Borrow Book
                </h1>

                <div class="page-subtitle">
                    Manage student book borrowing
                </div>

            </div>

        </div>

        <div class="profile-area">

            <div class="profile-text">

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
                class="profile-avatar"
                onerror="this.src='../public/images/default-avatar.png';"
            >

        </div>

    </header>

    <section class="content">

        <!-- Academic Year -->

        <div class="academic-year-bar">

            <div class="academic-year-left">

                <div class="academic-year-icon">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div>

                    <div class="academic-year-label">
                        Active Academic Year
                    </div>

                    <div class="academic-year-value">
                        <?= e($academicYearName) ?>
                    </div>

                </div>

            </div>

            <div class="academic-year-right">

                <span class="small">
                    Today:
                    <strong>
                        <?= e($todayEthiopianFormatted) ?>
                    </strong>
                </span>

            </div>

        </div>

        <!-- Flash -->

        <?php if ($flashMessage): ?>

            <div
                class="alert alert-<?= e(
                    $flashMessage['type'] ?? 'info'
                ) ?> alert-dismissible fade show"
                role="alert"
            >

                <i class="bi bi-info-circle me-2"></i>

                <?= e(
                    $flashMessage['message'] ?? ''
                ) ?>

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

                    <div class="stat-icon stat-blue">
                        <i class="bi bi-book"></i>
                    </div>

                    <div class="stat-label">
                        Currently Borrowed
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $totalBorrowed
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-green">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <div class="stat-label">
                        Returned
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $totalReturned
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-red">
                        <i class="bi bi-exclamation-circle"></i>
                    </div>

                    <div class="stat-label">
                        Overdue
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $totalOverdue
                        ) ?>
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon stat-orange">
                        <i class="bi bi-stack"></i>
                    </div>

                    <div class="stat-label">
                        Available Copies
                    </div>

                    <div class="stat-value">
                        <?= number_format(
                            $totalAvailableBooks
                        ) ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- Borrowing Records -->

        <div class="card-custom">

            <div class="card-header-custom">

                <div>

                    <h2 class="card-header-title">
                        Borrowing Records
                    </h2>

                    <div class="text-muted small mt-1">
                        <?= number_format(
                            $totalRecords
                        ) ?>
                        total record<?= $totalRecords === 1 ? '' : 's' ?>
                    </div>

                </div>

                <button
                    type="button"
                    class="btn btn-primary btn-add"
                    data-bs-toggle="modal"
                    data-bs-target="#borrowBookModal"
                >
                    <i class="bi bi-plus-lg"></i>
                    Borrow Book
                </button>

            </div>

            <!-- Filters -->

            <div class="p-3 border-bottom">

                <form
                    method="GET"
                    class="row g-2"
                >

                    <div class="col-lg-7">

                        <div class="search-box">

                            <i class="bi bi-search"></i>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search student name, student code or book title..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-lg-3">

                        <select
                            name="status"
                            class="form-select"
                        >

                            <option value="">
                                All Status
                            </option>

                            <option
                                value="Borrowed"
                                <?= $statusFilter === 'Borrowed'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Borrowed
                            </option>

                            <option
                                value="Returned"
                                <?= $statusFilter === 'Returned'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Returned
                            </option>

                            <option
                                value="Overdue"
                                <?= $statusFilter === 'Overdue'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Overdue
                            </option>

                        </select>

                    </div>

                    <div class="col-lg-2">

                        <button
                            type="submit"
                            class="btn btn-outline-primary w-100"
                        >
                            <i class="bi bi-filter me-1"></i>
                            Filter
                        </button>

                    </div>

                </form>

            </div>

            <!-- Table -->

            <?php if (!empty($borrowings)): ?>

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>#</th>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Book
                                </th>

                                <th>
                                    Borrow Date
                                </th>

                                <th>
                                    Due Date
                                </th>

                                <th>
                                    Return Date
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $borrowings
                            as $index => $borrowing
                        ): ?>

                            <?php

                            $status =
                                safeString(
                                    $borrowing['status']
                                    ?? ''
                                );

                            $statusClass =
                                match ($status) {
                                    'Returned' =>
                                        'status-returned',

                                    'Overdue' =>
                                        'status-overdue',

                                    default =>
                                        'status-borrowed'
                                };

                            $statusIcon =
                                match ($status) {
                                    'Returned' =>
                                        'bi-check-circle-fill',

                                    'Overdue' =>
                                        'bi-exclamation-circle-fill',

                                    default =>
                                        'bi-book-fill'
                                };

                            ?>

                            <tr>

                                <td class="text-muted">
                                    <?= e(
                                        $offset +
                                        $index +
                                        1
                                    ) ?>
                                </td>

                                <td>

                                    <div class="student-name">
                                        <?= e(
                                            $borrowing[
                                                'student_name'
                                            ]
                                        ) ?>
                                    </div>

                                    <div class="student-code">
                                        <?= e(
                                            $borrowing[
                                                'student_code'
                                            ]
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="book-title">
                                        <?= e(
                                            $borrowing[
                                                'book_title'
                                            ]
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="date-text">
                                        <?= e(
                                            formatEthiopianDate(
                                                $borrowing[
                                                    'borrow_date'
                                                ]
                                            )
                                        ) ?>
                                    </div>

                                    <div class="time-text">
                                        <?= !empty(
                                            $borrowing[
                                                'borrow_time'
                                            ]
                                        )
                                            ? e(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $borrowing[
                                                            'borrow_time'
                                                        ]
                                                    )
                                                )
                                            )
                                            : '—'
                                        ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="date-text">
                                        <?= e(
                                            formatEthiopianDate(
                                                $borrowing[
                                                    'due_date'
                                                ]
                                            )
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <?php if (
                                        !empty(
                                            $borrowing[
                                                'return_date'
                                            ]
                                        ) ||
                                        !empty(
                                            $borrowing[
                                                'return_time'
                                            ]
                                        )
                                    ): ?>

                                        <div class="date-text">
                                            <?= e(
                                                formatEthiopianDate(
                                                    $borrowing[
                                                        'return_date'
                                                    ]
                                                )
                                            ) ?>
                                        </div>

                                        <?php if (
                                            !empty(
                                                $borrowing[
                                                    'return_time'
                                                ]
                                            )
                                        ): ?>

                                            <div class="time-text">
                                                <?= e(
                                                    date(
                                                        'h:i A',
                                                        strtotime(
                                                            $borrowing[
                                                                'return_time'
                                                            ]
                                                        )
                                                    )
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <span
                                        class="status-badge <?= e(
                                            $statusClass
                                        ) ?>"
                                    >

                                        <i
                                            class="bi <?= e(
                                                $statusIcon
                                            ) ?>"
                                        ></i>

                                        <?= e(
                                            $status
                                        ) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div class="empty-state-icon">
                        <i class="bi bi-journal-x"></i>
                    </div>

                    <h5 class="fw-bold mb-2">
                        No borrowing records found
                    </h5>

                    <p class="mb-3">
                        No records match your current search or filter.
                    </p>

                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#borrowBookModal"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Borrow a Book
                    </button>

                </div>

            <?php endif; ?>

            <!-- Pagination -->

            <?php if ($totalPages > 1): ?>

                <div class="px-3 py-3 border-top">

                    <nav aria-label="Pagination">

                        <ul class="pagination pagination-sm mb-0 justify-content-center">

                            <li
                                class="page-item <?= $page <= 1
                                    ? 'disabled'
                                    : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page > 1
                                        ? e(
                                            paginationUrl(
                                                $page - 1,
                                                $search,
                                                $statusFilter
                                            )
                                        )
                                        : '#' ?>"
                                >
                                    Previous
                                </a>

                            </li>

                            <?php

                            $startPage =
                                max(
                                    1,
                                    $page - 2
                                );

                            $endPage =
                                min(
                                    $totalPages,
                                    $page + 2
                                );

                            ?>

                            <?php for (
                                $pageNumber =
                                    $startPage;
                                $pageNumber <=
                                    $endPage;
                                $pageNumber++
                            ): ?>

                                <li
                                    class="page-item <?= $pageNumber === $page
                                        ? 'active'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $pageNumber,
                                                $search,
                                                $statusFilter
                                            )
                                        ) ?>"
                                    >
                                        <?= $pageNumber ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li
                                class="page-item <?= $page >= $totalPages
                                    ? 'disabled'
                                    : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page < $totalPages
                                        ? e(
                                            paginationUrl(
                                                $page + 1,
                                                $search,
                                                $statusFilter
                                            )
                                        )
                                        : '#' ?>"
                                >
                                    Next
                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<!--
|--------------------------------------------------------------------------
| Mobile Bottom Navigation
|--------------------------------------------------------------------------
-->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2-fill"></i>

        <span>
            Home
        </span>

    </a>

    <a href="books.php">

        <i class="bi bi-book-fill"></i>

        <span>
            Books
        </span>

    </a>

    <a
        href="#"
        class="bottom-add"
        data-bs-toggle="modal"
        data-bs-target="#borrowBookModal"
    >

        <i class="bi bi-plus-lg"></i>

        <span>
            Borrow
        </span>

    </a>

    <a href="return-book.php">

        <i class="bi bi-arrow-return-left"></i>

        <span>
            Return
        </span>

    </a>

    <a href="profile.php">

        <i class="bi bi-person-circle"></i>

        <span>
            Profile
        </span>

    </a>

</nav>

<!--
|--------------------------------------------------------------------------
| Borrow Book Modal
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="borrowBookModal"
    tabindex="-1"
    aria-labelledby="borrowBookModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <form
                method="POST"
                id="borrowBookForm"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    name="action"
                    value="borrow_book"
                >

                <input
                    type="hidden"
                    name="student_id"
                    id="studentId"
                >

                <input
                    type="hidden"
                    name="registration_id"
                    id="registrationId"
                >

                <input
                    type="hidden"
                    name="book_id"
                    id="bookId"
                >

                <div class="modal-header border-bottom">

                    <div>

                        <h5
                            class="modal-title fw-bold"
                            id="borrowBookModalLabel"
                        >
                            Borrow Book
                        </h5>

                        <div class="text-muted small mt-1">
                            Select a student and available book.
                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <!-- Student -->

                    <div class="mb-3">

                        <label
                            for="studentSearch"
                            class="form-label fw-semibold"
                        >
                            Student
                            <span class="text-danger">*</span>
                        </label>

                        <div class="autocomplete">

                            <input
                                type="text"
                                id="studentSearch"
                                class="form-control"
                                placeholder="Type student name or student code..."
                                autocomplete="off"
                            >

                            <div
                                class="autocomplete-results"
                                id="studentResults"
                            ></div>

                        </div>

                        <div
                            class="selected-box"
                            id="selectedStudentBox"
                        >

                            <span>

                                <i class="bi bi-person-check me-1"></i>

                                <span
                                    id="selectedStudentText"
                                ></span>

                            </span>

                            <button
                                type="button"
                                class="btn btn-sm p-0 text-primary"
                                id="clearStudent"
                                title="Clear student"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>

                        </div>

                    </div>

                    <!-- Book -->

                    <div class="mb-3">

                        <label
                            for="bookSearch"
                            class="form-label fw-semibold"
                        >
                            Book
                            <span class="text-danger">*</span>
                        </label>

                        <div class="autocomplete">

                            <input
                                type="text"
                                id="bookSearch"
                                class="form-control"
                                placeholder="Type book title..."
                                autocomplete="off"
                            >

                            <div
                                class="autocomplete-results"
                                id="bookResults"
                            ></div>

                        </div>

                        <div
                            class="selected-box"
                            id="selectedBookBox"
                        >

                            <span>

                                <i class="bi bi-book me-1"></i>

                                <span
                                    id="selectedBookText"
                                ></span>

                            </span>

                            <button
                                type="button"
                                class="btn btn-sm p-0 text-primary"
                                id="clearBook"
                                title="Clear book"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>

                        </div>

                        <div class="form-text">
                            Type at least 2 characters to search by book title.
                        </div>

                    </div>

                    <!-- Ethiopian Due Date -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Due Date
                        </label>

                        <div class="ethiopian-date-box">

                            <div class="ethiopian-date-label">
                                Ethiopian Calendar
                            </div>

                            <div class="row g-2">

                                <div class="col-4">

                                    <select
                                        name="due_year"
                                        id="dueYear"
                                        class="form-select"
                                    >

                                        <option value="0">
                                            Year
                                        </option>

                                        <?php foreach (
                                            $ethiopianYears
                                            as $year
                                        ): ?>

                                            <option
                                                value="<?= e($year) ?>"
                                            >
                                                <?= e($year) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-5">

                                    <select
                                        name="due_month"
                                        id="dueMonth"
                                        class="form-select"
                                    >

                                        <option value="0">
                                            Month
                                        </option>

                                        <?php foreach (
                                            $ethiopianMonths
                                            as $monthNumber =>
                                                $monthName
                                        ): ?>

                                            <option
                                                value="<?= e(
                                                    $monthNumber
                                                ) ?>"
                                            >
                                                <?= e(
                                                    $monthName
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-3">

                                    <select
                                        name="due_day"
                                        id="dueDay"
                                        class="form-select"
                                    >

                                        <option value="0">
                                            Day
                                        </option>

                                        <?php for (
                                            $day = 1;
                                            $day <= 30;
                                            $day++
                                        ): ?>

                                            <option
                                                value="<?= $day ?>"
                                            >
                                                <?= $day ?>
                                            </option>

                                        <?php endfor; ?>

                                    </select>

                                </div>

                            </div>

                            <div
                                class="ethiopian-date-preview"
                                id="ethiopianDatePreview"
                            ></div>

                        </div>

                        <div class="form-text">
                            Leave empty if there is no due date.
                        </div>

                    </div>

                    <!-- Note -->

                    <div>

                        <label
                            for="note"
                            class="form-label fw-semibold"
                        >
                            Note
                        </label>

                        <textarea
                            name="note"
                            id="note"
                            class="form-control"
                            rows="3"
                            maxlength="255"
                            placeholder="Optional note..."
                        ></textarea>

                    </div>

                </div>

                <div class="modal-footer border-top">

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
                        id="borrowSubmitBtn"
                    >
                        <i class="bi bi-box-arrow-up-right me-1"></i>
                        Borrow Book
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        const sidebar =
            document.getElementById(
                'sidebar'
            );

        const sidebarOverlay =
            document.getElementById(
                'sidebarOverlay'
            );

        const mobileMenuBtn =
            document.getElementById(
                'mobileMenuBtn'
            );

        function openSidebar() {

            sidebar.classList.add(
                'show'
            );

            sidebarOverlay.classList.add(
                'show'
            );

            document.body.style.overflow =
                'hidden';
        }

        function closeSidebar() {

            sidebar.classList.remove(
                'show'
            );

            sidebarOverlay.classList.remove(
                'show'
            );

            document.body.style.overflow =
                '';
        }

        mobileMenuBtn.addEventListener(
            'click',
            openSidebar
        );

        sidebarOverlay.addEventListener(
            'click',
            closeSidebar
        );

        /*
        |--------------------------------------------------------------------------
        | Student Search
        |--------------------------------------------------------------------------
        */

        const studentSearch =
            document.getElementById(
                'studentSearch'
            );

        const studentResults =
            document.getElementById(
                'studentResults'
            );

        const studentId =
            document.getElementById(
                'studentId'
            );

        const registrationId =
            document.getElementById(
                'registrationId'
            );

        const selectedStudentBox =
            document.getElementById(
                'selectedStudentBox'
            );

        const selectedStudentText =
            document.getElementById(
                'selectedStudentText'
            );

        const clearStudent =
            document.getElementById(
                'clearStudent'
            );

        let studentTimer = null;

        async function searchStudents(
            query
        ) {

            if (query.length < 2) {

                studentResults.innerHTML =
                    '';

                studentResults.classList.remove(
                    'show'
                );

                return;
            }

            studentResults.innerHTML = `
                <div class="autocomplete-loading">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Searching students...
                </div>
            `;

            studentResults.classList.add(
                'show'
            );

            try {

                const response =
                    await fetch(
                        'borrow-book.php?ajax=student_search&q=' +
                        encodeURIComponent(
                            query
                        )
                    );

                const data =
                    await response.json();

                if (!data.success) {

                    studentResults.innerHTML = `
                        <div class="autocomplete-empty">
                            ${escapeHtml(
                                data.message ||
                                'Unable to search students.'
                            )}
                        </div>
                    `;

                    return;
                }

                if (
                    !data.students ||
                    data.students.length === 0
                ) {

                    studentResults.innerHTML = `
                        <div class="autocomplete-empty">
                            No matching student found.
                        </div>
                    `;

                    return;
                }

                studentResults.innerHTML =
                    '';

                data.students.forEach(
                    function (student) {

                        const item =
                            document.createElement(
                                'div'
                            );

                        item.className =
                            'autocomplete-item';

                        item.innerHTML = `
                            <div class="autocomplete-title">
                                ${escapeHtml(
                                    student.full_name
                                )}
                            </div>

                            <div class="autocomplete-meta">
                                ${escapeHtml(
                                    student.student_code
                                )}
                            </div>
                        `;

                        item.addEventListener(
                            'click',
                            function () {

                                studentId.value =
                                    student.id;

                                registrationId.value =
                                    student.registration_id;

                                studentSearch.value =
                                    student.full_name;

                                selectedStudentText.textContent =
                                    student.full_name +
                                    ' (' +
                                    student.student_code +
                                    ')';

                                selectedStudentBox.classList.add(
                                    'show'
                                );

                                studentResults.classList.remove(
                                    'show'
                                );
                            }
                        );

                        studentResults.appendChild(
                            item
                        );
                    }
                );

            } catch (error) {

                studentResults.innerHTML = `
                    <div class="autocomplete-empty">
                        Unable to search students.
                    </div>
                `;
            }
        }

        studentSearch.addEventListener(
            'input',
            function () {

                studentId.value =
                    '';

                registrationId.value =
                    '';

                selectedStudentBox.classList.remove(
                    'show'
                );

                clearTimeout(
                    studentTimer
                );

                const query =
                    this.value.trim();

                studentTimer =
                    setTimeout(
                        function () {

                            searchStudents(
                                query
                            );

                        },
                        300
                    );
            }
        );

        clearStudent.addEventListener(
            'click',
            function () {

                studentId.value =
                    '';

                registrationId.value =
                    '';

                studentSearch.value =
                    '';

                selectedStudentText.textContent =
                    '';

                selectedStudentBox.classList.remove(
                    'show'
                );

                studentSearch.focus();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Book Search
        |--------------------------------------------------------------------------
        |
        | TITLE ONLY.
        |--------------------------------------------------------------------------
        */

        const bookSearch =
            document.getElementById(
                'bookSearch'
            );

        const bookResults =
            document.getElementById(
                'bookResults'
            );

        const bookId =
            document.getElementById(
                'bookId'
            );

        const selectedBookBox =
            document.getElementById(
                'selectedBookBox'
            );

        const selectedBookText =
            document.getElementById(
                'selectedBookText'
            );

        const clearBook =
            document.getElementById(
                'clearBook'
            );

        let bookTimer = null;

        async function searchBooks(
            query
        ) {

            if (query.length < 2) {

                bookResults.innerHTML =
                    '';

                bookResults.classList.remove(
                    'show'
                );

                return;
            }

            bookResults.innerHTML = `
                <div class="autocomplete-loading">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Searching books...
                </div>
            `;

            bookResults.classList.add(
                'show'
            );

            try {

                const response =
                    await fetch(
                        'borrow-book.php?ajax=book_search&q=' +
                        encodeURIComponent(
                            query
                        )
                    );

                const data =
                    await response.json();

                if (!data.success) {

                    bookResults.innerHTML = `
                        <div class="autocomplete-empty">
                            ${escapeHtml(
                                data.message ||
                                'Unable to search books.'
                            )}
                        </div>
                    `;

                    return;
                }

                if (
                    !data.books ||
                    data.books.length === 0
                ) {

                    bookResults.innerHTML = `
                        <div class="autocomplete-empty">
                            No available book found.
                        </div>
                    `;

                    return;
                }

                bookResults.innerHTML =
                    '';

                data.books.forEach(
                    function (book) {

                        const item =
                            document.createElement(
                                'div'
                            );

                        item.className =
                            'autocomplete-item';

                        item.innerHTML = `
                            <div class="autocomplete-title">
                                ${escapeHtml(
                                    book.title
                                )}
                            </div>

                            <div class="autocomplete-meta">
                                ${escapeHtml(
                                    String(
                                        book.available_quantity
                                    )
                                )}
                                available
                            </div>
                        `;

                        item.addEventListener(
                            'click',
                            function () {

                                bookId.value =
                                    book.id;

                                bookSearch.value =
                                    book.title;

                                selectedBookText.textContent =
                                    book.title +
                                    ' — ' +
                                    book.available_quantity +
                                    ' available';

                                selectedBookBox.classList.add(
                                    'show'
                                );

                                bookResults.classList.remove(
                                    'show'
                                );
                            }
                        );

                        bookResults.appendChild(
                            item
                        );
                    }
                );

            } catch (error) {

                bookResults.innerHTML = `
                    <div class="autocomplete-empty">
                        Unable to search books.
                    </div>
                `;
            }
        }

        bookSearch.addEventListener(
            'input',
            function () {

                bookId.value =
                    '';

                selectedBookBox.classList.remove(
                    'show'
                );

                clearTimeout(
                    bookTimer
                );

                const query =
                    this.value.trim();

                bookTimer =
                    setTimeout(
                        function () {

                            searchBooks(
                                query
                            );

                        },
                        300
                    );
            }
        );

        clearBook.addEventListener(
            'click',
            function () {

                bookId.value =
                    '';

                bookSearch.value =
                    '';

                selectedBookText.textContent =
                    '';

                selectedBookBox.classList.remove(
                    'show'
                );

                bookSearch.focus();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Date
        |--------------------------------------------------------------------------
        */

        const dueYear =
            document.getElementById(
                'dueYear'
            );

        const dueMonth =
            document.getElementById(
                'dueMonth'
            );

        const dueDay =
            document.getElementById(
                'dueDay'
            );

        const dueDatePreview =
            document.getElementById(
                'ethiopianDatePreview'
            );

        const ethiopianMonths =
            <?= json_encode(
                array_values(
                    $ethiopianMonths
                ),
                JSON_UNESCAPED_UNICODE
            ) ?>;

        function updateEthiopianDatePreview() {

            const year =
                parseInt(
                    dueYear.value
                );

            const month =
                parseInt(
                    dueMonth.value
                );

            const day =
                parseInt(
                    dueDay.value
                );

            if (
                !year ||
                !month ||
                !day
            ) {

                dueDatePreview.textContent =
                    '';

                dueDatePreview.classList.remove(
                    'show'
                );

                return;
            }

            const monthName =
                ethiopianMonths[
                    month - 1
                ];

            dueDatePreview.textContent =
                'Selected: ' +
                monthName +
                ' ' +
                day +
                ', ' +
                year;

            dueDatePreview.classList.add(
                'show'
            );
        }

        dueYear.addEventListener(
            'change',
            updateEthiopianDatePreview
        );

        dueMonth.addEventListener(
            'change',
            updateEthiopianDatePreview
        );

        dueDay.addEventListener(
            'change',
            updateEthiopianDatePreview
        );

        /*
        |--------------------------------------------------------------------------
        | Adjust Pagume Days
        |--------------------------------------------------------------------------
        */

        function updateDays() {

            const year =
                parseInt(
                    dueYear.value
                );

            const month =
                parseInt(
                    dueMonth.value
                );

            const selectedDay =
                parseInt(
                    dueDay.value
                ) || 0;

            let maxDay = 30;

            if (month === 13) {

                /*
                |--------------------------------------------------------------------------
                | Ethiopian leap year:
                | divisible by 4 with remainder 3
                |--------------------------------------------------------------------------
                */

                maxDay =
                    year > 0 &&
                    year % 4 === 3
                        ? 6
                        : 5;
            }

            dueDay.innerHTML = `
                <option value="0">
                    Day
                </option>
            `;

            for (
                let day = 1;
                day <= maxDay;
                day++
            ) {

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    String(day);

                option.textContent =
                    String(day);

                if (
                    day === selectedDay &&
                    day <= maxDay
                ) {
                    option.selected =
                        true;
                }

                dueDay.appendChild(
                    option
                );
            }

            updateEthiopianDatePreview();
        }

        dueYear.addEventListener(
            'change',
            updateDays
        );

        dueMonth.addEventListener(
            'change',
            updateDays
        );

        /*
        |--------------------------------------------------------------------------
        | Form Validation
        |--------------------------------------------------------------------------
        */

        const borrowBookForm =
            document.getElementById(
                'borrowBookForm'
            );

        const borrowSubmitBtn =
            document.getElementById(
                'borrowSubmitBtn'
            );

        borrowBookForm.addEventListener(
            'submit',
            function (event) {

                if (!studentId.value) {

                    event.preventDefault();

                    studentSearch.focus();

                    alert(
                        'Please select a student from the search results.'
                    );

                    return;
                }

                if (!registrationId.value) {

                    event.preventDefault();

                    alert(
                        'The selected student registration is invalid.'
                    );

                    return;
                }

                if (!bookId.value) {

                    event.preventDefault();

                    bookSearch.focus();

                    alert(
                        'Please select a book from the search results.'
                    );

                    return;
                }

                const year =
                    parseInt(
                        dueYear.value
                    ) || 0;

                const month =
                    parseInt(
                        dueMonth.value
                    ) || 0;

                const day =
                    parseInt(
                        dueDay.value
                    ) || 0;

                /*
                |--------------------------------------------------------------------------
                | Either all due-date fields are empty,
                | or all three must be selected.
                |--------------------------------------------------------------------------
                */

                if (
                    (
                        year > 0 ||
                        month > 0 ||
                        day > 0
                    ) &&
                    (
                        year <= 0 ||
                        month <= 0 ||
                        day <= 0
                    )
                ) {

                    event.preventDefault();

                    alert(
                        'Please select the complete Ethiopian due date.'
                    );

                    return;
                }

                borrowSubmitBtn.disabled =
                    true;

                borrowSubmitBtn.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                    ></span>
                    Borrowing...
                `;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Close Dropdowns Outside
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
            'click',
            function (event) {

                if (
                    !studentSearch.contains(
                        event.target
                    ) &&
                    !studentResults.contains(
                        event.target
                    )
                ) {

                    studentResults.classList.remove(
                        'show'
                    );
                }

                if (
                    !bookSearch.contains(
                        event.target
                    ) &&
                    !bookResults.contains(
                        event.target
                    )
                ) {

                    bookResults.classList.remove(
                        'show'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Reset Modal
        |--------------------------------------------------------------------------
        */

        const borrowBookModal =
            document.getElementById(
                'borrowBookModal'
            );

        borrowBookModal.addEventListener(
            'hidden.bs.modal',
            function () {

                borrowBookForm.reset();

                studentId.value =
                    '';

                registrationId.value =
                    '';

                bookId.value =
                    '';

                studentSearch.value =
                    '';

                bookSearch.value =
                    '';

                selectedStudentText.textContent =
                    '';

                selectedBookText.textContent =
                    '';

                selectedStudentBox.classList.remove(
                    'show'
                );

                selectedBookBox.classList.remove(
                    'show'
                );

                studentResults.innerHTML =
                    '';

                bookResults.innerHTML =
                    '';

                studentResults.classList.remove(
                    'show'
                );

                bookResults.classList.remove(
                    'show'
                );

                dueDatePreview.textContent =
                    '';

                dueDatePreview.classList.remove(
                    'show'
                );

                borrowSubmitBtn.disabled =
                    false;

                borrowSubmitBtn.innerHTML = `
                    <i class="bi bi-box-arrow-up-right me-1"></i>
                    Borrow Book
                `;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Escape HTML
        |--------------------------------------------------------------------------
        */

        function escapeHtml(value) {

            return String(value)
                .replaceAll(
                    '&',
                    '&amp;'
                )
                .replaceAll(
                    '<',
                    '&lt;'
                )
                .replaceAll(
                    '>',
                    '&gt;'
                )
                .replaceAll(
                    '"',
                    '&quot;'
                )
                .replaceAll(
                    "'",
                    '&#039;'
                );
        }

    }
);

</script>

</body>

</html>