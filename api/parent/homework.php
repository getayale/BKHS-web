<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
        $origin
    )
) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

require_once '../../config/database.php';

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();

    foreach ($headers as $key => $value) {
        if (strtolower((string) $key) === 'authorization') {
            $authorizationHeader = trim((string) $value);
            break;
        }
    }
}

if ($authorizationHeader === '') {
    jsonResponse(
        false,
        'Authorization token is required.',
        [],
        401
    );
}

if (
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {
    jsonResponse(
        false,
        'Invalid authorization format.',
        [],
        401
    );
}

$rawToken = trim($matches[1]);

if ($rawToken === '') {
    jsonResponse(
        false,
        'Authentication token is missing.',
        [],
        401
    );
}

$tokenHash = hash('sha256', $rawToken);

/*
|--------------------------------------------------------------------------
| Validate API Token
|--------------------------------------------------------------------------
*/

$tokenStmt = $conn->prepare("
    SELECT
        at.user_id,
        u.role,
        u.is_deleted
    FROM api_tokens AS at
    INNER JOIN users AS u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
");

if (!$tokenStmt) {
    jsonResponse(
        false,
        'Failed to prepare authentication query.',
        [],
        500
    );
}

$tokenStmt->bind_param('s', $tokenHash);

if (!$tokenStmt->execute()) {
    $tokenStmt->close();

    jsonResponse(
        false,
        'Failed to validate authentication token.',
        [],
        500
    );
}

$tokenResult = $tokenStmt->get_result();
$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (!$tokenUser) {
    jsonResponse(
        false,
        'Invalid or expired authentication token.',
        [],
        401
    );
}

$userId = (int) $tokenUser['user_id'];

$role = strtolower(
    (string) $tokenUser['role']
);

if ($role !== 'parent') {
    jsonResponse(
        false,
        'Parent access is required.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Selected Student
|--------------------------------------------------------------------------
*/

$selectedStudentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if (
    $selectedStudentId === false ||
    $selectedStudentId === null ||
    $selectedStudentId <= 0
) {
    jsonResponse(
        false,
        'A valid student ID is required.',
        [],
        400
    );
}

$studentId = (int) $selectedStudentId;

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$currentPage = filter_input(
    INPUT_GET,
    'current_page',
    FILTER_VALIDATE_INT
);

if (
    $currentPage === false ||
    $currentPage === null ||
    $currentPage <= 0
) {
    $currentPage = 1;
}

$historyPage = filter_input(
    INPUT_GET,
    'history_page',
    FILTER_VALIDATE_INT
);

if (
    $historyPage === false ||
    $historyPage === null ||
    $historyPage <= 0
) {
    $historyPage = 1;
}

$perPage = 10;

$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Get Parent
|--------------------------------------------------------------------------
*/

$parentStmt = $conn->prepare("
    SELECT
        parents.id AS parent_id,
        parents.user_id,
        parents.full_name,
        parents.phone
    FROM parents
    INNER JOIN users
        ON users.id = parents.user_id
    WHERE parents.user_id = ?
      AND users.is_deleted = 0
      AND LOWER(users.role) = 'parent'
    LIMIT 1
");

if (!$parentStmt) {
    jsonResponse(
        false,
        'Failed to prepare parent query.',
        [],
        500
    );
}

$parentStmt->bind_param('i', $userId);

if (!$parentStmt->execute()) {
    $parentStmt->close();

    jsonResponse(
        false,
        'Failed to load parent information.',
        [],
        500
    );
}

$parentResult = $parentStmt->get_result();
$parent = $parentResult->fetch_assoc();

$parentStmt->close();

if (!$parent) {
    jsonResponse(
        false,
        'Parent account was not found.',
        [],
        404
    );
}

$parentId = (int) $parent['parent_id'];

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
|--------------------------------------------------------------------------
*/

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$academicYearStmt) {
    jsonResponse(
        false,
        'Failed to prepare academic year query.',
        [],
        500
    );
}

if (!$academicYearStmt->execute()) {
    $academicYearStmt->close();

    jsonResponse(
        false,
        'Failed to load academic year.',
        [],
        500
    );
}

$academicYearResult = $academicYearStmt->get_result();
$academicYear = $academicYearResult->fetch_assoc();

$academicYearStmt->close();

if (!$academicYear) {
    jsonResponse(
        false,
        'No active academic year found.',
        [],
        404
    );
}

$academicYearId = (int) $academicYear['id'];
$academicYearName = (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify Selected Student Belongs to Parent
|--------------------------------------------------------------------------
*/

$childStmt = $conn->prepare("
    SELECT
        s.id AS student_id,
        s.student_code,
        s.full_name,
        sp.relationship,
        sr.id AS registration_id,
        g.grade_number,
        sec.code AS section
    FROM parents AS p
    INNER JOIN student_parents AS sp
        ON sp.parent_id = p.id
        AND sp.student_id = ?
        AND sp.is_account_access = 1
    INNER JOIN students AS s
        ON s.id = sp.student_id
    INNER JOIN student_registrations AS sr
        ON sr.student_id = s.id
        AND sr.academic_year_id = ?
    INNER JOIN grades AS g
        ON g.id = sr.grade_id
    INNER JOIN sections AS sec
        ON sec.id = sr.section_id
    INNER JOIN users AS u
        ON u.id = s.user_id
    WHERE p.user_id = ?
      AND s.is_deleted = 0
      AND u.is_deleted = 0
      AND LOWER(u.role) = 'student'
    ORDER BY sr.id DESC
    LIMIT 1
");

if (!$childStmt) {
    jsonResponse(
        false,
        'Failed to prepare child query.',
        [],
        500
    );
}

$childStmt->bind_param(
    'iii',
    $studentId,
    $academicYearId,
    $userId
);

if (!$childStmt->execute()) {
    $childStmt->close();

    jsonResponse(
        false,
        'Failed to verify selected student.',
        [],
        500
    );
}

$childResult = $childStmt->get_result();
$child = $childResult->fetch_assoc();

$childStmt->close();

if (!$child) {
    jsonResponse(
        false,
        'The selected student does not belong to this parent.',
        [],
        403
    );
}

$studentCode = (string) $child['student_code'];
$studentName = (string) $child['full_name'];
$relationship = (string) $child['relationship'];
$gradeNumber = (int) $child['grade_number'];
$section = (string) $child['section'];

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

final class EthiopianCalendar
{
    private const ETHIOPIAN_EPOCH = 1723856;

    private const MONTHS = [
        1 => 'Meskerem',
        2 => 'Tikimt',
        3 => 'Hidar',
        4 => 'Tahsas',
        5 => 'Tir',
        6 => 'Yekatit',
        7 => 'Megabit',
        8 => 'Miazia',
        9 => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    public static function fromGregorian(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return $date;
        }

        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $day = (int) date('j', $timestamp);

        $jd = self::gregorianToJd(
            $year,
            $month,
            $day
        );

        $ethiopianYear = (int) floor(
            ($jd - self::ETHIOPIAN_EPOCH) / 365.25
        ) + 1;

        $ethiopianNewYearJd = self::ethiopianToJd(
            $ethiopianYear,
            1,
            1
        );

        $ethiopianMonth = (int) floor(
            ($jd - $ethiopianNewYearJd) / 30
        ) + 1;

        $ethiopianDay =
            $jd
            - $ethiopianNewYearJd
            - (30 * ($ethiopianMonth - 1))
            + 1;

        return $ethiopianDay
            . ' '
            . self::MONTHS[$ethiopianMonth]
            . ' '
            . $ethiopianYear;
    }

    private static function gregorianToJd(
        int $year,
        int $month,
        int $day
    ): int {
        $a = (int) floor(
            (14 - $month) / 12
        );

        $y = $year + 4800 - $a;

        $m =
            $month
            + (12 * $a)
            - 3;

        return $day
            + (int) floor(
                (153 * $m + 2) / 5
            )
            + (365 * $y)
            + (int) floor($y / 4)
            - (int) floor($y / 100)
            + (int) floor($y / 400)
            - 32045;
    }

    private static function ethiopianToJd(
        int $year,
        int $month,
        int $day
    ): int {
        return self::ETHIOPIAN_EPOCH
            + (365 * ($year - 1))
            + (int) floor($year / 4)
            + (30 * ($month - 1))
            + $day
            - 1;
    }
}

/*
|--------------------------------------------------------------------------
| Pagination Helper
|--------------------------------------------------------------------------
*/

function getPagination(
    int $page,
    int $perPage,
    int $total
): array {
    $totalPages = max(
        1,
        (int) ceil($total / $perPage)
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    return [
        'current_page' => $page,
        'records_per_page' => $perPage,
        'total_records' => $total,
        'total_pages' => $totalPages,
        'has_previous' => $page > 1,
        'has_next' => $page < $totalPages,
    ];
}

/*
|--------------------------------------------------------------------------
| Current Homework Count
|--------------------------------------------------------------------------
*/

$currentCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM homeworks
    WHERE academic_year = ?
      AND grade = ?
      AND section = ?
      AND status = 'Active'
      AND due_date >= ?
");

if (!$currentCountStmt) {
    jsonResponse(
        false,
        'Failed to prepare current homework count query.',
        [],
        500
    );
}

$currentCountStmt->bind_param(
    'siss',
    $academicYearName,
    $gradeNumber,
    $section,
    $today
);

if (!$currentCountStmt->execute()) {
    $currentCountStmt->close();

    jsonResponse(
        false,
        'Failed to load current homework count.',
        [],
        500
    );
}

$currentCountResult = $currentCountStmt->get_result();
$currentCountRow = $currentCountResult->fetch_assoc();

$currentCountStmt->close();

$currentTotal = (int) ($currentCountRow['total'] ?? 0);

/*
|--------------------------------------------------------------------------
| Homework History Count
|--------------------------------------------------------------------------
*/

$historyCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM homeworks
    WHERE academic_year = ?
      AND grade = ?
      AND section = ?
      AND due_date < ?
");

if (!$historyCountStmt) {
    jsonResponse(
        false,
        'Failed to prepare homework history count query.',
        [],
        500
    );
}

$historyCountStmt->bind_param(
    'siss',
    $academicYearName,
    $gradeNumber,
    $section,
    $today
);

if (!$historyCountStmt->execute()) {
    $historyCountStmt->close();

    jsonResponse(
        false,
        'Failed to load homework history count.',
        [],
        500
    );
}

$historyCountResult = $historyCountStmt->get_result();
$historyCountRow = $historyCountResult->fetch_assoc();

$historyCountStmt->close();

$historyTotal = (int) ($historyCountRow['total'] ?? 0);

/*
|--------------------------------------------------------------------------
| Pagination Information
|--------------------------------------------------------------------------
*/

$currentPagination = getPagination(
    $currentPage,
    $perPage,
    $currentTotal
);

$historyPagination = getPagination(
    $historyPage,
    $perPage,
    $historyTotal
);

$currentPage = $currentPagination['current_page'];
$historyPage = $historyPagination['current_page'];

$currentOffset = ($currentPage - 1) * $perPage;
$historyOffset = ($historyPage - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Current Homework
|--------------------------------------------------------------------------
*/

$currentHomework = [];

$currentHomeworkStmt = $conn->prepare("
    SELECT
        h.id,
        h.title,
        h.assigned_date,
        h.due_date,
        gs.subject_name,
        u.full_name AS teacher_name,
        COALESCE(
            hss.status,
            'Not Done'
        ) AS student_status
    FROM homeworks AS h
    INNER JOIN grade_subjects AS gs
        ON gs.id = h.grade_subject_id
    INNER JOIN users AS u
        ON u.id = h.teacher_user_id
        AND u.is_deleted = 0
    LEFT JOIN homework_student_status AS hss
        ON hss.homework_id = h.id
        AND hss.student_id = ?
    WHERE h.academic_year = ?
      AND h.grade = ?
      AND h.section = ?
      AND h.status = 'Active'
      AND h.due_date >= ?
    ORDER BY
        h.assigned_date DESC,
        h.id DESC
    LIMIT ? OFFSET ?
");

if (!$currentHomeworkStmt) {
    jsonResponse(
        false,
        'Failed to prepare current homework query.',
        [],
        500
    );
}

$currentHomeworkStmt->bind_param(
    'isisiii',
    $studentId,
    $academicYearName,
    $gradeNumber,
    $section,
    $today,
    $perPage,
    $currentOffset
);

if (!$currentHomeworkStmt->execute()) {
    $currentHomeworkStmt->close();

    jsonResponse(
        false,
        'Failed to load current homework.',
        [],
        500
    );
}

$currentResult = $currentHomeworkStmt->get_result();

while ($row = $currentResult->fetch_assoc()) {
    $assignedDate = (string) $row['assigned_date'];
    $dueDate = (string) $row['due_date'];

    $currentHomework[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'assigned_date' => $assignedDate,
        'assigned_date_ethiopian' =>
            EthiopianCalendar::fromGregorian($assignedDate),
        'due_date' => $dueDate,
        'due_date_ethiopian' =>
            EthiopianCalendar::fromGregorian($dueDate),
        'subject_name' => (string) $row['subject_name'],
        'teacher_name' => (string) $row['teacher_name'],
        'student_status' => (string) $row['student_status'],
    ];
}

$currentHomeworkStmt->close();

/*
|--------------------------------------------------------------------------
| Homework History
|--------------------------------------------------------------------------
*/

$historyHomework = [];

$historyHomeworkStmt = $conn->prepare("
    SELECT
        h.id,
        h.title,
        h.assigned_date,
        h.due_date,
        gs.subject_name,
        u.full_name AS teacher_name,
        COALESCE(
            hss.status,
            'Not Done'
        ) AS student_status
    FROM homeworks AS h
    INNER JOIN grade_subjects AS gs
        ON gs.id = h.grade_subject_id
    INNER JOIN users AS u
        ON u.id = h.teacher_user_id
        AND u.is_deleted = 0
    LEFT JOIN homework_student_status AS hss
        ON hss.homework_id = h.id
        AND hss.student_id = ?
    WHERE h.academic_year = ?
      AND h.grade = ?
      AND h.section = ?
      AND h.due_date < ?
    ORDER BY
        h.due_date DESC,
        h.id DESC
    LIMIT ? OFFSET ?
");

if (!$historyHomeworkStmt) {
    jsonResponse(
        false,
        'Failed to prepare homework history query.',
        [],
        500
    );
}

$historyHomeworkStmt->bind_param(
    'isisiii',
    $studentId,
    $academicYearName,
    $gradeNumber,
    $section,
    $today,
    $perPage,
    $historyOffset
);

if (!$historyHomeworkStmt->execute()) {
    $historyHomeworkStmt->close();

    jsonResponse(
        false,
        'Failed to load homework history.',
        [],
        500
    );
}

$historyResult = $historyHomeworkStmt->get_result();

while ($row = $historyResult->fetch_assoc()) {
    $assignedDate = (string) $row['assigned_date'];
    $dueDate = (string) $row['due_date'];

    $historyHomework[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'assigned_date' => $assignedDate,
        'assigned_date_ethiopian' =>
            EthiopianCalendar::fromGregorian($assignedDate),
        'due_date' => $dueDate,
        'due_date_ethiopian' =>
            EthiopianCalendar::fromGregorian($dueDate),
        'subject_name' => (string) $row['subject_name'],
        'teacher_name' => (string) $row['teacher_name'],
        'student_status' => (string) $row['student_status'],
    ];
}

$historyHomeworkStmt->close();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Homework loaded successfully.',
    [
        'today' => $today,

        'academic_year' => [
            'id' => $academicYearId,
            'name' => $academicYearName,
            'status' => (string) $academicYear['status'],
        ],

        'student' => [
            'student_id' => $studentId,
            'student_code' => $studentCode,
            'full_name' => $studentName,
            'relationship' => $relationship,
            'grade_number' => $gradeNumber,
            'grade_label' => 'Grade ' . $gradeNumber,
            'section' => $section,
        ],

        'current_homework' => [
            'items' => $currentHomework,
            'homework_count' => count($currentHomework),
            'pagination' => $currentPagination,
        ],

        'history_homework' => [
            'items' => $historyHomework,
            'homework_count' => count($historyHomework),
            'pagination' => $historyPagination,
        ],
    ]
);
?>