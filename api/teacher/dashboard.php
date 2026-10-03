<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^http:\/\/localhost:\d+$/',
        $origin
    )
) {
    header(
        'Access-Control-Allow-Origin: ' . $origin
    );

    header(
        'Access-Control-Allow-Headers: Content-Type, Authorization'
    );

    header(
        'Access-Control-Allow-Methods: GET, OPTIONS'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true
    ]);

    exit;
}

require_once '../../config/database.php';

require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);

    exit;
}

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();

    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authorizationHeader = trim(
                (string) $value
            );

            break;
        }
    }
}

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$tokenHash = hash(
    'sha256',
    $rawToken
);

$tokenSql = "
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
";

$tokenStmt = $conn->prepare($tokenSql);

if (!$tokenStmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

$tokenStmt->execute();

$tokenResult = $tokenStmt->get_result();

$tokenUser = $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower((string) $tokenUser['role']) !== 'teacher'
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}

$teacherUserId = (int) $tokenUser['user_id'];

$todayEthiopian = EthiopianCalendar::today();

$subjectAssignments = [];

$homeroomAssignments = [];

$sql = "
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.id AS teacher_id
    FROM users AS u
    LEFT JOIN teachers AS t
        ON t.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);

    exit;
}

$stmt->bind_param(
    'i',
    $teacherUserId
);

$stmt->execute();

$result = $stmt->get_result();

$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Teacher not found'
    ]);

    exit;
}

$academicYear = null;

$academicYearSql = "
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
";

$academicYearResult = $conn->query(
    $academicYearSql
);

if ($academicYearResult) {
    $academicYear =
        $academicYearResult->fetch_assoc();
}

if ($academicYear) {
    $subjectSql = "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            gs.subject_name,
            gs.id AS grade_subject_id
        FROM subject_teacher_assignments AS sta
        INNER JOIN grade_subjects AS gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $subjectStmt =
        $conn->prepare($subjectSql);

    if ($subjectStmt) {
        $subjectStmt->bind_param(
            'is',
            $teacherUserId,
            $academicYear['name']
        );

        $subjectStmt->execute();

        $subjectResult =
            $subjectStmt->get_result();

        while (
            $row =
            $subjectResult->fetch_assoc()
        ) {
            $subjectAssignments[] = [
                'id' =>
                    (int) $row['id'],

                'grade' =>
                    (int) $row['grade'],

                'section' =>
                    (string) $row['section'],

                'subject_name' =>
                    (string) $row['subject_name'],

                'grade_subject_id' =>
                    (int) $row['grade_subject_id']
            ];
        }

        $subjectStmt->close();
    }
}

if ($academicYear) {
    $homeroomSql = "
        SELECT
            hta.id,
            hta.grade,
            hta.section
        FROM homeroom_teacher_assignments AS hta
        WHERE hta.teacher_user_id = ?
          AND hta.academic_year = ?
          AND hta.is_active = 1
        ORDER BY
            hta.grade ASC,
            hta.section ASC
    ";

    $homeroomStmt =
        $conn->prepare($homeroomSql);

    if ($homeroomStmt) {
        $homeroomStmt->bind_param(
            'is',
            $teacherUserId,
            $academicYear['name']
        );

        $homeroomStmt->execute();

        $homeroomResult =
            $homeroomStmt->get_result();

        while (
            $row =
            $homeroomResult->fetch_assoc()
        ) {
            $homeroomAssignments[] = [
                'id' =>
                    (int) $row['id'],

                'grade' =>
                    (int) $row['grade'],

                'section' =>
                    (string) $row['section']
            ];
        }

        $homeroomStmt->close();
    }
}

echo json_encode(
    [
        'success' => true,

        'teacher' => [
            'id' =>
                (int) $teacher['teacher_id'],

            'user_id' =>
                (int) $teacher['user_id'],

            'name' =>
                (string) $teacher['full_name'],

            'email' =>
                (string) ($teacher['email'] ?? ''),

            'phone' =>
                (string) ($teacher['phone'] ?? '')
        ],

        'academic_year' => $academicYear
            ? [
                'id' =>
                    (int) $academicYear['id'],

                'name' =>
                    (string) $academicYear['name'],

                'status' =>
                    (string) $academicYear['status']
            ]
            : null,

        'subject_assignments' =>
            $subjectAssignments,

        'homeroom_assignments' =>
            $homeroomAssignments,

        'today' => [
            'year' =>
                (int) $todayEthiopian['year'],

            'month' =>
                (int) $todayEthiopian['month'],

            'month_name' =>
                (string) $todayEthiopian['month_name'],

            'day' =>
                (int) $todayEthiopian['day'],

            'day_name' =>
                (string) $todayEthiopian['day_name'],

            'formatted' =>
                (string) $todayEthiopian['formatted']
        ]
    ],
    JSON_UNESCAPED_UNICODE
);