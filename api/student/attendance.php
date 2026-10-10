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
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ]);

    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
    $authorization === '' &&
    function_exists('getallheaders')
) {
    $headers = getallheaders();

    $authorization =
        $headers['Authorization']
        ?? $headers['authorization']
        ?? '';
}

if (
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        trim($authorization),
        $matches
    )
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is required.',
    ]);

    exit;
}

$token = trim($matches[1]);

if ($token === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication token is invalid.',
    ]);

    exit;
}

try {

    /*
     * ---------------------------------------------------------
     * Validate API token
     * ---------------------------------------------------------
     */

    $tokenHash = hash(
        'sha256',
        $token
    );

    $authStmt = $conn->prepare("
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

    if (!$authStmt) {
        throw new RuntimeException(
            'Failed to prepare authentication query.'
        );
    }

    $authStmt->bind_param(
        's',
        $tokenHash
    );

    $authStmt->execute();

    $authResult = $authStmt->get_result();

    $authUser = $authResult->fetch_assoc();

    $authStmt->close();

    if (!$authUser) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication token is invalid or expired.',
        ]);

        exit;
    }

    /*
     * ---------------------------------------------------------
     * Student role
     * ---------------------------------------------------------
     */

    if (
        strtolower(
            (string) ($authUser['role'] ?? '')
        ) !== 'student'
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Only student accounts can access attendance.',
        ]);

        exit;
    }

    $userId = (int) $authUser['user_id'];

    /*
     * ---------------------------------------------------------
     * Get logged-in student
     * ---------------------------------------------------------
     */

    $studentStmt = $conn->prepare("
        SELECT
            s.id,
            s.student_code,
            s.full_name
        FROM students AS s
        INNER JOIN users AS u
            ON u.id = s.user_id
        WHERE s.user_id = ?
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'
        LIMIT 1
    ");

    if (!$studentStmt) {
        throw new RuntimeException(
            'Failed to prepare student query.'
        );
    }

    $studentStmt->bind_param(
        'i',
        $userId
    );

    $studentStmt->execute();

    $studentResult = $studentStmt->get_result();

    $student = $studentResult->fetch_assoc();

    $studentStmt->close();

    if (!$student) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Student account was not found.',
        ]);

        exit;
    }

    $studentId = (int) $student['id'];

    /*
     * ---------------------------------------------------------
     * Pagination
     * ---------------------------------------------------------
     */

    $perPage = 10;

    $currentPage = isset($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    if ($currentPage < 1) {
        $currentPage = 1;
    }

    /*
     * ---------------------------------------------------------
     * Count Absent + Late records
     * ---------------------------------------------------------
     */

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM student_attendance
        WHERE student_id = ?
          AND status IN ('Absent', 'Late')
    ");

    if (!$countStmt) {
        throw new RuntimeException(
            'Failed to prepare attendance count query.'
        );
    }

    $countStmt->bind_param(
        'i',
        $studentId
    );

    $countStmt->execute();

    $countResult = $countStmt->get_result();

    $countRow = $countResult->fetch_assoc();

    $totalRecords = (int) (
        $countRow['total'] ?? 0
    );

    $countStmt->close();

    /*
     * ---------------------------------------------------------
     * Calculate pagination
     * ---------------------------------------------------------
     */

    $totalPages = max(
        1,
        (int) ceil(
            $totalRecords / $perPage
        )
    );

    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $offset = (
        $currentPage - 1
    ) * $perPage;

    /*
     * ---------------------------------------------------------
     * Get attendance records
     *
     * Latest records first.
     * Only Absent and Late.
     * ---------------------------------------------------------
     */

    $attendanceStmt = $conn->prepare("
        SELECT
            id,
            attendance_date,
            ethiopian_date,
            status
        FROM student_attendance
        WHERE student_id = ?
          AND status IN ('Absent', 'Late')
        ORDER BY
            attendance_date DESC,
            id DESC
        LIMIT ? OFFSET ?
    ");

    if (!$attendanceStmt) {
        throw new RuntimeException(
            'Failed to prepare attendance query.'
        );
    }

    $attendanceStmt->bind_param(
        'iii',
        $studentId,
        $perPage,
        $offset
    );

    $attendanceStmt->execute();

    $attendanceResult =
        $attendanceStmt->get_result();

    $attendanceRecords = [];

    while (
        $row = $attendanceResult->fetch_assoc()
    ) {
        $attendanceDate =
            (string) ($row['attendance_date'] ?? '');

        $timestamp = strtotime(
            $attendanceDate
        );

        $dayName = '';

        $gregorianDate = '';

        if ($timestamp !== false) {
            $dayName = date(
                'l',
                $timestamp
            );

            $gregorianDate = date(
                'd M Y',
                $timestamp
            );
        }

        $attendanceRecords[] = [
            'id' => (int) $row['id'],
            'attendance_date' =>
                $attendanceDate,
            'ethiopian_date' =>
                (string) (
                    $row['ethiopian_date'] ?? ''
                ),
            'gregorian_date' =>
                $gregorianDate,
            'day_name' =>
                $dayName,
            'status' =>
                (string) (
                    $row['status'] ?? ''
                ),
        ];
    }

    $attendanceStmt->close();

    /*
     * ---------------------------------------------------------
     * Statistics
     *
     * These are based on the same records displayed
     * by the student attendance page.
     * ---------------------------------------------------------
     */

    $statisticsStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(
                status = 'Absent'
            ) AS absent,
            SUM(
                status = 'Late'
            ) AS late
        FROM student_attendance
        WHERE student_id = ?
          AND status IN ('Absent', 'Late')
    ");

    if (!$statisticsStmt) {
        throw new RuntimeException(
            'Failed to prepare attendance statistics query.'
        );
    }

    $statisticsStmt->bind_param(
        'i',
        $studentId
    );

    $statisticsStmt->execute();

    $statisticsResult =
        $statisticsStmt->get_result();

    $statistics =
        $statisticsResult->fetch_assoc();

    $statisticsStmt->close();

    /*
     * ---------------------------------------------------------
     * Ethiopian today
     * ---------------------------------------------------------
     */

    $todayEthiopian =
        EthiopianCalendar::todayFormatted('en');

    /*
     * ---------------------------------------------------------
     * Response
     * ---------------------------------------------------------
     */

    echo json_encode([
        'success' => true,
        'message' =>
            'Student attendance loaded successfully.',

        'student' => [
            'id' => $userId,
            'student_id' => $studentId,
            'student_code' =>
                (string) $student['student_code'],
            'full_name' =>
                (string) $student['full_name'],
        ],

        'attendance' => $attendanceRecords,

        'statistics' => [
            'total' => (int) (
                $statistics['total'] ?? 0
            ),
            'absent' => (int) (
                $statistics['absent'] ?? 0
            ),
            'late' => (int) (
                $statistics['late'] ?? 0
            ),
        ],

        'pagination' => [
            'current_page' =>
                $currentPage,
            'per_page' =>
                $perPage,
            'total_pages' =>
                $totalPages,
            'total_items' =>
                $totalRecords,
            'has_previous' =>
                $currentPage > 1,
            'has_next' =>
                $currentPage < $totalPages,
        ],

        'ethiopian_today' =>
            $todayEthiopian,
    ]);

} catch (Throwable $e) {

    error_log(
        'Student attendance API error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Failed to load student attendance.',
    ]);
}