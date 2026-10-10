<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Africa/Addis_Ababa');

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true
    ]);

    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection is not available.'
    ]);

    exit;
}

$conn->set_charset('utf8mb4');

if (
    $_SERVER['REQUEST_METHOD'] !== 'GET'
) {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    array $data,
    int $statusCode = 200
): never {

    http_response_code(
        $statusCode
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
| Get Authorization Header
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (
    isset(
        $_SERVER['HTTP_AUTHORIZATION']
    )
) {

    $authorizationHeader =
        trim(
            (string) $_SERVER[
                'HTTP_AUTHORIZATION'
            ]
        );

} elseif (
    function_exists('getallheaders')
) {

    $headers =
        getallheaders();

    foreach (
        $headers as $name => $value
    ) {

        if (
            strtolower(
                (string) $name
            ) === 'authorization'
        ) {

            $authorizationHeader =
                trim(
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

    jsonResponse([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

/*
|--------------------------------------------------------------------------
| Raw Token
|--------------------------------------------------------------------------
*/

$rawToken =
    trim(
        (string) $matches[1]
    );

if (
    $rawToken === ''
) {

    jsonResponse([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

/*
|--------------------------------------------------------------------------
| Hash Token
|--------------------------------------------------------------------------
*/

$tokenHash =
    hash(
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

$tokenStmt =
    $conn->prepare(
        $tokenSql
    );

if (!$tokenStmt) {

    jsonResponse([
        'success' => false,
        'message' => 'Database error'
    ], 500);
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

    jsonResponse([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
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

$studentStmt =
    $conn->prepare(
        $studentSql
    );

if (!$studentStmt) {

    jsonResponse([
        'success' => false,
        'message' => 'Database error'
    ], 500);
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

if (!$student) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Student record or active registration was not found.'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Student Values
|--------------------------------------------------------------------------
*/

$studentId =
    (int) $student['student_id'];

$studentCode =
    (string) $student['student_code'];

$studentName =
    (string) $student['full_name'];

$registrationId =
    (int) $student['registration_id'];

$gradeNumber =
    (int) $student['grade_number'];

$section =
    (string) $student['section'];

$academicYearId =
    (int) $student['academic_year_id'];

$academicYear =
    (string) $student['academic_year'];

$academicYearStatus =
    (string) $student['academic_year_status'];

/*
|--------------------------------------------------------------------------
| Today's Ethiopian Date
|--------------------------------------------------------------------------
*/

$todayEthiopian = '';

try {

    $todayEthiopian =
        EthiopianCalendar::todayFormatted(
            'en'
        );

} catch (Throwable $exception) {

    $todayEthiopian = '';
}

/*
|--------------------------------------------------------------------------
| Automatically Close Expired Announcements
|--------------------------------------------------------------------------
*/

$closeExpiredSql = "
    UPDATE announcements

    SET status = 'Closed'

    WHERE status = 'Published'

      AND closed_at IS NOT NULL

      AND closed_at < CURDATE()
";

$conn->query(
    $closeExpiredSql
);

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string) (
            $_GET['search']
            ?? ''
        )
    );

if (
    strlen($search) > 100
) {

    $search =
        substr(
            $search,
            0,
            100
        );
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 9;

$page =
    isset($_GET['page'])
        ? max(
            1,
            (int) $_GET['page']
        )
        : 1;

/*
|--------------------------------------------------------------------------
| Count Announcements
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT
        COUNT(DISTINCT a.id) AS total

    FROM announcements AS a

    INNER JOIN announcement_audiences AS aa
        ON aa.announcement_id = a.id

    WHERE a.status = 'Published'

      AND (
            a.closed_at IS NULL
            OR a.closed_at >= CURDATE()
          )

      AND (
            aa.audience_type = 'Public'

            OR (
                aa.audience_type = 'Student'
                AND aa.grade IS NULL
            )

            OR (
                aa.audience_type = 'Student'
                AND aa.grade = ?
            )
          )
";

$countParams = [
    $gradeNumber
];

$countTypes = 'i';

if (
    $search !== ''
) {

    $countSql .= "
        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )
    ";

    $searchLike =
        '%' .
        $search .
        '%';

    $countParams[] =
        $searchLike;

    $countParams[] =
        $searchLike;

    $countTypes .= 'ss';
}

$countStmt =
    $conn->prepare(
        $countSql
    );

if (!$countStmt) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Unable to prepare announcement count query.'
    ], 500);
}

$countStmt->bind_param(
    $countTypes,
    ...$countParams
);

$countStmt->execute();

$countResult =
    $countStmt->get_result();

$countRow =
    $countResult->fetch_assoc();

$totalAnnouncements =
    (int) (
        $countRow['total']
        ?? 0
    );

$countStmt->close();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalAnnouncements /
            $perPage
        )
    );

if (
    $page > $totalPages
) {

    $page =
        $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Fetch Announcements
|--------------------------------------------------------------------------
*/

$announcementSql = "
    SELECT
        a.id,
        a.title,
        a.content,
        a.status,
        a.published_at,
        a.closed_at,
        a.created_at

    FROM announcements AS a

    INNER JOIN announcement_audiences AS aa
        ON aa.announcement_id = a.id

    WHERE a.status = 'Published'

      AND (
            a.closed_at IS NULL
            OR a.closed_at >= CURDATE()
          )

      AND (
            aa.audience_type = 'Public'

            OR (
                aa.audience_type = 'Student'
                AND aa.grade IS NULL
            )

            OR (
                aa.audience_type = 'Student'
                AND aa.grade = ?
            )
          )
";

$params = [
    $gradeNumber
];

$types = 'i';

if (
    $search !== ''
) {

    $announcementSql .= "
        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )
    ";

    $searchLike =
        '%' .
        $search .
        '%';

    $params[] =
        $searchLike;

    $params[] =
        $searchLike;

    $types .= 'ss';
}

$announcementSql .= "
    GROUP BY
        a.id,
        a.title,
        a.content,
        a.status,
        a.published_at,
        a.closed_at,
        a.created_at

    ORDER BY
        a.published_at DESC,
        a.created_at DESC,
        a.id DESC

    LIMIT ? OFFSET ?
";

$params[] =
    $perPage;

$params[] =
    $offset;

$types .= 'ii';

$announcementStmt =
    $conn->prepare(
        $announcementSql
    );

if (!$announcementStmt) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Unable to prepare announcements query.'
    ], 500);
}

$announcementStmt->bind_param(
    $types,
    ...$params
);

$announcementStmt->execute();

$announcementResult =
    $announcementStmt->get_result();

$announcements = [];

while (
    $row =
        $announcementResult->fetch_assoc()
) {

    $announcements[] = [
        'id' =>
            (int) $row['id'],

        'title' =>
            (string) $row['title'],

        'content' =>
            (string) (
                $row['content']
                ?? ''
            ),

        'status' =>
            (string) $row['status'],

        'published_at' =>
            $row['published_at'],

        'closed_at' =>
            $row['closed_at'],

        'created_at' =>
            $row['created_at'],

        'images' => [],

        'attachments' => []
    ];
}

$announcementStmt->close();

/*
|--------------------------------------------------------------------------
| Fetch Announcement Media
|--------------------------------------------------------------------------
*/

if (
    !empty($announcements)
) {

    $announcementIds =
        array_map(
            static fn(
                array $announcement
            ): int =>
                (int) $announcement['id'],
            $announcements
        );

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($announcementIds),
                '?'
            )
        );

    $mediaSql = "
        SELECT
            id,
            announcement_id,
            media_type,
            file_path,
            original_name,
            mime_type,
            file_size,
            display_order

        FROM announcement_media

        WHERE announcement_id IN (
            $placeholders
        )

        ORDER BY
            announcement_id ASC,
            display_order ASC,
            id ASC
    ";

    $mediaStmt =
        $conn->prepare(
            $mediaSql
        );

    if ($mediaStmt) {

        $mediaTypes =
            str_repeat(
                'i',
                count($announcementIds)
            );

        $mediaStmt->bind_param(
            $mediaTypes,
            ...$announcementIds
        );

        $mediaStmt->execute();

        $mediaResult =
            $mediaStmt->get_result();

        $announcementIndex = [];

        foreach (
            $announcements as $index => $announcement
        ) {

            $announcementIndex[
                (int) $announcement['id']
            ] = $index;
        }

        while (
            $mediaRow =
                $mediaResult->fetch_assoc()
        ) {

            $announcementId =
                (int) (
                    $mediaRow[
                        'announcement_id'
                    ]
                );

            if (
                !isset(
                    $announcementIndex[
                        $announcementId
                    ]
                )
            ) {
                continue;
            }

            $index =
                $announcementIndex[
                    $announcementId
                ];

            $mediaType =
                (string) (
                    $mediaRow['media_type']
                );

            $media = [
                'id' =>
                    (int) $mediaRow['id'],

                'media_type' =>
                    $mediaType,

                'file_path' =>
                    (string) (
                        $mediaRow['file_path']
                        ?? ''
                    ),

                'original_name' =>
                    (string) (
                        $mediaRow['original_name']
                        ?? ''
                    ),

                'mime_type' =>
                    (string) (
                        $mediaRow['mime_type']
                        ?? ''
                    ),

                'file_size' =>
                    !empty(
                        $mediaRow['file_size']
                    )
                    ? (int) $mediaRow['file_size']
                    : 0,

                'display_order' =>
                    (int) (
                        $mediaRow[
                            'display_order'
                        ] ?? 0
                    )
            ];

            if (
                $mediaType === 'Image'
            ) {

                $announcements[
                    $index
                ]['images'][] =
                    $media;

            } elseif (
                $mediaType === 'Attachment'
            ) {

                $announcements[
                    $index
                ]['attachments'][] =
                    $media;
            }
        }

        $mediaStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Add File URLs
|--------------------------------------------------------------------------
|
| Flutter Web needs a browser-accessible URL.
|
*/

foreach (
    $announcements as &$announcement
) {

    foreach (
        $announcement['images']
        as &$image
    ) {

        $filePath =
            trim(
                (string) (
                    $image['file_path']
                    ?? ''
                )
            );

        $filePath =
            str_replace(
                '\\',
                '/',
                $filePath
            );

        $filePath =
            ltrim(
                $filePath,
                '/'
            );

        if (
            str_starts_with(
                strtolower($filePath),
                'bkhs/'
            )
        ) {

            $filePath =
                substr(
                    $filePath,
                    5
                );
        }

        $image['file_url'] =
            'http://localhost/BKHS/' .
            $filePath;
    }

    unset($image);

    foreach (
        $announcement['attachments']
        as &$attachment
    ) {

        $filePath =
            trim(
                (string) (
                    $attachment['file_path']
                    ?? ''
                )
            );

        $filePath =
            str_replace(
                '\\',
                '/',
                $filePath
            );

        $filePath =
            ltrim(
                $filePath,
                '/'
            );

        if (
            str_starts_with(
                strtolower($filePath),
                'bkhs/'
            )
        ) {

            $filePath =
                substr(
                    $filePath,
                    5
                );
        }

        $attachment['file_url'] =
            'http://localhost/BKHS/' .
            $filePath;
    }

    unset($attachment);
}

unset($announcement);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse([
    'success' => true,

    'message' =>
        'Student announcements loaded successfully.',

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
            'Grade ' .
            $gradeNumber,

        'section' =>
            $section,

        'academic_year_id' =>
            $academicYearId,

        'academic_year' =>
            $academicYear,

        'academic_year_status' =>
            $academicYearStatus
    ],

    'announcements' =>
        $announcements,

    'announcement_count' =>
        $totalAnnouncements,

    'pagination' => [
        'current_page' =>
            $page,

        'per_page' =>
            $perPage,

        'total_pages' =>
            $totalPages,

        'total_items' =>
            $totalAnnouncements,

        'has_previous' =>
            $page > 1,

        'has_next' =>
            $page < $totalPages
    ],

    'search' =>
        $search,

    'ethiopian_today' =>
        $todayEthiopian
]);
