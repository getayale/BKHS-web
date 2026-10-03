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

function e(?string $value): string
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

    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;

    header('Location: overdue-books.php');
    exit;
}

function formatEthiopianDate(
    ?string $gregorianDate
): string {

    if (empty($gregorianDate)) {
        return '—';
    }

    try {

        $date = EthiopianCalendar::fromGregorian(
            substr($gregorianDate, 0, 10)
        );

        return $date['formatted'];

    } catch (Throwable $e) {

        return '—';
    }
}

function formatTime(
    ?string $dateTime
): string {

    if (empty($dateTime)) {
        return '—';
    }

    try {

        $date = new DateTime(
            $dateTime,
            new DateTimeZone('Africa/Addis_Ababa')
        );

        return $date->format('h:i A');

    } catch (Throwable $e) {

        return '—';
    }
}

/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

$flashType = $_SESSION['flash_type'] ?? '';
$flashMessage = $_SESSION['flash_message'] ?? '';

unset(
    $_SESSION['flash_type'],
    $_SESSION['flash_message']
);

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

        $librarianName =
            $row['full_name'] ?? 'Librarian';

        $librarianEmail =
            $row['email'] ?? '';

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

        $photoUrl =
            '../' . $photoPath;
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
| Ethiopian Today
|--------------------------------------------------------------------------
*/

$todayEthiopian =
    EthiopianCalendar::today();

$todayEthiopianFormatted =
    $todayEthiopian['formatted'];

/*
|--------------------------------------------------------------------------
| POST - Return Book
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        safeString(
            $_POST['action'] ?? ''
        );

    if ($action === 'return_book') {

        $borrowingId =
            (int) (
                $_POST['borrowing_id'] ?? 0
            );

        if ($borrowingId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid borrowing record.'
            );
        }

        $conn->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Lock Borrowing Record
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    book_id,
                    status,
                    return_date,
                    return_time
                FROM library_borrowings
                WHERE id = ?
                  AND academic_year_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare borrowing query.'
                );
            }

            $stmt->bind_param(
                'ii',
                $borrowingId,
                $academicYearId
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $borrowing =
                $result->fetch_assoc();

            $stmt->close();

            if (!$borrowing) {

                throw new RuntimeException(
                    'Borrowing record was not found.'
                );
            }

            $status =
                strtolower(
                    safeString(
                        $borrowing['status'] ?? ''
                    )
                );

            if (
                !in_array(
                    $status,
                    [
                        'borrowed',
                        'overdue'
                    ],
                    true
                )
            ) {

                throw new RuntimeException(
                    'This book has already been returned.'
                );
            }

            $bookId =
                (int) $borrowing['book_id'];

            /*
            |--------------------------------------------------------------------------
            | Lock Book
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
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
                    'Unable to prepare book query.'
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
                    'The related book was not found.'
                );
            }

            $totalQuantity =
                (int) $book['total_quantity'];

            $availableQuantity =
                (int) $book['available_quantity'];

            $newAvailableQuantity =
                min(
                    $totalQuantity,
                    $availableQuantity + 1
                );

            /*
            |--------------------------------------------------------------------------
            | Update Borrowing
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE library_borrowings
                SET
                    return_date = CURDATE(),
                    return_time = NOW(),
                    status = 'Returned',
                    recorded_by = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND academic_year_id = ?
                LIMIT 1
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare return query.'
                );
            }

            $stmt->bind_param(
                'iii',
                $librarianId,
                $borrowingId,
                $academicYearId
            );

            if (!$stmt->execute()) {

                $stmt->close();

                throw new RuntimeException(
                    'Unable to record the book return.'
                );
            }

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Increase Available Quantity
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE library_books
                SET
                    available_quantity = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare inventory update.'
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
                    'Unable to update book quantity.'
                );
            }

            $stmt->close();

            $conn->commit();

            redirectWithMessage(
                'success',
                'Overdue book returned successfully.'
            );

        } catch (Throwable $e) {

            $conn->rollback();

            redirectWithMessage(
                'danger',
                $e->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Automatically Mark Borrowed Books as Overdue
|--------------------------------------------------------------------------
|
| A book becomes overdue when due_date is before today.
|
*/

$stmt = $conn->prepare("
    UPDATE library_borrowings
    SET status = 'Overdue'
    WHERE academic_year_id = ?
      AND status = 'Borrowed'
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    safeString(
        $_GET['search'] ?? ''
    );

$gradeFilter =
    (int) (
        $_GET['grade'] ?? 0
    );

$sectionFilter =
    safeString(
        $_GET['section'] ?? ''
    );

$page =
    max(
        1,
        (int) (
            $_GET['page'] ?? 1
        )
    );

$perPage = 20;

/*
|--------------------------------------------------------------------------
| Grade Options
|--------------------------------------------------------------------------
*/

$grades = [];

$stmt = $conn->prepare("
    SELECT DISTINCT
        g.id,
        g.grade_number,
        g.name
    FROM student_registrations sr

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN library_borrowings lbr
        ON lbr.student_registration_id = sr.id

    WHERE sr.academic_year_id = ?
      AND lbr.status = 'Overdue'

    ORDER BY
        g.grade_number ASC
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $grades[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Section Options
|--------------------------------------------------------------------------
*/

$sections = [];

$stmt = $conn->prepare("
    SELECT DISTINCT
        sec.id,
        sec.name,
        sec.code
    FROM student_registrations sr

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_borrowings lbr
        ON lbr.student_registration_id = sr.id

    WHERE sr.academic_year_id = ?
      AND lbr.status = 'Overdue'

    ORDER BY
        sec.name ASC
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $sections[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalOverdue = 0;
$totalOverdueStudents = 0;
$totalOverdueBooks = 0;
$longestOverdue = 0;

/*
|--------------------------------------------------------------------------
| Total Overdue
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
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

    if ($row = $result->fetch_assoc()) {

        $totalOverdue =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Unique Overdue Students
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT student_id) AS total
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

    if ($row = $result->fetch_assoc()) {

        $totalOverdueStudents =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Unique Overdue Books
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT book_id) AS total
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

    if ($row = $result->fetch_assoc()) {

        $totalOverdueBooks =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Longest Overdue
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        MAX(
            DATEDIFF(
                CURDATE(),
                due_date
            )
        ) AS total
    FROM library_borrowings
    WHERE academic_year_id = ?
      AND status = 'Overdue'
      AND due_date IS NOT NULL
");

if ($stmt) {

    $stmt->bind_param(
        'i',
        $academicYearId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $longestOverdue =
            max(
                0,
                (int) (
                    $row['total'] ?? 0
                )
            );
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Main Conditions
|--------------------------------------------------------------------------
*/

$where = [
    "lbr.status = 'Overdue'",
    "s.is_deleted = 0",
    "lb.is_deleted = 0",
    "sr.academic_year_id = ?"
];

$params = [
    $academicYearId
];

$types = 'i';

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "
        (
            s.full_name LIKE ?
            OR s.student_code LIKE ?
            OR lb.title LIKE ?
        )
    ";

    $searchLike =
        '%' . $search . '%';

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;

    $types .= 'sss';
}

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($gradeFilter > 0) {

    $where[] =
        'sr.grade_id = ?';

    $params[] =
        $gradeFilter;

    $types .= 'i';
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($sectionFilter !== '') {

    $sectionId =
        (int) $sectionFilter;

    if ($sectionId > 0) {

        $where[] =
            'sr.section_id = ?';

        $params[] =
            $sectionId;

        $types .= 'i';
    }
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/*
|--------------------------------------------------------------------------
| Count Filtered Records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total

    FROM library_borrowings lbr

    INNER JOIN students s
        ON s.id = lbr.student_id

    INNER JOIN student_registrations sr
        ON sr.id = lbr.student_registration_id

    INNER JOIN library_books lb
        ON lb.id = lbr.book_id

    WHERE {$whereSql}
";

$totalRecords = 0;

$stmt =
    $conn->prepare(
        $countSql
    );

if ($stmt) {

    $bindParams = [
        $types
    ];

    foreach ($params as $key => $value) {

        $bindParams[] =
            &$params[$key];
    }

    call_user_func_array(
        [
            $stmt,
            'bind_param'
        ],
        $bindParams
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

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages =
    max(
        1,
        (int) ceil(
            $totalRecords / $perPage
        )
    );

if ($page > $totalPages) {

    $page =
        $totalPages;
}

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Main Overdue Records
|--------------------------------------------------------------------------
*/

$overdueBooks = [];

$sql = "
    SELECT

        lbr.id,
        lbr.student_id,
        lbr.student_registration_id,
        lbr.book_id,
        lbr.borrow_date,
        lbr.borrow_time,
        lbr.due_date,
        lbr.return_date,
        lbr.return_time,
        lbr.status,
        lbr.note,

        s.full_name AS student_name,
        s.student_code,

        g.grade_number,
        g.name AS grade_name,

        sec.name AS section_name,
        sec.code AS section_code,

        lb.title AS book_title,
        lb.available_quantity,
        lb.total_quantity,

        DATEDIFF(
            CURDATE(),
            lbr.due_date
        ) AS overdue_days

    FROM library_borrowings lbr

    INNER JOIN students s
        ON s.id = lbr.student_id

    INNER JOIN student_registrations sr
        ON sr.id = lbr.student_registration_id

    INNER JOIN grades g
        ON g.id = sr.grade_id

    INNER JOIN sections sec
        ON sec.id = sr.section_id

    INNER JOIN library_books lb
        ON lb.id = lbr.book_id

    WHERE {$whereSql}

    ORDER BY
        overdue_days DESC,
        lbr.due_date ASC,
        lbr.borrow_time ASC

    LIMIT ? OFFSET ?
";

$params[] =
    $perPage;

$params[] =
    $offset;

$types .= 'ii';

$stmt =
    $conn->prepare($sql);

if ($stmt) {

    $bindParams = [
        $types
    ];

    foreach ($params as $key => $value) {

        $bindParams[] =
            &$params[$key];
    }

    call_user_func_array(
        [
            $stmt,
            'bind_param'
        ],
        $bindParams
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $overdueBooks[] =
            $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function pageUrl(
    int $pageNumber,
    string $search,
    int $gradeFilter,
    string $sectionFilter
): string {

    $query = [
        'page' => $pageNumber
    ];

    if ($search !== '') {

        $query['search'] =
            $search;
    }

    if ($gradeFilter > 0) {

        $query['grade'] =
            $gradeFilter;
    }

    if ($sectionFilter !== '') {

        $query['section'] =
            $sectionFilter;
    }

    return 'overdue-books.php?' .
        http_build_query($query);
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
        Overdue Books | BKHS Library
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
            --border: #e5e7eb;
            --muted: #6b7280;
            --bg: #f8fafc;
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
            color: #111827;
            font-family: 'Inter', sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1040;
            overflow-y: auto;
        }

        .brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 10px;
            background: #fff;
            padding: 4px;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 18px 14px 8px;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d1d5db;
            padding: 11px 14px;
            margin: 2px 10px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            transition: background .2s ease, color .2s ease;
        }

        .nav-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .nav-link.active {
            color: #fff;
            background: var(--primary);
        }

        .nav-link i {
            width: 20px;
            font-size: 17px;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 800;
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
            gap: 11px;
        }

        .profile-text {
            text-align: right;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 700;
        }

        .profile-role {
            font-size: 11px;
            color: var(--muted);
        }

        .profile-photo {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .content {
            padding: 28px;
        }

        .year-banner {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            color: #1e40af;
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .year-banner strong {
            font-size: 13px;
        }

        .year-date {
            font-size: 12px;
            font-weight: 600;
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fee2e2;
            color: var(--danger);
            font-size: 20px;
        }

        .stat-number {
            font-size: 25px;
            font-weight: 800;
            margin-top: 13px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .control-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            margin-top: 22px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
        }

        .form-control,
        .form-select {
            min-height: 42px;
            border-color: #d1d5db;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.10);
        }

        .btn {
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
        }

        .table-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-top: 22px;
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .table-title {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
        }

        .table-subtitle {
            font-size: 11px;
            color: var(--muted);
            margin-top: 3px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            min-width: 1120px;
        }

        .table > :not(caption) > * > * {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom-color: #eef0f3;
        }

        .table thead th {
            background: #f8fafc;
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        .student-name {
            font-size: 13px;
            font-weight: 700;
        }

        .student-code {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .book-title {
            font-size: 13px;
            font-weight: 700;
            max-width: 230px;
        }

        .small-info {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .overdue-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            color: #b91c1c;
            background: #fee2e2;
        }

        .overdue-days {
            color: var(--danger);
            font-size: 12px;
            font-weight: 800;
        }

        .pagination-wrap {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination .page-link {
            font-size: 12px;
            border-radius: 7px;
            margin: 0 2px;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 42px;
            margin-bottom: 12px;
            color: #9ca3af;
        }

        .empty-title {
            color: #374151;
            font-size: 14px;
            font-weight: 700;
        }

        .empty-text {
            font-size: 12px;
        }

        .mobile-overlay {
            display: none;
        }

        .mobile-bottom-nav {
            display: none;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-overlay.show {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,.45);
                z-index: 1030;
            }

            .content {
                padding: 20px;
            }
        }

        @media (max-width: 767.98px) {

            .topbar {
                height: 70px;
                padding: 0 15px;
            }

            .content {
                padding: 15px;
                padding-bottom: 85px;
            }

            .profile-text {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .page-subtitle {
                display: none;
            }

            .year-banner {
                align-items: flex-start;
                flex-direction: column;
            }

            .stat-card {
                padding: 15px;
            }

            .control-card {
                padding: 15px;
            }

            .table-header {
                padding: 15px;
                align-items: flex-start;
                flex-direction: column;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                bottom: 0;
                left: 0;
                right: 0;
                height: 66px;
                background: #fff;
                border-top: 1px solid var(--border);
                z-index: 1020;
                justify-content: space-around;
                align-items: center;
            }

            .mobile-bottom-nav a {
                text-decoration: none;
                color: #6b7280;
                font-size: 10px;
                font-weight: 700;
                text-align: center;
            }

            .mobile-bottom-nav i {
                display: block;
                font-size: 20px;
                margin-bottom: 2px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }
        }

    </style>

</head>

<body>

<div
    class="mobile-overlay"
    id="mobileOverlay"
    onclick="closeSidebar()"
></div>

<!-- Sidebar -->
<aside
    class="sidebar"
    id="sidebar"
>

    <div class="brand">

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

    <a
        href="dashboard.php"
        class="nav-link"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="books.php"
        class="nav-link"
    >
        <i class="bi bi-book-fill"></i>
        <span>Books</span>
    </a>

    <a
        href="categories.php"
        class="nav-link"
    >
        <i class="bi bi-tags-fill"></i>
        <span>Categories</span>
    </a>

    <div class="sidebar-section">
        Circulation
    </div>

    <a
        href="study-attendance.php"
        class="nav-link"
    >
        <i class="bi bi-person-check-fill"></i>
        <span>Study Attendance</span>
    </a>

    <a
        href="borrow-book.php"
        class="nav-link"
    >
        <i class="bi bi-journal-arrow-up"></i>
        <span>Borrow Book</span>
    </a>

    <a
        href="return-book.php"
        class="nav-link"
    >
        <i class="bi bi-journal-arrow-down"></i>
        <span>Return Book</span>
    </a>

    <a
        href="borrowing-control.php"
        class="nav-link"
    >
        <i class="bi bi-arrow-left-right"></i>
        <span>Borrowing Control</span>
    </a>

    <a
        href="overdue-books.php"
        class="nav-link active"
    >
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>Overdue Books</span>
    </a>

    <div class="sidebar-section">
        Management
    </div>

    <a
        href="reservations.php"
        class="nav-link"
    >
        <i class="bi bi-bookmark-fill"></i>
        <span>Reservations</span>
    </a>

    <a
        href="fines.php"
        class="nav-link"
    >
        <i class="bi bi-cash-stack"></i>
        <span>Fines</span>
    </a>

    <a
        href="reports.php"
        class="nav-link"
    >
        <i class="bi bi-bar-chart-fill"></i>
        <span>Reports</span>
    </a>

    <div class="sidebar-section">
        Account
    </div>

    <a
        href="profile.php"
        class="nav-link"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../auth/logout.php"
        class="nav-link"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</aside>

<!-- Main -->
<main class="main">

    <!-- Topbar -->
    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="btn btn-light d-lg-none"
                onclick="openSidebar()"
            >
                <i class="bi bi-list fs-5"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Overdue Books
                </h1>

                <div class="page-subtitle">
                    Monitor books that have passed their due date
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
                class="profile-photo"
            >

        </div>

    </header>

    <div class="content">

        <!-- Active Academic Year -->
        <div class="year-banner">

            <div>

                <i class="bi bi-calendar3 me-2"></i>

                <strong>
                    Active Academic Year:
                    <?= e($academicYearName) ?>
                </strong>

            </div>

            <div class="year-date">

                Today:
                <?= e($todayEthiopianFormatted) ?>

            </div>

        </div>

        <?php if ($flashMessage !== ''): ?>

            <div
                class="alert alert-<?= e($flashType ?: 'info') ?> alert-dismissible fade show"
                role="alert"
            >

                <?= e($flashMessage) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Statistics -->
        <div class="row g-3">

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalOverdue) ?>
                    </div>

                    <div class="stat-label">
                        Overdue Records
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div
                        class="stat-icon"
                        style="background:#fff7ed;color:#ea580c;"
                    >
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalOverdueStudents) ?>
                    </div>

                    <div class="stat-label">
                        Students
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div
                        class="stat-icon"
                        style="background:#fef3c7;color:#d97706;"
                    >
                        <i class="bi bi-book-fill"></i>
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalOverdueBooks) ?>
                    </div>

                    <div class="stat-label">
                        Books
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div
                        class="stat-icon"
                        style="background:#f3e8ff;color:#9333ea;"
                    >
                        <i class="bi bi-clock-history"></i>
                    </div>

                    <div class="stat-number">
                        <?= number_format($longestOverdue) ?>
                    </div>

                    <div class="stat-label">
                        Longest Overdue Days
                    </div>

                </div>

            </div>

        </div>

        <!-- Filters -->
        <div class="control-card">

            <form
                method="GET"
                action="overdue-books.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-12 col-lg-5">

                        <label class="form-label">
                            Search
                        </label>

                        <div class="input-group">

                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Student name, code or book title..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-6 col-lg-2">

                        <label class="form-label">
                            Grade
                        </label>

                        <select
                            name="grade"
                            class="form-select"
                        >

                            <option value="0">
                                All Grades
                            </option>

                            <?php foreach ($grades as $grade): ?>

                                <option
                                    value="<?= (int) $grade['id'] ?>"
                                    <?= $gradeFilter === (int) $grade['id'] ? 'selected' : '' ?>
                                >
                                    Grade
                                    <?= (int) $grade['grade_number'] ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-6 col-lg-2">

                        <label class="form-label">
                            Section
                        </label>

                        <select
                            name="section"
                            class="form-select"
                        >

                            <option value="">
                                All Sections
                            </option>

                            <?php foreach ($sections as $section): ?>

                                <option
                                    value="<?= (int) $section['id'] ?>"
                                    <?= $sectionFilter === (string) $section['id'] ? 'selected' : '' ?>
                                >

                                    <?= e(
                                        $section['name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $section['code']
                                        )
                                    ): ?>

                                        (
                                        <?= e(
                                            $section['code']
                                        ) ?>
                                        )

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-6 col-lg-3">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >

                                <i class="bi bi-funnel me-1"></i>

                                Filter

                            </button>

                            <a
                                href="overdue-books.php"
                                class="btn btn-light border"
                                title="Clear filters"
                            >

                                <i class="bi bi-arrow-counterclockwise"></i>

                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <!-- Overdue Table -->
        <div class="table-card">

            <div class="table-header">

                <div>

                    <h2 class="table-title">

                        Overdue Books

                    </h2>

                    <div class="table-subtitle">

                        <?= number_format($totalRecords) ?>

                        overdue record<?= $totalRecords === 1 ? '' : 's' ?>

                    </div>

                </div>

                <a
                    href="return-book.php"
                    class="btn btn-success"
                >

                    <i class="bi bi-journal-arrow-down me-1"></i>

                    Return Book

                </a>

            </div>

            <?php if (empty($overdueBooks)): ?>

                <div class="empty-state">

                    <i class="bi bi-check-circle"></i>

                    <div class="empty-title">
                        No overdue books
                    </div>

                    <div class="empty-text">
                        There are currently no overdue books for the active academic year.
                    </div>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table mb-0">

                        <thead>

                            <tr>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Grade / Section
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
                                    Overdue
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-end">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($overdueBooks as $book): ?>

                            <?php

                            $overdueDays =
                                max(
                                    0,
                                    (int) (
                                        $book['overdue_days']
                                        ?? 0
                                    )
                                );

                            ?>

                            <tr>

                                <!-- Student -->
                                <td>

                                    <div class="student-name">

                                        <?= e(
                                            $book['student_name']
                                        ) ?>

                                    </div>

                                    <div class="student-code">

                                        <?= e(
                                            $book['student_code']
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Grade / Section -->
                                <td>

                                    <div class="fw-semibold small">

                                        Grade
                                        <?= (int) $book['grade_number'] ?>

                                    </div>

                                    <div class="small-info">

                                        Section
                                        <?= e(
                                            $book['section_name']
                                        ) ?>

                                        <?php if (
                                            !empty(
                                                $book['section_code']
                                            )
                                        ): ?>

                                            ·
                                            <?= e(
                                                $book['section_code']
                                            ) ?>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <!-- Book -->
                                <td>

                                    <div class="book-title">

                                        <?= e(
                                            $book['book_title']
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Borrow Date -->
                                <td>

                                    <div class="fw-semibold small">

                                        <?= e(
                                            formatEthiopianDate(
                                                $book['borrow_date']
                                            )
                                        ) ?>

                                    </div>

                                    <div class="small-info">

                                        <?= e(
                                            formatTime(
                                                $book['borrow_time']
                                            )
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Due Date -->
                                <td>

                                    <div class="fw-semibold small text-danger">

                                        <?= e(
                                            formatEthiopianDate(
                                                $book['due_date']
                                            )
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Overdue -->
                                <td>

                                    <div class="overdue-days">

                                        <i class="bi bi-clock-history me-1"></i>

                                        <?= number_format(
                                            $overdueDays
                                        ) ?>

                                        day<?= $overdueDays === 1 ? '' : 's' ?>

                                    </div>

                                </td>

                                <!-- Status -->
                                <td>

                                    <span class="overdue-badge">

                                        <i class="bi bi-exclamation-triangle-fill"></i>

                                        Overdue

                                    </span>

                                </td>

                                <!-- Action -->
                                <td class="text-end">

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-success"
                                        data-bs-toggle="modal"
                                        data-bs-target="#returnModal<?= (int) $book['id'] ?>"
                                    >

                                        <i class="bi bi-arrow-return-left me-1"></i>

                                        Return

                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>

                    <div class="pagination-wrap">

                        <div class="pagination-info">

                            Showing

                            <strong>
                                <?= number_format(
                                    $offset + 1
                                ) ?>
                            </strong>

                            to

                            <strong>
                                <?= number_format(
                                    min(
                                        $offset + $perPage,
                                        $totalRecords
                                    )
                                ) ?>
                            </strong>

                            of

                            <strong>
                                <?= number_format(
                                    $totalRecords
                                ) ?>
                            </strong>

                        </div>

                        <nav>

                            <ul class="pagination mb-0">

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                max(
                                                    1,
                                                    $page - 1
                                                ),
                                                $search,
                                                $gradeFilter,
                                                $sectionFilter
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi bi-chevron-left"></i>

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

                                for (
                                    $p = $startPage;
                                    $p <= $endPage;
                                    $p++
                                ):

                                ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                pageUrl(
                                                    $p,
                                                    $search,
                                                    $gradeFilter,
                                                    $sectionFilter
                                                )
                                            ) ?>"
                                        >

                                            <?= $p ?>

                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                min(
                                                    $totalPages,
                                                    $page + 1
                                                ),
                                                $search,
                                                $gradeFilter,
                                                $sectionFilter
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

    </div>

</main>

<!-- Return Confirmation Modals -->
<?php foreach ($overdueBooks as $book): ?>

    <div
        class="modal fade"
        id="returnModal<?= (int) $book['id'] ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        <i
                            class="bi bi-arrow-return-left text-success me-2"
                        ></i>

                        Return Overdue Book

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <p class="mb-3">

                        Are you sure you want to record this book
                        as returned?

                    </p>

                    <div class="bg-light rounded-3 p-3">

                        <div class="mb-2">

                            <small class="text-muted d-block">
                                Student
                            </small>

                            <strong>
                                <?= e(
                                    $book['student_name']
                                ) ?>
                            </strong>

                        </div>

                        <div class="mb-2">

                            <small class="text-muted d-block">
                                Book
                            </small>

                            <strong>
                                <?= e(
                                    $book['book_title']
                                ) ?>
                            </strong>

                        </div>

                        <div class="mb-2">

                            <small class="text-muted d-block">
                                Grade / Section
                            </small>

                            <strong>

                                Grade
                                <?= (int) $book['grade_number'] ?>

                                /

                                <?= e(
                                    $book['section_name']
                                ) ?>

                            </strong>

                        </div>

                        <div class="mb-2">

                            <small class="text-muted d-block">
                                Overdue
                            </small>

                            <strong class="text-danger">

                                <?= number_format(
                                    max(
                                        0,
                                        (int) (
                                            $book['overdue_days']
                                            ?? 0
                                        )
                                    )
                                ) ?>

                                day<?= (
                                    (int) (
                                        $book['overdue_days']
                                        ?? 0
                                    ) === 1
                                ) ? '' : 's' ?>

                            </strong>

                        </div>

                        <div>

                            <small class="text-muted d-block">
                                Return Date
                            </small>

                            <strong>
                                <?= e(
                                    $todayEthiopianFormatted
                                ) ?>
                            </strong>

                        </div>

                    </div>

                    <div class="alert alert-info mt-3 mb-0 small">

                        <i class="bi bi-info-circle me-1"></i>

                        The return time will be recorded automatically
                        and the available book quantity will be increased.

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

                    <form
                        method="POST"
                        action="overdue-books.php"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="return_book"
                        >

                        <input
                            type="hidden"
                            name="borrowing_id"
                            value="<?= (int) $book['id'] ?>"
                        >

                        <button
                            type="submit"
                            class="btn btn-success"
                        >

                            <i class="bi bi-check-lg me-1"></i>

                            Confirm Return

                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

<?php endforeach; ?>

<!-- Mobile Bottom Navigation -->
<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2-fill"></i>

        Dashboard

    </a>

    <a href="books.php">

        <i class="bi bi-book-fill"></i>

        Books

    </a>

    <a
        href="overdue-books.php"
        class="active"
    >

        <i class="bi bi-exclamation-triangle-fill"></i>

        Overdue

    </a>

    <a href="return-book.php">

        <i class="bi bi-journal-arrow-down"></i>

        Return

    </a>

    <a href="profile.php">

        <i class="bi bi-person-circle"></i>

        Profile

    </a>

</nav>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    function openSidebar() {

        document
            .getElementById('sidebar')
            .classList.add('show');

        document
            .getElementById('mobileOverlay')
            .classList.add('show');
    }

    function closeSidebar() {

        document
            .getElementById('sidebar')
            .classList.remove('show');

        document
            .getElementById('mobileOverlay')
            .classList.remove('show');
    }

    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth >= 992) {
                closeSidebar();
            }

        }
    );

</script>

</body>

</html>