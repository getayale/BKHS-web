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
    if ($value === null || !is_scalar($value)) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function safeString(mixed $value): string
{
    return is_scalar($value)
        ? trim((string) $value)
        : '';
}

function redirectWithMessage(
    string $type,
    string $message,
    array $extra = []
): never {
    $params = array_merge(
        [
            'message_type' => $type,
            'message' => $message
        ],
        $extra
    );

    header(
        'Location: study-attendance.php?' .
        http_build_query($params)
    );

    exit;
}

function jsonResponse(
    array $data,
    int $statusCode = 200
): never {
    http_response_code($statusCode);

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
| Database Check
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Librarian Information
|--------------------------------------------------------------------------
*/

$librarianId = (int) ($_SESSION['user_id'] ?? 0);

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
            safeString(
                $row['full_name'] ?? ''
            ) ?: 'Librarian';

        $librarianEmail =
            safeString(
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

    $photoPath =
        ltrim($photoPath, '/');

    if (
        str_starts_with($photoPath, 'public/') &&
        !str_contains($photoPath, '..')
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

    $result =
        $stmt->get_result();

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
| AJAX SEARCH ENDPOINTS
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Books and students are NOT loaded into the page.
|
| The browser asks the server for matching records while typing.
|
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        safeString($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | SEARCH STUDENTS
    |--------------------------------------------------------------------------
    */

    if ($action === 'search_students') {

        $query =
            safeString($_POST['query'] ?? '');

        $gradeId =
            (int) ($_POST['grade_id'] ?? 0);

        $sectionId =
            (int) ($_POST['section_id'] ?? 0);

        if (mb_strlen($query) < 2) {

            jsonResponse([
                'success' => true,
                'students' => []
            ]);
        }

        $searchParam =
            '%' . $query . '%';

        $studentSql = "
            SELECT
                s.id,
                s.full_name,
                s.student_code,

                g.name AS grade_name,
                g.grade_number,

                sec.name AS section_name,
                sec.code AS section_code

            FROM student_registrations sr

            INNER JOIN students s
                ON s.id = sr.student_id

            INNER JOIN grades g
                ON g.id = sr.grade_id

            INNER JOIN sections sec
                ON sec.id = sr.section_id

            WHERE sr.academic_year_id = ?

              AND s.is_deleted = 0

              AND (
                    s.full_name LIKE ?
                    OR s.student_code LIKE ?
              )
        ";

        $studentTypes =
            'iss';

        $studentValues = [
            $academicYearId,
            $searchParam,
            $searchParam
        ];

        if ($gradeId > 0) {

            $studentSql .= "
                AND sr.grade_id = ?
            ";

            $studentTypes .= 'i';

            $studentValues[] =
                $gradeId;
        }

        if ($sectionId > 0) {

            $studentSql .= "
                AND sr.section_id = ?
            ";

            $studentTypes .= 'i';

            $studentValues[] =
                $sectionId;
        }

        $studentSql .= "
            ORDER BY
                g.grade_number ASC,
                sec.name ASC,
                s.full_name ASC

            LIMIT 20
        ";

        $stmt =
            $conn->prepare($studentSql);

        if (!$stmt) {

            jsonResponse([
                'success' => false,
                'message' => 'Unable to search students.'
            ], 500);
        }

        $stmt->bind_param(
            $studentTypes,
            ...$studentValues
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $students = [];

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $students[] = [
                'id' =>
                    (int) $row['id'],

                'full_name' =>
                    safeString(
                        $row['full_name'] ?? ''
                    ),

                'student_code' =>
                    safeString(
                        $row['student_code'] ?? ''
                    ),

                'grade_name' =>
                    safeString(
                        $row['grade_name'] ?? ''
                    ),

                'grade_number' =>
                    (int) (
                        $row['grade_number'] ?? 0
                    ),

                'section_name' =>
                    safeString(
                        $row['section_name'] ?? ''
                    ),

                'section_code' =>
                    safeString(
                        $row['section_code'] ?? ''
                    )
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
    | SEARCH BOOKS
    |--------------------------------------------------------------------------
    |
    | Only available books are searched.
    | Maximum 20 books are returned.
    |
    */

    if ($action === 'search_books') {

        $query =
            safeString($_POST['query'] ?? '');

        if (mb_strlen($query) < 2) {

            jsonResponse([
                'success' => true,
                'books' => []
            ]);
        }

        /*
        |----------------------------------------------------------------------
        | Prefix search
        |----------------------------------------------------------------------
        |
        | Example:
        |
        | "math"
        |
        | finds:
        |
        | Mathematics Grade 8
        | Mathematics Grade 7
        |
        | Prefix searching is much more suitable for a very large library
        | than loading all books into a browser dropdown.
        |
        */

        $searchParam =
            $query . '%';

        $stmt = $conn->prepare("
            SELECT
                id,
                title,
                isbn,
                available_quantity
            FROM library_books
            WHERE is_deleted = 0
              AND available_quantity > 0
              AND (
                    title LIKE ?
                    OR isbn LIKE ?
              )
            ORDER BY title ASC
            LIMIT 20
        ");

        if (!$stmt) {

            jsonResponse([
                'success' => false,
                'message' => 'Unable to search books.'
            ], 500);
        }

        $stmt->bind_param(
            'ss',
            $searchParam,
            $searchParam
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $books = [];

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $books[] = [
                'id' =>
                    (int) $row['id'],

                'title' =>
                    safeString(
                        $row['title'] ?? ''
                    ),

                'isbn' =>
                    safeString(
                        $row['isbn'] ?? ''
                    ),

                'available_quantity' =>
                    (int) (
                        $row['available_quantity'] ?? 0
                    )
            ];
        }

        $stmt->close();

        jsonResponse([
            'success' => true,
            'books' => $books
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Current Date
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message =
    safeString(
        $_GET['message'] ?? ''
    );

$messageType =
    safeString(
        $_GET['message_type'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$gradeFilter =
    isset($_GET['grade_id'])
        ? (int) $_GET['grade_id']
        : 0;

$sectionFilter =
    isset($_GET['section_id'])
        ? (int) $_GET['section_id']
        : 0;

$search =
    safeString(
        $_GET['search'] ?? ''
    );

$page =
    isset($_GET['page'])
        ? max(
            1,
            (int) $_GET['page']
        )
        : 1;

$perPage = 20;

/*
|--------------------------------------------------------------------------
| Grade List
|--------------------------------------------------------------------------
*/

$grades = [];

$result = $conn->query("
    SELECT
        id,
        name,
        grade_number
    FROM grades
    ORDER BY grade_number ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Section List
|--------------------------------------------------------------------------
*/

$sections = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM sections
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        safeString(
            $_POST['action'] ?? ''
        );

    /*
    |--------------------------------------------------------------------------
    | TAKE BOOK
    |--------------------------------------------------------------------------
    */

    if ($action === 'take_book') {

        $studentId =
            (int) (
                $_POST['student_id'] ?? 0
            );

        $bookId =
            (int) (
                $_POST['book_id'] ?? 0
            );

        if ($studentId <= 0) {

            redirectWithMessage(
                'danger',
                'Please select a student.'
            );
        }

        if ($bookId <= 0) {

            redirectWithMessage(
                'danger',
                'Please select a book.'
            );
        }

        $conn->begin_transaction();

        try {

            /*
            |----------------------------------------------------------------------
            | Get Student Current Registration
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    sr.id AS registration_id,
                    sr.student_id,
                    sr.academic_year_id,
                    sr.grade_id,
                    sr.section_id,
                    s.full_name,
                    s.student_code

                FROM student_registrations sr

                INNER JOIN students s
                    ON s.id = sr.student_id

                WHERE sr.student_id = ?
                  AND sr.academic_year_id = ?
                  AND s.is_deleted = 0

                ORDER BY sr.id DESC

                LIMIT 1

                FOR UPDATE
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare student registration query.'
                );
            }

            $stmt->bind_param(
                'ii',
                $studentId,
                $academicYearId
            );

            $stmt->execute();

            $registrationResult =
                $stmt->get_result();

            $registration =
                $registrationResult->fetch_assoc();

            $stmt->close();

            if (!$registration) {

                throw new RuntimeException(
                    'The selected student is not registered for the active academic year.'
                );
            }

            $registrationId =
                (int) $registration['registration_id'];

            $studentGradeId =
                (int) $registration['grade_id'];

            $studentSectionId =
                (int) $registration['section_id'];

            /*
            |----------------------------------------------------------------------
            | Check Existing Active Book
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    book_id
                FROM library_study_attendance

                WHERE student_registration_id = ?
                  AND status = 'Reading'

                LIMIT 1

                FOR UPDATE
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to check the student library status.'
                );
            }

            $stmt->bind_param(
                'i',
                $registrationId
            );

            $stmt->execute();

            $activeResult =
                $stmt->get_result();

            $activeReading =
                $activeResult->fetch_assoc();

            $stmt->close();

            if ($activeReading) {

                throw new RuntimeException(
                    'This student already has a book marked as Reading. Return that book before taking another one.'
                );
            }

            /*
            |----------------------------------------------------------------------
            | Get Book
            |----------------------------------------------------------------------
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
                    'Unable to prepare book query.'
                );
            }

            $stmt->bind_param(
                'i',
                $bookId
            );

            $stmt->execute();

            $bookResult =
                $stmt->get_result();

            $book =
                $bookResult->fetch_assoc();

            $stmt->close();

            if (!$book) {

                throw new RuntimeException(
                    'The selected book was not found.'
                );
            }

            $bookTitle =
                safeString(
                    $book['title'] ?? ''
                );

            $availableQuantity =
                (int) (
                    $book['available_quantity'] ?? 0
                );

            if ($availableQuantity <= 0) {

                throw new RuntimeException(
                    'This book is currently not available.'
                );
            }

            /*
            |----------------------------------------------------------------------
            | Insert Reading Record
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO library_study_attendance (
                    student_id,
                    student_registration_id,
                    academic_year_id,
                    grade_id,
                    section_id,
                    book_id,
                    attendance_date,
                    take_time,
                    return_time,
                    status,
                    note,
                    recorded_by
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    CURDATE(),
                    NOW(),
                    NULL,
                    'Reading',
                    NULL,
                    ?
                )
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare library record query.'
                );
            }

            $stmt->bind_param(
                'iiiiiii',
                $studentId,
                $registrationId,
                $academicYearId,
                $studentGradeId,
                $studentSectionId,
                $bookId,
                $librarianId
            );

            if (!$stmt->execute()) {

                throw new RuntimeException(
                    'Unable to record the book take.'
                );
            }

            $stmt->close();

            /*
            |----------------------------------------------------------------------
            | Reduce Available Quantity
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE library_books

                SET available_quantity =
                    available_quantity - 1

                WHERE id = ?
                  AND is_deleted = 0
                  AND available_quantity > 0

                LIMIT 1
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to update book quantity.'
                );
            }

            $stmt->bind_param(
                'i',
                $bookId
            );

            if (
                !$stmt->execute() ||
                $stmt->affected_rows !== 1
            ) {

                throw new RuntimeException(
                    'Unable to update the available book quantity.'
                );
            }

            $stmt->close();

            $conn->commit();

            redirectWithMessage(
                'success',
                $bookTitle .
                ' was given to ' .
                safeString(
                    $registration['full_name'] ??
                    'the student'
                ) .
                '. Take time was recorded automatically.'
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
    | RETURN BOOK
    |--------------------------------------------------------------------------
    */

    if ($action === 'return_book') {

        $attendanceId =
            (int) (
                $_POST['attendance_id'] ?? 0
            );

        if ($attendanceId <= 0) {

            redirectWithMessage(
                'danger',
                'Invalid library record.'
            );
        }

        $conn->begin_transaction();

        try {

            /*
            |----------------------------------------------------------------------
            | Get Active Reading Record
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    lsa.id,
                    lsa.book_id,
                    lsa.student_id,
                    lsa.status,
                    lb.title

                FROM library_study_attendance lsa

                INNER JOIN library_books lb
                    ON lb.id = lsa.book_id

                WHERE lsa.id = ?
                  AND lsa.status = 'Reading'

                LIMIT 1

                FOR UPDATE
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare return query.'
                );
            }

            $stmt->bind_param(
                'i',
                $attendanceId
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $readingRecord =
                $result->fetch_assoc();

            $stmt->close();

            if (!$readingRecord) {

                throw new RuntimeException(
                    'This book has already been returned or the record does not exist.'
                );
            }

            $returnBookId =
                (int) $readingRecord['book_id'];

            $returnBookTitle =
                safeString(
                    $readingRecord['title'] ?? ''
                );

            /*
            |----------------------------------------------------------------------
            | Mark As Returned
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE library_study_attendance

                SET
                    return_time = NOW(),
                    status = 'Returned'

                WHERE id = ?
                  AND status = 'Reading'

                LIMIT 1
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare return update.'
                );
            }

            $stmt->bind_param(
                'i',
                $attendanceId
            );

            if (
                !$stmt->execute() ||
                $stmt->affected_rows !== 1
            ) {

                throw new RuntimeException(
                    'Unable to record the return time.'
                );
            }

            $stmt->close();

            /*
            |----------------------------------------------------------------------
            | Increase Available Quantity
            |----------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE library_books

                SET
                    available_quantity =
                        LEAST(
                            total_quantity,
                            available_quantity + 1
                        )

                WHERE id = ?
                  AND is_deleted = 0

                LIMIT 1
            ");

            if (!$stmt) {

                throw new RuntimeException(
                    'Unable to prepare book quantity update.'
                );
            }

            $stmt->bind_param(
                'i',
                $returnBookId
            );

            if (!$stmt->execute()) {

                throw new RuntimeException(
                    'Unable to update the book quantity.'
                );
            }

            $stmt->close();

            $conn->commit();

            redirectWithMessage(
                'success',
                $returnBookTitle .
                ' was returned successfully. Return time was recorded automatically.'
            );

        } catch (Throwable $exception) {

            $conn->rollback();

            redirectWithMessage(
                'danger',
                $exception->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Base Filter Conditions
|--------------------------------------------------------------------------
*/

$whereParts = [
    "lsa.academic_year_id = ?"
];

$bindTypes = 'i';

$bindValues = [
    $academicYearId
];

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($gradeFilter > 0) {

    $whereParts[] =
        "lsa.grade_id = ?";

    $bindTypes .= 'i';

    $bindValues[] =
        $gradeFilter;
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($sectionFilter > 0) {

    $whereParts[] =
        "lsa.section_id = ?";

    $bindTypes .= 'i';

    $bindValues[] =
        $sectionFilter;
}

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $whereParts[] = "
        (
            s.full_name LIKE ?
            OR s.student_code LIKE ?
            OR lb.title LIKE ?
        )
    ";

    $searchParam =
        '%' . $search . '%';

    $bindTypes .= 'sss';

    $bindValues[] =
        $searchParam;

    $bindValues[] =
        $searchParam;

    $bindValues[] =
        $searchParam;
}

/*
|--------------------------------------------------------------------------
| Count Records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT
        COUNT(*) AS total

    FROM library_study_attendance lsa

    INNER JOIN students s
        ON s.id = lsa.student_id

    INNER JOIN library_books lb
        ON lb.id = lsa.book_id

    WHERE " .
    implode(
        ' AND ',
        $whereParts
    );

$stmt =
    $conn->prepare($countSql);

$totalRecords = 0;

if ($stmt) {

    $stmt->bind_param(
        $bindTypes,
        ...$bindValues
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
            $totalRecords /
            $perPage
        )
    );

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Library Records
|--------------------------------------------------------------------------
*/

$records = [];

$listSql = "
    SELECT
        lsa.id,
        lsa.student_id,
        lsa.student_registration_id,
        lsa.grade_id,
        lsa.section_id,
        lsa.book_id,
        lsa.attendance_date,
        lsa.take_time,
        lsa.return_time,
        lsa.status,
        lsa.note,

        s.full_name AS student_name,
        s.student_code,

        g.name AS grade_name,
        sec.name AS section_name,

        lb.title AS book_title

    FROM library_study_attendance lsa

    INNER JOIN students s
        ON s.id = lsa.student_id

    LEFT JOIN grades g
        ON g.id = lsa.grade_id

    LEFT JOIN sections sec
        ON sec.id = lsa.section_id

    INNER JOIN library_books lb
        ON lb.id = lsa.book_id

    WHERE " .
    implode(
        ' AND ',
        $whereParts
    ) . "

    ORDER BY
        lsa.attendance_date DESC,
        lsa.take_time DESC,
        lsa.id DESC

    LIMIT ? OFFSET ?
";

$listTypes =
    $bindTypes . 'ii';

$listValues =
    $bindValues;

$listValues[] =
    $perPage;

$listValues[] =
    $offset;

$stmt =
    $conn->prepare($listSql);

if ($stmt) {

    $stmt->bind_param(
        $listTypes,
        ...$listValues
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {
        $records[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Today's Visits
|--------------------------------------------------------------------------
*/

$todayVisits = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total

    FROM library_study_attendance

    WHERE academic_year_id = ?
      AND attendance_date = CURDATE()
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

        $todayVisits =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Currently Reading
|--------------------------------------------------------------------------
*/

$currentReading = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total

    FROM library_study_attendance

    WHERE academic_year_id = ?
      AND status = 'Reading'
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

        $currentReading =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Returned Today
|--------------------------------------------------------------------------
*/

$returnedToday = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total

    FROM library_study_attendance

    WHERE academic_year_id = ?
      AND status = 'Returned'
      AND DATE(return_time) = CURDATE()
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

        $returnedToday =
            (int) $row['total'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Available Book Copies
|--------------------------------------------------------------------------
*/

$totalAvailableBooks = 0;

$result = $conn->query("
    SELECT
        COALESCE(
            SUM(available_quantity),
            0
        ) AS total

    FROM library_books

    WHERE is_deleted = 0
");

if ($result) {

    if ($row = $result->fetch_assoc()) {

        $totalAvailableBooks =
            (int) $row['total'];
    }

    $result->free();
}

/*
|--------------------------------------------------------------------------
| Page URL Helper
|--------------------------------------------------------------------------
*/

function pageUrl(
    int $pageNumber,
    int $gradeFilter,
    int $sectionFilter,
    string $search
): string {

    $params = [
        'page' => $pageNumber
    ];

    if ($gradeFilter > 0) {

        $params['grade_id'] =
            $gradeFilter;
    }

    if ($sectionFilter > 0) {

        $params['section_id'] =
            $sectionFilter;
    }

    if ($search !== '') {

        $params['search'] =
            $search;
    }

    return 'study-attendance.php?' .
        http_build_query($params);
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

    <title>Library Study Log | BKHS</title>

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
            --sidebar-muted: #94a3b8;
            --border: #e5e7eb;
            --bg: #f8fafc;
            --text: #111827;
            --muted: #64748b;
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
            color: var(--text);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 260px;
            background: var(--sidebar);
            color: white;
            z-index: 1050;
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

        .sidebar-brand img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 10px;
            background: white;
            padding: 3px;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -.2px;
        }

        .brand-subtitle {
            color: var(--sidebar-muted);
            font-size: 11px;
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 20px 14px 8px;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-nav {
            padding: 0 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar-nav a i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .sidebar-nav a:hover {
            background: var(--sidebar-hover);
            color: white;
        }

        .sidebar-nav a.active {
            background: var(--primary);
            color: white;
        }

        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .topbar-subtitle {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-mini img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
        }

        .profile-mini-name {
            font-size: 13px;
            font-weight: 700;
        }

        .profile-mini-role {
            color: var(--muted);
            font-size: 11px;
        }

        .content {
            padding: 28px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 22px;
        }

        .page-heading h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -.5px;
        }

        .page-heading p {
            color: var(--muted);
            margin: 5px 0 0;
            font-size: 13px;
        }

        .btn-primary-custom {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            border-radius: 9px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
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
            color: var(--muted);
            font-size: 12px;
            margin-top: 7px;
        }

        .card-custom {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 5px rgba(15,23,42,.025);
        }

        .filter-card {
            padding: 18px;
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            border-color: #dbe2ea;
            border-radius: 9px;
            font-size: 13px;
            min-height: 42px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.10);
        }

        .table-card {
            overflow: hidden;
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .table-title {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
        }

        .table-subtitle {
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            margin: 0;
            min-width: 950px;
        }

        .table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            border-bottom: 1px solid var(--border);
            padding: 13px 16px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
        }

        .student-name {
            font-weight: 700;
            color: #1e293b;
        }

        .student-code {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .book-title {
            font-weight: 700;
            color: #1e293b;
        }

        .badge-reading {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
            font-size: 10px;
            font-weight: 700;
            padding: 6px 9px;
            border-radius: 999px;
        }

        .badge-returned {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
            font-size: 10px;
            font-weight: 700;
            padding: 6px 9px;
            border-radius: 999px;
        }

        .time-text {
            font-size: 11px;
            font-weight: 600;
            color: #334155;
        }

        .date-text {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .return-btn {
            border: 0;
            background: #dcfce7;
            color: #15803d;
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 11px;
            font-weight: 700;
        }

        .return-btn:hover {
            background: #bbf7d0;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-icon {
            width: 55px;
            height: 55px;
            margin: 0 auto 14px;
            border-radius: 14px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .pagination-wrap {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 11px;
        }

        .pagination .page-link {
            font-size: 11px;
            color: #475569;
            border-color: #e2e8f0;
        }

        .pagination .active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .modal-content {
            border: 0;
            border-radius: 15px;
            overflow: visible;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            border-radius: 15px 15px 0 0;
        }

        .modal-title {
            font-size: 16px;
            font-weight: 800;
        }

        .modal-body {
            padding: 20px;
        }

        /*
        |--------------------------------------------------------------------------
        | Searchable Dropdown
        |--------------------------------------------------------------------------
        */

        .search-dropdown-wrapper {
            position: relative;
        }

        .search-input-wrapper {
            position: relative;
        }

        .search-input {
            padding-left: 40px;
            padding-right: 40px;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 5;
            pointer-events: none;
        }

        .search-clear-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #94a3b8;
            width: 30px;
            height: 30px;
            border-radius: 7px;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 6;
        }

        .search-clear-btn:hover {
            background: #f1f5f9;
            color: #475569;
        }

        .search-results {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            max-height: 280px;
            overflow-y: auto;
            background: white;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(15,23,42,.14);
            z-index: 1060;
            display: none;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            width: 100%;
            border: 0;
            border-bottom: 1px solid #f1f5f9;
            background: white;
            text-align: left;
            padding: 11px 13px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .search-result-item:last-child {
            border-bottom: 0;
        }

        .search-result-item:hover {
            background: #eff6ff;
        }

        .search-result-title {
            color: #1e293b;
            font-size: 12px;
            font-weight: 700;
        }

        .search-result-code {
            color: var(--primary);
            font-size: 10px;
            font-weight: 600;
            margin-top: 3px;
        }

        .search-result-meta {
            color: #64748b;
            font-size: 10px;
            margin-top: 3px;
        }

        .search-result-available {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 6px;
            padding: 3px 7px;
            background: #f0fdf4;
            color: #15803d;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
        }

        .search-no-result {
            padding: 18px 13px;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }

        .search-loading {
            padding: 18px 13px;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }

        .selected-search-item {
            display: none;
            margin-top: 9px;
            padding: 10px 12px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 9px;
        }

        .selected-search-item.show {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .selected-search-info {
            min-width: 0;
        }

        .selected-search-title {
            font-size: 12px;
            font-weight: 700;
            color: #166534;
        }

        .selected-search-details {
            font-size: 10px;
            color: #15803d;
            margin-top: 2px;
        }

        .selected-search-check {
            color: #16a34a;
            font-size: 18px;
            flex-shrink: 0;
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1040;
        }

        .mobile-bottom-nav {
            display: none;
        }

        .mobile-menu-btn {
            display: none;
            width: 38px;
            height: 38px;
            border: 1px solid var(--border);
            background: white;
            border-radius: 9px;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .mobile-overlay.show {
                display: block;
            }

            .main-wrapper {
                margin-left: 0;
            }

            .content {
                padding: 20px;
            }

            .mobile-menu-btn {
                display: inline-flex !important;
            }
        }

        @media (max-width: 767.98px) {

            body {
                padding-bottom: 68px;
            }

            .topbar {
                height: 68px;
                padding: 0 14px;
            }

            .topbar-title {
                font-size: 15px;
            }

            .topbar-subtitle {
                display: none;
            }

            .profile-mini-name,
            .profile-mini-role {
                display: none;
            }

            .profile-mini img {
                width: 36px;
                height: 36px;
            }

            .content {
                padding: 16px 13px 25px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
                margin-bottom: 18px;
            }

            .page-heading h1 {
                font-size: 20px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-value {
                font-size: 21px;
            }

            .filter-card {
                padding: 14px;
            }

            .table-header {
                padding: 15px;
                align-items: flex-start;
                flex-direction: column;
            }

            .table-header .btn {
                width: 100%;
            }

            .pagination-wrap {
                flex-direction: column;
                align-items: flex-start;
            }

            .mobile-bottom-nav {
                position: fixed;
                display: flex;
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: white;
                border-top: 1px solid var(--border);
                z-index: 1030;
                justify-content: space-around;
                align-items: center;
            }

            .mobile-bottom-nav a {
                color: #64748b;
                text-decoration: none;
                font-size: 9px;
                font-weight: 600;
                text-align: center;
            }

            .mobile-bottom-nav a i {
                display: block;
                font-size: 18px;
                margin-bottom: 2px;
            }

            .mobile-bottom-nav a.active {
                color: var(--primary);
            }
        }

    </style>

</head>

<body>

<!-- ==============================================================
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
                Library Management
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2"></i>
            <span>Dashboard</span>
        </a>

        <a href="books.php">
            <i class="bi bi-book"></i>
            <span>Books</span>
        </a>

        <a href="categories.php">
            <i class="bi bi-tags"></i>
            <span>Categories</span>
        </a>

        <a
            href="study-attendance.php"
            class="active"
        >
            <i class="bi bi-journal-bookmark"></i>
            <span>Study / Reading Log</span>
        </a>

        <a href="borrow-book.php">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Borrow Book</span>
        </a>

        <a href="return-book.php">
            <i class="bi bi-box-arrow-in-down"></i>
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

        <a href="reservations.php">
            <i class="bi bi-bookmark-star"></i>
            <span>Reservations</span>
        </a>

        <a href="fines.php">
            <i class="bi bi-cash-stack"></i>
            <span>Fines</span>
        </a>

        <a href="reports.php">
            <i class="bi bi-bar-chart"></i>
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
    class="mobile-overlay"
    id="mobileOverlay"
></div>

<!-- ==============================================================
     MAIN
================================================================ -->

<div class="main-wrapper">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h2 class="topbar-title">
                    Library Study / Reading Log
                </h2>

                <div class="topbar-subtitle">
                    <?= e($academicYearName) ?>
                </div>

            </div>

        </div>

        <div class="profile-mini">

            <div class="text-end">

                <div class="profile-mini-name">
                    <?= e($librarianName) ?>
                </div>

                <div class="profile-mini-role">
                    Librarian
                </div>

            </div>

            <img
                src="<?= e($photoUrl) ?>"
                alt="Librarian"
            >

        </div>

    </header>

    <!-- CONTENT -->

    <main class="content">

        <div class="page-heading">

            <div>

                <h1>
                    Study / Reading Log
                </h1>

                <p>
                    Record the book each student reads, take time,
                    and return time.
                </p>

            </div>

        </div>

        <!-- ======================================================
             MESSAGES
        ======================================================= -->

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= e($messageType ?: 'info') ?> alert-dismissible fade show"
                role="alert"
            >

                <i
                    class="bi
                    <?= $messageType === 'success'
                        ? 'bi-check-circle'
                        : 'bi-exclamation-circle'
                    ?>
                    me-2"
                ></i>

                <?= e($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- ======================================================
             STATISTICS
        ======================================================= -->

        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($todayVisits) ?>
                    </div>

                    <div class="stat-label">
                        Today's Library Visits
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-book-half"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($currentReading) ?>
                    </div>

                    <div class="stat-label">
                        Currently Reading
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($returnedToday) ?>
                    </div>

                    <div class="stat-label">
                        Returned Today
                    </div>

                </div>

            </div>

            <div class="col-6 col-xl-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-bookshelf"></i>
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalAvailableBooks) ?>
                    </div>

                    <div class="stat-label">
                        Available Book Copies
                    </div>

                </div>

            </div>

        </div>

        <!-- ======================================================
             FILTERS
        ======================================================= -->

        <div class="card-custom filter-card">

            <form
                method="GET"
                action="study-attendance.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-lg-3 col-md-6">

                        <label
                            for="grade_id"
                            class="form-label"
                        >
                            Grade
                        </label>

                        <select
                            name="grade_id"
                            id="grade_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Grades
                            </option>

                            <?php foreach ($grades as $grade): ?>

                                <option
                                    value="<?= (int) $grade['id'] ?>"
                                    <?= $gradeFilter === (int) $grade['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= e($grade['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-3 col-md-6">

                        <label
                            for="section_id"
                            class="form-label"
                        >
                            Section
                        </label>

                        <select
                            name="section_id"
                            id="section_id"
                            class="form-select"
                        >

                            <option value="0">
                                All Sections
                            </option>

                            <?php foreach ($sections as $section): ?>

                                <option
                                    value="<?= (int) $section['id'] ?>"
                                    <?= $sectionFilter === (int) $section['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= e($section['name']) ?>

                                    <?php if (!empty($section['code'])): ?>

                                        (
                                        <?= e($section['code']) ?>
                                        )

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-lg-4 col-md-6">

                        <label
                            for="search"
                            class="form-label"
                        >
                            Search
                        </label>

                        <input
                            type="text"
                            name="search"
                            id="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Student name, student code or book title..."
                        >

                    </div>

                    <div class="col-lg-2 col-md-6 d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary-custom flex-grow-1"
                        >

                            <i class="bi bi-search me-1"></i>

                            Filter

                        </button>

                        <a
                            href="study-attendance.php"
                            class="btn btn-light border"
                            title="Clear Filters"
                        >
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- ======================================================
             RECORDS TABLE
        ======================================================= -->

        <div class="card-custom table-card">

            <div class="table-header">

                <div>

                    <h3 class="table-title">
                        Library Reading Records
                    </h3>

                    <div class="table-subtitle">

                        <?= number_format($totalRecords) ?>

                        record<?= $totalRecords === 1 ? '' : 's' ?>

                        found

                    </div>

                </div>

                <button
                    type="button"
                    class="btn btn-primary-custom"
                    data-bs-toggle="modal"
                    data-bs-target="#takeBookModal"
                >

                    <i class="bi bi-plus-lg me-1"></i>

                    Record Book Take

                </button>

            </div>

            <?php if (!empty($records)): ?>

                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Grade / Section
                                </th>

                                <th>
                                    Book Title
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Take Time
                                </th>

                                <th>
                                    Return Time
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

                            <?php foreach ($records as $record): ?>

                                <tr>

                                    <td>

                                        <div class="student-name">
                                            <?= e($record['student_name']) ?>
                                        </div>

                                        <div class="student-code">
                                            <?= e($record['student_code']) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <div class="fw-semibold">
                                            <?= e(
                                                $record['grade_name'] ??
                                                'N/A'
                                            ) ?>
                                        </div>

                                        <div class="date-text">
                                            <?= e(
                                                $record['section_name'] ??
                                                'N/A'
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <div class="book-title">

                                            <i class="bi bi-book me-1 text-primary"></i>

                                            <?= e(
                                                $record['book_title']
                                            ) ?>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="time-text">
                                            <?= e(
                                                $record['attendance_date']
                                            ) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <?php

                                        $takeTimestamp =
                                            !empty($record['take_time'])
                                                ? strtotime(
                                                    (string)
                                                    $record['take_time']
                                                )
                                                : false;

                                        ?>

                                        <?php if ($takeTimestamp): ?>

                                            <div class="time-text">

                                                <?= e(
                                                    date(
                                                        'h:i A',
                                                        $takeTimestamp
                                                    )
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php

                                        $returnTimestamp =
                                            !empty($record['return_time'])
                                                ? strtotime(
                                                    (string)
                                                    $record['return_time']
                                                )
                                                : false;

                                        ?>

                                        <?php if ($returnTimestamp): ?>

                                            <div class="time-text">

                                                <?= e(
                                                    date(
                                                        'h:i A',
                                                        $returnTimestamp
                                                    )
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if (
                                            $record['status'] === 'Reading'
                                        ): ?>

                                            <span class="badge-reading">

                                                <i class="bi bi-book-half me-1"></i>

                                                Reading

                                            </span>

                                        <?php else: ?>

                                            <span class="badge-returned">

                                                <i class="bi bi-check-circle me-1"></i>

                                                Returned

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end">

                                        <?php if (
                                            $record['status'] === 'Reading'
                                        ): ?>

                                            <form
                                                method="POST"
                                                action="study-attendance.php"
                                                class="d-inline"
                                                onsubmit="return confirm('Confirm that this book has been returned?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="return_book"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="attendance_id"
                                                    value="<?= (int) $record['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="return-btn"
                                                >

                                                    <i class="bi bi-arrow-return-left me-1"></i>

                                                    Return

                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div class="empty-icon">

                        <i class="bi bi-journal-bookmark"></i>

                    </div>

                    <h5 class="fw-bold">
                        No library records found
                    </h5>

                    <p class="small mb-0">
                        No reading records match the selected filters.
                    </p>

                </div>

            <?php endif; ?>

            <!-- ==================================================
                 PAGINATION
            =================================================== -->

            <?php if ($totalPages > 1): ?>

                <div class="pagination-wrap">

                    <div class="pagination-info">

                        Showing

                        <strong>

                            <?= $totalRecords > 0
                                ? number_format($offset + 1)
                                : 0
                            ?>

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
                            <?= number_format($totalRecords) ?>
                        </strong>

                        records

                    </div>

                    <nav
                        aria-label="Library records pagination"
                    >

                        <ul class="pagination pagination-sm mb-0">

                            <li
                                class="page-item
                                <?= $page <= 1 ? 'disabled' : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page > 1
                                        ? e(
                                            pageUrl(
                                                $page - 1,
                                                $gradeFilter,
                                                $sectionFilter,
                                                $search
                                            )
                                        )
                                        : '#'
                                    ?>"
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
                                $paginationPage = $startPage;
                                $paginationPage <= $endPage;
                                $paginationPage++
                            ):

                            ?>

                                <li
                                    class="page-item
                                    <?= $paginationPage === $page
                                        ? 'active'
                                        : ''
                                    ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            pageUrl(
                                                $paginationPage,
                                                $gradeFilter,
                                                $sectionFilter,
                                                $search
                                            )
                                        ) ?>"
                                    >

                                        <?= $paginationPage ?>

                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li
                                class="page-item
                                <?= $page >= $totalPages
                                    ? 'disabled'
                                    : ''
                                ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page < $totalPages
                                        ? e(
                                            pageUrl(
                                                $page + 1,
                                                $gradeFilter,
                                                $sectionFilter,
                                                $search
                                            )
                                        )
                                        : '#'
                                    ?>"
                                >

                                    <i class="bi bi-chevron-right"></i>

                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    </main>

</div>

<!-- ==============================================================
     MOBILE BOTTOM NAV
================================================================ -->

<nav class="mobile-bottom-nav">

    <a href="dashboard.php">

        <i class="bi bi-grid-1x2"></i>

        Dashboard

    </a>

    <a href="books.php">

        <i class="bi bi-book"></i>

        Books

    </a>

    <a
        href="study-attendance.php"
        class="active"
    >

        <i class="bi bi-journal-bookmark"></i>

        Study

    </a>

    <a href="reports.php">

        <i class="bi bi-bar-chart"></i>

        Reports

    </a>

    <a href="profile.php">

        <i class="bi bi-person"></i>

        Profile

    </a>

</nav>

<!-- ==============================================================
     TAKE BOOK MODAL
================================================================ -->

<div
    class="modal fade"
    id="takeBookModal"
    tabindex="-1"
    aria-labelledby="takeBookModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5
                        class="modal-title"
                        id="takeBookModalLabel"
                    >
                        Record Book Take
                    </h5>

                    <div class="small text-muted mt-1">
                        Take date and time are recorded automatically.
                    </div>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form
                method="POST"
                action="study-attendance.php"
                id="takeBookForm"
            >

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="take_book"
                    >

                    <!-- ==================================================
                         STUDENT SEARCH
                    =================================================== -->

                    <div class="mb-3">

                        <label
                            for="studentSearch"
                            class="form-label"
                        >
                            Student
                        </label>

                        <div class="search-dropdown-wrapper">

                            <div class="search-input-wrapper">

                                <i
                                    class="bi bi-search search-icon"
                                ></i>

                                <input
                                    type="text"
                                    id="studentSearch"
                                    class="form-control search-input"
                                    placeholder="Search by name or student code..."
                                    autocomplete="off"
                                >

                                <button
                                    type="button"
                                    class="search-clear-btn"
                                    id="studentClearBtn"
                                    aria-label="Clear student"
                                >
                                    <i class="bi bi-x-lg"></i>
                                </button>

                            </div>

                            <div
                                class="search-results"
                                id="studentResults"
                            ></div>

                        </div>

                        <input
                            type="hidden"
                            name="student_id"
                            id="selectedStudentId"
                            value=""
                        >

                        <div
                            class="selected-search-item"
                            id="selectedStudent"
                        >

                            <div class="selected-search-info">

                                <div
                                    class="selected-search-title"
                                    id="selectedStudentName"
                                ></div>

                                <div
                                    class="selected-search-details"
                                    id="selectedStudentDetails"
                                ></div>

                            </div>

                            <i
                                class="bi bi-check-circle-fill selected-search-check"
                            ></i>

                        </div>

                        <div class="text-muted small mt-2">

                            <i class="bi bi-info-circle me-1"></i>

                            Type at least 2 characters to search.

                        </div>

                    </div>

                    <!-- ==================================================
                         BOOK SEARCH
                    =================================================== -->

                    <div class="mb-3">

                        <label
                            for="bookSearch"
                            class="form-label"
                        >
                            Book
                        </label>

                        <div class="search-dropdown-wrapper">

                            <div class="search-input-wrapper">

                                <i
                                    class="bi bi-book search-icon"
                                ></i>

                                <input
                                    type="text"
                                    id="bookSearch"
                                    class="form-control search-input"
                                    placeholder="Search by book title or ISBN..."
                                    autocomplete="off"
                                >

                                <button
                                    type="button"
                                    class="search-clear-btn"
                                    id="bookClearBtn"
                                    aria-label="Clear book"
                                >
                                    <i class="bi bi-x-lg"></i>
                                </button>

                            </div>

                            <div
                                class="search-results"
                                id="bookResults"
                            ></div>

                        </div>

                        <input
                            type="hidden"
                            name="book_id"
                            id="selectedBookId"
                            value=""
                        >

                        <div
                            class="selected-search-item"
                            id="selectedBook"
                        >

                            <div class="selected-search-info">

                                <div
                                    class="selected-search-title"
                                    id="selectedBookTitle"
                                ></div>

                                <div
                                    class="selected-search-details"
                                    id="selectedBookDetails"
                                ></div>

                            </div>

                            <i
                                class="bi bi-check-circle-fill selected-search-check"
                            ></i>

                        </div>

                        <div class="text-muted small mt-2">

                            <i class="bi bi-info-circle me-1"></i>

                            Only books with available copies are shown.

                        </div>

                    </div>

                    <!-- ==================================================
                         INFORMATION
                    =================================================== -->

                    <div class="alert alert-info py-2 px-3 small mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        When you click
                        <strong>Take Book</strong>,
                        the system automatically records the current
                        date and time and reduces the available quantity
                        by one.

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
                        class="btn btn-primary-custom"
                        id="takeBookSubmitBtn"
                        disabled
                    >

                        <i class="bi bi-book-half me-1"></i>

                        Take Book

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ==============================================================
     BOOTSTRAP
================================================================ -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const mobileOverlay =
    document.getElementById('mobileOverlay');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

function openSidebar() {

    if (!sidebar || !mobileOverlay) {
        return;
    }

    sidebar.classList.add('show');

    mobileOverlay.classList.add('show');
}

function closeSidebar() {

    if (!sidebar || !mobileOverlay) {
        return;
    }

    sidebar.classList.remove('show');

    mobileOverlay.classList.remove('show');
}

if (mobileMenuBtn) {

    mobileMenuBtn.addEventListener(
        'click',
        openSidebar
    );
}

if (mobileOverlay) {

    mobileOverlay.addEventListener(
        'click',
        closeSidebar
    );
}

document
    .querySelectorAll('.sidebar-nav a')
    .forEach(function (link) {

        link.addEventListener(
            'click',
            function () {

                if (window.innerWidth <= 991) {
                    closeSidebar();
                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Searchable Student Dropdown
|--------------------------------------------------------------------------
*/

const studentSearch =
    document.getElementById('studentSearch');

const studentResults =
    document.getElementById('studentResults');

const selectedStudentId =
    document.getElementById('selectedStudentId');

const selectedStudent =
    document.getElementById('selectedStudent');

const selectedStudentName =
    document.getElementById('selectedStudentName');

const selectedStudentDetails =
    document.getElementById('selectedStudentDetails');

const studentClearBtn =
    document.getElementById('studentClearBtn');


/*
|--------------------------------------------------------------------------
| Searchable Book Dropdown
|--------------------------------------------------------------------------
*/

const bookSearch =
    document.getElementById('bookSearch');

const bookResults =
    document.getElementById('bookResults');

const selectedBookId =
    document.getElementById('selectedBookId');

const selectedBook =
    document.getElementById('selectedBook');

const selectedBookTitle =
    document.getElementById('selectedBookTitle');

const selectedBookDetails =
    document.getElementById('selectedBookDetails');

const bookClearBtn =
    document.getElementById('bookClearBtn');


/*
|--------------------------------------------------------------------------
| Submit Button
|--------------------------------------------------------------------------
*/

const takeBookSubmitBtn =
    document.getElementById('takeBookSubmitBtn');

const takeBookForm =
    document.getElementById('takeBookForm');


/*
|--------------------------------------------------------------------------
| Current Page Filters
|--------------------------------------------------------------------------
*/

const activeGradeFilter =
    <?= (int) $gradeFilter ?>;

const activeSectionFilter =
    <?= (int) $sectionFilter ?>;


/*
|--------------------------------------------------------------------------
| AJAX Search Timer
|--------------------------------------------------------------------------
*/

let studentSearchTimer = null;

let bookSearchTimer = null;


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    const div =
        document.createElement('div');

    div.textContent =
        value ?? '';

    return div.innerHTML;
}


/*
|--------------------------------------------------------------------------
| Update Take Book Button
|--------------------------------------------------------------------------
*/

function updateTakeBookButton() {

    if (!takeBookSubmitBtn) {
        return;
    }

    const studentSelected =
        selectedStudentId &&
        selectedStudentId.value !== '';

    const bookSelected =
        selectedBookId &&
        selectedBookId.value !== '';

    takeBookSubmitBtn.disabled =
        !studentSelected ||
        !bookSelected;
}


/*
|--------------------------------------------------------------------------
| Show Loading
|--------------------------------------------------------------------------
*/

function showLoading(container) {

    if (!container) {
        return;
    }

    container.innerHTML = `
        <div class="search-loading">
            <div
                class="spinner-border spinner-border-sm text-primary me-2"
                role="status"
            ></div>
            Searching...
        </div>
    `;

    container.classList.add('show');
}


/*
|--------------------------------------------------------------------------
| Show No Result
|--------------------------------------------------------------------------
*/

function showNoResult(
    container,
    message
) {

    if (!container) {
        return;
    }

    container.innerHTML = `
        <div class="search-no-result">
            <i class="bi bi-search fs-5 d-block mb-1"></i>
            ${escapeHtml(message)}
        </div>
    `;

    container.classList.add('show');
}


/*
|--------------------------------------------------------------------------
| Search Students
|--------------------------------------------------------------------------
*/

async function searchStudents(query) {

    if (!studentResults) {
        return;
    }

    query =
        query.trim();

    if (query.length < 2) {

        studentResults.innerHTML = '';

        studentResults.classList.remove(
            'show'
        );

        return;
    }

    showLoading(studentResults);

    const formData =
        new FormData();

    formData.append(
        'action',
        'search_students'
    );

    formData.append(
        'query',
        query
    );

    formData.append(
        'grade_id',
        String(activeGradeFilter)
    );

    formData.append(
        'section_id',
        String(activeSectionFilter)
    );

    try {

        const response =
            await fetch(
                'study-attendance.php',
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        if (!response.ok) {
            throw new Error(
                'Unable to search students.'
            );
        }

        const data =
            await response.json();

        if (
            !data.success ||
            !Array.isArray(data.students)
        ) {

            showNoResult(
                studentResults,
                'Unable to search students.'
            );

            return;
        }

        renderStudentResults(
            data.students
        );

    } catch (error) {

        showNoResult(
            studentResults,
            'Unable to search students. Please try again.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Render Student Results
|--------------------------------------------------------------------------
*/

function renderStudentResults(
    students
) {

    if (!studentResults) {
        return;
    }

    studentResults.innerHTML = '';

    if (!students.length) {

        showNoResult(
            studentResults,
            'No student found.'
        );

        return;
    }

    students.forEach(function (student) {

        const button =
            document.createElement('button');

        button.type =
            'button';

        button.className =
            'search-result-item';

        const fullName =
            escapeHtml(
                student.full_name
            );

        const studentCode =
            escapeHtml(
                student.student_code
            );

        const gradeName =
            escapeHtml(
                student.grade_name
            );

        const sectionName =
            escapeHtml(
                student.section_name
            );

        button.innerHTML = `
            <div class="search-result-title">
                ${fullName}
            </div>

            <div class="search-result-code">
                ${studentCode}
            </div>

            <div class="search-result-meta">
                ${gradeName} / ${sectionName}
            </div>
        `;

        button.addEventListener(
            'click',
            function () {

                selectStudent(
                    student
                );

            }
        );

        studentResults.appendChild(
            button
        );

    });

    studentResults.classList.add(
        'show'
    );
}


/*
|--------------------------------------------------------------------------
| Select Student
|--------------------------------------------------------------------------
*/

function selectStudent(student) {

    if (!student) {
        return;
    }

    selectedStudentId.value =
        String(student.id);

    studentSearch.value =
        String(
            student.full_name ?? ''
        );

    selectedStudentName.textContent =
        String(
            student.full_name ?? ''
        );

    selectedStudentDetails.textContent =
        String(
            student.student_code ?? ''
        ) +
        ' • ' +
        String(
            student.grade_name ?? ''
        ) +
        ' / ' +
        String(
            student.section_name ?? ''
        );

    selectedStudent.classList.add(
        'show'
    );

    studentResults.classList.remove(
        'show'
    );

    studentClearBtn.style.display =
        'flex';

    updateTakeBookButton();
}


/*
|--------------------------------------------------------------------------
| Clear Student
|--------------------------------------------------------------------------
*/

function clearStudent() {

    selectedStudentId.value =
        '';

    studentSearch.value =
        '';

    selectedStudentName.textContent =
        '';

    selectedStudentDetails.textContent =
        '';

    selectedStudent.classList.remove(
        'show'
    );

    studentResults.innerHTML =
        '';

    studentResults.classList.remove(
        'show'
    );

    studentClearBtn.style.display =
        'none';

    updateTakeBookButton();

    if (studentSearch) {
        studentSearch.focus();
    }
}


/*
|--------------------------------------------------------------------------
| Student Input
|--------------------------------------------------------------------------
*/

if (studentSearch) {

    studentSearch.addEventListener(
        'input',
        function () {

            /*
            |----------------------------------------------------------------------
            | Clear selected student when typing changes the value.
            |----------------------------------------------------------------------
            */

            if (
                selectedStudentId.value !== '' &&
                studentSearch.value !==
                selectedStudentName.textContent
            ) {

                selectedStudentId.value =
                    '';

                selectedStudent.classList.remove(
                    'show'
                );

                updateTakeBookButton();
            }

            if (
                studentSearch.value.trim() !== ''
            ) {

                studentClearBtn.style.display =
                    'flex';

            } else {

                studentClearBtn.style.display =
                    'none';
            }

            clearTimeout(
                studentSearchTimer
            );

            studentSearchTimer =
                setTimeout(
                    function () {

                        searchStudents(
                            studentSearch.value
                        );

                    },
                    300
                );

        }
    );

}


/*
|--------------------------------------------------------------------------
| Clear Student Button
|--------------------------------------------------------------------------
*/

if (studentClearBtn) {

    studentClearBtn.addEventListener(
        'click',
        function () {

            clearStudent();

        }
    );
}


/*
|--------------------------------------------------------------------------
| Search Books
|--------------------------------------------------------------------------
*/

async function searchBooks(query) {

    if (!bookResults) {
        return;
    }

    query =
        query.trim();

    if (query.length < 2) {

        bookResults.innerHTML = '';

        bookResults.classList.remove(
            'show'
        );

        return;
    }

    showLoading(bookResults);

    const formData =
        new FormData();

    formData.append(
        'action',
        'search_books'
    );

    formData.append(
        'query',
        query
    );

    try {

        const response =
            await fetch(
                'study-attendance.php',
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        if (!response.ok) {
            throw new Error(
                'Unable to search books.'
            );
        }

        const data =
            await response.json();

        if (
            !data.success ||
            !Array.isArray(data.books)
        ) {

            showNoResult(
                bookResults,
                'Unable to search books.'
            );

            return;
        }

        renderBookResults(
            data.books
        );

    } catch (error) {

        showNoResult(
            bookResults,
            'Unable to search books. Please try again.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Render Book Results
|--------------------------------------------------------------------------
*/

function renderBookResults(
    books
) {

    if (!bookResults) {
        return;
    }

    bookResults.innerHTML = '';

    if (!books.length) {

        showNoResult(
            bookResults,
            'No available book found.'
        );

        return;
    }

    books.forEach(function (book) {

        const button =
            document.createElement('button');

        button.type =
            'button';

        button.className =
            'search-result-item';

        const title =
            escapeHtml(
                book.title
            );

        const isbn =
            escapeHtml(
                book.isbn || 'No ISBN'
            );

        const available =
            Number(
                book.available_quantity
            ) || 0;

        button.innerHTML = `
            <div class="search-result-title">
                ${title}
            </div>

            <div class="search-result-meta">
                ISBN: ${isbn}
            </div>

            <div class="search-result-available">
                <i class="bi bi-check-circle"></i>
                Available: ${available}
            </div>
        `;

        button.addEventListener(
            'click',
            function () {

                selectBook(
                    book
                );

            }
        );

        bookResults.appendChild(
            button
        );

    });

    bookResults.classList.add(
        'show'
    );
}


/*
|--------------------------------------------------------------------------
| Select Book
|--------------------------------------------------------------------------
*/

function selectBook(book) {

    if (!book) {
        return;
    }

    selectedBookId.value =
        String(book.id);

    bookSearch.value =
        String(
            book.title ?? ''
        );

    selectedBookTitle.textContent =
        String(
            book.title ?? ''
        );

    const isbn =
        book.isbn
            ? 'ISBN: ' + book.isbn
            : 'No ISBN';

    selectedBookDetails.textContent =
        isbn +
        ' • Available: ' +
        String(
            book.available_quantity ?? 0
        );

    selectedBook.classList.add(
        'show'
    );

    bookResults.classList.remove(
        'show'
    );

    bookClearBtn.style.display =
        'flex';

    updateTakeBookButton();
}


/*
|--------------------------------------------------------------------------
| Clear Book
|--------------------------------------------------------------------------
*/

function clearBook() {

    selectedBookId.value =
        '';

    bookSearch.value =
        '';

    selectedBookTitle.textContent =
        '';

    selectedBookDetails.textContent =
        '';

    selectedBook.classList.remove(
        'show'
    );

    bookResults.innerHTML =
        '';

    bookResults.classList.remove(
        'show'
    );

    bookClearBtn.style.display =
        'none';

    updateTakeBookButton();

    if (bookSearch) {
        bookSearch.focus();
    }
}


/*
|--------------------------------------------------------------------------
| Book Input
|--------------------------------------------------------------------------
*/

if (bookSearch) {

    bookSearch.addEventListener(
        'input',
        function () {

            /*
            |----------------------------------------------------------------------
            | Clear selected book when typing changes the value.
            |----------------------------------------------------------------------
            */

            if (
                selectedBookId.value !== '' &&
                bookSearch.value !==
                selectedBookTitle.textContent
            ) {

                selectedBookId.value =
                    '';

                selectedBook.classList.remove(
                    'show'
                );

                updateTakeBookButton();
            }

            if (
                bookSearch.value.trim() !== ''
            ) {

                bookClearBtn.style.display =
                    'flex';

            } else {

                bookClearBtn.style.display =
                    'none';
            }

            clearTimeout(
                bookSearchTimer
            );

            bookSearchTimer =
                setTimeout(
                    function () {

                        searchBooks(
                            bookSearch.value
                        );

                    },
                    300
                );

        }
    );

}


/*
|--------------------------------------------------------------------------
| Clear Book Button
|--------------------------------------------------------------------------
*/

if (bookClearBtn) {

    bookClearBtn.addEventListener(
        'click',
        function () {

            clearBook();

        }
    );
}


/*
|--------------------------------------------------------------------------
| Close Search Results When Clicking Outside
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'click',
    function (event) {

        document
            .querySelectorAll(
                '.search-dropdown-wrapper'
            )
            .forEach(
                function (wrapper) {

                    if (
                        !wrapper.contains(
                            event.target
                        )
                    ) {

                        const results =
                            wrapper.querySelector(
                                '.search-results'
                            );

                        results?.classList.remove(
                            'show'
                        );

                    }

                }
            );

    }
);


/*
|--------------------------------------------------------------------------
| Reset Modal
|--------------------------------------------------------------------------
*/

const takeBookModal =
    document.getElementById(
        'takeBookModal'
    );

if (takeBookModal) {

    takeBookModal.addEventListener(
        'hidden.bs.modal',
        function () {

            clearStudent();

            clearBook();

            updateTakeBookButton();

        }
    );

}


/*
|--------------------------------------------------------------------------
| Form Validation
|--------------------------------------------------------------------------
*/

if (takeBookForm) {

    takeBookForm.addEventListener(
        'submit',
        function (event) {

            if (
                !selectedStudentId.value ||
                selectedStudentId.value === '0'
            ) {

                event.preventDefault();

                alert(
                    'Please search for and select a student.'
                );

                studentSearch?.focus();

                return;
            }

            if (
                !selectedBookId.value ||
                selectedBookId.value === '0'
            ) {

                event.preventDefault();

                alert(
                    'Please search for and select a book.'
                );

                bookSearch?.focus();

                return;
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Initial Button State
|--------------------------------------------------------------------------
*/

updateTakeBookButton();

</script>

</body>

</html>