<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student Materials API
|--------------------------------------------------------------------------
|
| Returns learning materials available to the authenticated student.
|
| Rules:
|
| 1. Authenticate using Bearer token.
| 2. Find the student record.
| 3. Find the current Active registration.
| 4. Materials must belong to the current academic year.
| 5. Materials must be active.
| 6. Materials must belong to the student's current grade.
| 7. Optional subject filter is supported.
| 8. Pagination is 10 materials per page.
|
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: application/json; charset=utf-8'
);

date_default_timezone_set(
    'Africa/Addis_Ababa'
);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/localhost:\d+$/',
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

if (
    $_SERVER['REQUEST_METHOD'] === 'OPTIONS'
) {
    http_response_code(200);

    echo json_encode(
        [
            'success' => true
        ]
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'Database connection is not available.'
        ]
    );

    exit;
}

$conn->set_charset('utf8mb4');

if (
    $_SERVER['REQUEST_METHOD'] !== 'GET'
) {
    http_response_code(405);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'Only GET requests are allowed.'
        ]
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

$ethiopianCalendarFile =
    '../../includes/EthiopianCalendar.php';

if (
    is_file($ethiopianCalendarFile)
) {
    require_once $ethiopianCalendarFile;
}

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): never {

    http_response_code(
        $statusCode
    );

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Authorization Header
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (
    isset($_SERVER['HTTP_AUTHORIZATION'])
) {
    $authorizationHeader = trim(
        (string) $_SERVER['HTTP_AUTHORIZATION']
    );

} elseif (
    function_exists('getallheaders')
) {

    $headers = getallheaders();

    foreach (
        $headers as $name => $value
    ) {

        if (
            strtolower($name) ===
            'authorization'
        ) {

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

    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$rawToken = trim(
    (string) $matches[1]
);

if (
    $rawToken === ''
) {

    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

/*
|--------------------------------------------------------------------------
| Hash Token
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $rawToken
);

/*
|--------------------------------------------------------------------------
| Validate Token
|--------------------------------------------------------------------------
*/

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

$tokenStmt = $conn->prepare(
    $tokenSql
);

if (
    !$tokenStmt
) {

    jsonResponse(
        false,
        'Database error',
        [],
        500
    );
}

$tokenStmt->bind_param(
    's',
    $tokenHash
);

$tokenStmt->execute();

$tokenResult =
    $tokenStmt->get_result();

$tokenUser =
    $tokenResult->fetch_assoc();

$tokenStmt->close();

if (
    !$tokenUser ||
    strtolower(
        (string) $tokenUser['role']
    ) !== 'student'
) {

    jsonResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$studentUserId =
    (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Student Information And Active Registration
|--------------------------------------------------------------------------
*/

$studentSql = "

    SELECT

        s.id AS student_id,

        s.student_code,

        s.full_name,

        sr.id AS registration_id,

        g.grade_number,

        sec.code AS section,

        ay.id AS academic_year_id,

        ay.name AS academic_year,

        ay.status AS academic_year_status

    FROM students AS s

    INNER JOIN student_registrations AS sr

        ON sr.student_id = s.id

    INNER JOIN grades AS g

        ON g.id = sr.grade_id

    INNER JOIN sections AS sec

        ON sec.id = sr.section_id

    INNER JOIN academic_years AS ay

        ON ay.id = sr.academic_year_id

    INNER JOIN users AS u

        ON u.id = s.user_id

    WHERE s.user_id = ?

      AND s.is_deleted = 0

      AND u.is_deleted = 0

      AND LOWER(u.role) = 'student'

      AND ay.status = 'Active'

    ORDER BY sr.id DESC

    LIMIT 1

";

$studentStmt = $conn->prepare(
    $studentSql
);

if (
    !$studentStmt
) {

    jsonResponse(
        false,
        'Database error',
        [],
        500
    );
}

$studentStmt->bind_param(
    'i',
    $studentUserId
);

$studentStmt->execute();

$studentResult =
    $studentStmt->get_result();

$student =
    $studentResult->fetch_assoc();

$studentStmt->close();

if (
    !$student
) {

    jsonResponse(
        false,
        'Student record or active registration was not found.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Registration Values
|--------------------------------------------------------------------------
*/

$studentId =
    (int) $student['student_id'];

$registrationId =
    (int) $student['registration_id'];

$academicYearId =
    (int) $student['academic_year_id'];

$gradeNumber =
    (int) $student['grade_number'];

$section =
    (string) $student['section'];

$studentName =
    (string) $student['full_name'];

$studentCode =
    (string) $student['student_code'];

$academicYear =
    (string) $student['academic_year'];

$academicYearStatus =
    (string) $student['academic_year_status'];

if (
    $academicYearId <= 0 ||
    $academicYear === '' ||
    $gradeNumber <= 0
) {

    jsonResponse(
        false,
        'Student registration information is incomplete.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Subject Filter
|--------------------------------------------------------------------------
*/

$selectedSubject =
    isset($_GET['subject'])
        ? (int) $_GET['subject']
        : 0;

/*
|--------------------------------------------------------------------------
| Subjects For Current Grade
|--------------------------------------------------------------------------
*/

$subjects = [];

$subjectSql = "

    SELECT

        id,

        subject_name

    FROM grade_subjects

    WHERE grade = ?

      AND is_active = 1

    ORDER BY subject_name ASC

";

$subjectStmt = $conn->prepare(
    $subjectSql
);

if (
    $subjectStmt
) {

    $subjectStmt->bind_param(
        'i',
        $gradeNumber
    );

    $subjectStmt->execute();

    $subjectResult =
        $subjectStmt->get_result();

    while (
        $row =
        $subjectResult->fetch_assoc()
    ) {

        $subjects[] = [

            'id' =>
                (int) $row['id'],

            'subject_name' =>
                (string) $row['subject_name']

        ];
    }

    $subjectStmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate Selected Subject
|--------------------------------------------------------------------------
|
| The selected subject must belong to the student's current grade.
|
|--------------------------------------------------------------------------
*/

if (
    $selectedSubject > 0
) {

    $validSubject = false;

    foreach (
        $subjects as $subject
    ) {

        if (
            (int) $subject['id'] ===
            $selectedSubject
        ) {

            $validSubject = true;

            break;
        }
    }

    if (
        !$validSubject
    ) {

        $selectedSubject = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$currentPage =
    isset($_GET['page'])
        ? max(
            1,
            (int) $_GET['page']
        )
        : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Count Materials
|--------------------------------------------------------------------------
*/

$totalMaterials = 0;

$countSql = "

    SELECT

        COUNT(*) AS total

    FROM teacher_materials AS tm

    INNER JOIN grade_subjects AS gs

        ON gs.id = tm.grade_subject_id

    WHERE tm.academic_year = ?

      AND tm.is_active = 1

      AND gs.grade = ?

      AND gs.is_active = 1

";

$countTypes = 'si';

$countParams = [

    $academicYear,

    $gradeNumber

];

if (
    $selectedSubject > 0
) {

    $countSql .= "

        AND tm.grade_subject_id = ?

    ";

    $countTypes .= 'i';

    $countParams[] =
        $selectedSubject;
}

$countStmt = $conn->prepare(
    $countSql
);

if (
    !$countStmt
) {

    jsonResponse(
        false,
        'Unable to count learning materials.',
        [],
        500
    );
}

$bindValues = [
    $countTypes
];

foreach (
    $countParams as &$value
) {

    $bindValues[] =
        &$value;
}

$countStmt->bind_param(
    ...$bindValues
);

$countStmt->execute();

$countResult =
    $countStmt->get_result();

$countRow =
    $countResult->fetch_assoc();

$totalMaterials =
    (int) (
        $countRow['total'] ??
        0
    );

$countStmt->close();

unset($value);

/*
|--------------------------------------------------------------------------
| Pagination Calculation
|--------------------------------------------------------------------------
*/

$totalPages =
    max(
        1,
        (int) ceil(
            $totalMaterials /
            $perPage
        )
    );

if (
    $currentPage >
    $totalPages
) {

    $currentPage =
        $totalPages;
}

$offset =
    ($currentPage - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Get Materials
|--------------------------------------------------------------------------
*/

$materials = [];

if (
    $totalMaterials > 0
) {

    $materialSql = "

        SELECT

            tm.id,

            tm.title,

            tm.description,

            tm.file_name,

            tm.file_path,

            tm.file_type,

            tm.file_size,

            tm.created_at,

            tm.updated_at,

            gs.id AS grade_subject_id,

            gs.subject_name,

            gs.grade

        FROM teacher_materials AS tm

        INNER JOIN grade_subjects AS gs

            ON gs.id = tm.grade_subject_id

        WHERE tm.academic_year = ?

          AND tm.is_active = 1

          AND gs.grade = ?

          AND gs.is_active = 1

    ";

    $materialTypes = 'si';

    $materialParams = [

        $academicYear,

        $gradeNumber

    ];

    if (
        $selectedSubject > 0
    ) {

        $materialSql .= "

            AND tm.grade_subject_id = ?

        ";

        $materialTypes .= 'i';

        $materialParams[] =
            $selectedSubject;
    }

    $materialSql .= "

        ORDER BY

            gs.subject_name ASC,

            tm.created_at DESC,

            tm.id DESC

        LIMIT ? OFFSET ?

    ";

    $materialTypes .= 'ii';

    $materialParams[] =
        $perPage;

    $materialParams[] =
        $offset;

    $materialStmt = $conn->prepare(
        $materialSql
    );

    if (
        !$materialStmt
    ) {

        jsonResponse(
            false,
            'Unable to load learning materials.',
            [],
            500
        );
    }

    $bindValues = [
        $materialTypes
    ];

    foreach (
        $materialParams as &$value
    ) {

        $bindValues[] =
            &$value;
    }

    $materialStmt->bind_param(
        ...$bindValues
    );

    $materialStmt->execute();

    $materialResult =
        $materialStmt->get_result();

    while (
        $row =
        $materialResult->fetch_assoc()
    ) {

        $materials[] = [

            'id' =>
                (int) $row['id'],

            'title' =>
                (string) (
                    $row['title'] ??
                    ''
                ),

            'description' =>
                (string) (
                    $row['description'] ??
                    ''
                ),

            'file_name' =>
                (string) (
                    $row['file_name'] ??
                    ''
                ),

            'file_path' =>
                (string) (
                    $row['file_path'] ??
                    ''
                ),

            'file_type' =>
                (string) (
                    $row['file_type'] ??
                    ''
                ),

            'file_size' =>
                isset($row['file_size'])
                    ? (int) $row['file_size']
                    : null,

            'created_at' =>
                (string) (
                    $row['created_at'] ??
                    ''
                ),

            'updated_at' =>
                (string) (
                    $row['updated_at'] ??
                    ''
                ),

            'grade_subject_id' =>
                (int) (
                    $row['grade_subject_id'] ??
                    0
                ),

            'subject_name' =>
                (string) (
                    $row['subject_name'] ??
                    ''
                ),

            'grade' =>
                (int) (
                    $row['grade'] ??
                    0
                )

        ];
    }

    $materialStmt->close();

    unset($value);
}

/*
|--------------------------------------------------------------------------
| Pagination Information
|--------------------------------------------------------------------------
*/

$startNumber =
    $totalMaterials > 0
        ? $offset + 1
        : 0;

$endNumber =
    min(
        $offset + $perPage,
        $totalMaterials
    );

/*
|--------------------------------------------------------------------------
| Ethiopian Today
|--------------------------------------------------------------------------
*/

$ethiopianToday =
    date('d M Y');

if (
    class_exists(
        'EthiopianCalendar'
    )
) {

    try {

        $ethiopianToday =
            EthiopianCalendar::todayFormatted(
                'en'
            );

    } catch (
        Throwable $e
    ) {

        $ethiopianToday =
            date('d M Y');
    }
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Student materials loaded successfully.',
    [

        'student' => [

            'id' =>
                $studentUserId,

            'student_id' =>
                $studentId,

            'student_code' =>
                $studentCode,

            'full_name' =>
                $studentName,

            'registration_id' =>
                $registrationId,

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                $section,

            'academic_year_id' =>
                $academicYearId,

            'academic_year' =>
                $academicYear,

            'academic_year_status' =>
                $academicYearStatus

        ],

        'subjects' =>
            $subjects,

        'selected_subject' =>
            $selectedSubject,

        'materials' =>
            $materials,

        'material_count' =>
            $totalMaterials,

        'pagination' => [

            'current_page' =>
                $currentPage,

            'per_page' =>
                $perPage,

            'total_materials' =>
                $totalMaterials,

            'total_pages' =>
                $totalPages,

            'start_number' =>
                $startNumber,

            'end_number' =>
                $endNumber

        ],

        'ethiopian_today' =>
            $ethiopianToday

    ]
);