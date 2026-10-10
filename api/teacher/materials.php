<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match(
        '/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

/*
|--------------------------------------------------------------------------
| OPTIONS Request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Database and Ethiopian Calendar
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    int $statusCode,
    array $data
): void {
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Authorization Header
|--------------------------------------------------------------------------
*/

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
    jsonResponse(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

/*
|--------------------------------------------------------------------------
| Validate API Token
|--------------------------------------------------------------------------
*/

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
    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error'
    ]);
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
    jsonResponse(401, [
        'success' => false,
        'message' => 'Unauthorized'
    ]);
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Load Teacher
|--------------------------------------------------------------------------
*/

$teacherSql = "
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

$teacherStmt = $conn->prepare($teacherSql);

if (!$teacherStmt) {
    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error'
    ]);
}

$teacherStmt->bind_param(
    'i',
    $teacherUserId
);

$teacherStmt->execute();

$teacherResult = $teacherStmt->get_result();

$teacher = $teacherResult->fetch_assoc();

$teacherStmt->close();

if (!$teacher) {
    jsonResponse(404, [
        'success' => false,
        'message' => 'Teacher not found'
    ]);
}

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

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
    $academicYear = $academicYearResult->fetch_assoc();
}

$activeAcademicYear = $academicYear
    ? (string) $academicYear['name']
    : '';

/*
|--------------------------------------------------------------------------
| Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = null;

try {
    $todayEthiopian = EthiopianCalendar::today();
} catch (Throwable $e) {
    $todayEthiopian = null;
}

/*
|--------------------------------------------------------------------------
| Teacher Subject Assignments
|--------------------------------------------------------------------------
*/

$assignments = [];

if ($activeAcademicYear !== '') {
    $assignmentSql = "
        SELECT
            sta.id AS assignment_id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments AS sta
        INNER JOIN grade_subjects AS gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
    ";

    $assignmentStmt = $conn->prepare($assignmentSql);

    if (!$assignmentStmt) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Database error'
        ]);
    }

    $assignmentStmt->bind_param(
        'is',
        $teacherUserId,
        $activeAcademicYear
    );

    $assignmentStmt->execute();

    $assignmentResult = $assignmentStmt->get_result();

    while ($row = $assignmentResult->fetch_assoc()) {
        $assignments[] = [
            'assignment_id' => (int) $row['assignment_id'],
            'grade' => (int) $row['grade'],
            'section' => (string) $row['section'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'subject_name' => (string) $row['subject_name']
        ];
    }

    $assignmentStmt->close();
}

/*
|--------------------------------------------------------------------------
| Build Filter Options
|--------------------------------------------------------------------------
*/

$grades = [];

$sections = [];

$subjects = [];

foreach ($assignments as $assignment) {
    $grade = (int) $assignment['grade'];

    $section = (string) $assignment['section'];

    $subjectId = (int) $assignment['grade_subject_id'];

    $subjectName = (string) $assignment['subject_name'];

    if (!in_array($grade, $grades, true)) {
        $grades[] = $grade;
    }

    if (!in_array($section, $sections, true)) {
        $sections[] = $section;
    }

    if (!isset($subjects[$subjectId])) {
        $subjects[$subjectId] = $subjectName;
    }
}

sort($grades, SORT_NUMERIC);

sort(
    $sections,
    SORT_NATURAL | SORT_FLAG_CASE
);

asort(
    $subjects,
    SORT_NATURAL | SORT_FLAG_CASE
);

$subjectOptions = [];

foreach ($subjects as $subjectId => $subjectName) {
    $subjectOptions[] = [
        'id' => (int) $subjectId,
        'subject_name' => (string) $subjectName
    ];
}

/*
|--------------------------------------------------------------------------
| Read Filters
|--------------------------------------------------------------------------
*/

$selectedGrade = isset($_GET['grade'])
    ? max(0, (int) $_GET['grade'])
    : 0;

$selectedSection = isset($_GET['section'])
    ? trim((string) $_GET['section'])
    : '';

$selectedSubject = isset($_GET['subject'])
    ? max(0, (int) $_GET['subject'])
    : 0;

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$search = mb_substr(
    $search,
    0,
    200,
    'UTF-8'
);

/*
|--------------------------------------------------------------------------
| Validate Pagination
|--------------------------------------------------------------------------
*/

$perPageOptions = [
    10,
    20,
    30,
    50
];

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 10;

if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}

$currentPageNumber = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

/*
|--------------------------------------------------------------------------
| Build WHERE Conditions
|--------------------------------------------------------------------------
*/

$where = [
    'tm.teacher_user_id = ?',
    'tm.academic_year = ?',
    'tm.is_active = 1'
];

$params = [
    $teacherUserId,
    $activeAcademicYear
];

$types = 'is';

/*
|--------------------------------------------------------------------------
| Grade Filter
|--------------------------------------------------------------------------
*/

if ($selectedGrade > 0) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM subject_teacher_assignments AS filter_sta
            WHERE filter_sta.teacher_user_id = tm.teacher_user_id
              AND filter_sta.academic_year = tm.academic_year
              AND filter_sta.grade_subject_id = tm.grade_subject_id
              AND filter_sta.grade = ?
              AND filter_sta.is_active = 1
        )
    ";

    $types .= 'i';

    $params[] = $selectedGrade;
}

/*
|--------------------------------------------------------------------------
| Section Filter
|--------------------------------------------------------------------------
*/

if ($selectedSection !== '') {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM subject_teacher_assignments AS filter_sta
            WHERE filter_sta.teacher_user_id = tm.teacher_user_id
              AND filter_sta.academic_year = tm.academic_year
              AND filter_sta.grade_subject_id = tm.grade_subject_id
              AND filter_sta.section = ?
              AND filter_sta.is_active = 1
        )
    ";

    $types .= 's';

    $params[] = $selectedSection;
}

/*
|--------------------------------------------------------------------------
| Subject Filter
|--------------------------------------------------------------------------
*/

if ($selectedSubject > 0) {
    $where[] = 'tm.grade_subject_id = ?';

    $types .= 'i';

    $params[] = $selectedSubject;
}

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

if ($search !== '') {
    $where[] = "
        (
            tm.title LIKE ?
            OR tm.file_name LIKE ?
            OR tm.description LIKE ?
            OR gs.subject_name LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $types .= 'ssss';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Count Filtered Materials
|--------------------------------------------------------------------------
*/

$totalMaterials = 0;

if ($activeAcademicYear !== '') {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM teacher_materials AS tm
        INNER JOIN grade_subjects AS gs
            ON gs.id = tm.grade_subject_id
        WHERE {$whereSql}
    ";

    $countStmt = $conn->prepare($countSql);

    if (!$countStmt) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Database error'
        ]);
    }

    $countStmt->bind_param(
        $types,
        ...$params
    );

    $countStmt->execute();

    $countResult = $countStmt->get_result();

    $countRow = $countResult->fetch_assoc();

    $totalMaterials = (int) ($countRow['total'] ?? 0);

    $countStmt->close();
}

/*
|--------------------------------------------------------------------------
| Pagination Calculations
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil($totalMaterials / $perPage)
);

if ($currentPageNumber > $totalPages) {
    $currentPageNumber = $totalPages;
}

$offset = (
    $currentPageNumber - 1
) * $perPage;

/*
|--------------------------------------------------------------------------
| Load Materials
|--------------------------------------------------------------------------
*/

$materials = [];

if (
    $activeAcademicYear !== '' &&
    $totalMaterials > 0
) {
    $materialSql = "
        SELECT
            tm.id,
            tm.teacher_user_id,
            tm.grade_subject_id,
            tm.academic_year,
            tm.title,
            tm.description,
            tm.file_name,
            tm.file_path,
            tm.file_type,
            tm.file_size,
            tm.created_at,
            tm.updated_at,

            gs.grade,
            gs.subject_name,

            GROUP_CONCAT(
                DISTINCT sta.section
                ORDER BY sta.section ASC
                SEPARATOR ', '
            ) AS sections

        FROM teacher_materials AS tm

        INNER JOIN grade_subjects AS gs
            ON gs.id = tm.grade_subject_id

        LEFT JOIN subject_teacher_assignments AS sta
            ON sta.grade_subject_id = tm.grade_subject_id
           AND sta.teacher_user_id = tm.teacher_user_id
           AND sta.academic_year = tm.academic_year
           AND sta.is_active = 1
           AND sta.grade = gs.grade

        WHERE {$whereSql}

        GROUP BY
            tm.id,
            tm.teacher_user_id,
            tm.grade_subject_id,
            tm.academic_year,
            tm.title,
            tm.description,
            tm.file_name,
            tm.file_path,
            tm.file_type,
            tm.file_size,
            tm.created_at,
            tm.updated_at,
            gs.grade,
            gs.subject_name

        ORDER BY tm.created_at DESC

        LIMIT ? OFFSET ?
    ";

    $materialTypes = $types . 'ii';

    $materialParams = $params;

    $materialParams[] = $perPage;

    $materialParams[] = $offset;

    $materialStmt = $conn->prepare($materialSql);

    if (!$materialStmt) {
        jsonResponse(500, [
            'success' => false,
            'message' => 'Database error'
        ]);
    }

    $materialStmt->bind_param(
        $materialTypes,
        ...$materialParams
    );

    $materialStmt->execute();

    $materialResult = $materialStmt->get_result();

    while ($row = $materialResult->fetch_assoc()) {
        $fileName = (string) ($row['file_name'] ?? '');

        $fileType = strtolower(
            trim((string) ($row['file_type'] ?? ''))
        );

        $extension = strtolower(
            pathinfo($fileName, PATHINFO_EXTENSION)
        );

        /*
        |--------------------------------------------------------------------------
        | Material Icon
        |--------------------------------------------------------------------------
        */

        $icon = 'bi-file-earmark-fill';

        if (
            str_contains($fileType, 'pdf') ||
            $extension === 'pdf'
        ) {
            $icon = 'bi-file-earmark-pdf-fill';
        } elseif (
            str_contains($fileType, 'word') ||
            in_array($extension, ['doc', 'docx'], true)
        ) {
            $icon = 'bi-file-earmark-word-fill';
        } elseif (
            str_contains($fileType, 'powerpoint') ||
            str_contains($fileType, 'presentation') ||
            in_array($extension, ['ppt', 'pptx'], true)
        ) {
            $icon = 'bi-file-earmark-slides-fill';
        } elseif (
            str_contains($fileType, 'excel') ||
            in_array($extension, ['xls', 'xlsx', 'csv'], true)
        ) {
            $icon = 'bi-file-earmark-spreadsheet-fill';
        } elseif (
            str_contains($fileType, 'image') ||
            in_array(
                $extension,
                ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                true
            )
        ) {
            $icon = 'bi-file-earmark-image-fill';
        } elseif (
            str_contains($fileType, 'video') ||
            in_array(
                $extension,
                ['mp4', 'webm', 'avi', 'mov'],
                true
            )
        ) {
            $icon = 'bi-file-earmark-play-fill';
        } elseif (
            str_contains($fileType, 'audio') ||
            in_array($extension, ['mp3', 'wav', 'ogg'], true)
        ) {
            $icon = 'bi-file-earmark-music-fill';
        }

        /*
        |--------------------------------------------------------------------------
        | File Size
        |--------------------------------------------------------------------------
        */

        $fileSize = (int) ($row['file_size'] ?? 0);

        if ($fileSize <= 0) {
            $formattedFileSize = 'Unknown';
        } else {
            $units = ['B', 'KB', 'MB', 'GB'];

            $size = (float) $fileSize;

            $unitIndex = 0;

            while (
                $size >= 1024 &&
                $unitIndex < count($units) - 1
            ) {
                $size /= 1024;

                $unitIndex++;
            }

            $formattedFileSize = number_format(
                $size,
                $unitIndex === 0 ? 0 : 1
            ) . ' ' . $units[$unitIndex];
        }

        /*
        |--------------------------------------------------------------------------
        | Ethiopian Upload Date
        |--------------------------------------------------------------------------
        */

        $uploadedEthiopian = '—';

        if (
            !empty($row['created_at']) &&
            class_exists('EthiopianCalendar')
        ) {
            try {
                $timestamp = strtotime(
                    (string) $row['created_at']
                );

                if ($timestamp !== false) {
                    $gregorianDate = date(
                        'Y-m-d',
                        $timestamp
                    );

                    $ethiopianDate =
                        EthiopianCalendar::fromGregorian(
                            $gregorianDate
                        );

                    $uploadedEthiopian = (string) (
                        $ethiopianDate['formatted'] ?? '—'
                    );
                }
            } catch (Throwable $e) {
                $uploadedEthiopian = '—';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Sections
        |--------------------------------------------------------------------------
        */

        $sectionsText = trim(
            (string) ($row['sections'] ?? '')
        );

        $sectionList = $sectionsText !== ''
            ? array_values(
                array_filter(
                    array_map(
                        'trim',
                        explode(',', $sectionsText)
                    ),
                    static fn(string $section): bool =>
                        $section !== ''
                )
            )
            : [];

        /*
        |--------------------------------------------------------------------------
        | Material Response
        |--------------------------------------------------------------------------
        */

        $title = trim(
            (string) ($row['title'] ?? '')
        );

        if ($title === '') {
            $title = $fileName !== ''
                ? $fileName
                : 'Untitled Material';
        }

        $materials[] = [
            'id' => (int) $row['id'],
            'teacher_user_id' => (int) $row['teacher_user_id'],
            'grade_subject_id' => (int) $row['grade_subject_id'],
            'academic_year' => (string) $row['academic_year'],
            'title' => $title,
            'description' => (string) ($row['description'] ?? ''),
            'file_name' => $fileName,
            'file_path' => (string) ($row['file_path'] ?? ''),
            'file_type' => (string) ($row['file_type'] ?? ''),
            'file_size' => $fileSize,
            'formatted_file_size' => $formattedFileSize,
            'file_icon' => $icon,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'uploaded_ethiopian' => $uploadedEthiopian,
            'grade' => (int) $row['grade'],
            'subject_name' => (string) $row['subject_name'],
            'sections' => $sectionList,
            'sections_text' => $sectionsText
        ];
    }

    $materialStmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$assignmentCount = count($assignments);

$subjectCount = count($subjects);

/*
|--------------------------------------------------------------------------
| Pagination Information
|--------------------------------------------------------------------------
*/

$startRecord = $totalMaterials > 0
    ? $offset + 1
    : 0;

$endRecord = min(
    $offset + $perPage,
    $totalMaterials
);

/*
|--------------------------------------------------------------------------
| Final JSON Response
|--------------------------------------------------------------------------
*/

jsonResponse(200, [
    'success' => true,
    'message' => 'Materials retrieved successfully.',

    'teacher' => [
        'user_id' => (int) $teacher['user_id'],
        'teacher_id' => isset($teacher['teacher_id'])
            ? (int) $teacher['teacher_id']
            : null,
        'name' => (string) ($teacher['full_name'] ?? ''),
        'email' => (string) ($teacher['email'] ?? ''),
        'phone' => (string) ($teacher['phone'] ?? '')
    ],

    'academic_year' => $academicYear
        ? [
            'id' => (int) $academicYear['id'],
            'name' => (string) $academicYear['name'],
            'status' => (string) $academicYear['status']
        ]
        : null,

    'today' => $todayEthiopian
        ? [
            'year' => (int) ($todayEthiopian['year'] ?? 0),
            'month' => (int) ($todayEthiopian['month'] ?? 0),
            'month_name' => (string) (
                $todayEthiopian['month_name'] ?? ''
            ),
            'day' => (int) ($todayEthiopian['day'] ?? 0),
            'day_name' => (string) (
                $todayEthiopian['day_name'] ?? ''
            ),
            'formatted' => (string) (
                $todayEthiopian['formatted'] ?? ''
            )
        ]
        : null,

    'statistics' => [
        'total_materials' => $totalMaterials,
        'assignment_count' => $assignmentCount,
        'subject_count' => $subjectCount
    ],

    'filter_options' => [
        'grades' => $grades,
        'sections' => $sections,
        'subjects' => $subjectOptions
    ],

    'filters' => [
        'grade' => $selectedGrade,
        'section' => $selectedSection,
        'subject' => $selectedSubject,
        'search' => $search
    ],

    'pagination' => [
        'current_page' => $currentPageNumber,
        'per_page' => $perPage,
        'per_page_options' => $perPageOptions,
        'total_records' => $totalMaterials,
        'total_pages' => $totalPages,
        'start_record' => $startRecord,
        'end_record' => $endRecord,
        'has_previous' => $currentPageNumber > 1,
        'has_next' => $currentPageNumber < $totalPages
    ],

    'materials' => $materials
]);